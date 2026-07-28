# US-016b : Clustering HDBSCAN des articles par similarité sémantique

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-016b |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-016 — Clustering sémantique et classification par sujets |

**Dépend de :** US-016a (embeddings sémantiques générés pour les articles)
**Requis par :** US-016c (classification des clusters par sujet), EPIC-004 (Daily Brief consomme les clusters)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que les articles avec embeddings soient automatiquement regroupés en clusters thématiques via l'algorithme HDBSCAN
**Afin de** voir les histoires majeures de la journée regroupées par thème, sans avoir à parcourir des dizaines d'articles redondants sur le même événement.

---

## Les 3 C

### Carte (résumé)

Pipeline Symfony Messenger asynchrone déclenché toutes les heures (après le job d'embedding US-016a). Lecture des embeddings depuis PostgreSQL (`pgvector`), envoi au microservice Python FastAPI HDBSCAN (`POST /cluster`, paramètres : min_cluster_size=3, min_samples=2). Le microservice retourne `[{article_id, cluster_id}]`. Les articles outliers (cluster_id = -1) reçoivent un UUID unique et le flag `outlier = true`. Clusters et associations sont persistés dans la table `article_clusters`. Les clusters dont tous les articles dépassent 48h sont archivés (`archived_at = NOW()`). Cette US est la fondation nécessaire à US-016c (classification par sujet).

### Conversation (notes & questions ouvertes)

- HDBSCAN en PHP natif ? Non, microservice Python FastAPI dédié, déployé dans Docker Compose (`hdbscan-service`).
- Format de l'échange avec le microservice : `POST /cluster` avec body `{"embeddings": [[...1024 floats...], ...], "article_ids": ["uuid1", ...]}`. Réponse : `[{"article_id": "uuid1", "cluster_id": 0}]`.
- Paramètres HDBSCAN configurables : `min_cluster_size=3` (au moins 3 articles par cluster), `min_samples=2` (densité minimale pour core point). Ajustables via configuration Symfony.
- Performance cible : ≤ 5 minutes pour 500 articles/heure.
- Comment gérer les clusters "fossiles" (articles > 48h) ? Marquer `archived_at = NOW()` ; filtrés dans `GET /api/v1/clusters/{topic}` (condition `archived_at IS NULL`).
- Les embeddings sont-ils tous envoyés au microservice ou seulement les nouveaux ? Pour simplifier en v1 : tous les articles des dernières 48h avec embedding. Le microservice recalcule à chaque cycle (pas de clustering incrémental en v1).

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Symfony Scheduler** | `ClusteringScheduledJob` | Cron `@hourly` ; dispatche `RunClusteringCommand` via Messenger (après `EmbeddingScheduledJob`) |
| **Domain** | `ClusteringService` | Fetch embeddings (articles ≤ 48h) → appel HDBSCAN → persist clusters ; gère outliers (cluster_id = -1) |
| **Infrastructure** | `HdbscanClusterer` | Client HTTP vers FastAPI `POST /cluster` ; mapping `[{article_id, cluster_id}]` ; retry 3× sur HTTP 503 avec backoff |
| **PostgreSQL** | Table `article_clusters` | `cluster_id UUID, article_id UUID FK, created_at TIMESTAMPTZ, archived_at TIMESTAMPTZ NULL, outlier BOOLEAN DEFAULT false` |
| **Migration** | Doctrine migration | Création table `article_clusters` avec FK sur `articles.id`, index sur `archived_at` et `cluster_id` |
| **Domain** | `ArchiveStaleClusterCommand` | Marque `archived_at = NOW()` pour les clusters dont tous les articles ont `published_at < NOW() - INTERVAL '48h'` |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Clustering réussi avec cohérence thématique ≥ 80%

```gherkin
Scenario: Le job de clustering regroupe les articles similaires en clusters cohérents
  GIVEN 120 articles avec embeddings (embedding IS NOT NULL) sont disponibles sur les dernières 48h
  AND le job ClusteringScheduledJob se déclenche selon le cron @hourly
  WHEN ClusteringService envoie les 120 embeddings au microservice HDBSCAN (POST /cluster)
  THEN le microservice retourne une liste de 120 associations {article_id, cluster_id}
  AND au moins 96 articles (≥ 80%) sont assignés à un cluster (cluster_id ≠ -1)
  AND les clusters sont persistés dans article_clusters avec cluster_id UUID, article_id, created_at
  AND le job se termine en moins de 5 minutes pour 120 articles
```

### Scénario alternatif 1 — Articles outliers identifiés et enregistrés avec flag dédié

```gherkin
Scenario: 15 articles très spécifiques ne correspondent à aucun cluster (outliers HDBSCAN)
  GIVEN le microservice HDBSCAN retourne cluster_id = -1 pour 15 articles
  WHEN ClusteringService traite les résultats
  THEN ces 15 articles sont insérés dans article_clusters avec outlier = true et un cluster_id UUID unique par article
  AND ils ne comptent pas dans le calcul du taux de cohérence (métrique excluant les outliers)
  AND ils restent accessibles en base pour la classification individuelle par US-016c
```

### Scénario alternatif 2 — Clusters archivés après 48h d'inactivité

```gherkin
Scenario: Les clusters contenant des articles vieux de plus de 48h sont archivés
  GIVEN un cluster "IA / Lancement GPT-5" a été créé il y a 49 heures
  AND tous les articles du cluster ont published_at < NOW() - INTERVAL '48h'
  WHEN ArchiveStaleClusterCommand s'exécute lors du cycle @hourly
  THEN ce cluster est marqué archived_at = NOW() en base
  AND il n'apparaît plus dans GET /api/v1/clusters/{topic} (filtre archived_at IS NULL)
  AND les articles du cluster restent en base avec leur cluster_id pour l'historique
```

### Scénario erreur 1 — Microservice HDBSCAN indisponible (HTTP 503)

```gherkin
Scenario: Le microservice Python FastAPI de clustering est hors ligne
  GIVEN HdbscanClusterer envoie POST /cluster et reçoit HTTP 503
  WHEN ClusteringService détecte l'échec du microservice
  THEN le job est ré-enqueué dans Symfony Messenger avec un délai de 10 minutes (retry 1/3)
  AND après 3 tentatives échouées, une alerte ERROR est émise vers le monitoring
  AND aucun article n'est marqué clusterisé de manière incorrecte (pas d'insertion partielle dans article_clusters)
  AND les anciens clusters restent disponibles en base sans modification
```

### Scénario erreur 2 — Aucun article avec embedding à traiter (cycle vide)

```gherkin
Scenario: Le job de clustering se déclenche mais aucun article avec embedding n'est disponible
  GIVEN aucun article avec embedding IS NOT NULL n'est disponible sur les 48 dernières heures
  WHEN ClusteringScheduledJob se déclenche
  THEN ClusteringService détecte 0 embedding à traiter
  AND aucun appel n'est envoyé au microservice HDBSCAN
  AND le job se termine immédiatement (< 1 seconde) sans erreur
  AND un log INFO est enregistré : "ClusteringJob: 0 articles à clusteriser — cycle ignoré"
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Must Have
- **Parent SPLIT** : US-016

### Validation INVEST

- [x] **I**ndependent : pipeline de clustering isolé du reste de EPIC-002 ; livrable sans la classification (US-016c) — les clusters bruts ont de la valeur dès cette US
- [x] **N**egotiable : algorithme (HDBSCAN vs K-Means vs agglomératif), paramètres (`min_cluster_size`, `min_samples`), fréquence du job, seuil d'archivage (48h négociable)
- [x] **V**aluable : sans clustering, le Daily Brief ne peut pas identifier les histoires majeures ; cette US est le cœur du différenciateur produit "déduplication par histoires"
- [x] **E**stimable : microservice FastAPI HDBSCAN + client PHP `HdbscanClusterer` + table `article_clusters` + archivage balisés, 3 pts calibré
- [x] **S**ized : 3 pts ≤ 8 pts ✓
- [x] **T**estable : taux de cohérence ≥ 80%, flag outlier, archivage, retry HTTP 503, cycle vide vérifiables en test d'intégration avec microservice stubé
