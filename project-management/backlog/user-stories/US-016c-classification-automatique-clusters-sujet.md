# US-016c : Classification automatique des clusters par sujet (taxonomie fixe)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-016c |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 2 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-016 — Clustering sémantique et classification par sujets |

**Dépend de :** US-016b (clusters créés dans `article_clusters`)
**Requis par :** EPIC-004 (Daily Brief consomme les clusters classifiés par topic)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que chaque cluster de la journée soit automatiquement classifié avec un sujet issu d'une taxonomie fixe (Tech / IA / Business / Géopolitique / Science / Santé / Environnement / Autre)
**Afin de** naviguer directement vers les histoires du secteur Tech et IA qui me concernent, sans avoir à lire les titres un par un.

---

## Les 3 C

### Carte (résumé)

Pour chaque cluster nouvellement créé par US-016b (`topic IS NULL`), `MistralTopicClassifier` envoie un prompt de classification à Mistral avec les titres des articles du cluster. La réponse est validée contre la taxonomie fixe : Tech / IA / Business / Géopolitique / Science / Santé / Environnement / Autre. Si Mistral retourne un sujet hors taxonomie, fallback automatique sur "Autre" avec log WARNING. Le topic est persisté dans `article_clusters.topic`. Les outliers (`outlier = true`) sont classifiés individuellement. La taxonomie est exposée via `GET /api/v1/topics` et `GET /api/v1/clusters/{topic}` (API Platform).

### Conversation (notes & questions ouvertes)

- Prompt de classification : "Given the following article titles from a news cluster: [titles]. Classify this cluster into exactly one of: Tech, IA, Business, Géopolitique, Science, Santé, Environnement, Autre. Respond with only the topic name."
- La taxonomie est-elle configurable en base ? Non en v1 — valeur fixe en PHP (enum ou constante). Évolution future si les besoins des personas l'exigent.
- Les outliers ont-ils aussi besoin d'un sujet ? Oui, pour permettre la recherche par thématique même sur les articles non clusterisés.
- L'endpoint `GET /api/v1/topics` expose-t-il uniquement les sujets actifs ? Oui, filtrés sur `archived_at IS NULL`.
- Les clusters sont-ils classifiés en batch ou individuellement ? Individuellement (1 appel Mistral par cluster) pour permettre un retry granulaire en cas d'échec.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `MistralTopicClassifier` | Prompt classification Mistral → validation taxonomie PHP → fallback "Autre" si réponse hors taxo ; 1 appel par cluster |
| **PostgreSQL** | `article_clusters.topic` | Colonne `VARCHAR(32)` ajoutée via migration ; enum PHP : Tech / IA / Business / Géopolitique / Science / Santé / Environnement / Autre |
| **API Platform** | `GET /api/v1/topics` | Liste les sujets actifs (archived_at IS NULL) avec `cluster_count` et `article_count` groupés par topic |
| **API Platform** | `GET /api/v1/clusters/{topic}` | Clusters actifs par topic, triés par taille décroissante (nombre d'articles) |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Chaque cluster reçoit un sujet valide de la taxonomie

```gherkin
Scenario: MistralTopicClassifier classifie les nouveaux clusters sans sujet
  GIVEN 12 clusters viennent d'être créés par US-016b avec topic IS NULL
  WHEN MistralTopicClassifier traite chaque cluster (1 appel Mistral par cluster)
  THEN chaque cluster reçoit exactement un sujet issu de la taxonomie (Tech, IA, Business, Géopolitique, Science, Santé, Environnement ou Autre)
  AND le topic est persisté dans article_clusters.topic (NOT NULL) pour chacun des 12 clusters
  AND les clusters sont disponibles via GET /api/v1/clusters/{topic} groupés par sujet
  AND le traitement de 12 clusters se termine en moins de 2 minutes
```

### Scénario alternatif 1 — Les outliers (outlier=true) sont classifiés individuellement

```gherkin
Scenario: Les articles outliers reçoivent aussi un sujet via classification individuelle
  GIVEN 8 articles outliers (outlier=true, cluster_id unique) issus de US-016b ont topic IS NULL
  WHEN MistralTopicClassifier les traite individuellement (titre seul comme contexte de classification)
  THEN chaque article outlier reçoit un sujet issu de la taxonomie persisté dans article_clusters.topic
  AND ils apparaissent dans GET /api/v1/clusters/{topic} avec le flag outlier=true
  AND ils ne remontent pas dans le Daily Brief des histoires majeures (filtrés côté EPIC-004)
```

### Scénario alternatif 2 — Taxonomie active exposée via l'API avec métriques

```gherkin
Scenario: L'API /api/v1/topics retourne les sujets actifs avec leurs métriques
  GIVEN 4 clusters actifs ont été classifiés : 2 "Tech" (15 et 8 articles), 1 "IA" (12 articles), 1 "Business" (6 articles)
  WHEN un client appelle GET /api/v1/topics
  THEN la réponse HTTP 200 contient 3 entrées (Tech, IA, Business)
  AND chaque entrée contient topic, cluster_count et article_count
  AND les sujets sans cluster actif (archived_at IS NULL) ne sont pas retournés
```

### Scénario erreur 1 — Mistral retourne un sujet hors taxonomie (fallback Autre)

```gherkin
Scenario: Mistral retourne un sujet non reconnu — fallback automatique sur "Autre"
  GIVEN MistralTopicClassifier appelle Mistral pour un cluster d'articles sur la politique internationale
  WHEN Mistral retourne la réponse "Politique Internationale" (hors taxonomie fixe)
  THEN TopicClassifier mappe le sujet non reconnu sur "Autre" (fallback)
  AND un log WARNING est enregistré : { "cluster_id": "...", "raw_topic": "Politique Internationale", "mapped_to": "Autre" }
  AND le cluster est persisté avec topic = "Autre" sans bloquer le pipeline
```

### Scénario erreur 2 — Mistral indisponible lors de la classification d'un cluster

```gherkin
Scenario: Mistral est indisponible lors de la classification d'un cluster (HTTP 503)
  GIVEN MistralTopicClassifier tente de classifier un cluster de 5 articles
  WHEN Mistral retourne HTTP 503
  THEN le cluster reste avec topic = NULL temporairement
  AND la classification est ré-enqueué dans Symfony Messenger avec un délai de 10 minutes (retry 1/3)
  AND après 3 tentatives échouées, topic est forcé à "Autre" et un log ERROR est enregistré
  AND le pipeline de clustering (US-016b) n'est pas re-déclenché
```

---

## Estimation & Références

- **Story Points** : 2
- **MoSCoW** : Must Have
- **Parent SPLIT** : US-016

### Validation INVEST

- [x] **I**ndependent : classification isolée du clustering (US-016b) ; testable séparément avec des clusters mockés (topic IS NULL fourni en fixture)
- [x] **N**egotiable : taxonomie (libellés, nombre de catégories), provider (Mistral vs règles fixes vs zero-shot), comportement fallback (Autre vs retry illimité)
- [x] **V**aluable : sans topic, Thomas ne peut pas "aller directement en Tech/IA" dans le Daily Brief ; l'API /topics/clusters est le point d'entrée de navigation thématique
- [x] **E**stimable : prompt Mistral + validation enum PHP + 2 endpoints API Platform balisés, 2 pts clairement borné
- [x] **S**ized : 2 pts ≤ 8 pts ✓
- [x] **T**estable : validation taxonomie (topic IN enum), fallback "Autre" sur réponse hors taxo, API /topics et /clusters/{topic} vérifiables, outlier classification séparable en test d'intégration
