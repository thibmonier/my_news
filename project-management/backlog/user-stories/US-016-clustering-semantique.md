# US-016 : Clustering sémantique et classification par sujets

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-016 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 8 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |

**Dépend de :** US-011 (Detailed level pour synthèse cluster), EPIC-001 (articles ingérés), EPIC-004 (Daily Brief — consommateur du clustering)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que les articles similaires soient automatiquement regroupés par thème et classifiés par sujet
**Afin de** repérer immédiatement les 3 histoires majeures de mon secteur sans parcourir des dizaines d'articles redondants.

---

## Les 3 C

### Carte (résumé)

Job Symfony Messenger (async) déclenché toutes les heures par Scheduler. Pipeline : embedding vectoriel des titres+résumés via Mistral Embeddings → clustering HDBSCAN (min_cluster_size=3, min_samples=2) → classification sujet par taxonomie fixe (Tech / IA / Politique / Économie / Science / Autre) via prompt Mistral → stockage des clusters en PostgreSQL. Les clusters alimentent le Daily Brief (EPIC-004). Les histoires outliers (non clusterisées) sont classifiées individuellement.

### Conversation (notes & questions ouvertes)

- Quelle dimension d'embedding Mistral ? `mistral-embed` produit des vecteurs de 1024 dimensions — stocker dans PostgreSQL avec extension `pgvector` ou en mémoire pour HDBSCAN ?
- HDBSCAN en PHP natif ? Non, utiliser un microservice Python (FastAPI) ou appeler `scipy` via Symfony Process. À décider en refinement Tech Lead.
- Taxonomie de sujets : Tech / IA / Business / Géopolitique / Science / Santé / Environnement / Autre — à valider avec les personas.
- Fréquence de clustering : toutes les heures en production, toutes les 6h en période basse (nuit UTC).
- Comment gérer les clusters "fossiles" (articles > 48h) ? Marquer comme archivés, ne plus apparaître dans le Daily Brief actif.
- Faut-il exposer la taxonomie via l'API ? Oui, `GET /api/v1/topics` pour le frontend et le mobile.
- Performance : clustering sur ~500 articles/heure, tolérance de latence job = 5 minutes.
- RGPD : les embeddings sont calculés sur le contenu éditorial des articles, jamais sur du contenu utilisateur.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Symfony Scheduler** | `ClusteringScheduledJob` | Cron `@hourly`, dispatche `RunClusteringCommand` via Messenger |
| **Domain** | `ClusteringService` | Orchestration : fetch articles non clusterisés → embed → HDBSCAN → classify → persist |
| **Infrastructure** | `MistralEmbeddingClient` | `POST /v1/embeddings`, modèle `mistral-embed`, batch de 100 articles max |
| **Infrastructure** | `HdbscanClusterer` | Appel microservice Python FastAPI (`POST /cluster`) ou `Process` scipy ; retourne `[{articleId, clusterId}]` |
| **Infrastructure** | `MistralTopicClassifier` | Prompt Mistral : "Classify this cluster into one of: Tech, IA, Business, Géopolitique, Science, Santé, Environnement, Autre" |
| **PostgreSQL** | `article_clusters` | `cluster_id UUID, article_id UUID FK, topic VARCHAR(32), cluster_label TEXT, created_at TIMESTAMPTZ, archived_at TIMESTAMPTZ` |
| **API Platform** | `GET /api/v1/topics` | Liste les topics actifs avec nombre de clusters et articles |
| **API Platform** | `GET /api/v1/clusters/{topic}` | Retourne les clusters actifs par topic, triés par taille décroissante |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Clustering réussi sur lot d'articles

