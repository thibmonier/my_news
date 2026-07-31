# Audit Symfony — Briefly AI
**Date :** 2026-07-31
**Version :** Sprints 1–3 livrés, branche `main`
**Auditeur :** Claude Code (agent php-reviewer)

---

## Score global : 84 / 100

| Catégorie | Score | /25 |
|-----------|-------|-----|
| Architecture | 22 | 25 |
| Qualité code | 18 | 25 |
| Tests | 20 | 25 |
| Sécurité | 24 | 25 |
| **TOTAL** | **84** | **100** |

---

## 1. Architecture — 22 / 25

### Résultat des mesures

| Contrôle | Résultat |
|----------|----------|
| Deptrac (couches hexagonales) | **0 violation** |
| Direction de dépendances | Domain ← Application ← Infrastructure / Presentation |
| Domain — absence d'import Doctrine/framework | **Confirmé** |
| Conventions dossiers | Domain / Application / Infrastructure / Presentation + bounded contexts |
| Séparation des interfaces | Interfaces dans Domain, implémentations dans Infrastructure |

### Findings

- **[PASS]** Architecture hexagonale DDD stricte respectée : 4 couches, 7 bounded contexts (Brief, Feed, Health, Quota, Summary, Synthesis, User).
- **[PASS]** Deptrac : 0 violation. Les règles `Domain:[]`, `Application:[Domain]`, `Infrastructure:[Domain,Application]`, `Presentation:[Domain,Application]` sont toutes satisfaites.
- **[PASS]** Domain PHP pur : aucun import `Doctrine`, `Symfony\Component\HttpFoundation` ou `ApiPlatform` dans `src/Domain/`.
- **[WARN]** `BriefController.php` : **1 404 lignes** — violation sévère du SRP. Le contrôleur embarque le rendu HTML complet, 8 blocs CSS inline (`badgeCss`, `summaryCss`, `synthesisCss`, `pageCss`, `featuredSummaryCss`, `progressBarCss`, `designTokensCss`) et 2 blocs JS inline (`synthesisJs`, `progressBarJs`). Twig est pourtant disponible (`symfony/twig-bundle ^8.1` dans `composer.json`). (-3 pts)
- **[WARN]** 4 autres contrôleurs utilisent du HTML inline : `RegistrationController`, `LoginController`, `PaywallModalController`, `DashboardController`. Commentés "Twig non installé en Sprint 1" — mais le bundle est enregistré dans `config/bundles.php`. (-0 pts supplémentaires, inclus dans le finding BriefController)

---

## 2. Qualité code — 18 / 25

### Résultat des mesures

| Outil | Résultat |
|-------|----------|
| PHPStan niveau max | **0 erreur** |
| php-cs-fixer | **0 diff** |
| Nommage / types stricts | **Conforme PSR-12, `declare(strict_types=1)` partout** |
| PhpDoc | Complet (toutes les méthodes publiques) |

### Findings

- **[PASS]** PHPStan niveau max : 0 erreur. Types strict, generics documentés, pas de `mixed` non documenté.
- **[PASS]** php-cs-fixer : 0 diff. PSR-12 respecté uniformément.
- **[PASS]** Nommage explicite, PhpDoc exhaustif sur les services critiques (`SynthesisService`, `BriefSelectorService`).
- **[CRITIQUE]** `BriefController` — **14 méthodes dépassent 30 lignes** : `renderBriefHtml` (~81 l.), `renderStory` (~64 l.), `renderSummaryBlock` (~59 l.), `summaryCss` (~104 l.), `synthesisCss` (~154 l.), `pageCss` (~142 l.), `featuredSummaryCss` (~73 l.), `synthesisJs` (~126 l.), `progressBarJs` (~52 l.), `emptyStateResponse` (~33 l.), `serviceUnavailableResponse` (~39 l.), `designTokensCss` (~41 l.), `badgeCss` (~47 l.). Complexité cyclomatique élevée dans `renderStory` et `synthesisJs`. (-5 pts)
- **[WARN]** `RegistrationController` : 388 lignes, méthode `renderForm` > 30 lignes avec HTML inline. (-1 pt)
- **[WARN]** URL hardcodée `'https://briefly.ai/brief'` dans `BriefController::renderBriefHtml()` (ligne 179) — `@TODO` documenté mais commité. (-1 pt)
- **[PASS]** `SynthesisService` : 365 lignes mais méthodes bien découpées (max ~60 lignes pour `validateUrlForSsrf`), algorithme en 8 étapes documentées.

---

