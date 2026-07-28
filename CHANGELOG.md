# Changelog — Briefly AI

Toutes les modifications notables sont documentées dans ce fichier.

Format : [Keep a Changelog](https://keepachangelog.com/) · Versionnement : [SemVer](https://semver.org/)

---

## [Unreleased]

---

## [0.1.0] — 2026-07-28 — Sprint 0 : Infrastructure & Walking Skeleton

### Added

#### Infrastructure Docker
- **FrankenPHP 1** (PHP 8.5 Alpine) — image multi-stage `base/deps/dev/prod`
- **PostgreSQL 16** — service `database`, healthcheck `pg_isready`
- **Redis 7** — service `redis`, healthcheck `redis-cli ping`, AOF activé
- `compose.yaml` — stack dev+prod avec volumes nommés et réseau bridge
- `compose.override.yaml` — surcharges dev (code mount, ports locaux exposés)
- `docker/php/app.ini` — OPcache + sécurité PHP pour worker mode FrankenPHP
- `config/preload.php` — script OPcache preload (actif en prod)

#### Symfony 8 + API Platform 4
- `src/Kernel.php` — MicroKernelTrait, config auto-discoverte depuis `config/`
- `config/bundles.php` — FrameworkBundle, DoctrineBundle, ApiPlatformBundle, SecurityBundle
- `config/packages/framework.yaml` — sessions HttpOnly/Secure/SameSite=strict
- `config/packages/doctrine.yaml` — PostgreSQL 16, lazy ghost objects, mapping `src/Infrastructure/`
- `config/packages/doctrine_migrations.yaml` — migrations versionnées, all_or_nothing
- `config/packages/api_platform.yaml` — REST/JSON-LD, path_prefix `/api/v1`, OpenAPI 3.1
- `config/packages/cache.yaml` — Redis adapter, pools `synthesis` (24h) et `brief` (60s)
- `config/packages/messenger.yaml` — Redis Streams, DLQ, retry backoff exponentiel (×2, max 3)
- `config/packages/security.yaml` — Argon2id via sodium (jamais bcrypt/MD5/SHA1)
- `config/packages/translation.yaml` — FR par défaut, fallback EN
- `config/packages/validator.yaml` — attributs PHP 8, html5 email
- `config/packages/test/framework.yaml` — test: true, mock session
- `config/packages/test/services.yaml` — services publics pour Feature tests
- `config/services.yaml` — autowire/autoconfigure, `_instanceof` HealthProbeInterface→tag
- `config/routes.yaml` — discovery controllers + API Platform route loader

#### Architecture hexagonale — slice Health (Walking Skeleton)
- `src/Domain/Health/ComponentStatus.php` — Value Object readonly immuable
- `src/Domain/Health/HealthReport.php` — Value Object agrégateur de statuts
- `src/Domain/Health/HealthProbeInterface.php` — Port secondaire (driven port)
- `src/Application/Health/GetHealthQuery.php` — Query CQRS (marqueur)
- `src/Application/Health/GetHealthHandler.php` — Handler : orchestre les sondes
- `src/Infrastructure/Health/DatabaseHealthProbe.php` — Adapter : ping PostgreSQL via DBAL
- `src/Infrastructure/Health/RedisHealthProbe.php` — Adapter : ping Redis via Predis
- `src/Presentation/Controller/HealthController.php` — `GET /api/health` → 200/503 + JSON

#### Outillage qualité
- `phpstan.dist.neon` — niveau max + strict-rules, paths src+tests
- `deptrac.yaml` — 4 couches hexagonales, ruleset strict, CI bloquant
- `.php-cs-fixer.dist.php` — PSR-12 + @Symfony + declare_strict_types
- `phpunit.dist.xml` — bootstrap tests/bootstrap.php, suites Unit+Feature, coverage src/
- `Makefile` — cibles `up/down/build/install/sh/test/phpstan/deptrac/cs/cs-fix/migrate`

#### Tests (Pest 3)
- `tests/bootstrap.php` — Symfony Dotenv bootEnv
- `tests/Pest.php` — configuration globale Pest
- `tests/Unit/Domain/Health/HealthReportTest.php` — 7 tests unitaires (VO, immutabilité, agrégation)
- `tests/Feature/HealthEndpointTest.php` — 4 tests HTTP (structure, types, Content-Type, timestamp)

#### CI — GitHub Actions
- `.github/workflows/ci.yml` — pipeline : composer validate → install → cs-fixer → phpstan → deptrac → pest → audit → hadolint
  - Services CI : postgres:16-alpine + redis:7-alpine
  - PHP 8.5 via shivammathur/setup-php@v2
  - Cache Composer
  - Hadolint Dockerfile lint

#### Documentation
- `README.md` — Prérequis, Quickstart, Architecture, Endpoints, Qualité
- `CHANGELOG.md` — Keep a Changelog (ce fichier)
- `env.example` — Template sans secrets (MISTRAL_API_KEY, STRIPE_*, OAUTH_* commentés)

### Security
- Sessions : HttpOnly + Secure + SameSite=Strict (constitution §6, OWASP #8)
- Mots de passe : Argon2id via libsodium (constitution §6)
- Aucun secret dans le code source ni dans les images Docker (constitution §6)
- `composer audit` intégré au CI (OWASP Supply Chain #6)
- Stack traces non exposées en prod (OWASP #7 Mishandling Exceptional Conditions)

---

[Unreleased]: https://github.com/briefly-ai/backend/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/briefly-ai/backend/releases/tag/v0.1.0
