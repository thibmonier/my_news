# Plan — Décomposition Sprint 003

## Context

Sprint 2 (Enrichissement) est livré et mergé sur `main` (437 tests verts). Le Sprint 3 n'est **pas encore initialisé** : aucun dossier `sprint-003-*`, aucune US rattachée, pas de `sprint-goal.md`. La commande `/project:decompose-tasks 003` suppose un sprint déjà démarré — il faut donc **initialiser le sprint puis le décomposer** en une passe.

La review Sprint 2 désigne comme objectif prioritaire la **mesure de la rétention réelle J+1/J+7**, qui exige un déploiement staging instrumenté. Décision périmètre validée : **5 US candidates (16 pts) + travaux d'infra transverses** (staging/CI/rétention) dans `technical-tasks.md`.

Projet **web-only** : aucun code Flutter n'existe (sprints 1-2 = Symfony/Twig/Turbo/API Platform). Le template générique de la commande contient des tâches `[FE-MOB]` Flutter — **elles sont exclues** (YAGNI, pas de codebase mobile).

## Périmètre Sprint 3

| US | Titre | Pts | EPIC | Persona |
|----|-------|-----|------|---------|
| US-006 | Featured Summary desktop + CTA « Lire le brief complet » | 5 | EPIC-001 | P-001 |
| US-007 | Indicateur de progression de lecture | 2 | EPIC-001 | P-001 |
| US-012 | Cache Redis 24h des synthèses | 3 | EPIC-002 | P-001 |
| US-022 | Déduplication SimHash de titre | 3 | EPIC-003 | P-001 |
| US-032 | Gestion du profil utilisateur | 3 | EPIC-004 | P-002 Priya |

**Total features : 16 pts.** Complété par l'infra transverse (voir technical-tasks) pour aligner l'objectif rétention et remplir la capacité.

- Slug proposé : `sprint-003-consolidation` (ajustable).
- Dates : 2026-08-25 → 2026-09-07 (2 semaines, suite du Sprint 2 qui finit 2026-08-24).
- Sprint Goal proposé : « Consolider l'expérience du Daily Brief (featured summary + CTA, progression de lecture, cache des synthèses, déduplication SimHash) et enrichir le profil utilisateur, tout en déployant un staging instrumenté pour mesurer la rétention réelle J+1/J+7. »

## Livrables (docs uniquement — aucun code applicatif)

Structure miroir exacte du Sprint 2 (format validé par exploration) :

```
project-management/sprints/sprint-003-consolidation/
├── sprint-goal.md            # goal, dates, périmètre, ordre (Mermaid), cérémonies,
│                             #   DoD (+ critère UI Stitch WCAG 2.1 AA / Lighthouse ≥90), risques
├── tasks/
│   ├── README.md             # vue d'ensemble par US + répartition par type + deps inter-US + risques + DoD
│   ├── US-006-tasks.md
│   ├── US-007-tasks.md
│   ├── US-012-tasks.md
│   ├── US-022-tasks.md
│   ├── US-032-tasks.md
│   └── technical-tasks.md    # infra transverse (staging/CI/rétention/prod-admin)
└── task-board.md             # Kanban double niveau (US + tâches)
```

Mises à jour : champ `Sprint` de chaque US (US-006/007/012/022/032) → `sprint-003-consolidation` ; `project-management/workflow-status.yaml` (bloc sprint_3).

Format `US-XXX-tasks.md` (miroir Sprint 2) : en-tête 4 lignes, table 6 colonnes `| ID | Type | Description | Heures | Dépend de | Statut |` (ID `T-XXX-YY`, types `[DB] [BE] [FE-WEB] [TEST] [DOC] [REV] [OPS]`, heures multiples de 0.5h, statut 🔲), ligne total, graphe Mermaid `graph TD`, section Notes techniques. `[REV]` toujours dernière, dépend de `[DOC]`.

## Décomposition prévue par US (grounded sur les Gherkin + couches réelles)

