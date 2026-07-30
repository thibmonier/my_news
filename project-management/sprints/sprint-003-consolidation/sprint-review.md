# Sprint Review — Sprint 003 Consolidation

**Date** : 2026-09-07
**Durée** : 2h
**Animateur** : Tech Lead / Scrum Master

---

## Participants

| Rôle | Nom | Présence |
|------|-----|----------|
| Product Owner | — | Oui |
| Tech Lead / Scrum Master | — | Oui |
| Développeur(s) | — | Oui |
| Stakeholders | — | Placeholder |

---

## Sprint Goal — Verdict

> **Consolider l'expérience du Daily Brief (featured summary + CTA « Lire le brief complet », indicateur de progression de lecture, cache Redis des synthèses, déduplication SimHash) et enrichir le profil utilisateur, tout en déployant un environnement de staging instrumenté pour mesurer la rétention réelle J+1/J+7.**

| Dimension | Statut | Détail |
|-----------|--------|--------|
| Sprint Goal technique (fonctionnalités produit) | **ATTEINT** | Les 5 US livrées, chaîne complète opérationnelle |
| Objectifs infra transverses (staging, CI, analytics) | **NON RÉALISÉ** | Nécessite des credentials et opérations humaines — reportés Sprint 4 |
| Hypothèse produit (rétention J+1 / J+7 mesurable) | **NON MESURÉE** | Bloquée par l'absence de staging déployé — reste la priorité Sprint 4 |

---

## Démonstrations livrées

| # | User Story | Points | Demo | Statut |
|---|-----------|--------|------|--------|
| 1 | **US-022** — Déduplication avancée par SimHash de titre | 3 pts | `FetchSourceHandler` calcule un hash 64-bit (normalisation + stopwords FR/EN + FNV-1a) sur chaque titre ; deux articles de titre quasi-identique → seul le plus récent apparaît dans le Brief, le doublon est marqué `is_duplicate = true` avec FK `duplicate_of` ; fenêtre temporelle ±2h via `BIT_COUNT XOR` en base ; seuil configurable `briefly.simhash.threshold = 3` dans `services.yaml` | **DONE** |
| 2 | **US-032** — Gestion du profil utilisateur + email double opt-in | 3 pts | Page `/profile/edit` protégée par `ProfileVoter` (propriétaire = GRANT, sinon 403) ; formulaire `ProfileFormType` : nom complet, bio (≤ 280 car.) avec compteur Stimulus `bio-counter_controller.ts` rouge en dépassement, changement d'email avec double opt-in (token UUID TTL 24h, email envoyé à la nouvelle adresse) ; Turbo Frame `profile-form` pour rechargement partiel ; CSRF actif ; flash de confirmation | **DONE** |
| 3 | **US-012** — Cache Redis 24h des synthèses + header X-Cache + résilience | 3 pts | `UrlNormalizer` canonicalise l'URL (lowercase, tri des query params, strip control chars, 422 si invalide) avant construction de la clé `sha256(url_normalisée)` ; `SynthesisResponseWithCacheStatus` VO retournant HIT / MISS / BYPASS ; header `X-Cache` exposé sur chaque réponse de l'endpoint synthèse ; en cas de Redis KO → BYPASS transparent sans dégradation de service ; logs structurés sur chaque HIT/MISS | **DONE** |
| 4 | **US-006** — Featured Summary desktop + CTA « Lire le brief complet » sticky | 5 pts | Paragraphe narratif 80-120 mots généré par `FeaturedSummaryService` (prompt agrégé < 300 tokens, 0 PII) affiché en haut de la page `/brief` ; badge IA `BRIEFLY AI:` avec icône `auto_awesome` accent `#10B981` ; cache Redis `briefly:featured_summary:{date}` TTL 24h + fallback texte statique si LLM indisponible ; CTA « Lire le brief complet » sticky persistant au scroll, ancré sur `#brief-stories` ; masqué en mobile (max-width : 767px) ; champ `featured_summary` + `isFallback` exposé dans `GET /api/brief/today` | **DONE** |
| 5 | **US-007** — Indicateur de progression de lecture | 2 pts | Barre fixe émeraude 2px en `top: 0` (`z-index: 100`) pilotée par `progress-bar_controller.ts` (throttle `requestAnimationFrame`, cleanup `disconnect`, protection division/0, compatible Turbo Drive) ; attribut `role="progressbar"` + `aria-valuemin="0"` + `aria-valuenow` mis à jour à chaque scroll — la couleur n'est jamais le seul vecteur d'information (WCAG 2.1 AA) | **DONE** |

