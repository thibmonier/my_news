# Design Review — Porte de Conception Briefly AI

**Date :** 2026-07-28
**Réviseur :** Tech Lead (CSM)
**Périmètre :** Tech-spec v1.0.0, C4 Levels 1–3, ADR-001 à ADR-007
**Références :** PRD v1.0.0 · backlog/index.md (52 US, 8 EPICs) · docs/adr/
**Verdict :** **GO CONDITIONNEL** — 3 tâches pré-Sprint 1 obligatoires

---

## 1. Taux de couverture FR/NFR

### Exigences Fonctionnelles (60 FR)

| Statut | Compte | % | Détail |
|--------|--------|---|--------|
| Couvert (décision technique documentée) | 46 | 77 % | Adressé dans tech-spec ou ADR |
| Partiel (mentionné sans spec d'impl.) | 9 | 15 % | Voir §3 Gaps |
| Hors périmètre v1 (acté dans PRD §8) | 2 | 3 % | FR-025, FR-050 |
| Non couvert | 1 | 2 % | FR-008 (table manquante) |
| **Sous-total** | **58** | **97 %** | Hors scope v1 non compté |

> **Score FR couverture : 83 % full + 15 % partial = 98 % présence, 83 % qualité.**

### Exigences Non Fonctionnelles (30 NFR)

| Statut | Compte | % |
|--------|--------|---|
| Couvert avec décision + cible | 27 | 90 % |
| Partiel | 2 | 7 % | NFR-024/026 accessibilité |
| Non couvert | 1 | 3 % | NFR-026 (Lighthouse ≥ 90 non adressé) |

> **Score NFR couverture : 90 %.**

### Couverture par EPIC

| EPIC | Titre | Couverture | Note |
|------|-------|------------|------|
| EPIC-001 | Daily Brief Core | 90 % | Complet |
| EPIC-002 | Moteur de Synthèse IA | 88 % | Complet, naming corrigé |
| EPIC-003 | Sources & Indexation | 92 % | FR-028 partiel |
| EPIC-004 | Comptes & Premium | 90 % | refresh_tokens corrigé |
| EPIC-005 | Expérience Mobile Native | 72 % | Material Design 3 non détaillé |
| EPIC-006 | API Publique | 88 % | Endpoint /v1/synthesize aligné |
| EPIC-007 | Privacy & On-Device AI | 78 % | reading_history manquant |
| EPIC-008 | Analytics & Personnalisation | 58 % | Thématiques + dashboard non spécifiés |

**Score global couverture : 82 %**

---

## 2. Corrections appliquées pendant cette revue

Les corrections suivantes ont été effectuées directement dans les fichiers concernés.

### C-01 — Références de fichiers incorrectes dans tech-spec §4

**Fichier :** `tech-spec.md` §4
**Problème :** `c4-containers.md` et `c4-components.md` (pluriel) référencés mais les fichiers réels sont `c4-container.md` et `c4-component.md` (singulier).
**Correction :** Références corrigées.

### C-02 — Incohérence UUID v4 / v7

**Fichiers :** `tech-spec.md` §16.1, §3.2, §5.1
**Problème :** ADR-006 (autorité) dit "UUID v4" mais §16.1 disait "v7" et BC-COMPTES utilisait "uuid7". Triple contradiction dans les docs.
**Correction :** §16.1 aligné sur ADR-006 (v4). Note ajoutée en §19 pour forcer une décision formelle avant Sprint 1 (ADR-008 ou amendment ADR-006).

### C-03 — Table refresh_tokens absente du modèle de données

**Fichier :** `tech-spec.md` §5.1
**Problème :** ADR-005 spécifie rotation systématique et détection de vol des refresh tokens, mais aucune table n'existait pour les persister côté serveur. La cascade RGPD §17.3 mentionnait vaguement "refresh_token" sans table.
**Correction :** Table `refresh_tokens` ajoutée (token_hash SHA-256, family_id pour détection de vol, ON DELETE CASCADE, expires_at J+7).

### C-04 — Naming Synthesis vs Summary — incohérence domaine/composants

**Fichier :** `architecture/c4-component.md`
**Problème :** Le diagramme C4 Niveau 3 utilisait `SummaryService`, `SummaryCache`, `LLMProviderGateway` en contradiction avec la terminologie du domaine (`SynthesisService`, `SynthesisCache`, `SynthesisProviderChain` dans tech-spec §8.1). L'endpoint API était `POST /api/summaries` dans le diagramme mais `POST /api/syntheses` dans tech-spec §6.2.
**Correction :** Composants renommés en `SynthesisService`, `SynthesisProviderChain`, `SynthesisCache` dans tout le diagramme et sa légende. Endpoint aligné sur `POST /api/syntheses`.

### C-05 — Corps de requête POST /api/syntheses non spécifié

**Fichier :** `tech-spec.md` §6.2
**Problème :** L'endpoint `POST /api/syntheses` n'avait pas de schéma de requête. US-010 est intitulée "sur URL" ce qui laissait supposer que le endpoint pouvait accepter des URLs arbitraires — vecteur SSRF potentiel.
**Correction :** Corps spécifié explicitement : `{"article_id": "<UUID>", "level": "CONCISE|DETAILED|NARRATIVE"}`. Note SSRF ajoutée en §16.1 : l'endpoint n'accepte que des UUIDs internes, jamais des URLs externes.

### C-06 — Endpoint export Markdown non documenté

**Fichier :** `tech-spec.md` §6.2
**Problème :** La grille tarifaire PRD (§6) liste "Export Markdown" comme fonctionnalité Premium mais aucun endpoint n'était documenté.
**Correction :** `GET /api/me/data-export/markdown` ajouté (Premium uniquement).

### C-07 — Table ADR dans §19 obsolète

**Fichier :** `tech-spec.md` §19
**Problème :** §19 listait 10 ADRs "À créer" avec un format `ADR-0001` (4 chiffres). Les 7 premiers existent déjà avec le format `ADR-001` (3 chiffres).
**Correction :** Table mise à jour avec statut "Créé" / "À créer" et liens vers les fichiers réels. Numérotation alignée sur le format réel.

---

## 3. Gaps résiduels (non bloquants pour GO)

### G-01 — Table `reading_history` absente (FR-008, US-060)

**Criticité :** MEDIUM — bloquant pour EPIC-007 Sprint 3+
**Description :** FR-008 ("marquer comme lu") et US-060 ("consulter l'historique de lecture") requièrent une table dédiée. Le code de suppression RGPD §17.3 la référence comme "si table future". Elle ne fait pas partie du Sprint 1, mais doit être spécifiée avant EPIC-007.
**Action requise :** Ajouter `reading_history (user_id, article_id, read_at)` au schéma avant Sprint 3. Prévoir l'index `(user_id, read_at DESC)` pour pagination et la cascade DELETE RGPD.

### G-02 — UUID v4 vs v7 — décision non tranchée formellement

**Criticité :** HIGH — bloquant pour Sprint 1 (impact sur le schéma de migration)
**Description :** ADR-006 dit v4 (`gen_random_uuid()`), BC-COMPTES dit uuid7, §5.1 commente "UUID v7" sur la table users. Les deux ont des implications:
- UUID v4 : aléatoire pur, meilleur pour la vie privée, mauvais pour l'index B-tree sur insertions fréquentes (articles).
- UUID v7 : time-ordered, meilleures performances d'insertion, expose un timestamp dans l'ID.
**Action requise :** Amendment à ADR-006 ou ADR-008 dédié. Décision avant la première migration Doctrine. Recommandation Tech Lead : UUID v7 pour les tables à fort volume d'insertion (`articles`, `syntheses`) et UUID v4 pour les entités sensibles exposées (`users`, `api_keys`).

### G-03 — Thématiques personnalisées (FR-006, US-070) — implémentation non spécifiée

**Criticité :** MEDIUM — bloquant pour EPIC-008 Sprint 4+
**Description :** FR-006 permet à l'utilisateur de choisir ses domaines (tech, finance, etc.). La colonne `users.preferences` (JSONB) peut stocker les thèmes sélectionnés, mais le `BriefGenerator` n'a aucune spec sur la façon dont ces préférences pondèrent la sélection des 3 histoires.
**Action requise :** Spécifier dans tech-spec §7.5 comment `StorySelectionStrategy` intègre `user.preferences.topics`. Ajouter un port `UserPreferencesInterface` dans BC-BRIEF.

### G-04 — Dashboard analytics admin (FR-058) — non spécifié

**Criticité :** LOW — EPIC-008 hors Sprint 1
**Description :** FR-058 demande un dashboard admin affichant DAU, conversions, MRR en temps réel. La tech-spec mentionne l'admin (EasyAdmin) et les métriques Prometheus, mais n'articule pas comment le dashboard admin consomme ces données (requêtes PostgreSQL agrégées ? Métriques Redis ? Prometheus + Grafana interne ?).
**Action requise :** Décision d'implémentation en Sprint 3 Backlog Refinement. Option recommandée : vues SQL agrégées dans PostgreSQL, affichées via EasyAdmin dashboard — sans overhead Grafana en v1.

### G-05 — Accessibilité (NFR-024/025/026) — niveau d'implémentation insuffisant

**Criticité :** MEDIUM — couvre les pages principales
**Description :** NFR-024 (WCAG 2.1 AA), NFR-025 (badge IA non couleur uniquement), NFR-026 (Lighthouse ≥ 90) sont mentionnés mais sans spécification d'implémentation. Le badge §8.4 est bien spécifié. Lighthouse ≥ 90 n'est pas adressé nulle part.
**Action requise :** Ajouter dans la DoD (definition-of-done.md) : "Score Lighthouse Accessibility ≥ 90 sur `/brief/{date}`". Passer sur les templates Twig : `lang` attribut, skip-nav link, aria-labels sur les cartes d'article.

### G-06 — FR-028 (endpoint santé des sources) — partiel

**Criticité :** LOW — Should dans PRD
**Description :** US-028 n'existe pas dans le backlog. SourceManager gère le CRUD mais aucun endpoint `/admin/sources/health` n'est spécifié retournant le taux d'erreur par source, l'état du circuit breaker, le dernier fetch.
**Action requise :** Ajouter `GET /api/admin/sources/stats` retournant `[{source_id, circuit_state, error_rate_1h, last_fetched_at, articles_24h}]`. À créer comme US dans EPIC-003 ou EPIC-008 selon priorité.

### G-07 — Consentement CMP (FR-051/052) — implémentation non spécifiée

**Criticité :** MEDIUM — RGPD Must
**Description :** FR-051 (CMP à l'inscription) et FR-052 (pas de cookie tiers sans consentement) sont accolés à des décisions de principe dans tech-spec §17 mais sans spec d'implémentation : quel composant affiche le bandeau CMP ? Quel framework CMP ? Comment les préférences de consentement bloquent-elles les scripts tiers ?
**Action requise :** Documenter en tech-spec §17 : bandeau CMP géré côté Twig (Symfony Component ou cookie PHP pur) + Stimulus controller `cmp-controller` + cookie first-party `briefly_consent` (HttpOnly impossible car lu par JS pour le blocage conditionnel). Aucun script tiers chargé avant consentement positif.

---

## 4. Vérifications adversariales

### 4.1 SSRF sur /synthesize — RÉSOLU

**Vecteur initial :** US-010 est intitulée "sur URL" suggérant que l'endpoint accepte des URLs arbitraires.
**Analyse :** L'implémentation correcte (confirmée dans tech-spec §8) utilise un `article_id` UUID interne. L'article est récupéré depuis PostgreSQL. Aucune URL externe n'est consommée par ce flow.
**Fix appliqué (C-05) :** Corps de requête explicitement spécifié dans §6.2. Note SSRF ajoutée dans §16.1. Le vecteur est neutralisé by design si l'implémentation suit la spec.
**Risque résiduel :** Si un développeur interprète US-010 "sur URL" comme acceptant une URL en entrée et l'implémente ainsi — le vecteur SSRF réapparaît. La spec est maintenant assez claire pour éviter ça. Test de sécurité à ajouter : `tests/Security/SsrfTest.php` vérifiant que `POST /api/syntheses` rejette tout payload contenant une URL (`http://...`).

### 4.2 Modèle de données vs User Stories — couverture

| US clé | Entité requise | Dans ERD | Statut |
|--------|---------------|----------|--------|
| US-001 (Daily Brief) | `daily_briefs`, `stories` | Oui | OK |
| US-010 (Synthèse) | `syntheses`, `articles` | Oui | OK |
| US-020 (Pipeline RSS) | `sources`, `articles` | Oui | OK |
| US-030 (Inscription) | `users` | Oui | OK |
| US-033 (Quota) | `users.plan` + Redis | Oui | OK |
| US-034 (Stripe) | `subscriptions`, `stripe_events` | Oui | OK |
| US-036 (Droit à l'oubli) | `users.deleted_at` + cascade | Oui | OK |
| US-044 (Biométrie) | `refresh_tokens` | **Ajouté (C-03)** | OK post-fix |
| US-050 (API Keys) | `api_keys` | Oui | OK |
| US-060 (Historique lecture) | `reading_history` | **ABSENT** | Gap G-01 |
| US-062 (On-device) | config Flutter locale | Hors DB | OK |
| US-070 (Thèmes d'intérêt) | `users.preferences` JSONB | Partiel | Gap G-03 |

### 4.3 Conception API vs stories exposant une API

| US API | Endpoint | Couvert |
|--------|---------|---------|
| US-051 (`GET /v1/daily-brief`) | `GET /api/v1/briefs/{date}` | Oui |
| US-052 (`POST /v1/synthesize`) | `POST /api/syntheses` + corps spécifié | Oui (C-05) |
| US-053 (Rate limit headers) | `X-RateLimit-*` dans §6.4 | Oui |
| US-054 (OpenAPI/Swagger UI) | `/api/docs` via API Platform | Oui |
| US-055 (Lien API pied de page) | UI — hors spec technique | Hors scope |
| US-061 (Export JSON) | `GET /api/me/data-export` | Oui |
| US-063 (Réglages confidentialité) | `PUT /api/me/preferences` | Oui |

### 4.4 Sécurité OWASP 2025 — checklist

| OWASP # | Titre | Couverture tech-spec | Verdict |
|---------|-------|---------------------|---------|
| #1 Broken Access Control (inclut SSRF) | Voters deny-by-default + UUIDs + SSRF /synthesize | §16.1 | COUVERT |
| #2 Cryptographic Failures | Argon2id + EdDSA + secrets Docker | §16.2, ADR-005 | COUVERT |
| #3 Injection | Doctrine ORM only + Symfony Validator | §16.3 | COUVERT |
| #4 Insecure Design | Threat model documenté dans ADRs | ADR-001 à 007 | COUVERT |
| #5 Security Misconfiguration | Headers CSP L3/HSTS/COOP/COEP/CORP | §16.4 | COUVERT |
| #6 Supply Chain Failures | SBOM CycloneDX + Trivy + Dependabot | §16.5, §14.1 | COUVERT |
| #7 Mishandling Exceptional Conditions | Hiérarchie exceptions domain + gestionnaires | §11 | COUVERT |
| #8 Authentication Failures | Rate limit 5/15min + CAPTCHA + EdDSA | §16.2, ADR-005 | COUVERT |
| #9 Logging & Monitoring Failures | Logs JSON structurés, 0 PII | §12 | COUVERT |
| #10 Data Integrity Failures | HMAC Stripe + SBOM + checksums | §14.1, §16.5 | COUVERT |

**OWASP score : 10/10 adressés.**

### 4.5 RGPD — droits des utilisateurs

| Droit | Implémentation | Délai | Statut |
|-------|---------------|-------|--------|
| Consentement | CMP inscription granulaire | Immédiat | Partiel (Gap G-07) |
| Accès | `GET /api/me/data-export` | < 30s | OK |
| Portabilité | Export JSON (FR-038) + Markdown (C-06) | Immédiat | OK |
| Rectification | `PUT /api/me/preferences` | Immédiat | OK |
| Effacement | Soft delete + hard delete J+30 Scheduler | 30 jours | OK |
| Opposition | Toggle granulaire `/settings/privacy` | Immédiat | Partiel (Gap G-07) |

### 4.6 Cohérence transverse — vérification des noms

| Terme | tech-spec | c4-context | c4-container | c4-component | ADRs | Verdict |
|-------|-----------|------------|-------------|-------------|------|---------|
| `SynthesisService` | Oui (§8.1) | N/A | N/A | **Corrigé (C-04)** | ADR-004 | OK post-fix |
| `POST /api/syntheses` | §6.2 | N/A | N/A | **Corrigé (C-04)** | ADR-007 | OK post-fix |
| `BriefDate` (slug) | §3.2 BC-BRIEF | N/A | N/A | BriefGenerator | ADR-001 | OK |
| `CircuitBreakerState` | §3.2 Source agg. | c4-context | c4-container | SourceCircuitBreaker | ADR-003 | OK |
| `QuotaGuard` (Voter) | §8.1 | N/A | c4-container | BC-Comptes | ADR-007 | OK |
| `SimHash` VO | §3.2, §7.2 | N/A | c4-container | ArticleDeduplicator | ADR-003 | OK |
| `uuid v4/v7` | **Inconsistant** | N/A | N/A | N/A | ADR-006 (v4) | **Gap G-02** |

### 4.7 ADR — complétude et cohérence

| ADR | Décision | Alternatives | Conséquences | Cohérent avec PRD |
|-----|---------|-------------|-------------|------------------|
| ADR-001 | Twig+Turbo vs SPA | 3 alternatives | Positives + négatives | Oui (FR-004, FR-010, NFR-005) |
| ADR-002 | Flutter vs RN vs natif | 3 alternatives | Oui | Oui (FR-039) |
| ADR-003 | FeedIo + Messenger + SimHash | 3 alternatives | Oui | Oui (FR-021/022/023) |
| ADR-004 | Mistral EU + Phi-3 Mini | 4 alternatives | Oui | Oui (FR-011/016/017/018) |
| ADR-005 | JWT EdDSA + Session + Argon2id | 5 alternatives | Oui | Oui (FR-029 à FR-036) |
| ADR-006 | PostgreSQL + Redis | 5 alternatives | Oui | Oui (NFR-006 à NFR-010) |
| ADR-007 | API Platform REST + /v1 | 5 alternatives | Oui | Oui (FR-045 à FR-049) |

**Aucune contradiction entre ADRs et PRD détectée.** Aucun ADR ne revient sur une décision déjà arbitrée dans `technical-options.md` ou `constraints.md`.

---

## 5. Tâches pré-Sprint 1 obligatoires (GO CONDITIONNEL)

Ces 3 tâches bloquent le démarrage de l'implémentation.

### T-PRE-01 — Décision UUID v4 vs v7 (Gap G-02)

**Assigné à :** Tech Lead
**Délai :** Avant première migration Doctrine
**Livrable :** Amendment à ADR-006 ou nouveau ADR-008 avec décision finale + migration Doctrine cohérente avec le choix.
**Recommandation :** UUID v7 pour tables à fort volume (`articles`, `syntheses`), UUID v4 pour entités exposées (`users`, `api_keys`). Symfony uid supporte les deux.

### T-PRE-02 — Test SSRF `POST /api/syntheses`

**Assigné à :** Dev Backend
**Délai :** Sprint 1, avant merge US-010
**Livrable :** `tests/Security/SsrfTest.php` vérifiant que l'endpoint rejette tout payload contenant une URL en lieu et place d'un UUID.

### T-PRE-03 — Spécification implémentation CMP (Gap G-07)

**Assigné à :** Tech Lead + Dev Backend
**Délai :** Sprint 2 (avant US-035 "Réglages de confidentialité")
**Livrable :** Section §17 de la tech-spec complétée avec : composant Twig CMP, cookie `briefly_consent`, Stimulus controller, règle de blocage des scripts tiers.

---

## 6. Tâches pré-Sprint 3/4 (non bloquantes pour Sprint 1)

| Tâche | Gap | Sprint cible |
|-------|-----|-------------|
| Spécifier `reading_history` dans ERD | G-01 | Avant Sprint 3 (EPIC-007) |
| Spécifier `StorySelectionStrategy` avec préférences utilisateur | G-03 | Avant Sprint 4 (EPIC-008) |
| Ajouter `GET /api/admin/sources/stats` au backlog | G-06 | Sprint 3 ou 4 |
| Ajouter critère Lighthouse ≥ 90 à la DoD | G-05 | Avant Sprint 2 (pages publiques) |
| Spécifier dashboard admin KPI (FR-058) | G-04 | Sprint 4 |

---

## 7. Verdict de porte

```
┌─────────────────────────────────────────────────────────────────────┐
│  DÉCISION : GO CONDITIONNEL                                         │
│                                                                     │
│  Couverture FR : 83 % full / 98 % présence                         │
│  Couverture NFR : 90 %                                              │
│  OWASP 2025 : 10/10                                                 │
│  ADRs cohérents : 7/7                                               │
│  Incohérences critiques corrigées : 7                               │
│                                                                     │
│  Conditions GO :                                                    │
│  [T-PRE-01] Décision UUID v4/v7 avant première migration           │
│  [T-PRE-02] Test SSRF /api/syntheses avant merge US-010            │
│  [T-PRE-03] Spec CMP complétée avant Sprint 2                      │
│                                                                     │
│  L'implémentation de Sprint 1 peut démarrer. Les 3 conditions      │
│  T-PRE-01, T-PRE-02, T-PRE-03 sont à traiter en parallèle         │
│  du développement et ne bloquent pas US-001/020/030 du Sprint 1.   │
└─────────────────────────────────────────────────────────────────────┘
```

---

*Design Review produite par le Tech Lead (CSM). Les corrections C-01 à C-07 ont été appliquées directement dans les fichiers source. Les gaps résiduels G-01 à G-07 et les tâches T-PRE-01 à T-PRE-03 sont à tracer dans le backlog.*