## 3. Tests — 20 / 25

### Résultat des mesures

| Métrique | Valeur |
|----------|--------|
| Couverture globale (Xdebug/Pest) | **65,7 %** |
| Seuil minimum requis | 80 % |
| Nombre de tests | 555 (Unit + Feature) |
| Fichiers de tests | 53 (35 Unit + 18 Feature) |
| Fichiers source couverts | 161 fichiers PHP src/ |

### Commande exécutée

```bash
docker compose exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage app vendor/bin/pest --coverage --min=80 --colors=never
```

**Résultat : FAIL — Code coverage below expected 80.0%, currently 65.7%**

### Findings

- **[CRITIQUE]** Couverture globale : **65,7 % < 80 %**. Écart de 14,3 points. (-5 pts)
- **[PASS]** Tests unitaires Domain présents (10 fichiers) : `BriefSelectorServiceTest` (98,9 %), `BriefPublicViewTest` (100 %), `UserTest`, `EmailTest`, `SynthesisLevelTest`, `ContentHashTest`, `ArticleCategoryTest`, `SourceTest`, `ArticleSummaryTest`, `HealthReportTest`.
- **[PASS]** Pyramide correcte : 35 fichiers Unit + 18 Feature. Tests Feature couvrent les flux critiques (Registration, OAuth, Brief, Synthesis, Quota, Admin).
- **[WARN]** 16 fichiers à 0 % de couverture :

  | Fichier | Couche |
  |---------|--------|
  | `Application/Feed/BulkFetch/BulkFetchHandler` | Application |
  | `Application/Feed/FetchAllSourcesTask` | Application |
  | `Domain/Feed/SourcePermission` | Domain |
  | `Infrastructure/Brief/Persistence/DoctrineBriefStoryEntity` | Infrastructure |
  | `Infrastructure/Brief/Persistence/DoctrineDailyBriefEntity` | Infrastructure |
  | `Infrastructure/Brief/Persistence/DoctrineDailyBriefSummaryEntity` | Infrastructure |
  | `Infrastructure/Feed/Persistence/DoctrineArticleEntity` | Infrastructure |
  | `Infrastructure/Quota/RedisQuotaCounter` | Infrastructure |
  | `Infrastructure/Summary/Persistence/DoctrineArticleSummaryEntity` | Infrastructure |
  | `Infrastructure/Synthesis/Cache/RedisSynthesisCache` | Infrastructure |
  | `Infrastructure/Synthesis/Http/HttpArticleContentFetcher` | Infrastructure |
  | `Infrastructure/Synthesis/Persistence/DoctrineSynthesisResultEntity` | Infrastructure |
  | `Infrastructure/Synthesis/Persistence/DoctrineSynthesisResultRepository` | Infrastructure |
  | `Infrastructure/User/Persistence/DoctrineOAuthAccountEntity` | Infrastructure |
  | `Infrastructure/User/SecurityUserUuidResolver` | Infrastructure |
  | `Presentation/Command/DevSeedCommand` | Presentation |
  | `Presentation/Command/FetchAllSourcesCommand` | Presentation |
  | `Presentation/Controller/DashboardController` | Presentation |
  | `Presentation/Controller/PaywallModalController` | Presentation |
  | `Presentation/Controller/QuotaController` | Presentation |
  | `Presentation/Form/SourceFormData` | Presentation |

- **[WARN]** Couverture faible sur des classes Domain importantes :
  - `Domain/Feed/Article` : 54,5 %
  - `Domain/Synthesis/SynthesisResult` : 37,5 %
  - `Domain/User/OAuthAccount` : 70,0 %

---

## 4. Sécurité — 24 / 25

### Résultat des mesures

| Contrôle | Résultat |
|----------|----------|
| `composer audit --no-dev` | **0 CVE** |
| `composer audit` (avec dev) | **0 CVE** |
| Secrets dans le code source | **0** |
| `.env.local` dans `.gitignore` | **Confirmé** (`/.env.local`, `/.env.*.local`) |
| Valeurs dans `env.example` | Toutes vides pour les secrets réels |

### Findings