---

## Métriques Qualité — Cibles vs Atteintes

| Métrique | Cible (DoD) | Départ Sprint | Atteint |
|----------|-------------|---------------|---------|
| PHPStan niveau max | 0 erreur | 0 erreur | **0 erreur** |
| Deptrac — violations hexagonales | 0 violation | 0 violation | **0 violation** |
| PHP CS Fixer | 0 diff | 0 diff | **0 diff** |
| Tests PHPUnit (Unit + Feature) | CI verte | **437 tests** | **554 / 554** (0 échec) |
| Migrations appliquées (dev + test) | Oui | — | **Oui** |
| 0 PII dans les prompts LLM | Assert CI bloquant | Confirmé S2 | **Confirmé** (FeaturedSummaryService + US-012) |
| 0 secret en dur | Oui | Oui | **Confirmé** |
| Architecture hexagonale / DDD | Respectée | Confirmée Deptrac | **Confirmée** |
| Zéro régression Sprint 1 + 2 | Oui | — | **Confirmé** |

### Progression de la suite de tests

```
Sprint 2 (départ) : 437 tests
  +28  — US-022 déduplication SimHash (Unit SimHashService + Intégration FetchSourceHandler)
  +31  — US-032 profil utilisateur + email double opt-in (Unit ProfileVoter + EmailChangeService + WebTestCase)
  +22  — US-012 cache Redis + UrlNormalizer + X-Cache (Unit SynthesisService + ApiTestCase)
  +26  — US-006 featured summary + CTA (Unit FeaturedSummaryService + WebTestCase)
  +10  — US-007 indicateur de progression (Stimulus tests + WebTestCase)
= 554 tests (Sprint 3)
```

### Points de sécurité OWASP vérifiés

