# Task Board — Sprint 003 Consolidation

> **Sprint Goal** : Consolider l'expérience du Daily Brief (featured summary + CTA, indicateur de progression, cache Redis synthèses, déduplication SimHash) et enrichir le profil utilisateur, tout en déployant un staging instrumenté pour mesurer la rétention J+1/J+7.

**Sprint** : 2026-08-25 → 2026-09-07 | **Vélocité cible** : 16 points

---

## Légende Statuts

| Icône | Statut | Description |
|-------|--------|-------------|
| 🔲 | A faire | Pas encore commencé |
| 🔄 | En cours | Développement en cours |
| 👀 | Review | Code review / QA |
| ✅ | Done | Critères DoD validés |
| 🚫 | Bloqué | Impediment identifié |

---

## Kanban — Niveau User Stories

### 🔲 A faire

| US | Titre | EPIC | Points | Assignee | Dépend de |
|----|-------|------|--------|----------|-----------|
| US-022 | Déduplication SimHash de titre | EPIC-003 | 3 | — | sprint 2 mergé |
| US-032 | Gestion du profil utilisateur | EPIC-004 | 3 | — | sprint 2 mergé |
| US-012 | Cache Redis 24h des synthèses | EPIC-002 | 3 | — | sprint 2 mergé |
| US-006 | Featured Summary desktop + CTA | EPIC-001 | 5 | — | US-012 + US-022 |
| US-007 | Indicateur de progression de lecture | EPIC-001 | 2 | — | US-006 |

**Total A faire : 16 pts / 5 US**

---

## Kanban — Niveau Tâches techniques

### 🔲 A faire — US-022 : Déduplication SimHash de titre

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-022-01 | [DB] | DoctrineArticleEntity + title_simhash, is_duplicate, duplicate_of | 1h | — |
| T-022-02 | [DB] | Migration colonnes SimHash + index published_at + FK self-ref | 0.5h | T-022-01 |
| T-022-03 | [BE] | SimHashServiceInterface domaine (compute, distance) | 0.5h | — |
| T-022-04 | [BE] | SimHashService 64-bit (normalisation, stopwords FR/EN, fnv1a) | 1.5h | T-022-03 |
| T-022-05 | [BE] | ArticleRepository::findPotentialDuplicates (BIT_COUNT XOR ±2h) | 1.5h | T-022-03, T-022-01 |
| T-022-06 | [BE] | FetchSourceHandler enrichi (SimHash + markAsDuplicate + filtre Brief) | 2h | T-022-04, T-022-05 |
| T-022-07 | [BE] | Config briefly.simhash.threshold services.yaml (défaut 3) | 0.5h | T-022-04 |
| T-022-08 | [TEST] | Unit SimHashService (vide → null, proches, distincts, CJK, stopwords) | 2h | T-022-04 |
| T-022-09 | [TEST] | Intégration FetchSourceHandler (doublon, seuil, fenêtre, exception) | 2h | T-022-06 |
| T-022-10 | [DOC] | PHPDoc SimHashService + findPotentialDuplicates + FetchSourceHandler | 0.5h | T-022-06 |
| T-022-11 | [REV] | Code review US-022 | 1h | T-022-10 |

**Sous-total US-022 : 11 tâches — 13h**

---

### 🔲 A faire — US-032 : Gestion du profil utilisateur

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-032-01 | [DB] | DoctrineUserEntity + bio VARCHAR(280) + email_pending VARCHAR(255) | 0.5h | — |
| T-032-02 | [DB] | Migration colonnes bio + email_pending (+ token + expires_at) | 0.5h | T-032-01 |
| T-032-03 | [BE] | ProfileFormType (full_name, bio Length 280, email, UniqueEntity, CSRF) | 1h | T-032-01 |
| T-032-04 | [BE] | ProfileVoter::EDIT (propriétaire = GRANT, sinon 403 + log WARN) | 1h | — |
| T-032-05 | [BE] | EmailChangeService double opt-in (token UUID, email_pending, TTL 24h) | 1.5h | T-032-01 |
| T-032-06 | [BE] | ProfileController::edit GET/POST (Voter, form, flash, Turbo Frame) | 1.5h | T-032-03, T-032-04, T-032-05 |
| T-032-07 | [BE] | ProfileController::confirmEmail (valide token, met à jour email) | 1h | T-032-05 |
| T-032-08 | [FE-WEB] | Template Twig templates/profile/edit.html.twig (Turbo Frame profile-form) | 2h | T-032-06, T-032-09 |
| T-032-09 | [FE-WEB] | Stimulus bio-counter_controller.ts (X/280, rouge si dépassement) | 0.5h | — |
| T-032-10 | [TEST] | Unit ProfileVoter (propriétaire, autre user, non-auth) | 1h | T-032-04 |
| T-032-11 | [TEST] | Unit EmailChangeService (token, email_pending, confirm, expiré) | 1.5h | T-032-05 |
| T-032-12 | [TEST] | WebTestCase /profile/edit (GET, POST valid, 422 bio, 422 email, 403) | 2h | T-032-06, T-032-07 |
| T-032-13 | [DOC] | PHPDoc ProfileController, ProfileVoter, EmailChangeService, ProfileFormType | 0.5h | T-032-06 |
| T-032-14 | [REV] | Code review US-032 | 1h | T-032-13 |

