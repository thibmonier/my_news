# Spécification Technique — Briefly AI

**Version :** 1.0.0
**Date :** 2026-07-28
**Statut :** Draft — Revue Tech Lead
**Références :** `prd.md` · `analysis/technical-options.md` · `analysis/constraints.md` · `docs/adr/`

---

## Table des matières

1. [Vue d'ensemble et objectifs techniques](#1-vue-densemble-et-objectifs-techniques)
2. [Architecture logique en couches (Hexagonale + DDD)](#2-architecture-logique-en-couches)
3. [Bounded Contexts](#3-bounded-contexts)
4. [Découpage en conteneurs (C4 Level 2)](#4-découpage-en-conteneurs)
5. [Modèle de données de haut niveau](#5-modèle-de-données-de-haut-niveau)
6. [Contrats d'API](#6-contrats-dapi)
7. [Pipeline d'ingestion RSS](#7-pipeline-dingestion-rss)
8. [Couche IA — Synthèse hybride](#8-couche-ia--synthèse-hybride)
9. [Stratégie de cache Redis](#9-stratégie-de-cache-redis)
10. [Internationalisation (i18n)](#10-internationalisation-i18n)
11. [Gestion des erreurs et résilience (OWASP #7)](#11-gestion-des-erreurs-et-résilience)
12. [Observabilité et logging](#12-observabilité-et-logging)
13. [Stratégie de test](#13-stratégie-de-test)
14. [CI/CD et Docker](#14-cicd-et-docker)
15. [Déploiement et scalabilité](#15-déploiement-et-scalabilité)
16. [Sécurité transverse (OWASP Top 10:2025)](#16-sécurité-transverse)
17. [Conformité RGPD et AI Act](#17-conformité-rgpd-et-ai-act)
18. [Correspondance FR/NFR ↔ décisions techniques](#18-correspondance-frnfr--décisions-techniques)
19. [Décisions d'architecture (ADR)](#19-décisions-darchitecture-adr)

---

## 1. Vue d'ensemble et objectifs techniques

### 1.1 Résumé de la proposition de valeur technique

Briefly AI est une plateforme de curation et de synthèse de l'actualité qui repose sur une architecture **server-first**, **privacy-by-design** et **scalable horizontalement**. Le Daily Brief (3 histoires curatées chaque matin) est la feature centrale ; tout le reste — synthèse IA, comptes Premium, mobile Flutter, API publique — amplifie cette valeur centrale sans la diluer.

La pile retenue est non négociable (voir `analysis/constraints.md` T1–T9) :

| Couche | Technologie | Justification |
|--------|-------------|---------------|
| Serveur applicatif | FrankenPHP (worker mode) | Zéro cold start, HTTP/2, CSP/HSTS natif |
| Framework backend | Symfony 8 + API Platform 4 | Architecture hexagonale + DDD, coexistence SSR et API |
| Frontend desktop | Twig + Symfony UX Turbo | SEO natif, pas de JS supplémentaire pour le rendu |
| Mobile | Flutter (Dart) | Codebase unique Android + iOS, perf proches du natif |
| Base de données | PostgreSQL 16 | Relations, index dédup, JSONB, PITR |
| Cache / Queue | Redis 7 | Sessions, synthèses, quotas, files Messenger, rate limit |
| Containerisation | Docker Compose (dev) → scaling horizontal | Stack unique, pas de K8s en v1 |

### 1.2 Objectifs techniques non fonctionnels prioritaires

| Priorité | Objectif | Cible | Source PRD |
|----------|----------|-------|-----------|
| 1 | TTI Daily Brief (4G P95) | < 1,5 s | NFR-001 |
| 2 | Latence API (P95) | < 200 ms | NFR-002 |
| 3 | Synthèse IA Mistral (P95) | < 8 s | NFR-003 |
| 4 | Débit ingestion | 500 sources/h, 10 000 art/h | NFR-006/007 |
| 5 | Cache hit rate synthèses IA | ≥ 80 % | NFR-010 |
| 6 | Disponibilité plateforme | 99,5 %/mois | NFR-027 |
| 7 | Rétention J+1 (hypothèse centrale) | > 50 % | PRD §9 |

### 1.3 Principes directeurs

- **Vertical slicing** : chaque User Story traverse DB → Domain → Application → API → Twig web ET Flutter mobile. Aucune US "backend only".
- **Hexagonale stricte** : le domaine métier (entités, Value Objects, interfaces) n'importe jamais d'infrastructure. Les adapters implémentent les ports du domaine.
- **Privacy by design** : aucun identifiant utilisateur dans les prompts LLM, mode on-device opt-in crédible (P-003), pseudonymisation des logs.
- **Fail-fast, recover silently** : chaque appel LLM et chaque source RSS est isolé via circuit breaker. L'échec d'une source ou d'un provider IA ne dégrade pas le reste du système.

---

## 2. Architecture logique en couches

### 2.1 Architecture hexagonale globale

```
┌────────────────────────────────────────────────────────────────────────┐
│  COUCHE PRÉSENTATION                                                   │
│                                                                        │
│  ┌──────────────────────────────┐   ┌──────────────────────────────┐  │
│  │  Twig + Symfony UX Turbo     │   │  Flutter (Dart)              │  │
│  │  Controllers Symfony         │   │  BLoC / Riverpod             │  │
│  │  Twig Components + Stimulus  │   │  Repositories Dart           │  │
│  │  Turbo Frames / Streams      │   │  Screens + Widgets           │  │
│  └──────────────┬───────────────┘   └──────────────┬───────────────┘  │
│                 │  Sessions HttpOnly                │  JWT EdDSA       │
└─────────────────┼─────────────────────────────────┼──────────────────┘
                  │                                  │
┌─────────────────▼──────────────────────────────────▼──────────────────┐
│  COUCHE API PLATFORM (port HTTP entrant)                               │
│                                                                        │
│  API Platform 4 Resources · StateProcessors · StateProviders           │
│  Serialization groups · Voters · OpenAPI 3.1 auto-générée             │
│  FrankenPHP worker (HTTP/2, HTTPS, CSP, HSTS, COOP, COEP)             │
└─────────────────────────────────┬──────────────────────────────────────┘
                                  │
┌─────────────────────────────────▼──────────────────────────────────────┐
│  COUCHE APPLICATION (Use Cases)                                        │
│                                                                        │
│  Command/Query handlers (CQRS léger)                                  │
│  Application Services (orchestration des ports)                        │
│  Symfony Messenger Handlers                                            │
│  Symfony Scheduler Tasks                                               │
└─────────────────────────────────┬──────────────────────────────────────┘
                                  │  dépend uniquement d'interfaces
┌─────────────────────────────────▼──────────────────────────────────────┐
│  COUCHE DOMAINE (coeur métier — 0 dépendance infrastructure)           │
│                                                                        │
│  Entités : Article, DailyBrief, Story, Source, User, Subscription     │
│  Value Objects : ArticleUrl, SimHash, SynthesisLevel, Quota, Money    │
│  Domain Events : ArticleIngested, BriefGenerated, QuotaExceeded       │
│  Interfaces (ports) : ArticleRepositoryInterface,                     │
│    SynthesisProviderInterface, BriefRepositoryInterface,              │
│    UserRepositoryInterface, SourceRepositoryInterface,                 │
│    NotificationGatewayInterface, BillingGatewayInterface,             │
│    CacheInterface, QuotaTrackerInterface                               │
└─────────────────────────────────┬──────────────────────────────────────┘
                                  │  implémentent les ports
┌─────────────────────────────────▼──────────────────────────────────────┐
│  COUCHE INFRASTRUCTURE (adapters)                                      │
│                                                                        │
│  Repositories Doctrine (PostgreSQL)                                    │
│  FeedIo RSS/Atom adapter                                               │
│  MistralAI HTTP client · OpenAI fallback client                       │
│  PhiOnDeviceAdapter (Flutter, opt-in)                                 │
│  RedisCache adapter (Symfony Cache + Redis)                            │
│  RedisQuotaTracker                                                     │
│  StripeGateway (billing + webhooks)                                   │
│  FcmApnsNotificationGateway                                            │
│  SymfonyMailer adapter                                                 │
│  SentryMonitor adapter                                                 │
└────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Règles d'import strictes

| Couche | Peut importer | Ne peut pas importer |
|--------|---------------|----------------------|
| Domaine | Aucune (PHP pur) | Symfony, Doctrine, HTTP, Redis |
| Application | Domaine | Doctrine, HTTP clients, Redis direct |
| Infrastructure | Domaine + Application + Symfony/Doctrine | Domaine ne doit pas retourner d'entités Doctrine |
| API Platform | Application + Infrastructure | Domaine directement (via Application) |
| Twig | Application | Infrastructure directe |

PHPStan règle de niveau max + `phpstan-symfony` + `phpstan-doctrine` valident ces contraintes à chaque CI.

---

## 3. Bounded Contexts

### 3.1 Cartographie des contextes

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Briefly AI                                   │
│                                                                     │
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────────────┐   │
│  │   INGESTION  │──▶│     BRIEF    │◀──│      SYNTHÈSE IA     │   │
│  │   (Sources)  │   │  (DailyBrief │   │  (SynthesisEngine)   │   │
│  │              │   │   + Stories) │   │                      │   │
│  └──────────────┘   └──────┬───────┘   └──────────────────────┘   │
│                             │                                       │
│                    ┌────────▼─────────────────────────┐            │
│                    │         COMPTES / BILLING         │            │
│                    │   (User, Subscription, Quota)     │            │
│                    └────────┬─────────────────────────┘            │
│                             │                                       │
│         ┌───────────────────┴──────────────────────┐               │
│         │                                          │               │
│  ┌──────▼──────┐                        ┌──────────▼──────┐        │
│  │ NOTIFICATIONS│                        │    ANALYTICS    │        │
│  │ (Push/Email) │                        │  (Métriques     │        │
│  │              │                        │   produit RGPD) │        │
│  └─────────────┘                        └─────────────────┘        │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.2 Description des contextes

#### BC-INGESTION — Sources & Indexation

**Responsabilité :** Fetch RSS/Atom, parsing, déduplication, stockage article.

| Élément | Valeur |
|---------|--------|
| Agrégat racine | `Source` (FeedUrl, FetchConfig, CircuitBreakerState) |
| Entités | `Article` (url, sha256Fingerprint, simHash, categoryTag, fetchedAt) |
| Value Objects | `ArticleUrl` (canonicalisation, strip UTM), `SimHash` (64-bit), `ETag` |
| Domain Events | `ArticleIngested`, `SourceSuspended`, `DuplicateDetected` |
| Ports (interfaces) | `SourceRepositoryInterface`, `ArticleFetcherInterface`, `ArticleRepositoryInterface` |
| Adapters | `FeedIoFetcher`, `DoctrineArticleRepository`, `RedisRateLimiter` |
| Scheduler | `FetchSourceTask` (toutes les 15 min par source, via Symfony Scheduler) |
| Messenger queues | `fetch_source`, `parse_article`, `dedup_article` |

#### BC-BRIEF — Daily Brief

**Responsabilité :** Sélection algorithmique des 3 histoires, génération du DailyBrief, slugs publics.

| Élément | Valeur |
|---------|--------|
| Agrégat racine | `DailyBrief` (date, stories[3], generatedAt, status) |
| Entités | `Story` (cluster d'articles, titre éditorial, horodatage LAST UPDATED) |
| Value Objects | `BriefDate` (slug `/brief/2026-07-28`), `StoryRank` (01/02/03) |
| Domain Events | `BriefGenerated`, `BriefPublished` |
| Ports | `BriefRepositoryInterface`, `StorySelectionStrategyInterface` |
| Adapters | `DoctrineBriefRepository`, `HdbscanStoryClusterer` |
| Scheduler | `GenerateDailyBriefTask` (5h00 UTC, puis toutes les heures 5h-22h si nouveaux articles) |

#### BC-SYNTHÈSE IA — SynthesisEngine

**Responsabilité :** Génération de synthèses, gestion du cache, circuit breaker providers.

| Élément | Valeur |
|---------|--------|
| Agrégat racine | `Synthesis` (articleId, level, content, provider, generatedAt) |
| Value Objects | `SynthesisLevel` (CONCISE, DETAILED, NARRATIVE), `SynthesisCacheKey` |
| Domain Events | `SynthesisGenerated`, `SynthesisProviderFailed`, `SynthesisCacheHit` |
| Ports | `SynthesisProviderInterface`, `SynthesisCacheInterface`, `SynthesisRepositoryInterface` |
| Adapters | `MistralProvider`, `OpenAIFallbackProvider`, `RedisSynthesisCache`, `PhiOnDeviceAdapter` |

#### BC-COMPTES / BILLING — Accounts

**Responsabilité :** Authentification, gestion des plans, quotas, RGPD.

| Élément | Valeur |
|---------|--------|
| Agrégat racine | `User` (uuid7, email, passwordHash, plan, preferences) |
| Entités | `Subscription` (stripeCustomerId, plan, status, expiresAt), `ApiKey` |
| Value Objects | `Email`, `PasswordHash` (Argon2id), `JwtToken` (EdDSA), `Quota` (count, resetAt) |
| Domain Events | `UserRegistered`, `SubscriptionActivated`, `QuotaExceeded`, `AccountDeleted` |
| Ports | `UserRepositoryInterface`, `BillingGatewayInterface`, `QuotaTrackerInterface` |
| Adapters | `DoctrineUserRepository`, `StripeGateway`, `RedisQuotaTracker` |

#### BC-NOTIFICATIONS

**Responsabilité :** Push quotidien (1/jour max), digest email.

| Ports | `NotificationGatewayInterface` |
|-------|-------------------------------|
| Adapters | `FcmApnsGateway` (via Notifee Flutter), `SymfonyMailerGateway` |
| Règle | 1 notification push/jour max, fenêtre configurable par utilisateur |

#### BC-ANALYTICS

**Responsabilité :** Métriques produit anonymisées (DAU/MAU, conversions, rétention).

| Contrainte | Zéro identifiant utilisateur direct, données agrégées, hébergement EU |
|------------|-----------------------------------------------------------------------|
| Ports | `AnalyticsTrackerInterface` |
| Adapters | `PosthogEuAdapter` (ou Plausible self-hosted) |

---

## 4. Découpage en conteneurs

> Diagrammes C4 complets : `architecture/c4-context.md`, `architecture/c4-container.md`, `architecture/c4-component.md`

### 4.1 Conteneurs Docker Compose (v1 — dev et production)

```yaml
# Résumé des services (voir docker-compose.yml pour le détail)

services:
  app:                    # FrankenPHP (worker mode) — Symfony 8
  worker_ingestion:       # Symfony Messenger consumer — queues fetch_source, parse_article, dedup_article
  worker_synthesis:       # Symfony Messenger consumer — queues generate_synthesis, send_notification
  worker_billing:         # Symfony Messenger consumer — queue stripe_webhook (idempotent)
  scheduler:              # Symfony Scheduler standalone (cron interne)
  postgres:               # PostgreSQL 16
  redis:                  # Redis 7 (AOF + RDB persistence)
  mobile:                 # Build Flutter (CI uniquement — pas de runtime prod)
```

### 4.2 Flux de communication

```
Navigateur (HTTPS)
  │
  ▼
FrankenPHP app:443
  ├── GET /brief/*         → Twig SSR (HTTP 200 + ETag)
  ├── POST /api/*          → API Platform (JSON:LD)
  └── POST /api/webhook/*  → Stripe webhook handler

Symf Scheduler (interne app)
  ├── FetchSourceTask       → Redis Stream: fetch_source
  └── GenerateBriefTask     → Redis Stream: generate_brief

worker_ingestion
  ├── consomme: fetch_source, parse_article, dedup_article
  └── écrit: PostgreSQL articles + Redis dedup cache

worker_synthesis
  ├── consomme: generate_synthesis
  ├── appelle: Mistral API (EU) ou OpenAI (fallback)
  └── écrit: PostgreSQL synthesis + Redis cache 24h

worker_billing
  ├── consomme: stripe_webhook
  └── écrit: PostgreSQL subscription, Redis quota

Flutter app (iOS/Android)
  └── HTTPS → FrankenPHP /api/* (JWT Bearer)
```

### 4.3 Règles réseau

- Tous les conteneurs worker sont **read-only** sur le filesystem, sauf `/tmp`.
- L'app FrankenPHP expose uniquement les ports 80 et 443.
- Redis et PostgreSQL ne sont **jamais** exposés sur l'hôte en production.
- Secrets injectés via `Docker Secrets` (production) ou fichier `.env.local` (dev uniquement).

---

## 5. Modèle de données de haut niveau

> Schéma ERD complet : `architecture/erd.md`

### 5.1 Entités principales

```sql
-- BC-INGESTION
sources (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  feed_url        TEXT NOT NULL UNIQUE,
  category        VARCHAR(50) NOT NULL,           -- tech, finance, science, geopolitique, ...
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  fetch_interval  SMALLINT NOT NULL DEFAULT 15,   -- minutes
  circuit_state   VARCHAR(20) DEFAULT 'closed',   -- closed | open | half_open
  etag            VARCHAR(255),
  last_modified   VARCHAR(255),
  last_fetched_at TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

articles (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  source_id       UUID NOT NULL REFERENCES sources(id),
  url_canonical   TEXT NOT NULL,
  url_sha256      CHAR(64) NOT NULL,              -- dédup niveau 1
  sim_hash        BIGINT,                          -- dédup niveau 2
  title           TEXT NOT NULL,
  summary         TEXT,
  content_snippet TEXT,                           -- 500 premiers chars pour clustering
  category_tag    VARCHAR(50),
  lang            CHAR(2) NOT NULL DEFAULT 'en',
  published_at    TIMESTAMPTZ,
  fetched_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  is_duplicate    BOOLEAN NOT NULL DEFAULT FALSE,
  canonical_id    UUID REFERENCES articles(id),    -- pointe vers l'article original
  CONSTRAINT uq_url_sha256 UNIQUE (url_sha256)
);
CREATE INDEX idx_articles_category_published ON articles(category_tag, published_at DESC);
CREATE INDEX idx_articles_sim_hash ON articles(sim_hash) WHERE is_duplicate = FALSE;

-- BC-BRIEF
daily_briefs (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  brief_date      DATE NOT NULL UNIQUE,            -- slug /brief/2026-07-28
  generated_at    TIMESTAMPTZ NOT NULL,
  updated_at      TIMESTAMPTZ NOT NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'draft' -- draft | published
);

stories (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  brief_id        UUID NOT NULL REFERENCES daily_briefs(id),
  rank            SMALLINT NOT NULL CHECK (rank IN (1, 2, 3)),
  editorial_title TEXT NOT NULL,
  last_updated_at TIMESTAMPTZ NOT NULL,
  article_ids     UUID[] NOT NULL,                -- articles formant le cluster
  cluster_size    SMALLINT NOT NULL,
  CONSTRAINT uq_brief_rank UNIQUE (brief_id, rank)
);

-- BC-SYNTHÈSE IA
syntheses (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  article_id      UUID REFERENCES articles(id),
  story_id        UUID REFERENCES stories(id),
  level           VARCHAR(20) NOT NULL,           -- CONCISE | DETAILED | NARRATIVE
  content         TEXT NOT NULL,
  provider        VARCHAR(50) NOT NULL,           -- mistral | openai | phi_ondevice
  cache_key       CHAR(64) NOT NULL,              -- SHA-256(article_id+level)
  generated_at    TIMESTAMPTZ NOT NULL,
  CONSTRAINT chk_article_or_story CHECK (article_id IS NOT NULL OR story_id IS NOT NULL),
  CONSTRAINT uq_cache_key UNIQUE (cache_key)
);

-- BC-COMPTES / BILLING
users (
  id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,  -- UUID v7
  email           VARCHAR(320) NOT NULL UNIQUE,
  password_hash   TEXT,                            -- Argon2id — NULL si OAuth only
  oauth_provider  VARCHAR(20),                     -- google | github | NULL
  oauth_subject   TEXT,
  plan            VARCHAR(20) NOT NULL DEFAULT 'free',  -- free | premium_monthly | premium_annual
  preferred_lang  CHAR(2) NOT NULL DEFAULT 'fr',
  timezone        VARCHAR(50) NOT NULL DEFAULT 'Europe/Paris',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deleted_at      TIMESTAMPTZ,                     -- soft delete RGPD (hard delete J+30)
  CONSTRAINT uq_oauth UNIQUE (oauth_provider, oauth_subject)
);

subscriptions (
  id                  UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id             UUID NOT NULL REFERENCES users(id),
  stripe_customer_id  VARCHAR(100) NOT NULL,
  stripe_sub_id       VARCHAR(100) NOT NULL UNIQUE,
  plan                VARCHAR(30) NOT NULL,
  status              VARCHAR(30) NOT NULL,         -- active | past_due | canceled | trialing
  current_period_end  TIMESTAMPTZ NOT NULL,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

api_keys (
  id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id     UUID NOT NULL REFERENCES users(id),
  key_hash    CHAR(64) NOT NULL UNIQUE,             -- SHA-256 de la clé affichée une seule fois
  name        VARCHAR(100) NOT NULL,
  last_used_at TIMESTAMPTZ,
  revoked_at  TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- BC-COMPTES (suite) — refresh tokens (rotation + détection de vol)
refresh_tokens (
  id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  CHAR(64) NOT NULL UNIQUE,              -- SHA-256 du token (jamais en clair)
  family_id   UUID NOT NULL,                         -- identifie la chaîne de rotation
  revoked     BOOLEAN NOT NULL DEFAULT FALSE,
  expires_at  TIMESTAMPTZ NOT NULL,                  -- J+7
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Détection de vol : si un token révoqué est réutilisé → invalider toute la famille
-- Purge RGPD : ON DELETE CASCADE sur user_id (hard delete J+30)

-- BC-RGPD
consent_records (
  id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id     UUID NOT NULL REFERENCES users(id),
  scope       VARCHAR(50) NOT NULL,                -- analytics | marketing | notifications
  granted     BOOLEAN NOT NULL,
  recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

stripe_events (
  id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  event_id    VARCHAR(100) NOT NULL UNIQUE,         -- idempotence Stripe
  event_type  VARCHAR(100) NOT NULL,
  processed   BOOLEAN NOT NULL DEFAULT FALSE,
  received_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### 5.2 Politique de rétention

| Table | Rétention active | Action après délai |
|-------|------------------|--------------------|
| articles | 90 jours | Archivage blob storage froid ou suppression |
| syntheses | 90 jours (liées aux articles) | Cascade delete |
| users (soft delete) | 30 jours | Hard delete cascade (droit à l'oubli) |
| consent_records | 3 ans (obligation légale) | Pseudonymisation → anonymisation |
| stripe_events | 7 ans (comptabilité) | Archivage froid |
| logs applicatifs | 12 mois max | Purge automatique |

---

## 6. Contrats d'API

> Contrats complets (OpenAPI 3.1) : `architecture/api.md`
> Documentation interactive auto-générée par API Platform : `/api/docs`

### 6.1 Endpoints publics (sans authentification)

| Méthode | Route | Description | Cache |
|---------|-------|-------------|-------|
| GET | `/brief/{date}` | Daily Brief du jour (Twig SSR, SEO) | ETag + Cache-Control 60s |
| GET | `/api/briefs/{date}` | Daily Brief en JSON:LD | 60s Redis |

### 6.2 Endpoints authentifiés (session ou JWT)

| Méthode | Route | Description | Plan requis |
|---------|-------|-------------|-------------|
| POST | `/api/register` | Création de compte | — |
| POST | `/api/login` | Authentification email/mot de passe | — |
| POST | `/api/auth/oauth/{provider}` | Callback OAuth2 (Google, GitHub) | — |
| POST | `/api/token/refresh` | Rafraîchissement JWT (mobile) | — |
| GET | `/api/me` | Profil utilisateur + quota | Free/Premium |
| GET | `/api/articles` | Flux paginé (cursor-based) | Free/Premium |
| GET | `/api/articles/{id}` | Article + synthèse IA | Free (quota) / Premium |
| POST | `/api/syntheses` | Demander une synthèse (quota Free: 3/j) — corps : `{"article_id": "<UUID>", "level": "CONCISE\|DETAILED\|NARRATIVE"}` | Free (quota) / Premium |
| GET | `/api/briefs` | Liste des briefs (paginated) | Free/Premium |
| PUT | `/api/me/preferences` | Préférences (langue, timezone, thèmes) | Free/Premium |
| DELETE | `/api/me` | Suppression de compte (RGPD) | Free/Premium |
| GET | `/api/me/data-export` | Export JSON portabilité RGPD | Free/Premium |
| GET | `/api/me/data-export/markdown` | Export Markdown de la bibliothèque personnelle (briefs + synthèses sauvegardés) — **Premium uniquement** | Premium |

### 6.3 Endpoints API publique Premium (Bearer API Key)

| Méthode | Route | Description | Rate limit |
|---------|-------|-------------|------------|
| GET | `/api/v1/briefs` | Liste briefs (cursor pagination) | 100 req/h |
| GET | `/api/v1/briefs/{date}` | Brief du jour en JSON | 100 req/h |
| GET | `/api/v1/articles` | Flux articles paginé | 100 req/h |
| GET | `/api/v1/articles/{id}` | Article + synthèse IA | 100 req/h |

### 6.4 Headers de réponse standard

```http
Content-Type: application/ld+json; charset=utf-8
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1722175200
X-Request-Id: uuid-v4-traçabilité-log
Cache-Control: max-age=60, s-maxage=300
ETag: "sha256-brief-content"
```

### 6.5 Schémas JSON:LD clés

```json
// GET /api/v1/briefs/2026-07-28
{
  "@context": "/api/contexts/Brief",
  "@type": "Brief",
  "@id": "/api/v1/briefs/2026-07-28",
  "date": "2026-07-28",
  "generatedAt": "2026-07-28T05:00:00Z",
  "lastUpdatedAt": "2026-07-28T08:30:00Z",
  "stories": [
    {
      "rank": 1,
      "editorialTitle": "...",
      "articleCount": 7,
      "synthesis": {
        "provider": "BRIEFLY AI",
        "level": "CONCISE",
        "content": "...",
        "sourceUrl": "https://..."
      }
    }
  ]
}
```

### 6.6 Erreurs standardisées (RFC 7807 Problem Details)

```json
{
  "type": "https://briefly.ai/errors/quota-exceeded",
  "title": "Daily synthesis quota exceeded",
  "status": 429,
  "detail": "You have used your 3 free syntheses today. Upgrade to Premium for unlimited access.",
  "instance": "/api/syntheses",
  "remainingQuota": 0,
  "resetAt": "2026-07-29T00:00:00Z"
}
```

---

## 7. Pipeline d'ingestion RSS

### 7.1 Vue d'ensemble du flux

```
Symfony Scheduler
  │  (FetchSourceTask, toutes les 15 min par source)
  │
  ▼
Redis Stream: fetch_source
  │
  ▼
worker_ingestion: FetchSourceHandler
  ├── FeedIo::read(source.feedUrl, ETag, Last-Modified)
  ├── Si HTTP 304 → skip (ETag conditionnel)
  ├── Pour chaque item RSS:
  │     ├── ArticleUrl::canonicalize() → strip UTM, normalize
  │     ├── SHA-256(canonicalUrl) → vérification UNIQUE PostgreSQL
  │     ├── Si doublon niveau 1 → DuplicateDetected event, skip
  │     └── Dispatch → Redis Stream: parse_article
  └── Mise à jour ETag + Last-Modified en base

worker_ingestion: ParseArticleHandler
  ├── Extraction contenu (titre, résumé, snippet 500 chars)
  ├── Normalisation encoding UTF-8
  ├── Dispatch → Redis Stream: dedup_article

worker_ingestion: DedupArticleHandler
  ├── SimHash(title, fenêtre ±2h)
  ├── Si sim(title, existing) > 0.85 → doublon niveau 2 → is_duplicate=true
  ├── Sinon → INSERT article (PostgreSQL)
  ├── Dispatch → Redis Stream: classify_article (via worker_synthesis)
  └── Si nouveau brief possible → Dispatch → generate_brief (si entre 5h-22h)
```

### 7.2 Déduplication en deux niveaux

| Niveau | Mécanisme | Fenêtre | Action |
|--------|-----------|---------|--------|
| 1 — URL exacte | SHA-256(canonical URL) + index UNIQUE PostgreSQL | Illimitée | INSERT ignoré (CONFLICT) |
| 2 — Similarité titre | SimHash 64-bit — distance de Hamming ≤ 3 | ±2 heures | is_duplicate=true, canonical_id → article existant |

**Algorithme SimHash :**
- Tokenisation du titre (lowercase, stop-words retirés, stemming léger)
- Pondération tf-idf simple (basé sur le corpus de la journée)
- Distance de Hamming ≤ 3 bits sur 64 = doublon confirmé
- Implémenté en PHP pur dans le domaine (`App\Domain\Ingestion\ValueObject\SimHash`)

### 7.3 Résilience par source

```
État circuit breaker (par source) :
  CLOSED   → fonctionnement normal
  OPEN     → suspendu (backoff exponentiel Redis TTL)
  HALF_OPEN → une tentative de sonde

Règle : 5 erreurs consécutives dans une fenêtre de 10 min → circuit OPEN
Back-off : 5 min → 15 min → 60 min → alerte admin

Rate limiter Redis :
  INCR fetch:{sourceId}:{window}
  TTL = durée de la fenêtre
  Limite = configurable par source (défaut : 4 req/heure max)
```

### 7.4 Classification par thématique

Après déduplication, chaque article déclenche un message `classify_article` :

```
ClassifyArticleHandler (worker_synthesis) :
  ├── Si article.categoryTag est déjà défini → skip
  ├── Prompt court à Mistral (classification uniquement) :
  │     "Classify this article title into ONE category:
  │      tech | finance | science | geopolitique | sante | culture | sport
  │      Title: {article.title}"
  ├── Résultat → article.category_tag = topic
  └── Durée : < 500ms (modèle léger, batch possible)
```

### 7.5 Génération du Daily Brief

```
GenerateBriefHandler (worker_ingestion, déclenché par Scheduler à 5h UTC) :
  ├── Sélection articles des 24h écoulées (non-doublons, per category)
  ├── HDBSCAN clustering sur embeddings des snippets (128-dim)
  │     └── Embeddings : Mistral embed (batch nocturne, coût optimisé)
  ├── Sélection des 3 clusters de plus grande taille / score d'importance
  ├── Pour chaque cluster sélectionné :
  │     ├── Génération titre éditorial (Mistral, prompt court)
  │     └── Génération synthèse CONCISE (pré-générée pour le brief)
  ├── INSERT daily_briefs + stories
  ├── Cache Redis : brief:{date} TTL 60s (pour GET /api/briefs/{date})
  └── Dispatch BriefGenerated event → Notifications (si opt-in)
```

---

## 8. Couche IA — Synthèse hybride

### 8.1 Architecture du provider IA

```
SynthesisService (Application Layer)
  │
  ├── SynthesisCacheInterface.get(cacheKey)
  │     └── Hit (Redis, TTL 24h) → retourner synthèse sans appel LLM
  │
  ├── QuotaTrackerInterface.canGenerate(userId)
  │     └── Free : compteur Redis ≤ 3/jour (TTL reset minuit UTC)
  │     └── Premium : toujours autorisé
  │
  └── SynthesisProviderChain (Strategy + Circuit Breaker)
        ├── Primaire : MistralProvider (EU, RGPD-conforme)
        │     ├── Circuit breaker : 3 erreurs / 5 min → fallback
        │     └── Timeout : 10s (NFR-003 : < 8s P95)
        ├── Fallback : OpenAIProvider (RTO < 30s — NFR-029)
        └── On-device (mobile Flutter, opt-in) : PhiOnDeviceAdapter
              └── Niveau CONCISE uniquement, jamais de données sortantes
```

### 8.2 Niveaux de synthèse

| Niveau | Longueur | Provider | Déclenchement | Cache |
|--------|----------|----------|---------------|-------|
| CONCISE | 3 phrases (~80 mots) | Mistral Small / Phi-3 Mini on-device | Pré-généré pour brief + à la demande | Redis 24h |
| DETAILED | 150-300 mots + 3 points clés | Mistral Medium | À la demande | Redis 24h |
| NARRATIVE | 500+ mots + analyse 7j | Mistral Large (Premium) | À la demande (Premium uniquement) | Redis 24h |

### 8.3 Politique de prompts — Privacy

```
RÈGLE ABSOLUE (NFR-018) : aucun identifiant utilisateur dans les prompts LLM.

Template prompt DETAILED :
"""
You are a neutral news analyst. Summarize the following article in 200 words,
in the same language as the article, with:
- 3 key takeaways (bullet points)
- A source attribution line: "Source: {article.source.name}"
- Prefix: "BRIEFLY AI:"
Do not add any personal opinions.

Article title: {article.title}
Article content: {article.contentSnippet}
Source URL: {article.urlCanonical}
Category: {article.categoryTag}
"""

Ce qui n'est JAMAIS inclus dans le prompt :
- user.id
- user.email
- user.readingHistory
- IP de l'utilisateur
- Historique des requêtes précédentes
```

### 8.4 Identification visuelle obligatoire (AI Act)

Toute synthèse produite par un LLM DOIT respecter (NFR-019) :
1. Préfixe textuel : **"BRIEFLY AI:"**
2. Accent émeraude `#10B981` sur le bloc
3. Icône robot/étoile (accessibilité — pas uniquement couleur — NFR-025)
4. Lien "OUVRIR L'ORIGINAL" vers `article.urlCanonical`
5. Attribut HTML `role="article" aria-label="Contenu généré par IA - BRIEFLY AI"`

### 8.5 Traitement on-device (opt-in — P-003, FR-042)

```
Activation (Flutter) :
  1. Utilisateur active "Traitement on-device" dans les réglages
  2. Téléchargement modèle Phi-3 Mini GGUF 4-bit (~1,8 Go) via HTTPS
  3. Vérification SHA-256 du modèle après téléchargement
  4. Stockage dans flutter_secure_storage (chemin modèle chiffré)

Synthèse on-device :
  ├── Niveau CONCISE uniquement (NFR-004 : < 15s P95)
  ├── Aucune donnée ne quitte le téléphone
  ├── Indicateur visuel "On-device ◉" dans l'interface
  └── Fallback silencieux vers serveur si modèle absent ou < iPhone 15 / Pixel 8

Conditions techniques minimales :
  - iOS : A16 Bionic (iPhone 15+), RAM libre ≥ 2 Go
  - Android : Snapdragon 8 Gen 2+ ou équivalent, RAM libre ≥ 3 Go
```

---

## 9. Stratégie de cache Redis

### 9.1 Namespaces et TTL

| Namespace | Clé | Valeur | TTL | Usage |
|-----------|-----|--------|-----|-------|
| `session:` | `session:{sessionId}` | Données session | 30 min (glissant) | Auth desktop |
| `brief:` | `brief:{date}` | JSON DailyBrief sérialisé | 60s | GET /api/briefs/{date} |
| `synthesis:` | `synthesis:{sha256(articleId+level)}` | Texte synthèse | 24h | Cache synthèses IA |
| `quota:` | `quota:{userId}:{date}` | Entier (compteur) | Jusqu'à minuit UTC | Quota Free 3/jour |
| `rate:login:` | `rate:login:{ip}` | Entier (tentatives) | 15 min | Anti-brute force |
| `rate:api:` | `rate:api:{apiKeyHash}` | Entier (requêtes) | 1h glissante | Rate limit API Premium |
| `rate:source:` | `rate:source:{sourceId}:{window}` | Entier | Fenêtre configurable | Rate limit RSS |
| `cb:` | `cb:source:{sourceId}` | État + compteur | Backoff exponentiel | Circuit breaker |
| `etag:` | `etag:source:{sourceId}` | ETag + Last-Modified | 15 min | Requêtes conditionnelles RSS |
| `lock:` | `lock:brief:{date}` | Mutex (SET NX) | 5 min | Évite génération concurrente du brief |
| `msg:` | Redis Streams (`fetch_source`, etc.) | Messages Messenger | Persistants (consumer groups) | Queues async |

### 9.2 Politique d'éviction

- **maxmemory-policy :** `allkeys-lru` (éviction LRU générale)
- **Priorités** : les clés `session:`, `quota:`, `cb:` ont une priorité haute — ne pas les laisser expirer par éviction.
- **Persistance** : RDB toutes les 15 min + AOF (appendonly yes) pour durabilité des messages Messenger.

### 9.3 Warm-up du cache synthèses

Chaque nuit à 4h UTC, un job pré-génère les synthèses CONCISE des top-10 articles de chaque catégorie (prévisibles = fort hit rate le matin). Cible : hit rate ≥ 80 % dès 7h00 (NFR-010).

---

## 10. Internationalisation (i18n)

### 10.1 Stratégie globale

- **Langues v1 :** Anglais (`en`) — langue de référence des développeurs — et Français (`fr_FR`) — marché cible principal.
- **Principe :** aucune chaîne de caractères en dur dans le code PHP, Twig ou Dart. Toutes les chaînes passent par les systèmes de traduction.
- **Détection :** `Accept-Language` HTTP header → préférence explicite utilisateur (stockée en base) → fallback `fr`.

### 10.2 Backend Symfony

```php
// symfony/translation avec format ICU (MessageFormat)
// config/packages/translation.yaml
framework:
    default_locale: fr
    translator:
        paths: [translations/]
        fallbacks: [en]
        providers: []

// Fichiers : translations/{domain}.{locale}.yaml
// Exemple : translations/messages.fr.yaml
brief.story.last_updated: "DERNIÈRE MISE À JOUR : {date, date, medium}"
synthesis.level.concise: "Synthèse concise"
quota.exceeded: "Vous avez utilisé vos {limit} synthèses gratuites aujourd'hui."
```

**Règles :**
- Dates et heures : `IntlDateFormatter` (locale utilisateur, timezone profil)
- Devises : `NumberFormatter::CURRENCY` (EUR, format locale)
- Nombres : séparateurs localisés (ICU)
- Contenu éditorial (titres articles, synthèses) : tagué `lang="{article.lang}"` dans le HTML

### 10.3 Mobile Flutter

```dart
// Localisation via flutter_localizations + intl + ARB
// lib/l10n/app_fr.arb
{
  "@@locale": "fr",
  "briefTitle": "Votre Brief du {date}",
  "@briefTitle": {
    "placeholders": {
      "date": { "type": "DateTime", "format": "MMMd" }
    }
  },
  "synthesisLabel": "BRIEFLY AI : ",
  "quotaExceeded": "Limite atteinte — {remaining} synthèse(s) disponible(s) demain"
}
```

**Détection locale Flutter :** `Localizations.localeOf(context)` + préférence stockée dans `SharedPreferences`.

### 10.4 Modèles de synthèse par langue

Les prompts Mistral incluent la directive de langue basée sur `article.lang` :
```
Summarize in {article.lang == 'fr' ? 'French' : 'English'}.
```
Les synthèses sont cachées par `(articleId, level, lang)`.

---

## 11. Gestion des erreurs et résilience

> Référence OWASP Top 10:2025 #7 — Mishandling of Exceptional Conditions

### 11.1 Principes

1. **Stack traces JAMAIS exposées en production.** FrankenPHP est configuré avec `APP_ENV=prod` qui active le gestionnaire d'exceptions Symfony (pages d'erreur génériques).
2. **Messages d'erreur côté client :** génériques pour les 5xx, contextuels pour les 4xx (RFC 7807).
3. **Logger toutes les exceptions :** Sentry (SDK PHP) en production, Monolog en développement.
4. **Fail fast avec erreurs métier claires :** les services de domaine lèvent des exceptions métier typées (`QuotaExceededException`, `SynthesisProviderUnavailableException`, `ArticleNotFoundException`).

### 11.2 Hiérarchie des exceptions domaine

```php
// Exceptions du domaine — pas de dépendance HTTP
App\Domain\Exception\DomainException (base)
  ├── IngestionException
  │     ├── FeedFetchException
  │     ├── DuplicateArticleException
  │     └── CircuitBreakerOpenException
  ├── SynthesisException
  │     ├── QuotaExceededException (→ HTTP 429)
  │     ├── SynthesisProviderUnavailableException (→ fallback automatique)
  │     └── SynthesisCacheMissException (→ génération déclenchée)
  ├── AuthException
  │     ├── InvalidCredentialsException (→ HTTP 401)
  │     └── TokenExpiredException (→ HTTP 401 + refresh flow)
  └── BillingException
        ├── SubscriptionNotFoundException
        └── WebhookAlreadyProcessedException (idempotence)
```

### 11.3 Gestionnaires d'exceptions centralisés

```php
// src/Infrastructure/Api/ExceptionSubscriber.php
// Convertit les DomainExceptions en réponses RFC 7807

// src/Infrastructure/Http/ExceptionListener.php
// Pour les pages Twig (HTTP 400, 403, 404, 500 → templates Twig)
```

### 11.4 Circuit breaker LLM

```
MistralProvider :
  ├── État CLOSED : appels normaux
  ├── 3 TimeoutException ou 5xx en 5 min → OPEN
  ├── État OPEN : dispatch vers OpenAIFallbackProvider sans délai
  ├── Après 30s : HALF_OPEN → une tentative Mistral
  └── Si succès → CLOSED ; si échec → OPEN prolongé (60s)

Monitoring : chaque transition d'état → log WARNING + métrique Sentry
```

### 11.5 Retry et back-off Messenger

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            fetch_source:
                retry_strategy:
                    max_retries: 3
                    delay: 1000        # ms
                    multiplier: 2      # back-off exponentiel
                    max_delay: 60000
        routing:
            App\Domain\Message\FetchSourceMessage: fetch_source
```

---

## 12. Observabilité et logging

### 12.1 Niveaux de log

| Niveau | Usage | Exemples |
|--------|-------|---------|
| ERROR | Exceptions non gérées, pannes critiques | LLM indisponible, DB down |
| WARNING | Dégradation, circuit breaker, quota atteint | Source suspendue, fallback LLM |
| INFO | Événements métier importants | Brief généré, article ingéré (sampled), inscription |
| DEBUG | Développement uniquement | Requêtes SQL, appels API internes |

### 12.2 Format de log structuré (JSON)

```json
{
  "timestamp": "2026-07-28T05:00:12.345Z",
  "level": "INFO",
  "channel": "ingestion",
  "message": "article_ingested",
  "context": {
    "source_id": "uuid-non-sequential",
    "article_id": "uuid-non-sequential",
    "category": "tech",
    "is_duplicate": false,
    "duration_ms": 142
  },
  "extra": {
    "request_id": "uuid-v4",
    "environment": "prod"
  }
}
```

**Règle RGPD (NFR-055) :** aucun `user.email`, `user.id` réel, ni IP directe dans les logs. Uniquement des UUIDs non séquentiels ou des pseudonymes. Les IPs sont hashées avec salt rotatif (30 jours) avant stockage.

### 12.3 Métriques applicatives

| Métrique | Type | Labels | Usage |
|----------|------|--------|-------|
| `briefly_articles_ingested_total` | Counter | source, category | Débit ingestion |
| `briefly_synthesis_generated_total` | Counter | level, provider, cache_hit | Cache hit rate |
| `briefly_synthesis_latency_seconds` | Histogram | level, provider | NFR-003 |
| `briefly_quota_exceeded_total` | Counter | — | Pression conversion |
| `briefly_brief_generated_total` | Counter | — | Fiabilité batch |
| `briefly_api_request_duration_seconds` | Histogram | endpoint, method, status | NFR-002 |

Exposition : endpoint `/metrics` (Prometheus format), protégé par IP allowlist ou token.

### 12.4 Alertes

| Condition | Sévérité | Canal |
|-----------|----------|-------|
| Aucun brief généré avant 7h00 UTC | CRITICAL | PagerDuty |
| Taux d'erreur 5xx > 1 % sur 5 min | ERROR | Slack #ops |
| Circuit breaker Mistral en OPEN > 5 min | WARNING | Slack #ops |
| Latence P95 API > 500 ms | WARNING | Slack #ops |
| Hit rate cache < 60 % | WARNING | Slack #analytics |
| Worker Messenger en lag > 1000 messages | WARNING | Slack #ops |

---

## 13. Stratégie de test

### 13.1 Pyramide des tests

```
           ┌─────────────────┐
           │    E2E (10%)    │  Playwright (web) / Flutter integration tests
           │   < 30 min CI  │  Parcours critiques : inscription → synthèse → paywall
           ├─────────────────┤
           │ Integration     │  PHPUnit ApiTestCase + Doctrine avec PostgreSQL test
           │   Tests (20%)  │  Flutter widget tests + mock HTTP
           │   < 5 min CI   │
           ├─────────────────┤
           │  Unit Tests     │  PHPUnit (PHP) + flutter_test (Dart)
           │    (70%)       │  Domaine pur : Value Objects, Services, Handlers
           │   < 2 min CI   │
           └─────────────────┘

Couverture cible : ≥ 80% global, ≥ 90% couche domaine
```

### 13.2 Tests PHP (Pest / PHPUnit)

**Tests unitaires — couche domaine :**
```php
// tests/Unit/Domain/Ingestion/SimHashTest.php
it('detects near-duplicate titles within 2 hour window', function () {
    $hash1 = SimHash::fromTitle('OpenAI launches GPT-5 with multimodal reasoning');
    $hash2 = SimHash::fromTitle('OpenAI unveils GPT-5 with multimodal capabilities');
    expect($hash1->isDuplicateOf($hash2))->toBeTrue();
});

// tests/Unit/Domain/Synthesis/QuotaTest.php
it('blocks synthesis at 4th request for free plan', function () {
    $quota = Quota::forFree(count: 3);
    expect($quota->canGenerate())->toBeFalse();
});
```

**Tests d'intégration — API Platform :**
```php
// tests/Integration/Api/SynthesisResourceTest.php
// Utilise ApiTestCase + WebTestCase de API Platform
it('returns 429 when quota exceeded', function () {
    // ...
    $response = $this->client->request('POST', '/api/syntheses', [...]);
    $this->assertResponseStatusCodeSame(429);
    $this->assertJsonContains(['type' => 'https://briefly.ai/errors/quota-exceeded']);
});
```

**Tests de sécurité :**
```php
// tests/Security/XssTest.php — vérification CSP headers
// tests/Security/SqlInjectionTest.php — via Doctrine uniquement
// tests/Security/AuthTest.php — voters, deny by default
```

### 13.3 Tests Flutter (flutter_test)

```dart
// test/widget/synthesis_badge_test.dart
testWidgets('synthesis badge shows BRIEFLY AI prefix', (tester) async {
  await tester.pumpWidget(SynthesisBadge(content: 'Test', provider: 'mistral'));
  expect(find.text('BRIEFLY AI:'), findsOneWidget);
  expect(find.byIcon(Icons.auto_awesome), findsOneWidget);
});

// test/integration/brief_screen_test.dart
// Teste le flux complet: chargement, cache offline, synthèse on-device
```

### 13.4 Outils de qualité PHP

| Outil | Config | CI bloquant |
|-------|--------|-------------|
| PHPStan | Niveau max + `phpstan-symfony` + `phpstan-doctrine` | Oui |
| PHP-CS-Fixer | PSR-12 + règles projet | Oui |
| Infection (mutation testing) | Score ≥ 70 % | Non (warning) |
| `composer audit` | CVE scan dépendances | Oui |
| Trivy | CVE scan image Docker | Oui |

### 13.5 Outils Flutter

| Outil | Usage | CI bloquant |
|-------|-------|-------------|
| `dart analyze` (strict) | Analyse statique | Oui |
| `flutter test --coverage` | Couverture ≥ 80 % | Oui |
| `flutter_lints` | Conventions Dart | Oui |

---

## 14. CI/CD et Docker

### 14.1 Pipeline CI (GitHub Actions)

```yaml
# .github/workflows/ci.yml (résumé)
jobs:
  php-quality:
    steps:
      - composer install --no-dev
      - php-cs-fixer (lint)
      - phpstan (analyse)
      - phpunit (tests unitaires)
      - phpunit (tests intégration — PostgreSQL service)
      - composer audit (CVE)

  php-security:
    steps:
      - trivy image scan (Docker image)
      - SBOM génération CycloneDX (NFR-014)

  flutter:
    steps:
      - dart analyze --fatal-infos
      - flutter test --coverage
      - flutter build apk --release (vérification compilabilité)
      - flutter build ios --release --no-codesign

  e2e:
    needs: [php-quality, flutter]
    steps:
      - docker compose up -d (stack complète)
      - playwright test (parcours critiques web)
      - flutter integration_test (parcours critiques mobile)

  deploy:
    needs: [php-quality, php-security, flutter, e2e]
    environment: production
    steps:
      - docker build + push registry
      - docker compose pull && up -d (rolling update)
      - Smoke tests (health check + brief endpoint)
```

### 14.2 Dockerfile multi-stage

```dockerfile
# Dockerfile (simplifié)
FROM dunglas/frankenphp:1-php8.5-alpine AS base
# Installation dépendances système (intl, pdo_pgsql, redis, opcache, apcu)

FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM base AS dev
COPY --from=deps /app/vendor ./vendor
COPY . .
RUN composer run-script post-install-cmd

FROM base AS prod
COPY --from=deps /app/vendor ./vendor
COPY . .
RUN php bin/console cache:warmup --env=prod
RUN php bin/console asset-map:compile
# Utilisateur non-root
USER www-data
EXPOSE 80 443
ENTRYPOINT ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
```

### 14.3 Docker Compose de production

```yaml
# docker-compose.prod.yml (résumé)
services:
  app:
    image: briefly/app:${VERSION}
    environment:
      APP_ENV: prod
      DATABASE_URL: ${DATABASE_URL}
      REDIS_URL: ${REDIS_URL}
    secrets: [mistral_key, openai_key, stripe_key, jwt_private_key]
    deploy:
      replicas: 2
      update_config:
        order: rolling-update

  worker_ingestion:
    image: briefly/app:${VERSION}
    command: ["php", "bin/console", "messenger:consume", "fetch_source", "parse_article", "dedup_article", "--time-limit=3600"]
    deploy:
      replicas: 3                # scalabilité horizontale NFR-009
      update_config:
        order: rolling-update

  worker_synthesis:
    image: briefly/app:${VERSION}
    command: ["php", "bin/console", "messenger:consume", "generate_synthesis", "send_notification"]
    deploy:
      replicas: 2

  worker_billing:
    image: briefly/app:${VERSION}
    command: ["php", "bin/console", "messenger:consume", "stripe_webhook", "--limit=1"]
    deploy:
      replicas: 1                # idempotence, un seul consumer suffit

  scheduler:
    image: briefly/app:${VERSION}
    command: ["php", "bin/console", "scheduler:run"]
    deploy:
      replicas: 1                # UN seul scheduler — lock Redis anti-doublon

  postgres:
    image: postgres:16-alpine
    volumes:
      - pgdata:/var/lib/postgresql/data
    environment:
      POSTGRES_DB: briefly_prod

  redis:
    image: redis:7-alpine
    command: ["redis-server", "--appendonly", "yes", "--maxmemory", "512mb", "--maxmemory-policy", "allkeys-lru"]
    volumes:
      - redisdata:/data
```

---

## 15. Déploiement et scalabilité

### 15.1 Infrastructure v1 (Docker Compose, petite équipe)

| Noeud | Rôle | Spec recommandée |
|-------|------|-----------------|
| Node 1 | app (2 répliques FrankenPHP) + scheduler | 4 vCPU, 8 Go RAM |
| Node 2 | workers ingestion (3) + workers synthesis (2) + workers billing (1) | 4 vCPU, 8 Go RAM |
| Node 3 | PostgreSQL 16 (primary) | 4 vCPU, 16 Go RAM, 200 Go SSD |
| Node 4 | Redis 7 (AOF) | 2 vCPU, 4 Go RAM |

**Hébergeur :** Hetzner Cloud (EU) ou OVH (EU) — conformité RGPD NFR-016.

### 15.2 Scalabilité horizontale des workers (NFR-009)

Les workers Symfony Messenger sont **stateless** et scalent linéairement. Pour passer de 500 à 2000 sources/h, il suffit d'augmenter les répliques `worker_ingestion` de 3 à 12. Pas de changement de code.

```bash
# Scaling manuel (v1)
docker service scale briefly_worker_ingestion=10

# Monitoring du lag
php bin/console messenger:stats
```

Limite actuelle : linéaire jusqu'à 10 workers (NFR-009). Au-delà, migrer Redis Streams vers AMQP (RabbitMQ) — ADR à créer.

### 15.3 Migrations PostgreSQL

```bash
# Workflow obligatoire (DoD)
php bin/console doctrine:migrations:diff     # Vérifie 0 diff
php bin/console doctrine:migrations:migrate  # Migration progressive
# Toujours réversible (down() implémentée)
# Jamais de modification de migration existante
```

Migration déployée **avant** le déploiement du code (backward compatible).

### 15.4 Secrets en production

| Secret | Source | Rotation |
|--------|--------|----------|
| `DATABASE_URL` | Docker Secret ou env chiffrée | Manuelle (trimestrielle) |
| `REDIS_URL` | Docker Secret | Manuelle |
| `MISTRAL_API_KEY` | Docker Secret | Manuelle (mensuelle) |
| `OPENAI_API_KEY` | Docker Secret | Manuelle |
| `STRIPE_SECRET_KEY` | Docker Secret | Rotation Stripe |
| `STRIPE_WEBHOOK_SECRET` | Docker Secret | Rotation Stripe |
| `JWT_PRIVATE_KEY` | Docker Secret (Ed25519 PEM) | Annuelle |
| `APP_SECRET` | Docker Secret (32 bytes random) | Annuelle |

**Règle :** aucun secret dans le code source, aucun secret dans les images Docker. `.env` en dev uniquement, jamais commité (`.gitignore`).

### 15.5 Sauvegarde et reprise (NFR-030)

- **Snapshots PostgreSQL :** quotidiens via `pg_dump` chiffré AES-256 + upload S3 EU. Rétention 30 jours.
- **PITR PostgreSQL :** WAL archivage en continu (RPO < 1h — NFR-030).
- **Redis :** RDB toutes les 15 min + AOF. Restauration < 5 min depuis dernier snapshot.
- **Runbook de reprise :** `docs/runbook-disaster-recovery.md`

---

## 16. Sécurité transverse

> Référence : `analysis/constraints.md` · OWASP Top 10:2025

### 16.1 Contrôle d'accès (OWASP #1 + SSRF)

- Symfony Security Voters sur **chaque ressource API Platform** : `ArticleVoter`, `SynthesisVoter`, `BriefVoter`.
- Deny by default : toute opération non explicitement autorisée retourne HTTP 403.
- UUIDs v4 non séquentiels sur toutes les entités (pas d'ID prédictible) — générés côté application via `symfony/uid` (`Uuid::v4()`). Voir ADR-006 pour la justification du choix v4 vs v7 (note : BC-COMPTES documente uuid7, à trancher en ADR-008bis).
- SSRF : les URLs de sources RSS sont validées côté serveur (protocole HTTPS uniquement, pas d'IP privée, pas de `localhost`, liste blanche de domaines si possible).
- SSRF — `POST /api/syntheses` : l'endpoint accepte un `article_id` (UUID interne), jamais une URL externe. Le contenu est récupéré depuis PostgreSQL. Toute tentative de passer une URL arbitraire est rejetée par la validation Symfony Validator (UUID format strict). Cela neutralise le vecteur SSRF sur ce endpoint.

### 16.2 Authentification (OWASP #2 + #8)

| Mécanisme | Implémentation |
|-----------|----------------|
| Mots de passe | Argon2id (128 MiB RAM, t=3, p=1) — `sodium_crypto_pwhash` PHP |
| JWT mobile | Ed25519 (EdDSA) — access 15 min, refresh 7 jours — `flutter_secure_storage` |
| Session desktop | HttpOnly, Secure, SameSite=Strict, TTL 30 min |
| OAuth2 | KnpU OAuth2 Client Bundle (Google, GitHub) |
| Biométrie | Déverrouille le refresh token local (ne remplace pas l'auth serveur) |
| Anti-brute force | Rate limit Redis : 5 tentatives / 15 min / IP (→ CAPTCHA) |
| API Keys | SHA-256 de la clé stockée (jamais la clé en clair), affichée une seule fois |

### 16.3 Injection (OWASP #3)

- **Doctrine ORM uniquement** pour toutes les requêtes SQL. Aucune concaténation SQL.
- Symfony Validator sur tous les inputs (whitelist, type, format, longueur).
- Escape systématique des outputs Twig (auto-escape activé).
- Validation des flux RSS : contenu parsé par FeedIo (entités HTML décodées, pas d'injection).

### 16.4 Headers de sécurité (NFR-015)

Configurés dans `Caddyfile` (FrankenPHP) :
```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self' https://api.briefly.ai; frame-ancestors 'none'; upgrade-insecure-requests
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Embedder-Policy: require-corp
Cross-Origin-Resource-Policy: same-origin
```

### 16.5 Supply Chain (OWASP #6 — NFR-014)

- Composer : toutes les dépendances pinées sur version exacte (`composer.lock` commité).
- `composer audit` en CI — bloquant si CVE critique.
- Trivy scan sur l'image Docker à chaque build — bloquant si HIGH/CRITICAL.
- SBOM CycloneDX généré automatiquement à chaque build : `syft briefly/app:latest -o cyclonedx-json > sbom.json`.
- Dependabot activé (PR auto pour mises à jour de sécurité).

---

## 17. Conformité RGPD et AI Act

### 17.1 Flux des données personnelles

```
Donnée personnelle → Collectée → Stockée (EU, Hetzner/OVH) → Traitée
                                                              │
                     ┌────────────────────────────────────────┤
                     │           Sous-traitants               │
                     ├────────────────────────────────────────┤
                     │ Mistral (EU, DPA) — synthèses IA      │
                     │ Stripe (DPA) — paiements              │
                     │ Postmark / SendGrid (DPA) — emails    │
                     └────────────────────────────────────────┘

Ce qui N'EST PAS transmis aux LLM :
  - email, user.id, IP, historique de lecture
  - tout contenu lié à un utilisateur identifiable
```

### 17.2 Droits des utilisateurs

| Droit RGPD | Implémentation | Délai |
|-----------|----------------|-------|
| Consentement | CMP à l'inscription (granulaire : analytique, marketing, notifs) | Immédiat |
| Accès | `GET /api/me/data-export` → JSON structuré | Immédiat (< 30s) |
| Portabilité | Export JSON `FR-038` | Immédiat |
| Rectification | `PUT /api/me/preferences` | Immédiat |
| Effacement | `DELETE /api/me` → soft delete + hard delete J+30 | 30 jours max |
| Opposition | Interrupteurs granulaires (`/settings/privacy`) | Immédiat |

### 17.3 Commande de suppression en cascade (RGPD — FR-037)

```php
// Symfony Command : app:gdpr:delete-user {userId}
// Déclenché à J+30 après soft delete (Scheduler)

class DeleteUserCommand extends Command {
    // Supprime dans l'ordre (FK) :
    // 1. consent_records
    // 2. api_keys
    // 3. reading_history (si table future)
    // 4. subscriptions (sans supprimer stripe_events — obligation comptable)
    // 5. users (hard delete)
    // Redis : DEL quota:{userId}:* session:{userId}:*
}
```

### 17.4 AI Act — Transparence

Conformité **Systèmes IA à risque limité** (Article 52, AI Act) :
- Badge visuel obligatoire "BRIEFLY AI:" sur toute synthèse générée.
- Page `/ai-transparency` publique décrivant les modèles utilisés, leur rôle, leurs limites.
- Conservation des logs de génération (provider, modèle, timestamp) sans données utilisateur — preuve d'audit.

---

## 18. Correspondance FR/NFR ↔ Décisions techniques

| ID PRD | Exigence | Décision technique | Fichiers concernés |
|--------|----------|-------------------|--------------------|
| FR-001 | Daily Brief automatique 3 histoires | GenerateBriefTask (Scheduler) + HDBSCAN | BC-BRIEF |
| FR-005 | Régénération horaire 5h-22h | Scheduler récurrent + Redis lock anti-doublon | BC-BRIEF |
| FR-010 | SSR SEO | FrankenPHP + Twig + ETag | Couche Présentation |
| FR-011 | Synthèse IA Mistral EU | MistralProvider + SynthesisProviderChain | BC-SYNTHÈSE IA |
| FR-012 | Badge émeraude + lien source | Obligation domaine SynthesisContent VO | BC-SYNTHÈSE IA |
| FR-013 | Cache Redis 24h | RedisSynthesisCache | §9 Cache Redis |
| FR-014/015 | Quota Free (3/j) / Premium illimité | RedisQuotaTracker + QuotaVoter | BC-COMPTES |
| FR-017 | Fallback OpenAI | OpenAIFallbackProvider + Circuit breaker | §8.1 |
| FR-021/022 | Ingestion RSS + dédup | FeedIo + SHA-256 + SimHash | BC-INGESTION |
| FR-023 | Circuit breaker source | RedisCircuitBreaker | §7.3 |
| FR-029 | Auth Argon2id + OAuth2 | KnpU OAuth2 + sodium_crypto_pwhash | BC-COMPTES |
| FR-030 | JWT mobile EdDSA | lexik/jwt-authentication-bundle | BC-COMPTES |
| FR-031 | Session desktop HttpOnly | Symfony Session + FrankenPHP | BC-COMPTES |
| FR-034 | Stripe Billing | StripeGateway + worker_billing | BC-COMPTES |
| FR-037 | Droit à l'oubli | app:gdpr:delete-user (Scheduler J+30) | §17.3 |
| FR-042 | On-device opt-in | PhiOnDeviceAdapter (Flutter, opt-in) | §8.5 |
| FR-047 | OpenAPI auto-générée | API Platform 4 native | §6 |
| NFR-001 | TTI < 1,5s | FrankenPHP worker mode + ETag + Twig cache | §15.1 |
| NFR-002 | API < 200ms P95 | Redis cache + Doctrine index | §9 + §5.1 |
| NFR-006/007 | 500 sources/h, 10k articles/h | Workers horizontaux Messenger | §7 + §15.2 |
| NFR-010 | Hit rate ≥ 80% | Warm-up nocturne + TTL 24h | §9.3 |
| NFR-011 | Deny by default | Symfony Voters | §16.1 |
| NFR-012 | Argon2id + EdDSA | PHP sodium + lexik JWT | §16.2 |
| NFR-014 | SBOM + CVE scan | Trivy + syft en CI | §14.1 |
| NFR-015 | Headers sécurité | Caddyfile FrankenPHP | §16.4 |
| NFR-019 | AI Act transparence | Badge obligatoire + /ai-transparency | §17.4 |
| NFR-021/022 | i18n fr+en | symfony/translation ICU + ARB Flutter | §10 |

---

## 19. Décisions d'architecture (ADR)

> Répertoire : `docs/adr/`
> Format : `docs/adr/XXXX-titre.md` (Keep-a-Decision-Log)

| ID ADR | Titre | Statut | Fichier |
|--------|-------|--------|---------|
| ADR-001 | Choix du frontend desktop (Twig+Turbo vs SPA) | **Créé** | `docs/adr/ADR-001-frontend-desktop-twig-turbo.md` |
| ADR-002 | Choix Flutter pour l'application mobile | **Créé** | `docs/adr/ADR-002-mobile-flutter.md` |
| ADR-003 | Pipeline d'ingestion RSS (FeedIo + Messenger + déduplication) | **Créé** | `docs/adr/ADR-003-ingestion-feedio-messenger.md` |
| ADR-004 | IA hybride — Mistral EU (serveur) + Phi-3 Mini on-device (opt-in) | **Créé** | `docs/adr/ADR-004-ia-hybride-on-device.md` |
| ADR-005 | Authentification — OAuth2, JWT EdDSA mobile, session desktop, biométrie | **Créé** | `docs/adr/ADR-005-authentification.md` |
| ADR-006 | Datastores — PostgreSQL 16 + Redis 7 | **Créé** | `docs/adr/ADR-006-datastore-postgres-redis.md` |
| ADR-007 | API Platform 4 REST/OpenAPI + rate limiting | **Créé** | `docs/adr/ADR-007-api-platform-rest.md` |
| ADR-008 | Architecture hexagonale + DDD — couches et règles d'import | **À créer** | `docs/adr/ADR-008-hexagonal-ddd.md` |
| ADR-009 | Clustering HDBSCAN pour la sélection des histoires | **À créer** | `docs/adr/ADR-009-clustering-hdbscan.md` |
| ADR-010 | Politique de rétention des données (90j articles, 30j suppression RGPD) | **À créer** | `docs/adr/ADR-010-data-retention.md` |

> **Note :** ADR-006 documente les UUIDs v4 mais BC-COMPTES (§3.2) et le schéma SQL (§5.1) référencent uuid7. Cette incohérence est à trancher en ADR-008 ou par amendment à ADR-006 avant Sprint 1.

Chaque ADR documente : contexte, décision, alternatives considérées, conséquences positives et négatives.

---

*Spécification technique maintenue par le Tech Lead (CSM). Toute modification structurelle (nouvelle couche, nouveau bounded context, changement de provider LLM) doit faire l'objet d'un ADR et être discutée en Sprint Review ou Backlog Refinement avant implémentation.*

*Prochaine révision planifiée : Sprint 1 Review (2026-08-10) — validation Walking Skeleton.*