- **US-006 (5 pts, ~15-17 tâches)** : `[DB]` table `daily_brief_summaries` (UUID, brief_id FK UNIQUE, content, model_version, generated_at) + migration ; `[BE]` `FeaturedSummaryService` (prompt agrégé mono-appel Mistral **réutilise** `MistralApiClient`, 0 PII test bloquant, fallback texte local sans badge, cache Redis `briefly:featured_summary:{date}` TTL 24h), enrichir `GenerateDailyBriefHandler`, champ `featured_summary` dans `GET /api/brief/today` ; `[FE-WEB]` section Featured Summary + CTA sticky ancre `#brief-stories` dans `BriefController` (rendu inline PHP actuel), masquage mobile `@media` ; `[TEST]` unit service + RGPD prompt, feature desktop/mobile + ancre CTA + fallback ; `[DOC]`/`[REV]`.
- **US-007 (2 pts, ~7-9 tâches)** : **frontend pur, backend = aucun** (documenté). `[FE-WEB]` Stimulus `progress-bar_controller` (rAF throttle, `disconnect()` anti-fuite, reset Turbo), CSS `position:fixed;height:2px;#10B981`, ARIA `role=progressbar`, intégration layout brief, fallback JS désactivé ; `[TEST]` fonctionnel (progressbar présent sans JS, non-scrollable=100%) ; `[DOC]`/`[REV]`.
- **US-012 (3 pts, ~11-13 tâches)** : **étend** `SynthesisService` (cache-aside déjà présent). `[BE]` normalisation/canonicalisation URL avant SHA-256 (422 si invalide), header `X-Cache: HIT|MISS|BYPASS` dans `UrlSynthesisProcessor` (**réutilise** pattern `X-Quota-Remaining`), gestion Redis indisponible = BYPASS, logs structurés `url_hash+level` ; `[TEST]` hit/miss/niveaux distincts/redis-down/injection de clé ; `[DOC]`/`[REV]`. Pas de nouvelle table (PostgreSQL `synthesis_results` inchangée).
- **US-022 (3 pts, ~13-15 tâches)** : `[DB]` 3 colonnes sur `Article` (`title_simhash BIGINT NULL`, `is_duplicate BOOL DEFAULT FALSE`, `duplicate_of UUID NULL FK self`) + migration + index `published_at` ; `[BE]` `SimHashService::compute/distance` (64 bits, stopwords FR/EN, seuil Hamming ≤3 configurable `briefly.simhash.threshold`), `ArticleRepository::findPotentialDuplicates` (`BIT_COUNT(title_simhash XOR :h)` + fenêtre ±2h), enrichir `FetchSourceHandler` (try/catch → NULL+log, jamais de suppression), filtre `is_duplicate=FALSE` dans la sélection du Brief ; `[TEST]` unit compute/distance/titre vide/exception, intégration fenêtre/seuil ; `[DOC]`/`[REV]`.
- **US-032 (3 pts, ~14-16 tâches)** : `[DB]` `User.bio VARCHAR(280)` + `email_pending` + migration (`full_name` existe déjà) ; `[BE]` `ProfileController::edit`, `ProfileFormType`, `ProfileVoter::EDIT` (propriétaire, 403 sinon + log), `EmailChangeService` double opt-in (token UUID TTL 24h, `email_pending`), validations `Length(max:280)`/`UniqueEntity` ; `[FE-WEB]` Turbo Frame `profile-form`, compteur bio Stimulus, flash hors Turbo ; `[TEST]` unit voter + email change, feature nominal/email-change/bio>280(422)/email-pris/403 ; `[DOC]`/`[REV]`.
- **technical-tasks.md (infra transverse)** : `[OPS]` débloquer CI GitHub Actions (billing) + pipeline vert (pest+phpstan+deptrac+cs+docker build) ; `[OPS]` provisioning admin **prod** (remplacer le provider in-memory vide par un mécanisme réel sécurisé) ; `[OPS]` déploiement **staging** (Docker, PostgreSQL/Redis, secrets) ; `[BE/OPS]` instrumentation **analytics rétention** J+1/J+7 (event tracking anonyme, **0 PII** RGPD) ; `[OPS]` monitoring quota Mistral + alerte ; `[OPS]` garde-fou charge Docker (doc worker séquentiel, action rétro S2).

Estimation globale indicative : ~60-75h features + ~19h infra (les agents de décomposition produiront les heures exactes, 0.5h-8h/tâche).

## Réutilisable (à référencer dans les tâches, éviter la duplication)

- Synthesis : `src/Application/Synthesis/SynthesisService.php` (cache-aside), `src/Presentation/StateProcessor/UrlSynthesisProcessor.php` (pattern header), `src/Infrastructure/Synthesis/Ai/MistralApiClient.php`.
- Feed : `src/Domain/Feed/Article.php`, `ArticleRepositoryInterface.php`, `src/Application/Feed/FetchSource/FetchSourceHandler.php`.
- User : `src/Infrastructure/User/Persistence/DoctrineUserEntity.php` (`full_name` déjà là), `src/Domain/User/User.php`.
- Brief : `src/Presentation/Controller/BriefController.php` (rendu inline PHP, tokens `#10B981` déjà en place), `src/Application/Brief/GenerateDailyBrief/GenerateDailyBriefHandler.php`.

## Exécution

Comme le Sprint 2 : sous-agents isolés (contexte orchestrateur léger). (1) un agent « start » écrit `sprint-goal.md` + rattache les US + `workflow-status.yaml` ; (2) un agent « decompose » écrit les 5 `US-XXX-tasks.md` + `technical-tasks.md` + `README.md` + `task-board.md` au format Sprint 2 (sans Flutter). Contrainte : français accents corrects, aucun fichier `src/`.

## Vérification

- Cohérence docs : IDs `T-XXX-YY` uniques, graphes Mermaid valides, totaux heures/tâches cohérents entre `US-XXX-tasks.md`, `README.md` et `task-board.md`.
- Gate : lancer `/gate:validate-sprint 003` (métadonnées, goal, INVEST+3C+Gherkin des US, estimations, capacité, dépendances) — cible PASS.
- Aucun test de code (livrables = documentation de planification). L'implémentation fera l'objet d'une passe séparée (ex. `/workflow:auto-sprint 003`).