**Sous-total US-032 : 14 tâches — 15h**

---

### 🔲 A faire — US-012 : Cache Redis 24h des synthèses

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-012-01 | [BE] | UrlNormalizer (lowercase, tri query params, strip control chars, 422 si invalide) | 1.5h | — |
| T-012-02 | [BE] | SynthesisService::buildCacheKey enrichi (URL normalisée + logs structurés) | 1h | T-012-01 |
| T-012-03 | [BE] | SynthesisResponseWithCacheStatus VO (HIT / MISS / BYPASS) | 1h | T-012-02 |
| T-012-04 | [BE] | Header X-Cache dans UrlSynthesisProcessor + fallback Redis KO → BYPASS | 1.5h | T-012-03 |
| T-012-05 | [TEST] | Unit SynthesisService (canonicalisation, \r\n→422, BYPASS, 3 niveaux) | 2h | T-012-02, T-012-03 |
| T-012-06 | [TEST] | ApiTestCase X-Cache headers (HIT, MISS, BYPASS Redis KO) | 1.5h | T-012-04 |
| T-012-07 | [DOC] | PHPDoc UrlNormalizer, SynthesisService, SynthesisResponseWithCacheStatus | 0.5h | T-012-03 |
| T-012-08 | [REV] | Code review US-012 | 1h | T-012-07 |

**Sous-total US-012 : 8 tâches — 10h**

---