```gherkin
Scenario: Le job de clustering regroupe les articles similaires et les classifie
  GIVEN 120 nouveaux articles ont été ingérés par EPIC-001 dans la dernière heure
  AND le job ClusteringScheduledJob se déclenche selon le cron @hourly
  WHEN le pipeline de clustering s'exécute complètement
  THEN au moins 80% des articles sont assignés à un cluster (cohérence HDBSCAN ≥ 80%)
  AND chaque cluster reçoit exactement un sujet issu de la taxonomie définie
  AND les clusters sont persistés dans article_clusters avec cluster_id, article_id et topic
  AND le job se termine en moins de 5 minutes pour 120 articles
  AND les clusters sont disponibles via GET /api/v1/clusters/{topic} en moins d'1 minute après le job
```

### Scénario alternatif 1 — Articles outliers non clusterisés

```gherkin
Scenario: Des articles très spécifiques ne sont assignés à aucun cluster (outliers)
  GIVEN 15 articles sur des sujets très niches ne correspondent à aucun groupe HDBSCAN
  WHEN le pipeline HDBSCAN les identifie comme outliers (clusterId = -1)
  THEN ces articles sont classifiés individuellement par Mistral (sujet unique)
  AND ils apparaissent dans article_clusters avec un cluster_id unique et le flag outlier = true
  AND ils ne remontent pas dans le Daily Brief des histoires majeures mais restent accessibles via l'API
```

### Scénario alternatif 2 — Clusters archivés après 48h

```gherkin
Scenario: Les clusters contenant des articles vieux de plus de 48h sont archivés
  GIVEN un cluster "IA / Lancement GPT-5" a été créé il y a 49 heures
  WHEN le job de clustering s'exécute
  THEN ce cluster est marqué archived_at = NOW()
  AND il n'apparaît plus dans GET /api/v1/clusters/{topic} (filtre sur archived_at IS NULL)
  AND les articles qu'il contient restent en base pour historique
```

### Scénario erreur 1 — Microservice HDBSCAN indisponible

```gherkin
Scenario: Le microservice Python FastAPI de clustering est hors ligne
  GIVEN le microservice HdbscanClusterer retourne HTTP 503
  WHEN ClusteringService tente le clustering
  THEN le job est ré-enqueué dans Symfony Messenger avec un délai de 10 minutes (retry)
  AND après 3 tentatives échouées, une alerte ERROR est émise vers le monitoring
  AND aucun article n'est marqué comme clusterisé de manière incorrecte
  AND les anciens clusters restent disponibles en base sans modification
```

### Scénario erreur 2 — Taxonomie de sujet retournée invalide par Mistral

```gherkin
Scenario: Mistral retourne un sujet hors taxonomie pour un cluster
  GIVEN Mistral classification retourne "Politique Internationale" (hors taxonomie)
  WHEN TopicClassifier valide la réponse
  THEN le sujet non reconnu est mappé sur "Autre" (fallback)
  AND un log WARNING est émis : { "cluster_id": "...", "raw_topic": "Politique Internationale", "mapped_to": "Autre" }
  AND le cluster est persisté avec topic = "Autre" sans bloquer le pipeline
```

---

## Estimation & Références

- **Story Points** : 8
- **MoSCoW** : Must Have
- **Validation INVEST** :
  - [x] Independent — pipeline async isolé ; EPIC-004 consomme les données mais ne conditionne pas la livraison de cette US
  - [x] Negotiable — algorithme (HDBSCAN vs K-Means vs agglomératif), taxonomie (nombre et libellés), fréquence du job
  - [x] Valuable — sans clustering, le Daily Brief ne peut pas identifier les 3 histoires majeures (feature clé du positionnement Briefly)
  - [x] Estimable — pipeline ML embarqué documenté, intégration Symfony Messenger + Scheduler maîtrisée
  - [x] Sized — 8 pts maximum INVEST respecté (découpage possible : embedding = 3 pts + HDBSCAN = 3 pts + classification = 2 pts si nécessaire en sprint)
  - [x] Testable — taux de cohérence ≥ 80%, durée job < 5 min, taxonomie valide, API fonctionnelle vérifiables en test d'intégration
