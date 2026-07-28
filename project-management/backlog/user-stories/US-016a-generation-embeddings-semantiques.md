# US-016a : Génération d'embeddings sémantiques pour les articles ingérés

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-016a |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-016 — Clustering sémantique et classification par sujets |

**Dépend de :** EPIC-001 (articles ingérés disponibles en base PostgreSQL)
**Requis par :** US-016b (clustering HDBSCAN)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que les articles ingérés par le pipeline soient automatiquement enrichis d'un embedding sémantique
**Afin de** permettre le regroupement automatique des articles similaires et d'identifier les histoires majeures de mon secteur sans parcourir des dizaines d'articles redondants.

---

## Les 3 C

### Carte (résumé)

Job Symfony Messenger asynchrone déclenché toutes les heures par Scheduler. Pour chaque article sans embedding (`embedding IS NULL`), appel batch (max 100 articles) à l'API Mistral Embeddings (`mistral-embed`, vecteurs 1024 dimensions). Les vecteurs sont stockés dans PostgreSQL via l'extension `pgvector` (colonne `embedding vector(1024)` sur la table `articles`). Les articles déjà traités (`embedding IS NOT NULL`) sont ignorés pour éviter les re-calculs. Traitement idempotent : un article peut être soumis plusieurs fois sans modifier son embedding existant. Cette US est la fondation nécessaire à US-016b (clustering HDBSCAN).

### Conversation (notes & questions ouvertes)

- Stocker les embeddings dans PostgreSQL (pgvector) ou en mémoire pour HDBSCAN ? Décision : pgvector en base, persistant. Le clustering HDBSCAN (US-016b) lit les vecteurs depuis la base.
- Taille du batch Mistral Embeddings : 100 articles max par requête API (limite Mistral). Les lots de plus de 100 articles sont découpés en plusieurs requêtes.
- Que faire si Mistral Embeddings est indisponible ? Retry via Symfony Messenger (3 tentatives, backoff exponentiel 10 min / 30 min). Les articles non traités restent avec `embedding IS NULL` — traités au prochain cycle @hourly.
- Quels champs sont embeddés ? `titre + résumé` de l'article (tronqué à 512 tokens si besoin). Le contenu complet n'est pas embeddé (performance et coût).
- Extension pgvector déjà activée ? À confirmer avec Tech Lead : `CREATE EXTENSION IF NOT EXISTS vector;` en migration Doctrine.
- Index HNSW nécessaire dès cette US ? Oui : `CREATE INDEX ON articles USING hnsw (embedding vector_cosine_ops)` pour les recherches de similarité ultérieures.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Symfony Scheduler** | `EmbeddingScheduledJob` | Cron `@hourly` ; dispatche `GenerateEmbeddingsCommand` via Messenger |
| **Domain** | `EmbeddingService` | Fetch articles (`embedding IS NULL`) → découpage en batches de 100 → appel Mistral → persist vecteurs |
| **Infrastructure** | `MistralEmbeddingClient` | `POST /v1/embeddings`, modèle `mistral-embed`, batch max 100, vecteur 1024 dims ; retry sur 503/429 |
| **PostgreSQL** | `articles.embedding` | Colonne `vector(1024)` (extension pgvector) ; index HNSW `CREATE INDEX ON articles USING hnsw (embedding vector_cosine_ops)` |
| **Migration** | Doctrine migration | `CREATE EXTENSION IF NOT EXISTS vector` + `ALTER TABLE articles ADD COLUMN embedding vector(1024)` |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Batch d'embeddings généré et persisté pour tous les nouveaux articles

```gherkin
Scenario: Le job génère les embeddings pour les nouveaux articles sans embedding
  GIVEN 80 nouveaux articles ont été ingérés avec embedding IS NULL
  AND le job EmbeddingScheduledJob se déclenche selon le cron @hourly
  WHEN EmbeddingService traite le lot de 80 articles en 1 requête batch Mistral (≤ 100 articles)
  THEN 80 vecteurs de dimension 1024 sont retournés par Mistral Embeddings
  AND les 80 articles sont mis à jour en base avec leur embedding (embedding IS NOT NULL)
  AND le job se termine en moins de 2 minutes pour 80 articles
  AND les articles avec embedding IS NOT NULL avant le cycle ne sont pas re-traités
```

