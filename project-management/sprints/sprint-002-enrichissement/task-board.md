# Task Board — Sprint 002 Enrichissement

> **Sprint Goal** : Enrichir le Daily Brief avec des condensés IA par article et des cartes typées par catégorie, offrir plusieurs niveaux de synthèse, ouvrir l'authentification sociale OAuth et donner à l'admin le contrôle des sources RSS — passant du Walking Skeleton à une expérience produit complète.

**Sprint** : 2026-08-11 → 2026-08-24 | **Vélocité cible** : 23 points

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
| US-004 | Condensé IA par article (badge BRIEFLY AI:) | EPIC-001 | 5 | — | sprint 1 mergé |
| US-021 | CRUD des sources RSS (back-office admin) | EPIC-003 | 5 | — | sprint 1 mergé |
| US-031 | Authentification OAuth Google / GitHub | EPIC-004 | 5 | — | sprint 1 mergé |
| US-011 | Niveaux de synthèse (Concise / Detailed / Narrative) | EPIC-002 | 5 | — | US-004 |
| US-005 | Cartes typées par catégorie éditoriale | EPIC-001 | 3 | — | US-004 |

**Total A faire : 23 pts / 5 US**

---

## Kanban — Niveau Tâches techniques

### 🔲 A faire — US-004 : Condensé IA par article

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-004-01 | [DB] | Entity ArticleSummaryEntity + ArticleSummaryRepositoryInterface | 1.5h | — |
| T-004-02 | [DB] | Migration table article_summaries | 0.5h | T-004-01 |
| T-004-03 | [BE] | Interfaces domaine : SummaryClientInterface + ArticleSummary VO | 1h | — |
| T-004-04 | [BE] | MistralApiClient enrichi (prompt condensé 3-4 puces, assert PII-free) | 2h | T-004-03 |
| T-004-05 | [BE] | OpenAiSummaryClient fallback (même SummaryClientInterface) | 1.5h | T-004-03 |
| T-004-06 | [BE] | SummaryCircuitBreaker Redis (2 timeouts → open 60s) | 1.5h | — |
| T-004-07 | [BE] | ArticleSummaryService (cache → Mistral → OpenAI → extrait brut) | 2.5h | T-004-03, T-004-04, T-004-05, T-004-06 |
| T-004-08 | [BE] | Enrichissement BriefController (appel ArticleSummaryService) | 1h | T-004-07 |
| T-004-09 | [FE-WEB] | Twig _article_summary.html.twig (badge IA, puces, noopener) | 1.5h | T-004-08 |
| T-004-10 | [FE-WEB] | Design token badge IA (--color-ai-badge: #10B981) | 0.5h | — |
| T-004-11 | [TEST] | Unit ArticleSummaryService (cache, PII-free, fallback, XSS) | 2h | T-004-07 |
| T-004-12 | [TEST] | WebTestCase GET /brief enrichi (badge, puces, noopener, XSS) | 2h | T-004-09 |
| T-004-13 | [DOC] | PHPDoc ArticleSummaryService, SummaryClientInterface, VO | 0.5h | T-004-07 |
| T-004-14 | [REV] | Code review US-004 | 1h | T-004-13 |

**Sous-total US-004 : 14 tâches — 19h**

---

### 🔲 A faire — US-005 : Cartes typées par catégorie

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-005-01 | [DB] | Migration articles.category ENUM + DEFAULT 'productivity' | 0.5h | — |
| T-005-02 | [BE] | ArticleCategory enum PHP 8.1 (label, badgeColor, 5 cases) | 1h | — |
| T-005-03 | [BE] | ArticleClassifierService (Mistral zéro-shot + fallback mots-clés) | 2h | T-005-02 |
| T-005-04 | [BE] | Enrichissement DoctrineArticleEntity champ category | 0.5h | T-005-01, T-005-02 |
| T-005-05 | [BE] | BriefStoryPublicView + DailyBriefRepository (JOIN category) | 1h | T-005-02, T-005-04 |
| T-005-06 | [FE-WEB] | Twig _brief_card.html.twig badge catégorie | 1.5h | T-005-05 |
| T-005-07 | [FE-WEB] | CSS design tokens 5 badges catégories | 0.5h | — |
| T-005-08 | [TEST] | Unit ArticleCategory + ArticleClassifierService (fallback, exception) | 1.5h | T-005-02, T-005-03 |
| T-005-09 | [TEST] | WebTestCase GET /brief badges (libellés, CSS class, mobile) | 1.5h | T-005-06 |
| T-005-10 | [DOC] | PHPDoc ArticleCategory, ArticleClassifierService | 0.5h | T-005-05 |
| T-005-11 | [REV] | Code review US-005 | 1h | T-005-10 |

**Sous-total US-005 : 11 tâches — 11h**

---

### 🔲 A faire — US-011 : Niveaux de synthèse

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-011-01 | [DB] | Migration synthesis_results.level VARCHAR(16) DEFAULT 'concise' | 0.5h | — |
| T-011-02 | [BE] | SynthesisLevel enum PHP (CONCISE/DETAILED/NARRATIVE + prompts + timeouts) | 1h | — |
| T-011-03 | [BE] | SynthesisRequest DTO + champ level (défaut CONCISE) | 0.5h | T-011-02 |
| T-011-04 | [BE] | MistralApiClient prompt/timeout par niveau (15/30/45s) | 2h | T-011-02, T-011-03 |
| T-011-05 | [BE] | SynthesisService clé cache sha256(url+level) + timeout adapté | 1h | T-011-03, T-011-04 |
| T-011-06 | [BE] | SynthesisResource API Platform level input + contrainte 422 | 1h | T-011-03 |
| T-011-07 | [FE-WEB] | Twig _synthesis_level_selector.html.twig (radio 3 niveaux) | 1.5h | — |
| T-011-08 | [FE-WEB] | synthesis_controller.js (envoi level, badge niveau affiché) | 1h | T-011-07 |
| T-011-09 | [FE-WEB] | synthesis_result.html.twig badge niveau discret | 0.5h | T-011-06 |
| T-011-10 | [TEST] | Unit SynthesisLevel (prompts distincts, timeouts, fromString invalide) | 1h | T-011-02 |
| T-011-11 | [TEST] | Unit SynthesisService niveau (3 clés cache distinctes, timeout) | 1.5h | T-011-05 |
| T-011-12 | [TEST] | ApiTestCase 3 niveaux + level invalide 422 + timeout narrative 503 | 1.5h | T-011-06 |
| T-011-13 | [DOC] | PHPDoc SynthesisLevel, SynthesisRequest, MistralApiClient | 0.5h | T-011-05 |
| T-011-14 | [REV] | Code review US-011 | 1h | T-011-13 |

**Sous-total US-011 : 14 tâches — 14h**

---

### 🔲 A faire — US-021 : CRUD sources admin

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-021-01 | [DB] | Migration sources : status ENUM + deleted_at + UNIQUE url + index | 1h | — |
| T-021-02 | [BE] | SourceStatus enum enrichi (PENDING_VALIDATION, VALIDATION_FAILED, DELETED) | 0.5h | — |
| T-021-03 | [BE] | DoctrineSourceEntity : champs status + deleted_at + UNIQUE url | 0.5h | T-021-01, T-021-02 |
| T-021-04 | [BE] | SourceRepository : findAllActive, search ILIKE, softDelete | 1.5h | T-021-03 |
| T-021-05 | [BE] | ValidateSourceMessage + ValidateSourceHandler (HEAD HTTP, status update) | 2h | T-021-04 |
| T-021-06 | [BE] | BulkFetchHandler (dispatch FetchSourceMessage par source active) | 1h | T-021-04 |
| T-021-07 | [BE] | SourceType Form (name, url HTTPS, feed_type, interval ; SSRF blocklist) | 2h | T-021-02 |
| T-021-08 | [BE] | SourceVoter (ROLE_ADMIN requis toutes opérations) | 1h | — |
| T-021-09 | [BE] | AdminSourceController (index, new, edit, delete soft, bulkUpdate) | 2.5h | T-021-06, T-021-07, T-021-08 |
| T-021-10 | [FE-WEB] | Templates admin/sources/ (index, new, edit, _form, _source_row) | 2.5h | T-021-09 |
| T-021-11 | [FE-WEB] | Turbo polling statut source asynchrone | 1h | T-021-10 |
| T-021-12 | [TEST] | Unit ValidateSourceHandler (URL valide, 404, Content-Type incorrect) | 1.5h | T-021-05 |
| T-021-13 | [TEST] | Unit SourceVoter (ROLE_ADMIN, ROLE_USER, non auth) | 1h | T-021-08 |
| T-021-14 | [TEST] | WebTestCase admin/sources CRUD + CSRF + SSRF + 403 non-admin | 2h | T-021-09, T-021-10 |
| T-021-15 | [DOC] | PHPDoc AdminSourceController, ValidateSourceHandler, SourceVoter, SourceType | 0.5h | T-021-09 |
| T-021-16 | [REV] | Code review US-021 | 1.5h | T-021-15 |

**Sous-total US-021 : 16 tâches — 21h**

---

### 🔲 A faire — US-031 : OAuth Google / GitHub

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-031-01 | [DB] | OAuthAccountEntity + OAuthAccountRepositoryInterface | 2h | — |
| T-031-02 | [DB] | Migration oauth_accounts (UNIQUE provider+provider_id, FK CASCADE) | 0.5h | T-031-01 |
| T-031-03 | [BE] | Composer oauth2-client-bundle + Google/GitHub providers + config YAML | 1.5h | — |
| T-031-04 | [BE] | OAuthUserProvider (lookup, fusion compte, création user) | 2h | T-031-01 |
| T-031-05 | [BE] | GoogleAuthenticator (state check, code échange, session HttpOnly) | 2h | T-031-04 |
| T-031-06 | [BE] | GithubAuthenticator (idem + email noreply GitHub) | 1.5h | T-031-04 |
| T-031-07 | [BE] | security.yaml : custom_authenticators + routes OAuth | 1h | T-031-05, T-031-06 |
| T-031-08 | [BE] | Rate limiting /oauth/callback/* Redis (10 req/5min/IP) | 0.5h | — |
| T-031-09 | [FE-WEB] | login.html.twig : boutons "Continuer avec Google/GitHub" | 1.5h | T-031-07 |
| T-031-10 | [FE-WEB] | register.html.twig : boutons OAuth (cohérence login) | 0.5h | T-031-09 |
| T-031-11 | [TEST] | Unit OAuthUserProvider (nouveau user, fusion email, noreply, doublon) | 2h | T-031-04 |
| T-031-12 | [TEST] | Unit GoogleAuthenticator (state valide/invalide, access_denied) | 1.5h | T-031-05 |
| T-031-13 | [TEST] | WebTestCase OAuth mocks (connexion, fusion, state invalide 400) | 1.5h | T-031-07 |
| T-031-14 | [DOC] | PHPDoc GoogleAuthenticator, GithubAuthenticator, OAuthUserProvider | 0.5h | T-031-06 |
| T-031-15 | [REV] | Code review US-031 | 1h | T-031-14 |

**Sous-total US-031 : 15 tâches — 20h**

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
| — | — | — | — |

---

## Métriques Sprint 002

| Indicateur | Valeur |
|------------|--------|
| Story Points | 23 pts |
| US en scope | 5 |
| Tâches totales | 70 |
| Heures estimées | 85h |
| Tâches terminées | 0 (0%) |
| Heures consommées | 0h |
| Heures restantes | 85h |

### Répartition par US

| US | Points | Tâches | Heures est. | Heures réel | Statut |
|----|--------|--------|-------------|-------------|--------|
| US-004 | 5 | 14 | 19h | 0h | 🔲 |
| US-005 | 3 | 11 | 11h | 0h | 🔲 |
| US-011 | 5 | 14 | 14h | 0h | 🔲 |
| US-021 | 5 | 16 | 21h | 0h | 🔲 |
| US-031 | 5 | 15 | 20h | 0h | 🔲 |
| **Total** | **23** | **70** | **85h** | **0h** | — |

### Répartition par type

| Type | Tâches | Heures |
|------|--------|--------|
| [DB] | 10 | 8h |
| [BE] | 30 | 44h |
| [FE-WEB] | 13 | 16.5h |
| [TEST] | 13 | 15.5h |
| [DOC] | 5 | 2.5h |
| [REV] | 5 | 5.5h |
| **Total** | **70** | **85h** |
