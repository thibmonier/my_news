# US-016 : ~~Clustering sémantique et classification par sujets~~ [SPLIT]

> **Statut : SPLIT** — Cette US a été découpée en 3 US enfants pour permettre une livraison incrémentale du pipeline ML. Elle n'est **pas comptabilisée** dans les totaux du backlog (ni en points ni en nombre d'US).

## En-tête (archivé)

| Champ | Valeur |
|-------|--------|
| **ID** | US-016 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **~~Story Points~~** | ~~8~~ (remplacés par les points des US enfants) |
| **Statut** | **SPLIT — non comptabilisée** |

---

## US enfants (à utiliser à la place)

| ID | Titre | Points | Sprint |
|----|-------|--------|--------|
| [US-016a](US-016a-generation-embeddings-semantiques.md) | Génération d'embeddings sémantiques pour les articles ingérés | 3 | backlog |
| [US-016b](US-016b-clustering-hdbscan-articles.md) | Clustering HDBSCAN des articles par similarité sémantique | 3 | backlog |
| [US-016c](US-016c-classification-automatique-clusters-sujet.md) | Classification automatique des clusters par sujet (taxonomie) | 2 | backlog |
| **Total** | | **8** | |

---

## Raison du découpage

L'US originale de 8 pts couvrait trois étapes distinctes d'un pipeline ML, chacune livrable et testable indépendamment :

1. **Génération d'embeddings (US-016a — 3 pts)** : enrichissement des articles via Mistral Embeddings (`mistral-embed`, 1024 dims), stockage pgvector. Livrable sans le clustering — les embeddings sont utiles dès cette étape pour la recherche sémantique future.

2. **Clustering HDBSCAN (US-016b — 3 pts)** : regroupement des articles en clusters thématiques via microservice Python FastAPI, persistance dans `article_clusters`, gestion des outliers et archivage des clusters > 48h. Requiert US-016a.

3. **Classification par sujet (US-016c — 2 pts)** : attribution d'un topic issu de la taxonomie fixe (Tech / IA / Business / ...) via prompt Mistral, exposition via `GET /api/v1/topics` et `GET /api/v1/clusters/{topic}`. Requiert US-016b.

Ce découpage permet de livrer les embeddings en avance et de valider le pipeline par étapes, réduisant le risque d'intégration en fin de sprint.