### Scénario alternatif 1 — Lot de plus de 100 articles (pagination batch)

```gherkin
Scenario: 250 articles sans embedding — traitement en plusieurs batches successifs
  GIVEN 250 articles avec embedding IS NULL sont disponibles en base
  WHEN EmbeddingService déclenche le traitement
  THEN il découpe le lot en 3 batches : 100, 100, 50 articles
  AND chaque batch fait une requête séparée à Mistral Embeddings
  AND l'ensemble des 250 articles sont mis à jour avec leur embedding en moins de 5 minutes
```

### Scénario alternatif 2 — Idempotence : aucun article à traiter (tous déjà embeddés)

```gherkin
Scenario: Le job se déclenche alors que tous les articles ont déjà un embedding
  GIVEN tous les articles en base ont embedding IS NOT NULL
  WHEN EmbeddingScheduledJob se déclenche
  THEN EmbeddingService détecte 0 article à traiter
  AND aucune requête n'est envoyée à Mistral Embeddings
  AND le job se termine en moins d'1 seconde sans erreur
  AND aucun embedding existant n'est écrasé
```

### Scénario erreur 1 — Mistral Embeddings indisponible (HTTP 503)

```gherkin
Scenario: Mistral Embeddings retourne HTTP 503 pendant le traitement d'un batch
  GIVEN EmbeddingService tente d'embedder un batch de 80 articles
  WHEN Mistral Embeddings retourne HTTP 503
  THEN le job est ré-enqueué dans Symfony Messenger avec un délai de 10 minutes (retry 1/3)
  AND après 3 tentatives échouées, une alerte ERROR est émise vers le monitoring (source_count, url, raison)
  AND aucun article n'est marqué embedding IS NOT NULL de manière incorrecte
  AND les articles concernés sont traités lors du prochain cycle @hourly
```

### Scénario erreur 2 — Quota Mistral Embeddings dépassé (HTTP 429)

```gherkin
Scenario: Le quota Mistral Embeddings est dépassé lors du traitement du batch
  GIVEN Mistral Embeddings retourne HTTP 429 (Too Many Requests) avec header Retry-After: 60
  WHEN MistralEmbeddingClient reçoit la réponse 429
  THEN le client attend le délai indiqué par Retry-After (60 secondes) avant de retenter
  AND si le retry échoue, le job est ré-enqueué via Messenger avec backoff exponentiel (10 min, puis 30 min)
  AND un log WARNING est enregistré : { "batch_size": 80, "http_status": 429, "retry_after": 60 }
  AND aucun embedding partiel n'est persisté pour le batch concerné (atomicité par batch)
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Must Have
- **Parent SPLIT** : US-016

### Validation INVEST

- [x] **I**ndependent : pipeline d'embedding isolé ; livrable autonome sans le clustering — les embeddings sont utiles en eux-mêmes pour les futures fonctions de recherche sémantique
- [x] **N**egotiable : modèle d'embedding (mistral-embed vs OpenAI text-embedding-3-small), stockage (pgvector vs Redis vs in-memory), champs embeddés (titre+résumé vs contenu complet)
- [x] **V**aluable : fondation nécessaire au clustering (US-016b) et à toute recherche sémantique future ; valeur directe mesurable (100% articles embeddés après cycle)
- [x] **E**stimable : `MistralEmbeddingClient` + pgvector migration + Scheduler balisés, 3 pts calibré
- [x] **S**ized : 3 pts ≤ 8 pts ✓
- [x] **T**estable : `embedding IS NOT NULL` après job, dimensionnalité 1024 vérifiable, pagination batch, retry sur 503/429 testables avec stubs Messenger