### 🔲 A faire — US-006 : Featured Summary desktop + CTA

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-006-01 | [DB] | DailyBriefSummaryEntity (id, brief_id FK UNIQUE, content, model_version, is_fallback) + Interface | 1.5h | — |
| T-006-02 | [DB] | Migration daily_brief_summaries + index UNIQUE brief_id | 0.5h | T-006-01 |
| T-006-03 | [BE] | FeaturedSummaryService (prompt agrégé, < 300 tokens, 0 PII, 80-120 mots) | 2h | T-006-01 |
| T-006-04 | [BE] | Cache Redis briefly:featured_summary:{date} TTL 24h + fallback texte | 1h | T-006-03 |
| T-006-05 | [BE] | GenerateDailyBriefHandler enrichi (appel FeaturedSummaryService + persist) | 1.5h | T-006-03, T-006-04 |
| T-006-06 | [BE] | Champ featured_summary + isFallback dans GET /api/brief/today | 1h | T-006-01, T-006-03 |
| T-006-07 | [FE-WEB] | Section HTML .featured-summary + badge IA + CTA sticky + id="brief-stories" | 2h | T-006-05 |
| T-006-08 | [FE-WEB] | CSS .featured-summary + .cta sticky + @media masquage mobile max-width:767px | 1h | T-006-07 |
| T-006-09 | [TEST] | Unit FeaturedSummaryService (PII-free assert, cache HIT, fallback, 80-120 mots) | 2h | T-006-03, T-006-04 |
| T-006-10 | [TEST] | WebTestCase GET /brief (.featured-summary, badge, ancre #brief-stories, mobile) | 2h | T-006-07, T-006-08 |
| T-006-11 | [DOC] | PHPDoc FeaturedSummaryService, DTO, Repository interface | 0.5h | T-006-03 |
| T-006-12 | [REV] | Code review US-006 | 1h | T-006-11 |

**Sous-total US-006 : 12 tâches — 16h**

---

### 🔲 A faire — US-007 : Indicateur de progression de lecture

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-007-01 | [FE-WEB] | Stimulus progress-bar_controller.ts (rAF throttle, disconnect, division/0, Turbo) | 2h | — |
| T-007-02 | [FE-WEB] | CSS _progress-bar.css (fixed top:0, 2px, emerald, z-index:100, transition) | 0.5h | — |
| T-007-03 | [FE-WEB] | BriefController intégration div progress-bar + ARIA role=progressbar | 1h | T-007-01, T-007-02 |
| T-007-04 | [TEST] | Tests Stimulus (connect, disconnect, scroll 0/50/100%, page non-scrollable, Turbo) | 2h | T-007-01 |
| T-007-05 | [TEST] | WebTestCase GET /brief (role=progressbar, aria-valuemin/max, id=brief-stories) | 1h | T-007-03 |
| T-007-06 | [DOC] | JSDoc progress-bar_controller (rAF, anti-fuite, division/0, ARIA) | 0.5h | T-007-01 |
| T-007-07 | [REV] | Code review US-007 | 0.5h | T-007-06 |

**Sous-total US-007 : 7 tâches — 7.5h**

---

### 🔲 A faire — Tâches techniques transverses

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-TECH-01 | [OPS] | CI GitHub Actions déblocage billing + pipeline vert (Pest/PHPStan/deptrac/CS/Docker) | 4h | — |
| T-TECH-02 | [OPS] | Provisioning admin prod P-004 Sophie (ROLE_ADMIN) | 2h | T-TECH-01 |
| T-TECH-03 | [OPS] | Déploiement staging Docker + PostgreSQL + Redis + secrets + URL dédiée | 4h | T-TECH-01 |
| T-TECH-04 | [BE] | Analytics rétention J+1/J+7 (0 PII, session_hash, brief_opened/completed) | 3h | T-TECH-03 |
| T-TECH-05 | [OPS] | Monitoring quota Mistral + alerte < 20% | 2h | T-TECH-03 |
| T-TECH-06 | [OPS] | Garde-fou charge Docker worker (mem_limit, cpus, restart) | 1h | T-TECH-03 |

**Sous-total technique transverse : 6 tâches — 16h**

---

## 🔄 En Cours

| ID | US | Tâche | Démarré | Assigné |
|----|-----|-------|---------|---------|
| — | — | — | — | — |

---

## 👀 En Review

| ID | US | Tâche | Reviewer |
|----|-----|-------|----------|
| — | — | — | — |

---

## ✅ Done

| ID | US | Tâche | Réel | Terminé |
|----|-----|-------|------|---------|
| — | — | — | — | — |

---

## 🚫 Bloqué

| ID | US | Raison | Action requise |
|----|-----|--------|----------------|
| T-TECH-01 | — | Billing GitHub Actions non débloqué | Admin repo : activer moyen de paiement ou plan adéquat |

---

## Métriques Sprint 003

| Indicateur | Valeur |
|------------|--------|
| Story Points | 16 pts |
| US en scope | 5 |
| Tâches features | 52 |
| Tâches techniques transverses | 6 |
| **Total tâches** | **58** |
| Heures estimées features | 61.5h |
| Heures estimées infra | 16h |
| **Total heures estimées** | **77.5h** |
| Tâches terminées | 0 (0%) |
| Heures consommées | 0h |
| Heures restantes | 77.5h |

### Répartition par US

| US | Points | Tâches | Heures est. | Heures réel | Statut |
|----|--------|--------|-------------|-------------|--------|
| US-022 | 3 | 11 | 13h | 0h | 🔲 |
| US-032 | 3 | 14 | 15h | 0h | 🔲 |
| US-012 | 3 | 8 | 10h | 0h | 🔲 |
| US-006 | 5 | 12 | 16h | 0h | 🔲 |
| US-007 | 2 | 7 | 7.5h | 0h | 🔲 |
| Technique | — | 6 | 16h | 0h | 🔲 |
| **Total** | **16** | **58** | **77.5h** | **0h** | — |

### Répartition par type (features)

| Type | Tâches | Heures |
|------|--------|--------|
| [DB] | 6 | 4h |
| [BE] | 24 | 32h |
| [FE-WEB] | 8 | 9.5h |
| [TEST] | 10 | 15.5h |
| [DOC] | 4 | 2h |
| [REV] | 5 | 5h |
| **Total features** | **52** (réel avec [DOC]/[REV]) | **61.5h** |
| [OPS] (transverse) | 5 | 13h |
| [BE] (transverse) | 1 | 3h |
| **Grand total** | **58** | **77.5h** |