- **[PASS]** 0 CVE — aucune vulnérabilité connue dans les dépendances prod et dev.
- **[PASS]** 0 secret commité : `MISTRAL_API_KEY=`, `OPENAI_API_KEY=`, `STRIPE_SECRET_KEY=`, `STRIPE_WEBHOOK_SECRET=`, `GOOGLE_CLIENT_SECRET=`, `GITHUB_CLIENT_SECRET=` — toutes vides dans `env.example`.
- **[PASS]** `.env.local` dans `.gitignore`. APP_SECRET dans `env.example` documenté `changeme_dev_only`.
- **[PASS]** Validation SSRF robuste dans `SynthesisService::validateUrlForSsrf()` : `filter_var(FILTER_VALIDATE_URL)`, rejet schémas non http(s), rejet loopback (`localhost`, `127.0.0.1`, `::1`), résolution DNS + rejet RFC 1918 via `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
- **[PASS]** XSS : `htmlspecialchars(ENT_QUOTES|ENT_HTML5)` systématique sur tout contenu LLM (Mistral, OpenAI) dans `BriefController`.
- **[PASS]** Argon2id pour mots de passe (confirmé dans constitution §6).
- **[PASS]** PII : aucun UUID utilisateur / email dans les prompts Mistral. Logs avec `url_hash` SHA-256 uniquement, jamais l'URL brute.
- **[PASS]** CSRF : `validateCsrfOrThrow()` dans `AdminSourceController`. Voters Symfony (`SourceVoter`, `ProfileVoter`).
- **[WARN]** Package abandonné : `debril/feed-io` → remplacement suggéré `php-feed-io/feed-io`. Pas de CVE associée mais risque maintenance. (-1 pt)
- **[INFO]** SSRF edge case documenté : si la résolution DNS échoue (`gethostbyname` retourne le hostname inchangé), la requête HTTP est autorisée et échouera lors du fetch — comportement acceptable (commentaire présent ligne 230).

---

## TOP 5 Actions Prioritaires

| # | Action | Catégorie | Impact points | Effort |
|---|--------|-----------|---------------|--------|
| 1 | **Migrer les 5 contrôleurs HTML inline vers Twig** — créer les templates `.html.twig` pour `BriefController`, `RegistrationController`, `LoginController`, `PaywallModalController`, `DashboardController`. Externaliser le CSS vers des fichiers `.css` (AssetMapper ou Encore). Ramènera `BriefController` de 1 404 à ~80 lignes. | QC + Archi | **+8** (QC +5, Archi +3) | Moyen |
| 2 | **Monter la couverture de tests à ≥ 80 %** — priorité : `BulkFetchHandler` (flux Feed critique), `RedisSynthesisCache`, `HttpArticleContentFetcher`, `RedisQuotaCounter` (intégration Redis), `Domain/Feed/Article` (54 %), `Domain/Synthesis/SynthesisResult` (37 %). Ajouter ~50 tests ciblés. | Tests | **+5** | Élevé |
| 3 | **Remplacer `debril/feed-io` par `php-feed-io/feed-io`** — migration directe suggérée par `composer audit`. Vérifier la compatibilité de l'API, mettre à jour les types dans `Infrastructure/Feed/`. | Sécurité | **+1** | Faible |
| 4 | **Injecter l'URL publique depuis un paramètre Symfony** — remplacer la valeur hardcodée `'https://briefly.ai/brief'` dans `BriefController::renderBriefHtml()` par un paramètre DI `$appPublicUrl` (variable d'env `APP_PUBLIC_URL`). | QC | **+1** | Faible |
| 5 | **Ajouter les tests Domain manquants** — `Domain/Feed/SourcePermission` (0 %), `Domain/Synthesis/SynthesisResult` (37 %), `Domain/User/OAuthAccount` (70 %). Ce sont des classes Domain qui doivent avoir 100 % de couverture selon les standards DDD. | Tests | **+1** | Faible |

---

## Synthèse exécutive

Le stack Symfony de Briefly AI est **solide sur les fondations** : architecture hexagonale stricte (deptrac 0 violation), PHPStan niveau max clean, 0 CVE, 0 secret, SSRF et XSS correctement traités. Les 555 tests passent sans échec.

Le principal déficit est **structurel et concentré** : `BriefController.php` (1 404 lignes) embarque la totalité du rendu HTML/CSS/JS de la page brief en PHP pur — une dette Sprint 1 clairement documentée mais non soldée malgré la disponibilité de Twig. C'est ce seul fichier qui tire la note Qualité Code à 18/25 et contribue à la note Architecture de 22/25.

La couverture de tests à **65,7 %** (vs 80 % requis) est le deuxième gap majeur, principalement dû aux classes Infrastructure non testées (adapters Redis, HTTP, Doctrine entities) et à quelques classes Domain sous-couvertes.

**Budget de correction estimé :** ~3 jours développeur pour les actions 1+2, ~2h pour les actions 3+4+5.