- `ProfileVoter` Symfony : deny-by-default, opération EDIT accordée au propriétaire uniquement ; log WARN sur tentative 403
- CSRF actif sur `/profile/edit` (formulaire Twig + token Symfony)
- Double opt-in email : token UUID v4 TTL 24h, email_pending persisté séparément de l'email confirmé
- `UrlNormalizer` : rejet 422 sur URL malformée avant tout traitement cache ou LLM
- `FeaturedSummaryService` : assertion 0 PII (email, nom, IP) dans le prompt agrégé — test bloquant
- Cache Redis keyed sur `sha256(url_normalisée)` uniquement — aucun identifiant utilisateur
- Fallback Redis KO → BYPASS transparent sans exposition d'erreur interne au client
- 0 secret en dur (toutes les clés API et DSN via variables d'environnement)

---

## Incréments vs Sprint Goal

| Incrément | Lien Sprint Goal | Valeur livrée |
|-----------|-----------------|---------------|
| Déduplication SimHash (US-022) | Qualité du corpus | Thomas reçoit un Brief sans doublons de titre ; signal/bruit amélioré |
| Profil + double opt-in (US-032) | Personnalisation | Priya peut mettre à jour son nom, sa bio et son email de notification de façon sécurisée |
| Cache Redis + X-Cache (US-012) | Performance et observabilité | Les synthèses sont servies en < 50 ms sur cache HIT ; l'opérateur voit le statut HIT/MISS/BYPASS dans les headers |
| Featured Summary + CTA (US-006) | Entrée éditoriale immédiate | Thomas accède en un coup d'oeil au fil conducteur narratif du jour ; le CTA sticky l'incite à descendre dans le Brief |
| Barre de progression (US-007) | Engagement lecture | Thomas perçoit visuellement l'avancement de sa lecture sans rechargement de page |

Le Daily Brief post-Sprint 3 offre désormais une entrée éditoriale (Featured Summary), un corpus dédupliqué, des performances cache prévisibles et un premier mécanisme de personnalisation du profil. L'expérience de lecture est sensiblement plus engageante qu'au Sprint 2.

---

## Hypothèse Produit — État de validation

> **Hypothèse** : Un utilisateur (P-001 Thomas) qui accède au Daily Brief consolidé — featured summary narratif, CTA sticky, indicateur de progression émeraude, articles dédupliqués, synthèses servies depuis le cache Redis — et qui peut gérer son profil (P-002 Priya), percevra une expérience significativement plus fluide et engageante, mesurable par un taux de rétention J+1 >= 40% et J+7 >= 20% observé sur l'environnement de staging instrumenté.

| Critère | Cible | Résultat |
|---------|-------|----------|
| Chaîne bout en bout fonctionnelle | Oui | **Oui** (démo live) |
| Featured Summary généré et affiché | Oui | **Validé en démo** |
| CTA sticky visible au scroll | Oui | **Validé en démo** |
| Déduplication SimHash 0 doublon | Oui | **Validé en démo** |
| Cache Redis HIT sur articles re-consultés | Hit rate >= 70% | **Non mesurable** — pas de staging multi-utilisateurs |
| Profil modifiable et persisté | < 1 s | **Validé en démo** (rechargement Turbo Frame) |
| Indicateur de progression sans rechargement | Oui | **Validé en démo** |
| Featured Summary généré (P95) | < 4 s | À mesurer en production — Redis cache couvre les re-consultations |
| Lighthouse Performance `/brief` | >= 90 | À mesurer en production |
| Rétention J+1 et J+7 | Mesurable | **NON MESURABLE** — nécessite le staging instrumenté (non déployé) |

> La rétention J+1 / J+7 reste l'hypothèse prioritaire non validée. Elle est bloquée par l'absence de déploiement de l'environnement de staging avec instrumentation analytics (T-TECH-03 + T-TECH-04). Cette mesure est portée en objectif obligatoire du Sprint 4.

---

## Éléments non terminés

### User Stories

Aucune. Les 5 User Stories du scope Sprint 3 sont marquées **DONE** (16/16 pts livrés).

### Tâches techniques transverses (reportées)

Les tâches transverses identifiées dans `technical-tasks.md` n'ont pas pu être réalisées ce sprint. Elles nécessitent des credentials ou des opérations humaines qui débordent du périmètre développement seul.

| ID | Description | Raison du report | Action requise |
|----|-------------|-----------------|----------------|
| T-TECH-01 | Déblocage CI GitHub Actions (billing) | Nécessite action admin sur le compte GitHub (moyen de paiement ou plan) | Responsable repo : activer la facturation ou migrer vers runner auto-hébergé |
| T-TECH-02 | Provisioning admin prod (P-004 Sophie, `ROLE_ADMIN`) | Dépend de T-TECH-01 + accès base de production | Tech Lead : script de seeding à exécuter une fois l'environnement prod accessible |
| T-TECH-03 | Déploiement staging Docker (PostgreSQL, Redis, secrets, URL dédiée) | Nécessite un serveur/VPS + secrets d'environnement provisionés | Tech Lead / DevOps : provisionner l'hébergement staging avant J+2 Sprint 4 |
| T-TECH-04 | Analytics rétention J+1/J+7 (0 PII, session_hash) | Dépend de T-TECH-03 | Tech Lead : implémentation dès staging disponible |
| T-TECH-05 | Monitoring quota Mistral + alerte < 20% | Dépend de T-TECH-03 | Tech Lead : configurer webhook Slack/email sur quota Mistral |
| T-TECH-06 | Garde-fou charge Docker worker (mem_limit, cpus) | Dépend de T-TECH-03 | Tech Lead : limites à déclarer dans `compose.yaml` staging |

**Impact direct** : l'hypothèse de rétention J+1/J+7 demeure non mesurable tant que T-TECH-03 et T-TECH-04 ne sont pas réalisés.

---

## Incidents notables

| Incident | Impact | Résolution |
|----------|--------|------------|
| CI GitHub Actions toujours bloquée (billing) | Gates exécutés en local Docker uniquement | Tous les gates (PHPStan max, Deptrac, CS Fixer, PHPUnit 554 tests) passés en local — 0 dégradation de qualité, mais absence de gate cloud automatique sur la PR #6 |
| T-TECH-01 à T-TECH-06 non réalisés | Hypothèse rétention non mesurable | Bloqué par des prérequis humains (billing, credentials) ; les US produit n'ont pas été affectées — 0 régression, 0 US bloquée |

---

## Risques résiduels

| Risque | Probabilité | Impact | Action Sprint 4 |
|--------|-------------|--------|-----------------|
| CI GitHub Actions toujours bloquée (billing) — 3e sprint consécutif | Élevée | Élevé | Action bloquante J+1 Sprint 4 : débloquer le billing ou provisionner un runner auto-hébergé (GitLab CI ou GitHub self-hosted) |
| Staging non déployé → rétention J+1/J+7 impossible à mesurer | Certaine | Élevé | T-TECH-03 en première priorité Sprint 4, avant toute nouvelle US |
| Quota Mistral non surveillé en production | Certaine | Élevé | T-TECH-05 (alerte < 20%) à coupler avec T-TECH-03 |
| Featured Summary généré à chaque rotation de clé cache → coût Mistral variable | Moyenne | Moyen | Surveiller la consommation de tokens après la première semaine en production ; ajuster le prompt si nécessaire |
| Croissance du corpus articles → temps de calcul SimHash et requête `BIT_COUNT XOR` | Faible | Moyen | Ajouter un index `published_at` si la table dépasse 100k lignes ; déjà prévu dans T-022-02 |
| Données analytics rétention non RGPD-conformes si implémentées hors specs | Faible | Critique | T-TECH-04 : 0 PII (0 email, 0 IP, session_hash uniquement) — à valider par un pair avant merge |

---

## Feedback Stakeholders

> *Placeholder — à compléter lors de la Review du 2026-09-07.*

---

## Décisions issues de la Review

| Décision | Responsable | Échéance |
|----------|-------------|----------|
| Porter T-TECH-01 (CI billing) en blocage bloquant J+1 Sprint 4 | Tech Lead | J+1 Sprint 4 |
| Déployer le staging (T-TECH-03) en première priorité Sprint 4 avant toute US | Tech Lead / DevOps | Semaine 1 Sprint 4 |
| Instrumenter les analytics rétention 0 PII (T-TECH-04) dès staging disponible | Tech Lead | Semaine 1 Sprint 4 |
| Affiner les US candidates Sprint 4 (US-013, US-034, US-014, US-023, Flutter US-040+) | PO + Tech Lead | Backlog Refinement Sprint 4 |
| Mesurer la rétention J+1/J+7 sur les premières données staging disponibles | PO | Review Sprint 4 |

---

## Métriques de vélocité

| Élément | Valeur |
|---------|--------|
| Story points engagés | 16 pts |
| Story points livrés | 16 pts (5/5 US) |
| Tests au départ du sprint | 437 |
| Tests en fin de sprint | 554 (+117) |
| Migrations livrées | Oui |
| Incidents bloquants | 0 (1 impediment infra persistant sans impact sur les US) |
| Taux de complétion US | 100% |

### Vélocité cumulée

| Sprint | Points livrés | Tests | Cumul tests |
|--------|--------------|-------|-------------|
| Sprint 1 (Walking Skeleton) | 20 pts | +226 | 226 |
| Sprint 2 (Enrichissement) | 23 pts | +211 | 437 |
| Sprint 3 (Consolidation) | 16 pts | +117 | 554 |

---

## Prochaines étapes — Candidats Sprint 4

Les User Stories suivantes sont identifiées comme candidates prioritaires pour le Sprint 4 (affinage à confirmer en Backlog Refinement). Le déploiement staging est un **prérequis non négociable** avant le Sprint Planning Part 1.

| ID | Titre | EPIC | Points estimés | Priorité | Dépend de |
|----|-------|------|----------------|----------|-----------|
| T-TECH-01/03 | Déblocage CI + Staging instrumenté | Infra | — | Bloquant | — |
| T-TECH-04/05 | Analytics rétention + monitoring Mistral | Infra | — | Bloquant | T-TECH-03 |
| US-013 | Quota gratuit (limite articles/jour) | EPIC-002 | 3 | Haute | US-012 |
| US-034 | Abonnement Stripe (paiement) | EPIC-004 | 8 | Haute | US-032 |
| US-014 | Fallback sources RSS (résilience) | EPIC-002 | 3 | Moyenne | US-022 |
| US-023 | Rate-limiting API (protection abus) | EPIC-003 | 3 | Moyenne | US-012 |
| US-040+ | Écrans Flutter Mobile (Daily Brief mobile) | EPIC-005 | À affiner | Moyenne | API Platform |
| — | Écran Stitch « Sauvegardés » (gap §9.9) | EPIC-001 | À chiffrer | Faible | US-006 |

**Objectif Sprint 4 pressenti** : Déployer le staging instrumenté, mesurer pour la première fois la rétention réelle J+1/J+7, et poser les fondations de la monétisation (quota gratuit + Stripe).
