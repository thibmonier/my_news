# Sprint Review — Sprint 001 Walking Skeleton

**Date** : 2026-08-10
**Duree** : 2h
**Animateur** : Tech Lead / Scrum Master

---

## Participants

| Role | Nom | Presence |
|------|-----|----------|
| Product Owner | — | Oui |
| Tech Lead / Scrum Master | — | Oui |
| Developpeur(s) | — | Oui |
| Stakeholders | — | Placeholder |

---

## Sprint Goal — Verdict

> **Livrer le Walking Skeleton de Briefly AI : pipeline RSS reel → selection algorithmique → Daily Brief public consultable sans authentification, enrichi d'une premiere synthese Mistral a la demande, avec inscription securisee Argon2id et quota Redis — prouvant de bout en bout la promesse «fort signal, faible bruit».**

| Dimension | Statut | Detail |
|-----------|--------|--------|
| Sprint Goal technique (bout en bout) | **ATTEINT** | Les 7 US livrees, chaine complete operationnelle |
| Hypothese produit (retention J+1) | **NON MESUREE** | Necessite un deploiement en production et des utilisateurs reels — reportee Sprint 2 |

---

## Demonstrations livrees

| # | User Story | Demo | Statut |
|---|-----------|------|--------|
| 1 | **US-020** — Pipeline RSS (FeedIo + dedup SHA-256) | Ingestion en temps reel depuis 3 sources publiques (TechCrunch, TheVerge, ArsTechnica) ; deduplication SHA-256 sur titre+URL validee | **DONE** |
| 2 | **US-001** — Page publique Daily Brief (Twig) | Page `/brief` affichant les 3 histoires horodatees, accessible sans authentification | **DONE** |
| 3 | **US-002** — Selection algorithmique des 3 histoires | Score composite calcule et persistant ; les 3 histoires majeures selectionnees et affichees dans l'ordre | **DONE** |
| 4 | **US-003** — Scheduler 5h UTC + TryLock Redis | Batch lance automatiquement a 5h UTC ; verrou Redis TryLock TTL 600s evitant les executions concurrentes | **DONE** |
| 5 | **US-010** — Synthese Mistral a la demande | Synthese prefixee `BRIEFLY AI:` generee en < 60s sur un article interne ; SSRF protege (article_id interne, pas d'URL externe) | **DONE** |
| 6 | **US-030** — Inscription securisee Argon2id | Formulaire d'inscription avec hash Argon2id (128 MiB, t=3, p=1) ; anti-enumeration : reponse 200 generique quel que soit l'etat de l'email | **DONE** |
| 7 | **US-033** — Quota Redis + paywall placeholder | HTTP 429 a la 4e synthese journaliere ; modal paywall affiche ; quota Redis sans FK utilisateur (UUID uniquement, RGPD) | **DONE** |

---

## Metriques Qualite — Cibles vs Atteintes

| Metrique | Cible (DoD) | Atteint |
|----------|-------------|---------|
| PHPStan niveau max | 0 erreur | **0 erreur** |
| Deptrac — violations hexagonales | 0 violation | **0 violation** (94 fichiers src audites) |
| PHP CS Fixer | 0 diff | **0 diff** |
| Tests Pest | CI verte | **226/226** (178 Unit + 48 Feature vs PostgreSQL+Redis reels) |
| Image Docker prod | Build OK | **Build OK** |
| Couverture de code | >= 80% | Atteinte (Unit + Feature) |
| Architecture hexagonale / DDD | Respectee | **Confirmee par Deptrac** |

### Points de securite OWASP verifies

- Argon2id (128 MiB, t=3, p=1) sur les mots de passe
- Anti-enumeration a l'inscription (200 generique)
- Entry point API : 401 JSON RFC 7807
- SSRF : `/synthesize` recoit un `article_id` interne (pas d'URL externe)
- UUID v4 sur toutes les entites

---

## Increments non termines

Aucun. Les 7 User Stories du scope Sprint 1 sont marquees **DONE**.

> Note : La CI GitHub Actions (pipeline en ligne) n'a pas pu etre verifiee en environnement cloud (billing repo prive bloque). La verification a ete effectuee en local via Docker. Ce point est repris en Retrospective.

---

## Metriques de velocite

| Element | Valeur |
|---------|--------|
| Story points engages | 36 pts |
| Story points livres | 36 pts (7/7 US) |
| Taches realisees | 84 taches |
| Heures estimees | 140,5h |
| Capacite equipe (estimee) | ~132h (2 devs) |
| Ecart | +6% (legerement au-dessus de la capacite) |

---

## Mesures Hypothese Produit (chrono en demo)

| Critere | Cible | Resultat |
|---------|-------|----------|
| Chaine bout en bout sans intervention manuelle | Oui | **Oui** |
| Temps de generation batch (5h UTC) | < 30s | A mesurer en prod |
| Temps de reponse `/brief` | < 500ms | A mesurer en prod |
| Inscription complete | < 2 min | **Validee en demo** |
| Synthese Mistral prefixee `BRIEFLY AI:` | >= 1 generee | **Validee en demo** |

> La metrique de retention J+1 ne peut etre mesuree qu'apres deploiement en production avec de vrais utilisateurs. Elle devient l'hypothese prioritaire du Sprint 2.

---

## Feedback Stakeholders

> *Placeholder — a completer lors de la Review du 2026-08-10.*

---

## Decisions issues de la Review

| Decision | Responsable | Echeance |
|----------|-------------|----------|
| Porter la validation de l'hypothese produit (retention J+1) en objectif Sprint 2 | PO | Sprint 2 Planning |
| Resoudre le blocage CI GitHub Actions (billing) avant le debut du Sprint 2 | Tech Lead | J+1 Sprint 2 |
| Preparer le deploiement en environnement de staging pour mesurer les metriques prod | Tech Lead / Devs | Sprint 2 |
| Affiner US-004, US-005, US-011, US-021, US-031 pour le Sprint 2 | PO + Tech Lead | Backlog Refinement Sprint 2 |
