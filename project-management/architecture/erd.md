# ERD — Modèle de données PostgreSQL — Briefly AI

**Version :** 1.0.0
**Date :** 2026-07-28
**Références :** `tech-spec.md §5` · `constitution.md §3` · `architecture/design-review.md`
**Statut :** Draft — T-PRE-01 (UUID v4/v7) à valider avant première migration Doctrine

> Corrections intégrées : **C-03** (refresh_tokens), **G-01** (reading_history — absent du tech-spec §5, requis avant Sprint 3/EPIC-007).
> Gap résiduel actif : **G-02** (UUID v4 vs v7) — voir §11.
> Le terme **Summary** est utilisé ici pour l'entité de synthèse IA (nommée `syntheses` dans le SQL tech-spec, `Summary` dans le domain model — alignement §4.6 design-review C-04).

---

## Table des matières

1. [Diagramme ERD (Mermaid)](#1-diagramme-erd-mermaid)
2. [BC-COMPTES / BILLING](#2-bc-comptes--billing)
3. [BC-INGESTION — Sources, Feeds, Articles](#3-bc-ingestion--sources-feeds-articles)
4. [BC-BRIEF — Daily Brief](#4-bc-brief--daily-brief)
5. [BC-SYNTHÈSE IA — Summaries](#5-bc-synthèse-ia--summaries)
6. [BC-NOTIFICATIONS](#6-bc-notifications)
7. [BC-ANALYTICS / PRIVACY — Saved, History, Consent](#7-bc-analytics--privacy)
8. [Stratégie d'index](#8-stratégie-dindex)
9. [Schémas JSONB](#9-schémas-jsonb)
10. [RGPD — Rétention et anonymisation](#10-rgpd--rétention-et-anonymisation)
11. [UUID — Stratégie v4 vs v7 (Gap G-02)](#11-uuid--stratégie-v4-vs-v7)

---

## 1. Diagramme ERD (Mermaid)

```mermaid
erDiagram
    %% ══════════════════════════════════════════════════
    %% BC-COMPTES / BILLING
    %% ══════════════════════════════════════════════════

    USER {
        uuid id pk "UUID v4 — exposé publiquement"
        varchar email uk "320 max NOT NULL"
        text password_hash "Argon2id — NULL si OAuth uniquement"
        varchar plan "free|premium_monthly|premium_annual"
        char preferred_lang "CHAR(2) DEFAULT fr"
        varchar timezone "DEFAULT Europe/Paris"
        timestamptz created_at
        timestamptz deleted_at "soft delete RGPD — hard delete J+30"
    }

    PROFILE {
        uuid id pk
        uuid user_id fk uk "ON DELETE CASCADE"
        varchar display_name "100"
        text avatar_url
        jsonb preferences "topics ui_theme notification_time"
        timestamptz updated_at
    }

    OAUTH_ACCOUNT {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        varchar provider "google|github — 20"
        text subject "ID utilisateur chez le provider"
        varchar provider_email "320"
        timestamptz created_at
    }

    REFRESH_TOKEN {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        char token_hash uk "CHAR(64) SHA-256 jamais en clair"
        uuid family_id "chaine rotation detection vol"
        boolean revoked "DEFAULT FALSE"
        timestamptz expires_at "J+7"
        timestamptz created_at
    }

    SUBSCRIPTION {
        uuid id pk
        uuid user_id fk "NOT NULL"
        varchar stripe_customer_id "100 NOT NULL"
        varchar stripe_sub_id uk "100 NOT NULL"
        varchar plan "30 NOT NULL"
        varchar status "active|past_due|canceled|trialing"
        timestamptz current_period_end "NOT NULL"
        timestamptz created_at
    }

    QUOTA_USAGE {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        date usage_date "NOT NULL"
        smallint synthesis_count "DEFAULT 0"
    }

    API_TOKEN {
        uuid id pk "UUID v4 securite"
        uuid user_id fk "ON DELETE CASCADE"
        char key_hash uk "CHAR(64) SHA-256 jamais en clair"
        varchar name "100 NOT NULL"
        jsonb scopes "DEFAULT [read]"
        timestamptz last_used_at
        timestamptz revoked_at
        timestamptz expires_at
        timestamptz created_at
    }

    PRIVACY_SETTING {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        varchar scope "analytics|marketing|notifications|on_device_ai — 50"
        boolean granted "NOT NULL"
        char ip_hash "CHAR(64) pseudonymise audit legal"
        timestamptz recorded_at "NOT NULL"
    }

    STRIPE_EVENT {
        uuid id pk
        varchar event_id uk "100 idempotence Stripe"
        varchar event_type "100 NOT NULL"
        jsonb payload "raw Stripe event"
        boolean processed "DEFAULT FALSE"
        timestamptz received_at
    }

    %% ══════════════════════════════════════════════════
    %% BC-INGESTION
    %% ══════════════════════════════════════════════════

    CATEGORY {
        uuid id pk
        varchar slug uk "50 NOT NULL"
        varchar name_fr "100 NOT NULL"
        varchar name_en "100 NOT NULL"
        smallint sort_order "DEFAULT 0"
    }

    SOURCE {
        uuid id pk
        uuid category_id fk
        varchar name "255 NOT NULL"
        text website_url
        boolean is_active "DEFAULT TRUE"
        boolean is_premium "indexation contractuelle uniquement"
        jsonb metadata "description logo_url country"
        timestamptz created_at
    }

    FEED {
        uuid id pk
        uuid source_id fk "ON DELETE CASCADE"
        text feed_url uk "NOT NULL"
        smallint fetch_interval "minutes DEFAULT 15"
        varchar circuit_state "closed|open|half_open DEFAULT closed"
        smallint error_count "DEFAULT 0"
        varchar etag "255"
        varchar last_modified "255"
        timestamptz last_fetched_at
        timestamptz created_at
    }

    ARTICLE {
        uuid id pk "UUID v7 recommande G-02 — fort volume"
        uuid feed_id fk "NOT NULL"
        uuid source_id fk "denorm perf requetes"
        uuid category_id fk
        uuid canonical_article_id fk "self-ref doublon niv.2"
        text url_canonical "NOT NULL"
        char url_sha256 uk "CHAR(64) NOT NULL — dedup niv.1"
        bigint sim_hash "SimHash 64-bit titre — dedup niv.2"
        text title "NOT NULL"
        text summary
        text content_snippet "500 chars clustering HDBSCAN"
        char lang "CHAR(2) DEFAULT en"
        timestamptz published_at
        timestamptz fetched_at "NOT NULL DEFAULT NOW()"
        boolean is_duplicate "DEFAULT FALSE"
        jsonb metadata "author image_url original_tags"
        timestamptz archived_at "scheduler retention 90j"
    }

    TOPIC {
        uuid id pk
        uuid category_id fk
        varchar slug "100"
        varchar name_fr "200"
        varchar name_en "200"
        char lang "CHAR(2) DEFAULT en"
        decimal trending_score "DECIMAL(5,4) DEFAULT 0"
        integer article_count "denorm DEFAULT 0"
    }

    ARTICLE_TOPIC {
        uuid article_id pk "FK ARTICLE ON DELETE CASCADE"
        uuid topic_id pk "FK TOPIC ON DELETE CASCADE"
        decimal relevance_score "DECIMAL(3,2)"
    }

    %% ══════════════════════════════════════════════════
    %% BC-BRIEF
    %% ══════════════════════════════════════════════════

    DAILY_BRIEF {
        uuid id pk
        date brief_date uk "NOT NULL slug /brief/YYYY-MM-DD"
        char lang "CHAR(2) DEFAULT fr"
        varchar status "draft|published DEFAULT draft"
        timestamptz generated_at
        timestamptz updated_at
    }

    BRIEF_ITEM {
        uuid id pk
        uuid brief_id fk "ON DELETE CASCADE"
        uuid story_id fk "NOT NULL"
        smallint rank "CHECK IN 1 2 3 — NOT NULL"
    }

    STORY {
        uuid id pk
        text editorial_title "NOT NULL"
        smallint cluster_size "NOT NULL DEFAULT 1"
        jsonb metadata "hdbscan scores embedding_centroid"
        timestamptz last_updated_at "NOT NULL"
        timestamptz created_at
    }

    STORY_ARTICLE {
        uuid story_id pk "FK STORY ON DELETE CASCADE"
        uuid article_id pk "FK ARTICLE"
        boolean is_primary "article principal du cluster DEFAULT FALSE"
    }

    %% ══════════════════════════════════════════════════
    %% BC-SYNTHESE IA
    %% ══════════════════════════════════════════════════

    SUMMARY {
        uuid id pk "UUID v7 recommande G-02 — fort volume"
        uuid article_id fk "nullable — article OU story obligatoire"
        uuid story_id fk "nullable — article OU story obligatoire"
        varchar level "CONCISE|DETAILED|NARRATIVE — 20 NOT NULL"
        text content "NOT NULL"
        varchar provider "mistral|openai|phi_ondevice — 50 NOT NULL"
        boolean on_device "DEFAULT FALSE"
        char cache_key uk "CHAR(64) SHA-256(entity_id + level)"
        timestamptz generated_at "NOT NULL"
        timestamptz expires_at "retention 90j"
    }

    %% ══════════════════════════════════════════════════
    %% BC-NOTIFICATIONS
    %% ══════════════════════════════════════════════════

    NOTIFICATION {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        uuid brief_id fk "nullable"
        varchar type "daily_brief|account|marketing — 30"
        varchar channel "push|email — 20"
        text title "NOT NULL"
        text body
        varchar status "pending|sent|failed|read DEFAULT pending"
        timestamptz sent_at
        timestamptz read_at
    }

    NOTIFICATION_PREFERENCE {
        uuid id pk
        uuid user_id fk uk "ON DELETE CASCADE"
        boolean daily_brief_push "DEFAULT TRUE"
        boolean daily_brief_email "DEFAULT FALSE"
        time send_time "DEFAULT 07:30"
        varchar timezone "50 DEFAULT Europe/Paris"
        timestamptz updated_at
    }

    %% ══════════════════════════════════════════════════
    %% SAVED ITEMS / READING HISTORY
    %% ══════════════════════════════════════════════════

    SAVED_ITEM {
        uuid id pk
        uuid user_id fk "ON DELETE CASCADE"
        uuid article_id fk "ON DELETE CASCADE"
        timestamptz saved_at "NOT NULL"
        jsonb tags "etiquettes utilisateur"
        text notes
    }

    READING_HISTORY {
        uuid id pk "UUID v7 time-ordered reads"
        uuid user_id fk "ON DELETE CASCADE — RGPD G-01"
        uuid article_id fk "ON DELETE CASCADE"
        timestamptz read_at "NOT NULL"
        smallint progress_pct "0-100 scroll progress"
    }

    %% ══════════════════════════════════════════════════
    %% RELATIONS — BC-COMPTES / BILLING
    %% ══════════════════════════════════════════════════

    USER ||--o| PROFILE : "possede"
    USER ||--o{ OAUTH_ACCOUNT : "authentifie via"
    USER ||--o{ REFRESH_TOKEN : "detient"
    USER ||--o{ SUBSCRIPTION : "souscrit"
    USER ||--o{ QUOTA_USAGE : "consomme par jour"
    USER ||--o{ API_TOKEN : "possede"
    USER ||--o{ SAVED_ITEM : "sauvegarde"
    USER ||--o{ READING_HISTORY : "lit"
    USER ||--o{ NOTIFICATION : "recoit"
    USER ||--o| NOTIFICATION_PREFERENCE : "configure"
    USER ||--o{ PRIVACY_SETTING : "controle"

    %% ══════════════════════════════════════════════════
    %% RELATIONS — BC-INGESTION
    %% ══════════════════════════════════════════════════

    CATEGORY ||--o{ SOURCE : "classe"
    CATEGORY ||--o{ ARTICLE : "classe"
    CATEGORY ||--o{ TOPIC : "organise"
    SOURCE ||--|{ FEED : "expose via"
    FEED ||--o{ ARTICLE : "contient"
    ARTICLE }o--o| ARTICLE : "est doublon de (self-ref)"
    ARTICLE ||--o{ ARTICLE_TOPIC : "etiquete"
    TOPIC ||--o{ ARTICLE_TOPIC : "applique a"

    %% ══════════════════════════════════════════════════
    %% RELATIONS — BC-BRIEF
    %% ══════════════════════════════════════════════════

    DAILY_BRIEF ||--|{ BRIEF_ITEM : "contient exactement 3"
    STORY ||--o{ BRIEF_ITEM : "reference par"
    STORY ||--|{ STORY_ARTICLE : "regroupe"
    ARTICLE ||--o{ STORY_ARTICLE : "participe a"

    %% ══════════════════════════════════════════════════
    %% RELATIONS — BC-SYNTHESE IA
    %% ══════════════════════════════════════════════════

    ARTICLE ||--o{ SUMMARY : "synthetise par"
    STORY ||--o{ SUMMARY : "synthetise par"

    %% ══════════════════════════════════════════════════
    %% RELATIONS — BC-NOTIFICATIONS
    %% ══════════════════════════════════════════════════

    DAILY_BRIEF ||--o{ NOTIFICATION : "notifie via"

    %% ══════════════════════════════════════════════════
    %% RELATIONS — CROSS-BC
    %% ══════════════════════════════════════════════════

    ARTICLE ||--o{ SAVED_ITEM : "sauvegarde"
    ARTICLE ||--o{ READING_HISTORY : "trace"
```

---

## 2. BC-COMPTES / BILLING

### users

Table d'identité centrale. UUID v4 (non séquentiel) pour éviter l'énumération. Soft delete RGPD : `deleted_at` positionné à `NOW()` ; un Scheduler purge en hard delete J+30 avec cascade sur toutes les tables enfants.

```sql
CREATE TABLE users (
    id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,  -- UUID v4
    email           VARCHAR(320) NOT NULL,
    password_hash   TEXT,                                         -- Argon2id (128 MiB, t=3, p=1) — NULL si OAuth uniquement
    plan            VARCHAR(20)  NOT NULL DEFAULT 'free',         -- free | premium_monthly | premium_annual
    preferred_lang  CHAR(2)      NOT NULL DEFAULT 'fr',
    timezone        VARCHAR(50)  NOT NULL DEFAULT 'Europe/Paris',
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ,                                  -- soft delete RGPD
    CONSTRAINT uq_users_email UNIQUE (email)
);
CREATE INDEX idx_users_email        ON users (email);
CREATE INDEX idx_users_active       ON users (id) WHERE deleted_at IS NULL;   -- partial index
CREATE INDEX idx_users_plan_active  ON users (plan) WHERE deleted_at IS NULL;
```

**Soft-delete vs anonymisation :** `deleted_at` déclenche l'accès restreint immédiat. Hard delete J+30 efface la ligne et cascade les FK. Les `consent_records` (privacy_settings) sont pseudonymisés (email remplacé par hash SHA-256 de l'email) puis conservés 3 ans (obligation légale RGPD Art. 7).

---

### profiles

Données de présentation séparées de l'identité pour respecter SRP et faciliter la pseudonymisation.

```sql
CREATE TABLE profiles (
    id           UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id      UUID NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    display_name VARCHAR(100),
    avatar_url   TEXT,
    preferences  JSONB NOT NULL DEFAULT '{}',   -- voir §9 schéma JSONB
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

### oauth_accounts

Un utilisateur peut avoir plusieurs comptes OAuth (Google + GitHub). Séparation de la table `users` pour permettre la liaison/déliaison sans modifier les credentials email.

```sql
CREATE TABLE oauth_accounts (
    id             UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id        UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider       VARCHAR(20) NOT NULL,          -- google | github
    subject        TEXT        NOT NULL,          -- provider user ID (sub du JWT OAuth)
    provider_email VARCHAR(320),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_oauth_provider_subject UNIQUE (provider, subject)
);
CREATE INDEX idx_oauth_user ON oauth_accounts (user_id);
```

---

### refresh_tokens

Ajout suite à la correction **C-03** (design-review). Rotation systématique à chaque rafraîchissement. Détection de vol : si un token révoqué est présenté, toute la famille (`family_id`) est invalidée immédiatement.

```sql
CREATE TABLE refresh_tokens (
    id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID    NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL,               -- SHA-256 du token — jamais en clair
    family_id   UUID    NOT NULL,                -- identifie la chaîne de rotation
    revoked     BOOLEAN NOT NULL DEFAULT FALSE,
    expires_at  TIMESTAMPTZ NOT NULL,            -- J+7 depuis la création
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_refresh_token_hash UNIQUE (token_hash)
);
CREATE INDEX idx_refresh_tokens_family  ON refresh_tokens (family_id);
CREATE INDEX idx_refresh_tokens_user    ON refresh_tokens (user_id) WHERE revoked = FALSE;
```

---

### subscriptions

Historique complet des abonnements Stripe. Un utilisateur peut avoir plusieurs lignes (changement de plan, annulation, ré-abonnement).

```sql
CREATE TABLE subscriptions (
    id                  UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id             UUID        NOT NULL REFERENCES users(id),
    stripe_customer_id  VARCHAR(100) NOT NULL,
    stripe_sub_id       VARCHAR(100) NOT NULL,
    plan                VARCHAR(30)  NOT NULL,   -- premium_monthly | premium_annual
    status              VARCHAR(30)  NOT NULL,   -- active | past_due | canceled | trialing
    current_period_end  TIMESTAMPTZ  NOT NULL,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_stripe_sub UNIQUE (stripe_sub_id)
);
CREATE INDEX idx_subscriptions_user   ON subscriptions (user_id, status);
CREATE INDEX idx_subscriptions_period ON subscriptions (current_period_end) WHERE status = 'active';
```

---

### quota_usages

Snapshot journalier PostgreSQL de la consommation. **Redis est la source autoritaire temps réel** (TTL reset minuit UTC). Ce tableau assure la traçabilité RGPD et permet les reconciliations.

```sql
CREATE TABLE quota_usages (
    id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id         UUID     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    usage_date      DATE     NOT NULL,
    synthesis_count SMALLINT NOT NULL DEFAULT 0,
    CONSTRAINT uq_quota_user_date UNIQUE (user_id, usage_date)
);
CREATE INDEX idx_quota_user_date ON quota_usages (user_id, usage_date DESC);
```

---

### api_tokens

UUID v4 pour les API keys exposées publiquement. Le token réel n'est affiché qu'une seule fois à la création ; seul son SHA-256 est stocké.

```sql
CREATE TABLE api_tokens (
    id           UUID DEFAULT gen_random_uuid() PRIMARY KEY,  -- UUID v4
    user_id      UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key_hash     CHAR(64)    NOT NULL,    -- SHA-256 — UNIQUE, jamais en clair
    name         VARCHAR(100) NOT NULL,
    scopes       JSONB        NOT NULL DEFAULT '["read"]',
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    expires_at   TIMESTAMPTZ,            -- NULL = pas d'expiration
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_api_token_hash UNIQUE (key_hash)
);
CREATE INDEX idx_api_tokens_user   ON api_tokens (user_id) WHERE revoked_at IS NULL;
CREATE INDEX idx_api_tokens_lookup ON api_tokens (key_hash) WHERE revoked_at IS NULL;
```

---

### privacy_settings

Historique de consentement RGPD par scope (Art. 7 — preuve du consentement). Une nouvelle ligne est insérée à chaque modification ; la ligne la plus récente par `(user_id, scope)` fait foi.

```sql
CREATE TABLE privacy_settings (
    id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope       VARCHAR(50) NOT NULL,   -- analytics | marketing | notifications | on_device_ai
    granted     BOOLEAN     NOT NULL,
    ip_hash     CHAR(64),               -- SHA-256(IP) pseudonymisé — audit légal
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_privacy_user_scope ON privacy_settings (user_id, scope, recorded_at DESC);
```

---

### stripe_events

Table d'idempotence des webhooks Stripe. Pas de FK vers `users` (Stripe génère des événements avant que l'utilisateur existe en base dans certains flows).

```sql
CREATE TABLE stripe_events (
    id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    event_id    VARCHAR(100) NOT NULL,     -- ID Stripe (evt_xxx) — idempotence
    event_type  VARCHAR(100) NOT NULL,
    payload     JSONB        NOT NULL,     -- raw event Stripe
    processed   BOOLEAN      NOT NULL DEFAULT FALSE,
    received_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_stripe_event UNIQUE (event_id)
);
CREATE INDEX idx_stripe_events_unprocessed ON stripe_events (received_at) WHERE processed = FALSE;
```

**Rétention :** 7 ans (comptabilité). Archivage froid après 1 an.

---

## 3. BC-INGESTION — Sources, Feeds, Articles

### categories

Table de référence des thématiques éditoriales. Bilingue FR/EN (i18n T8).

```sql
CREATE TABLE categories (
    id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    slug        VARCHAR(50)  NOT NULL,   -- tech | finance | science | geopolitique | sante | culture | sport
    name_fr     VARCHAR(100) NOT NULL,
    name_en     VARCHAR(100) NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    CONSTRAINT uq_category_slug UNIQUE (slug)
);
```

---

### sources

Le média/site d'origine. `is_premium = TRUE` signifie que la source n'est accessible que par indexation contractuelle (jamais de scraping — contrainte B3/R4).

```sql
CREATE TABLE sources (
    id          UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    category_id UUID REFERENCES categories(id),
    name        VARCHAR(255) NOT NULL,
    website_url TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    is_premium  BOOLEAN NOT NULL DEFAULT FALSE,
    metadata    JSONB   NOT NULL DEFAULT '{}',   -- description, logo_url, country, language
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_sources_category ON sources (category_id) WHERE is_active = TRUE;
```

---

### feeds

Un feed RSS/Atom associé à une source. Une source peut avoir plusieurs feeds (ex. : TechCrunch général + TechCrunch Startups). Porte l'état du circuit breaker et les headers ETag/Last-Modified pour la récupération conditionnelle.

```sql
CREATE TABLE feeds (
    id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    source_id       UUID        NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    feed_url        TEXT        NOT NULL,
    fetch_interval  SMALLINT    NOT NULL DEFAULT 15,    -- minutes
    circuit_state   VARCHAR(20) NOT NULL DEFAULT 'closed', -- closed | open | half_open
    error_count     SMALLINT    NOT NULL DEFAULT 0,
    etag            VARCHAR(255),
    last_modified   VARCHAR(255),
    last_fetched_at TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_feed_url UNIQUE (feed_url)
);
CREATE INDEX idx_feeds_source        ON feeds (source_id);
CREATE INDEX idx_feeds_next_fetch    ON feeds (last_fetched_at, fetch_interval)
    WHERE circuit_state = 'closed';   -- partial index scheduler
```

---

### articles

Entité centrale de l'ingestion. UUID v7 recommandé (G-02) pour les insertions à fort débit (10 000 art/h en pic — NFR-006/007). Deux niveaux de déduplication :

- **Niveau 1** : `url_sha256` — UNIQUE constraint PostgreSQL. SHA-256 de l'URL canonisée (strip UTM, normalisation).
- **Niveau 2** : `sim_hash` — SimHash 64-bit du titre tokenisé. Distance de Hamming ≤ 3 dans une fenêtre ±2h.

```sql
CREATE TABLE articles (
    id                   UUID DEFAULT gen_random_uuid() PRIMARY KEY, -- v7 recommandé
    feed_id              UUID NOT NULL REFERENCES feeds(id),
    source_id            UUID NOT NULL REFERENCES sources(id),        -- dénorm query perf
    category_id          UUID REFERENCES categories(id),
    canonical_article_id UUID REFERENCES articles(id),                -- self-ref doublon niv.2
    url_canonical        TEXT     NOT NULL,
    url_sha256           CHAR(64) NOT NULL,            -- SHA-256 URL canonique — dédup niv.1
    sim_hash             BIGINT,                        -- SimHash 64-bit titre — dédup niv.2
    title                TEXT     NOT NULL,
    summary              TEXT,                          -- résumé issu du feed
    content_snippet      TEXT,                          -- 500 premiers chars — clustering HDBSCAN
    lang                 CHAR(2)  NOT NULL DEFAULT 'en',
    published_at         TIMESTAMPTZ,
    fetched_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    is_duplicate         BOOLEAN  NOT NULL DEFAULT FALSE,
    metadata             JSONB    NOT NULL DEFAULT '{}', -- author, image_url, original_tags
    archived_at          TIMESTAMPTZ,                   -- Scheduler rétention 90j
    CONSTRAINT uq_article_url_sha256 UNIQUE (url_sha256)
);

-- Index déduplication (critiques — voir §8)
CREATE UNIQUE INDEX idx_articles_dedup_url
    ON articles (url_sha256);                            -- dédup niv.1 (couvert par UNIQUE)

CREATE INDEX idx_articles_dedup_simhash
    ON articles (sim_hash, fetched_at)
    WHERE is_duplicate = FALSE;                          -- dédup niv.2 — partial index

-- Index requêtes applicatives
CREATE INDEX idx_articles_feed_published
    ON articles (feed_id, published_at DESC);

CREATE INDEX idx_articles_category_published
    ON articles (category_id, published_at DESC)
    WHERE is_duplicate = FALSE;

CREATE INDEX idx_articles_lang_published
    ON articles (lang, published_at DESC)
    WHERE is_duplicate = FALSE;

CREATE INDEX idx_articles_retention
    ON articles (fetched_at DESC)
    WHERE archived_at IS NULL;                           -- Scheduler purge 90j
```

**Méta-données JSONB :** voir §9.

---

### topics

Sujets/thèmes identifiés par classification IA ou agrégation. `trending_score` mis à jour périodiquement par le Scheduler.

```sql
CREATE TABLE topics (
    id             UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    category_id    UUID REFERENCES categories(id),
    slug           VARCHAR(100) NOT NULL,
    name_fr        VARCHAR(200),
    name_en        VARCHAR(200),
    lang           CHAR(2) NOT NULL DEFAULT 'en',
    trending_score DECIMAL(5,4) NOT NULL DEFAULT 0,  -- 0.0000 à 9.9999
    article_count  INTEGER      NOT NULL DEFAULT 0,  -- dénormalisé, mis à jour en batch
    CONSTRAINT uq_topic_slug_lang UNIQUE (slug, lang)
);
CREATE INDEX idx_topics_trending ON topics (trending_score DESC) WHERE trending_score > 0;
CREATE INDEX idx_topics_category ON topics (category_id, trending_score DESC);
```

---

### article_topics (table de jointure)

```sql
CREATE TABLE article_topics (
    article_id      UUID NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    topic_id        UUID NOT NULL REFERENCES topics(id)   ON DELETE CASCADE,
    relevance_score DECIMAL(3,2),   -- 0.00 à 1.00
    PRIMARY KEY (article_id, topic_id)
);
CREATE INDEX idx_article_topics_topic ON article_topics (topic_id);
```

---

## 4. BC-BRIEF — Daily Brief

### daily_briefs

Une ligne par date par langue. `brief_date` est le slug public (`/brief/2026-07-28`).

```sql
CREATE TABLE daily_briefs (
    id           UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    brief_date   DATE        NOT NULL,     -- slug /brief/YYYY-MM-DD
    lang         CHAR(2)     NOT NULL DEFAULT 'fr',
    status       VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft | published
    generated_at TIMESTAMPTZ,
    updated_at   TIMESTAMPTZ,
    CONSTRAINT uq_brief_date_lang UNIQUE (brief_date, lang)
);
CREATE INDEX idx_daily_briefs_date ON daily_briefs (brief_date DESC) WHERE status = 'published';
```

---

### brief_items

Lien explicite entre un `DailyBrief` et ses 3 `Story` (rang 01, 02, 03 — INV-1). Entité séparée de `Story` pour permettre qu'un story soit réutilisé dans d'éventuels briefs futurs sans duplication.

```sql
CREATE TABLE brief_items (
    id       UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    brief_id UUID     NOT NULL REFERENCES daily_briefs(id) ON DELETE CASCADE,
    story_id UUID     NOT NULL REFERENCES stories(id),
    rank     SMALLINT NOT NULL CHECK (rank IN (1, 2, 3)),
    CONSTRAINT uq_brief_item_rank UNIQUE (brief_id, rank),
    CONSTRAINT uq_brief_item_story UNIQUE (brief_id, story_id)
);
CREATE INDEX idx_brief_items_brief ON brief_items (brief_id);
```

---

### stories (ArticleCluster)

Cluster d'articles représentant une histoire majeure. `editorial_title` est généré par Mistral. `metadata` stocke les paramètres du clustering HDBSCAN pour reproductibilité.

```sql
CREATE TABLE stories (
    id              UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    editorial_title TEXT        NOT NULL,
    cluster_size    SMALLINT    NOT NULL DEFAULT 1,
    metadata        JSONB       NOT NULL DEFAULT '{}', -- hdbscan_params, importance_score, embedding_centroid
    last_updated_at TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

### story_articles (table de jointure)

Relation many-to-many entre `Story` et `Article`. `is_primary` identifie l'article le plus représentatif du cluster (affiché en priorité dans l'UI).

```sql
CREATE TABLE story_articles (
    story_id   UUID    NOT NULL REFERENCES stories(id)   ON DELETE CASCADE,
    article_id UUID    NOT NULL REFERENCES articles(id),
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (story_id, article_id)
);
CREATE INDEX idx_story_articles_article ON story_articles (article_id);
CREATE INDEX idx_story_articles_primary ON story_articles (story_id) WHERE is_primary = TRUE;
```

---

## 5. BC-SYNTHÈSE IA — Summaries

### summaries

Table centrale de la synthèse IA. Une `Summary` peut être liée à un `Article` ou à un `Story` (la synthèse du brief complet). Contrainte CHECK pour garantir qu'au moins une FK est renseignée.

`cache_key` = SHA-256(`article_id` ou `story_id` + `level`) — utilisé pour la clé Redis (TTL 24h) et pour éviter les doublons en base.

```sql
CREATE TABLE summaries (
    id           UUID DEFAULT gen_random_uuid() PRIMARY KEY,  -- v7 recommandé
    article_id   UUID        REFERENCES articles(id),         -- nullable
    story_id     UUID        REFERENCES stories(id),          -- nullable
    level        VARCHAR(20) NOT NULL,   -- CONCISE | DETAILED | NARRATIVE
    content      TEXT        NOT NULL,
    provider     VARCHAR(50) NOT NULL,   -- mistral | openai | phi_ondevice
    on_device    BOOLEAN     NOT NULL DEFAULT FALSE,
    cache_key    CHAR(64)    NOT NULL,   -- SHA-256(entity_id + level) — clé Redis
    generated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at   TIMESTAMPTZ,           -- rétention 90j (liée à l'article référencé)
    CONSTRAINT chk_summary_entity CHECK (
        article_id IS NOT NULL OR story_id IS NOT NULL
    ),
    CONSTRAINT uq_summary_cache_key UNIQUE (cache_key)
);

CREATE INDEX idx_summaries_article ON summaries (article_id, level) WHERE article_id IS NOT NULL;
CREATE INDEX idx_summaries_story   ON summaries (story_id, level)   WHERE story_id IS NOT NULL;
CREATE INDEX idx_summaries_expiry  ON summaries (expires_at)        WHERE expires_at IS NOT NULL;
```

**Politique cache Redis :** `synthesis:{cache_key}` TTL 24h. Hit Redis = pas d'appel LLM, pas d'écriture PostgreSQL. Cache hit rate cible ≥ 80 % (NFR-010).

**Privacy :** aucun `user_id` dans cette table — les synthèses sont des ressources partagées, pas personnelles (INV-6 : aucun identifiant utilisateur dans les prompts LLM).

---

## 6. BC-NOTIFICATIONS

### notifications

```sql
CREATE TABLE notifications (
    id       UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id  UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    brief_id UUID        REFERENCES daily_briefs(id),   -- nullable
    type     VARCHAR(30) NOT NULL,   -- daily_brief | account | marketing
    channel  VARCHAR(20) NOT NULL,   -- push | email
    title    TEXT        NOT NULL,
    body     TEXT,
    status   VARCHAR(20) NOT NULL DEFAULT 'pending',    -- pending | sent | failed | read
    sent_at  TIMESTAMPTZ,
    read_at  TIMESTAMPTZ
);

CREATE INDEX idx_notifications_user   ON notifications (user_id, sent_at DESC);
CREATE INDEX idx_notifications_status ON notifications (status, sent_at)
    WHERE status IN ('pending', 'failed');
```

**Règle INV-5 :** maximum 1 notification push de type `daily_brief` par utilisateur par jour. Contrôle applicatif dans `NotificationService` + vérification index `(user_id, type, DATE(sent_at))`.

---

### notification_preferences

```sql
CREATE TABLE notification_preferences (
    id                 UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id            UUID    NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    daily_brief_push   BOOLEAN NOT NULL DEFAULT TRUE,
    daily_brief_email  BOOLEAN NOT NULL DEFAULT FALSE,
    send_time          TIME    NOT NULL DEFAULT '07:30',
    timezone           VARCHAR(50) NOT NULL DEFAULT 'Europe/Paris',
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## 7. BC-ANALYTICS / PRIVACY

### saved_items

```sql
CREATE TABLE saved_items (
    id         UUID DEFAULT gen_random_uuid() PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    article_id UUID        NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    saved_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    tags       JSONB       NOT NULL DEFAULT '[]',  -- ["tag1", "tag2"]
    notes      TEXT,
    CONSTRAINT uq_saved_item_user_article UNIQUE (user_id, article_id)
);

CREATE INDEX idx_saved_items_user ON saved_items (user_id, saved_at DESC);
```

---

### reading_history

Résolution du **Gap G-01** (design-review §3). Table absente du `tech-spec §5.1`, requise par FR-008 et US-060. Index `(user_id, read_at DESC)` pour pagination cursor-based.

```sql
CREATE TABLE reading_history (
    id           UUID DEFAULT gen_random_uuid() PRIMARY KEY,  -- UUID v7 time-ordered
    user_id      UUID        NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    article_id   UUID        NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    read_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    progress_pct SMALLINT    NOT NULL DEFAULT 0 CHECK (progress_pct BETWEEN 0 AND 100)
);

-- Index G-01 spécifié dans design-review §3/G-01
CREATE INDEX idx_reading_history_user_date
    ON reading_history (user_id, read_at DESC);

CREATE INDEX idx_reading_history_article
    ON reading_history (article_id);
```

**Rétention :** alignée sur les articles référencés. Suppression en cascade quand `users.deleted_at` → hard delete J+30 (RGPD droit à l'oubli). Les entrées dont l'article est archivé (90j) sont supprimées en même temps.

---

## 8. Stratégie d'index

### Index de déduplication (critiques pour performance ingestion)

| Index | Table | Colonnes | Type | Raison |
|-------|-------|----------|------|--------|
| `uq_article_url_sha256` | articles | `url_sha256` | UNIQUE B-tree | Dédup niveau 1 — ON CONFLICT DO NOTHING |
| `idx_articles_dedup_simhash` | articles | `(sim_hash, fetched_at)` WHERE `is_duplicate = FALSE` | Partial B-tree | Dédup niveau 2 — fenêtre ±2h, Hamming ≤ 3 |
| `uq_summary_cache_key` | summaries | `cache_key` | UNIQUE B-tree | Cache hit lookup O(1) |
| `uq_brief_item_rank` | brief_items | `(brief_id, rank)` | UNIQUE B-tree | Garantit exactement 3 rangs par brief |

### Index de performance applicative

| Index | Table | Colonnes | Type | Endpoint cible |
|-------|-------|----------|------|----------------|
| `idx_articles_category_published` | articles | `(category_id, published_at DESC)` WHERE `is_duplicate = FALSE` | Partial B-tree | `GET /api/articles?category=tech` |
| `idx_articles_lang_published` | articles | `(lang, published_at DESC)` WHERE `is_duplicate = FALSE` | Partial B-tree | Sélection brief par langue |
| `idx_daily_briefs_date` | daily_briefs | `brief_date DESC` WHERE `status = 'published'` | Partial B-tree | `GET /brief/{date}` TTI < 1,5s |
| `idx_reading_history_user_date` | reading_history | `(user_id, read_at DESC)` | B-tree | Pagination cursor US-060 |
| `idx_saved_items_user` | saved_items | `(user_id, saved_at DESC)` | B-tree | Bibliothèque US-072/073 |
| `idx_subscriptions_period` | subscriptions | `current_period_end` WHERE `status = 'active'` | Partial B-tree | Scheduler expiration Premium |
| `idx_users_active` | users | `id` WHERE `deleted_at IS NULL` | Partial B-tree | Voter `IsActiveUser` |
| `idx_topics_trending` | topics | `trending_score DESC` WHERE `trending_score > 0` | Partial B-tree | `GET /api/topics/trending` |

### Index anti-N+1

Pour `GET /api/briefs/{date}` (requête critique TTI < 1,5s), les relations `DailyBrief → BriefItem → Story → StoryArticle → Article` sont résolues par une seule requête avec `JOIN` et l'index `idx_brief_items_brief` + `idx_story_articles_primary`.

---

## 9. Schémas JSONB

### profiles.preferences

```json
{
  "topics": ["tech", "science", "finance"],
  "ui_theme": "dark",
  "notification_time": "07:30",
  "font_size": "medium",
  "show_source_logos": true
}
```

### articles.metadata

```json
{
  "author": "Jane Doe",
  "image_url": "https://cdn.example.com/image.jpg",
  "original_tags": ["AI", "startup"],
  "reading_time_min": 4,
  "paywall": false,
  "feed_item_id": "urn:example:12345"
}
```

### sources.metadata

```json
{
  "description": "Technology news and analysis",
  "logo_url": "https://example.com/logo.svg",
  "country": "US",
  "language": "en",
  "contact_email": "rss@example.com"
}
```

### stories.metadata

```json
{
  "hdbscan_min_cluster_size": 3,
  "importance_score": 0.847,
  "embedding_centroid": [0.123, -0.456, "..."],
  "article_ids_ranked": ["uuid1", "uuid2", "uuid3"]
}
```

### api_tokens.scopes

```json
["read"]              // lecture seule (Free)
["read", "synthesize"] // Premium — accès /v1/synthesize
```

---

## 10. RGPD — Rétention et anonymisation

| Table | Rétention active | Déclencheur | Action après délai |
|-------|------------------|-----------|--------------------|
| `users` | 30 j post `deleted_at` | `deleted_at IS NOT NULL` | Hard delete + CASCADE sur toutes FK `ON DELETE CASCADE` |
| `articles` | 90 j | `fetched_at + 90j` | `archived_at = NOW()` → soft archive → purge Scheduler |
| `summaries` | 90 j | `expires_at` | DELETE (cascade du côté article) |
| `reading_history` | 90 j (aligné articles) | CASCADE + Scheduler | DELETE on article purge ou user hard delete |
| `saved_items` | Durée de vie utilisateur | User hard delete | CASCADE |
| `refresh_tokens` | J+7 (`expires_at`) | Scheduler nightly | DELETE WHERE `expires_at < NOW()` |
| `quota_usages` | 90 j | Scheduler | DELETE pour analytics brutes |
| `privacy_settings` | 3 ans | Scheduler | Pseudonymisation (`ip_hash` gardé, `user_id` remplacé par hash) |
| `stripe_events` | 7 ans | Scheduler | Archivage cold storage |
| `notifications` | 12 mois | Scheduler | DELETE |
| `oauth_accounts` | Durée de vie utilisateur | User hard delete | CASCADE |

### Droit à l'oubli — cascade complète

```
DELETE FROM users WHERE id = :userId AND deleted_at < NOW() - INTERVAL '30 days'
-- Cascade automatique (ON DELETE CASCADE) vers :
--   profiles, oauth_accounts, refresh_tokens, quota_usages,
--   api_tokens, saved_items, reading_history, notifications,
--   notification_preferences
-- CASCADE PARTIELLE (FK sans CASCADE) :
--   subscriptions → mise à jour statut 'deleted' avant hard delete
--   privacy_settings → pseudonymisation, pas de suppression (obligation légale 3 ans)
```

### Soft-delete vs anonymisation

| Entité | Approche | Justification |
|--------|----------|---------------|
| `users` | Soft delete → hard delete J+30 | Délai légal raisonnable, désactivation immédiate |
| `articles` | Soft archive (`archived_at`) | Contenu éditorial — pas de PII |
| `privacy_settings` | Pseudonymisation, pas de suppression | Preuve de consentement Art. 7 RGPD — 3 ans |
| `summaries` | Expiration + purge (pas de PII) | Lié aux articles — aucune donnée personnelle |
| `reading_history` | Hard delete (CASCADE) | PII comportementale — droit à l'oubli strict |
| `notifications` | Hard delete 12 mois | Données de contact |

---

## 11. UUID — Stratégie v4 vs v7

> **Gap G-02** (design-review §3) — décision à formaliser dans **ADR-008** ou amendment ADR-006 avant la première migration Doctrine.

### Recommandation Tech Lead (design-review T-PRE-01)

| Type de table | UUID recommandé | Justification |
|---------------|-----------------|---------------|
| `articles`, `summaries`, `reading_history` | **UUID v7** (time-ordered) | Fort volume d'insertion (10 000 art/h). UUID v7 = B-tree friendly, insertions séquentielles, fragmentation minimale |
| `users`, `api_tokens`, `oauth_accounts`, `refresh_tokens` | **UUID v4** (aléatoire) | Exposé publiquement ou sensible. UUID v4 = résistant à l'énumération, pas de timestamp dans l'ID |
| Autres tables | UUID v4 (défaut `gen_random_uuid()`) | Volume modéré, sécurité suffisante |

### Implémentation Symfony

```php
// UUID v7 — Symfony UID component
use Symfony\Component\Uid\UuidV7;
$id = UuidV7::generate();  // time-ordered, Doctrine type 'uuid'

// UUID v4 — défaut Symfony UID
use Symfony\Component\Uid\UuidV4;
$id = UuidV4::generate();  // aléatoire pur

// Doctrine : les deux sont stockés en BINARY(16) ou UUID natif PostgreSQL
// PostgreSQL DEFAULT en SQL : gen_random_uuid() = v4, pas de v7 natif PostgreSQL 16
// → Générer v7 en PHP/Doctrine, pas en DEFAULT SQL
```

> **ADR-006 actuel :** UUID v4 (`gen_random_uuid()`). La stratégie hybride ci-dessus requiert un **amendment formel** — aucun changement ne doit être appliqué avant la décision officielle.

---

*Ce modèle couvre les 22 entités du domaine Briefly AI réparties sur 6 bounded contexts, intègre les corrections C-03 et G-01 du design-review, et définit une stratégie d'index déduplication à deux niveaux (SHA-256 URL + SimHash 64-bit) alignée sur les contraintes de débit NFR-006/007 (500 sources/h, 10 000 articles/h en pic).*
