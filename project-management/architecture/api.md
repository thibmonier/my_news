# Conception API — Briefly AI

**Version :** 1.0.0
**Date :** 2026-07-28
**Statut :** Référence normative (Tech Lead validé)
**Références :** `tech-spec.md §6`, `constitution.md §3/6`, `design-review.md §4`, ADR-005, ADR-006, ADR-007
**Stories couvertes :** EPIC-001 (US-001 à 007), EPIC-002 (US-010 à 016), EPIC-004 (US-030 à 036), EPIC-006 (US-050 à 055)

---

## Table des matières

1. [Vue d'ensemble et périmètre](#1-vue-densemble-et-périmètre)
2. [Espaces d'URL et versionnement](#2-espaces-durl-et-versionnement)
3. [Ressources API Platform 4](#3-ressources-api-platform-4)
4. [Authentification et contextes clients](#4-authentification-et-contextes-clients)
5. [Endpoints API privée /api](#5-endpoints-api-privée-api)
6. [Endpoints API publique /api/v1](#6-endpoints-api-publique-apiv1)
7. [Sécurité SSRF — protection /synthesize](#7-sécurité-ssrf--protection-synthesize)
8. [Contrôle d'accès — Voters Symfony](#8-contrôle-daccès--voters-symfony)
9. [Sérialisation et groupes](#9-sérialisation-et-groupes)
10. [Pagination par curseur](#10-pagination-par-curseur)
11. [Quotas, rate limiting et paywall](#11-quotas-rate-limiting-et-paywall)
12. [Format des erreurs (RFC 7807 — OWASP #7)](#12-format-des-erreurs-rfc-7807--owasp-7)
13. [Headers de réponse standard](#13-headers-de-réponse-standard)
14. [CORS](#14-cors)
15. [Flux OAuth2 Google / GitHub](#15-flux-oauth2-google--github)
16. [Flux JWT mobile (EdDSA)](#16-flux-jwt-mobile-edsa)
17. [Gestion des clés API](#17-gestion-des-clés-api)
18. [Documentation OpenAPI automatique](#18-documentation-openapi-automatique)
19. [Checklist sécurité API](#19-checklist-sécurité-api)

---

## 1. Vue d'ensemble et périmètre

Briefly AI expose **deux espaces d'API distincts** :

| Espace | Préfixe | Public cible | Authentification | Framework |
|--------|---------|-------------|-----------------|-----------|
| **API privée** | `/api/` | Clients desktop (Twig/Turbo) et Flutter | Session HttpOnly *ou* JWT EdDSA | API Platform 4 |
| **API publique v1** | `/api/v1/` | Développeurs tiers (P-003), intégrateurs B2B | Bearer API Key (SHA-256 hashed) | API Platform 4 |

Les deux espaces sont servis par **FrankenPHP** (worker mode, HTTP/2, HTTPS uniquement) sous le même conteneur applicatif Symfony 8. L'espace `/api/docs` expose la documentation OpenAPI 3.1 générée automatiquement par API Platform.

**Décisions d'architecture appliquées :**
- ADR-007 : API Platform REST + préfixe `/v1/` pour les endpoints publics
- ADR-005 : JWT EdDSA (Ed25519) pour mobile, session HttpOnly SameSite=Strict pour desktop
- ADR-006 : UUID v4 non séquentiels pour toutes les ressources exposées (`users`, `api_keys`) ; UUID v7 pour tables à fort volume (`articles`, `syntheses`) — voir T-PRE-01 design-review
- Constitution §6 : OWASP Top 10:2025 socle immuable ; SSRF bloqué par design sur `/synthesize`

---

## 2. Espaces d'URL et versionnement

### 2.1 Structure des préfixes

```
https://api.briefly.ai
├── /api/                     API privée (session + JWT)
│   ├── /api/register
│   ├── /api/login
│   ├── /api/auth/oauth/{provider}
│   ├── /api/token/refresh
│   ├── /api/me
│   ├── /api/articles
│   ├── /api/briefs
│   ├── /api/syntheses
│   ├── /api/webhook/stripe
│   └── /api/docs             OpenAPI UI (public, sans auth)
└── /api/v1/                  API publique Premium (Bearer API Key)
    ├── /api/v1/daily-brief
    ├── /api/v1/briefs
    ├── /api/v1/articles
    └── /api/v1/synthesize
```

### 2.2 Versionnement

- **Stratégie :** URL path (`/v1/`) pour les endpoints publics — explicite et compatible avec tous les clients HTTP
- **Header de réponse :** `API-Version: 1` présent sur chaque réponse `/api/v1/*`
- **Dépréciation :** headers RFC 8594 ajoutés si endpoint déprécié :

```http
Deprecation: true
Sunset: Mon, 31 Dec 2026 23:59:59 GMT
Link: </api/v2/daily-brief>; rel="successor-version"
API-Version: 1
```

- Les endpoints `/api/` (privés) ne portent pas de version dans l'URL ; les breaking changes y sont gérés par cycle de sprint et annoncés en avance aux clients mobile (canary releases Flutter).

### 2.3 Configuration API Platform 4

```yaml
# config/packages/api_platform.yaml
api_platform:
    title: 'Briefly AI API'
    version: '1.0.0'
    formats:
        jsonld: ['application/ld+json']
        json: ['application/json']
    docs_formats:
        jsonld: ['application/ld+json']
        jsonopenapi: ['application/vnd.openapi+json']
        html: ['text/html']
    defaults:
        pagination_enabled: true
        pagination_type: cursor
        pagination_items_per_page: 20
        pagination_maximum_items_per_page: 100
    exception_to_status:
        App\Domain\Exception\QuotaExceededException: 429
        App\Domain\Exception\InvalidCredentialsException: 401
        App\Domain\Exception\ArticleNotFoundException: 404
        App\Domain\Exception\SynthesisProviderUnavailableException: 503
    openapi:
        security_schemes:
            bearerAuth:
                type: http
                scheme: bearer
                bearerFormat: JWT
            apiKeyAuth:
                type: apiKey
                in: header
                name: X-API-Key
```

---

## 3. Ressources API Platform 4

### 3.1 Cartographie des ressources

| Ressource API Platform | Entité domaine | Préfixe | Opérations exposées |
|-----------------------|---------------|---------|---------------------|
| `BriefResource` | `DailyBrief` + `Story` | `/api/briefs`, `/api/v1/briefs`, `/api/v1/daily-brief` | `Get`, `GetCollection` |
| `ArticleResource` | `Article` | `/api/articles`, `/api/v1/articles` | `Get`, `GetCollection` |
| `SynthesisResource` | `Synthesis` | `/api/syntheses`, `/api/v1/synthesize` | `Post` |
| `UserResource` | `User` | `/api/me` | `Get`, `Put`, `Delete` |
| `AuthResource` | — (commande) | `/api/login`, `/api/register`, `/api/token/refresh` | `Post` (Custom StateProcessor) |
| `OAuthResource` | — (commande) | `/api/auth/oauth/{provider}` | `Post` |
| `ApiKeyResource` | `ApiKey` | `/api/me/api-keys` | `Get`, `GetCollection`, `Post`, `Delete` |
| `DataExportResource` | — (projection) | `/api/me/data-export` | `Get` (Custom StateProvider) |
| `StripeWebhookResource` | — (commande) | `/api/webhook/stripe` | `Post` (Custom StateProcessor, HMAC validé) |

### 3.2 Règles de conception ressources

- **Jamais de logique métier dans les StateProcessors** : les processors délèguent à un Application Service (use case) via Command/Query.
- **UUID v4 obligatoires dans les réponses** pour toutes les ressources exposées aux clients (`/api/` et `/api/v1/`).
- **Serialization groups** déclarés au niveau de l'attribut `#[ApiResource]` — jamais dans les entités domaine.
- **Voters** appelés dans les StateProviders/Processors avant toute opération.

### 3.3 Exemple de déclaration BriefResource

```php
// src/Infrastructure/Api/Resource/BriefResource.php
#[ApiResource(
    shortName: 'Brief',
    operations: [
        new GetCollection(
            uriTemplate: '/briefs',
            paginationType: 'cursor',
            security: 'is_granted("ROLE_USER") or is_granted("API_KEY")',
            normalizationContext: ['groups' => ['brief:list']],
            openapiContext: ['tags' => ['Briefs'], 'summary' => 'List all daily briefs'],
        ),
        new Get(
            uriTemplate: '/briefs/{date}',
            security: 'is_granted("ROLE_USER") or is_granted("API_KEY") or is_granted("PUBLIC_ACCESS")',
            normalizationContext: ['groups' => ['brief:read']],
        ),
    ],
)]
class BriefResource {}
```

---

## 4. Authentification et contextes clients

### 4.1 Trois contextes d'authentification

| Contexte | Mécanisme | Stockage | Utilisé par |
|----------|-----------|---------|-------------|
| **Desktop web** | Session PHP HttpOnly, SameSite=Strict, Secure | Côté serveur (Redis), cookie opaque au JS | Twig/Turbo controllers |
| **Mobile Flutter** | JWT Bearer (access token EdDSA Ed25519, 15 min) + Refresh Token (7 jours, rotation) | `flutter_secure_storage` (keychain/keystore natif) | App Flutter via `/api/*` |
| **API publique** | Bearer API Key (token affiché une seule fois, stocké en SHA-256 en base) | Client tiers | `/api/v1/*` |

### 4.2 Firewall Symfony

```yaml
# config/packages/security.yaml (extraits)
security:
    firewalls:
        # Stripe webhook : signature HMAC uniquement
        stripe_webhook:
            pattern: ^/api/webhook/stripe
            stateless: true
            custom_authenticators:
                - App\Infrastructure\Security\StripeWebhookAuthenticator

        # API publique : clé API SHA-256
        api_v1:
            pattern: ^/api/v1
            stateless: true
            custom_authenticators:
                - App\Infrastructure\Security\ApiKeyAuthenticator

        # API privée mobile : JWT EdDSA
        api_jwt:
            pattern: ^/api
            stateless: true
            custom_authenticators:
                - App\Infrastructure\Security\JwtAuthenticator

        # Web desktop : session HttpOnly
        main:
            lazy: true
            provider: users_in_memory
            form_login: ~
            logout: ~
            remember_me: false
```

### 4.3 Sécurité des cookies de session

```http
Set-Cookie: PHPSESSID=<opaque_id>; Path=/; HttpOnly; Secure; SameSite=Strict
```

- `HttpOnly` : inaccessible au JavaScript (protection XSS)
- `Secure` : HTTPS uniquement
- `SameSite=Strict` : protection CSRF sans token supplémentaire
- Durée d'inactivité : 30 minutes glissantes (Redis TTL)
- Renouvellement après login
- Invalidation immédiate sur `DELETE /api/me` (logout)

---

## 5. Endpoints API privée /api

### 5.1 Authentification

#### POST /api/register

Création d'un compte email/mot de passe.

**Authentification requise :** Aucune
**Rate limit :** 10 tentatives/heure par IP (Redis `rate:register:{ip}`)

**Requête :**
```http
POST /api/register
Content-Type: application/json

{
  "email": "thomas@example.com",
  "password": "MonMotDeP@sse12",
  "lang": "fr",
  "consent": {
    "analytics": true,
    "marketing": false,
    "notifications": true
  }
}
```

**Contraintes de validation :**
- `email` : format RFC 5322, unicité (index UNIQUE PostgreSQL)
- `password` : minimum 12 caractères, au moins 1 majuscule, 1 chiffre, 1 caractère spécial
- `lang` : enum `["fr", "en"]`
- `consent.analytics`, `consent.marketing`, `consent.notifications` : booléens obligatoires (RGPD)

**Réponse 201 Created :**
```json
{
  "@context": "/api/contexts/User",
  "@type": "User",
  "@id": "/api/me",
  "id": "018f4e8b-1234-7abc-89de-f012345678ab",
  "email": "thomas@example.com",
  "plan": "free",
  "lang": "fr",
  "createdAt": "2026-07-28T10:30:00Z",
  "quota": {
    "used": 0,
    "limit": 3,
    "resetAt": "2026-07-29T00:00:00Z"
  }
}
```

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 201 | Compte créé, session démarrée (desktop) ou token JWT retourné (mobile) |
| 400 | Payload mal formé |
| 409 | Email déjà enregistré |
| 422 | Validation métier (mot de passe trop faible, email invalide) |
| 429 | Rate limit dépassé |

---

#### POST /api/login

Authentification email/mot de passe.

**Authentification requise :** Aucune
**Rate limit :** 5 tentatives/15 minutes par IP ET par compte (Redis `rate:login:{ip}` et `rate:login:account:{userId}`)

**Requête :**
```http
POST /api/login
Content-Type: application/json

{
  "email": "thomas@example.com",
  "password": "MonMotDeP@sse12"
}
```

**Réponse 200 OK (mobile — retourne JWT) :**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJFZERTQSJ9...",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "eyJ...",
  "refresh_expires_in": 604800,
  "user": {
    "id": "018f4e8b-1234-7abc-89de-f012345678ab",
    "email": "thomas@example.com",
    "plan": "free"
  }
}
```

**Réponse 200 OK (desktop — session cookie, pas de token dans le corps) :**
```json
{
  "user": {
    "id": "018f4e8b-1234-7abc-89de-f012345678ab",
    "plan": "free"
  },
  "redirectTo": "/brief/2026-07-28"
}
```

Le client desktop identifie son contexte via l'en-tête `X-Client-Type: web` (optionnel) ou via `Accept` ; l'API retourne systématiquement un cookie `Set-Cookie` pour le desktop.

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 200 | Authentification réussie |
| 401 | Identifiants invalides (message générique — ne pas distinguer email/mdp invalide) |
| 429 | Rate limit atteint — CAPTCHA requis |

---

#### POST /api/token/refresh

Renouvellement du JWT mobile via rotation de refresh token.

**Authentification requise :** Aucune (refresh token dans le corps)
**Détection de vol :** si un refresh token révoqué est réutilisé → toute la `family_id` est invalidée

**Requête :**
```http
POST /api/token/refresh
Content-Type: application/json

{
  "refresh_token": "eyJ..."
}
```

**Réponse 200 OK :**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJFZERTQSJ9...",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "eyJnew..."
}
```

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 200 | Nouveau pair de tokens retourné |
| 401 | Refresh token invalide, expiré ou révoqué |
| 403 | Refresh token révoqué détecté (vol) — famille entière invalidée, re-login requis |

---

#### POST /api/auth/oauth/{provider}

Authentification déléguée OAuth2 (Google, GitHub).

**Paramètre :** `{provider}` = `google` ou `github`
**Authentification requise :** Aucune

**Requête (échange de code d'autorisation) :**
```http
POST /api/auth/oauth/google
Content-Type: application/json

{
  "code": "4/0AcvDMr...",
  "redirect_uri": "https://app.briefly.ai/auth/callback",
  "state": "csrf_state_token"
}
```

Le paramètre `state` est validé côté serveur avant l'échange (protection CSRF OAuth2).

**Réponse 200 OK :**
Identique à `POST /api/login` — access + refresh tokens pour mobile, cookie pour desktop.

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 200 | Authentification réussie (compte existant ou créé) |
| 400 | `code` absent ou `state` invalide |
| 401 | Code OAuth rejeté par le provider |
| 422 | Provider non supporté |

---

### 5.2 Profil utilisateur

#### GET /api/me

**Authentification requise :** Session ou JWT
**Serialization group :** `user:read`
**Voter :** `ROLE_USER` (deny by default)

**Réponse 200 OK :**
```json
{
  "@context": "/api/contexts/User",
  "@type": "User",
  "@id": "/api/me",
  "id": "018f4e8b-1234-7abc-89de-f012345678ab",
  "email": "thomas@example.com",
  "plan": "free",
  "lang": "fr",
  "timezone": "Europe/Paris",
  "createdAt": "2026-07-01T08:00:00Z",
  "quota": {
    "used": 2,
    "limit": 3,
    "resetAt": "2026-07-29T00:00:00Z"
  },
  "subscription": null,
  "preferences": {
    "topics": ["tech", "science"],
    "notificationTime": "07:30"
  }
}
```

**Codes de statut :** 200, 401

---

#### PUT /api/me/preferences

Mise à jour partielle des préférences utilisateur (langue, timezone, thèmes, notification).

**Authentification requise :** Session ou JWT
**Serialization group :** `user:write`

**Requête :**
```http
PUT /api/me/preferences
Content-Type: application/json

{
  "lang": "en",
  "timezone": "America/New_York",
  "preferences": {
    "topics": ["tech", "finance"],
    "notificationTime": "08:00",
    "consent": {
      "analytics": true,
      "marketing": false,
      "notifications": true
    }
  }
}
```

**Codes de statut :** 200, 400, 401, 422

---

#### DELETE /api/me

Suppression du compte (droit à l'oubli RGPD). Déclenche un Symfony Messenger message `DeleteAccountMessage` traité de manière asynchrone (hard delete cascade dans 30 jours).

**Authentification requise :** Session ou JWT
**Confirmation requise :** corps `{"confirm": true}`

**Requête :**
```http
DELETE /api/me
Content-Type: application/json

{
  "confirm": true
}
```

**Réponse 204 No Content** (session invalidée immédiatement, soft delete `deleted_at` = now)

**Codes de statut :** 204, 400 (confirmation manquante), 401

---

#### GET /api/me/data-export

Export JSON de portabilité RGPD (article 20 RGPD).

**Authentification requise :** Session ou JWT
**Rate limit :** 1 export/heure par utilisateur

**Réponse 200 OK :**
```json
{
  "exportedAt": "2026-07-28T12:00:00Z",
  "user": {
    "id": "018f4e8b-...",
    "email": "thomas@example.com",
    "plan": "free",
    "createdAt": "2026-07-01T08:00:00Z",
    "preferences": { "topics": ["tech"], "lang": "fr" }
  },
  "consent": [
    { "scope": "analytics", "granted": true, "recordedAt": "2026-07-01T08:00:00Z" }
  ],
  "readingHistory": [],
  "savedArticles": []
}
```

---

#### GET /api/me/data-export/markdown

Export Markdown de la bibliothèque personnelle (briefs + synthèses sauvegardées).

**Authentification requise :** Session ou JWT **Premium**
**Voter :** `ROLE_PREMIUM`

**Réponse 200 OK :**
```
Content-Type: text/markdown; charset=utf-8
Content-Disposition: attachment; filename="briefly-export-2026-07-28.md"
```

**Codes de statut :** 200, 401, 403 (plan Free)

---

### 5.3 Articles

#### GET /api/articles

Liste paginée des articles (flux personnel, filtrés par thèmes si configurés).

**Authentification requise :** Session ou JWT
**Pagination :** Curseur (voir §10)
**Serialization group :** `article:list`

**Paramètres de requête :**

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `limit` | integer | 20 | Nombre d'articles (max 100) |
| `cursor` | string | — | Curseur opaque (base64 JSON) |
| `category` | string | — | Filtre catégorie (`tech`, `finance`, `science`, `geopolitique`, `sante`, `culture`, `sport`) |
| `lang` | string | préférence user | Filtre langue (`fr`, `en`) |
| `from` | ISO 8601 | -24h | Borne inférieure `published_at` |

**Réponse 200 OK :**
```json
{
  "@context": "/api/contexts/Article",
  "@type": "hydra:Collection",
  "hydra:member": [
    {
      "@type": "Article",
      "@id": "/api/articles/018f4e8b-5678-7abc-89de-f012345678cd",
      "id": "018f4e8b-5678-7abc-89de-f012345678cd",
      "title": "OpenAI launches GPT-5 with multimodal reasoning",
      "sourceUrl": "https://techcrunch.com/...",
      "sourceName": "TechCrunch",
      "category": "tech",
      "lang": "en",
      "publishedAt": "2026-07-28T07:15:00Z",
      "clusterSize": 8
    }
  ],
  "hydra:totalItems": null,
  "hydra:view": {
    "@type": "hydra:PartialCollectionView",
    "hydra:next": "/api/articles?cursor=eyJpZCI6IjAxOGY0ZThiLTU2NzgifQ&limit=20"
  },
  "briefly:pagination": {
    "nextCursor": "eyJpZCI6IjAxOGY0ZThiLTU2NzgifQ",
    "hasMore": true,
    "limit": 20
  }
}
```

**Codes de statut :** 200, 400, 401

---

#### GET /api/articles/{id}

Détail d'un article. La synthèse IA est incluse si elle existe en cache Redis ; sinon `synthesis` est `null` (à générer via `POST /api/syntheses`).

**Authentification requise :** Session ou JWT
**Serialization group :** `article:read`
**Voter :** `ArticleVoter::VIEW` — vérifie que l'article n'est pas archivé

**Réponse 200 OK :**
```json
{
  "@context": "/api/contexts/Article",
  "@type": "Article",
  "@id": "/api/articles/018f4e8b-5678-7abc-89de-f012345678cd",
  "id": "018f4e8b-5678-7abc-89de-f012345678cd",
  "title": "OpenAI launches GPT-5 with multimodal reasoning",
  "summary": "OpenAI unveiled GPT-5, its latest large language model...",
  "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
  "sourceName": "TechCrunch",
  "category": "tech",
  "lang": "en",
  "publishedAt": "2026-07-28T07:15:00Z",
  "synthesis": {
    "level": "CONCISE",
    "content": "BRIEFLY AI: OpenAI a lancé GPT-5 avec des capacités multimodales avancées. Le modèle surpasse GPT-4 sur les benchmarks de raisonnement de 37%. Source: TechCrunch",
    "provider": "mistral",
    "aiGenerated": true,
    "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
    "generatedAt": "2026-07-28T04:00:00Z"
  }
}
```

**Codes de statut :** 200, 401, 404

---

### 5.4 Synthèses IA

#### POST /api/syntheses

Demande de génération d'une synthèse IA pour un article existant.

**Authentification requise :** Session ou JWT
**Voter :** `SynthesisVoter::CREATE`
  - Vérifie que l'`article_id` existe en base (ArticleRepository) → SSRF impossible (voir §7)
  - Vérifie le quota (Free : ≤ 3/jour, Premium : illimité)
  - Vérifie le cache Redis avant tout appel LLM

**Requête :**
```http
POST /api/syntheses
Content-Type: application/json

{
  "article_id": "018f4e8b-5678-7abc-89de-f012345678cd",
  "level": "DETAILED"
}
```

**Validation des contraintes :**
- `article_id` : UUID v4 ou v7, référence valide en base PostgreSQL — **pas d'URL, pas de chemin, pas d'identifiant externe**
- `level` : enum strict `["CONCISE", "DETAILED", "NARRATIVE"]`
- `NARRATIVE` : réservé aux utilisateurs Premium (voter)

**Réponse 201 Created (depuis Mistral) :**
```json
{
  "@context": "/api/contexts/Synthesis",
  "@type": "Synthesis",
  "id": "018f4e91-abcd-7abc-89de-aabbccddeeff",
  "articleId": "018f4e8b-5678-7abc-89de-f012345678cd",
  "level": "DETAILED",
  "content": "BRIEFLY AI: OpenAI a lancé GPT-5, son modèle le plus avancé à ce jour. Les points clés :\n\n• Capacités multimodales étendues (texte, image, audio, vidéo)\n• Performance supérieure de 37% sur les benchmarks de raisonnement\n• Déploiement progressif à partir de septembre 2026\n\nSource: TechCrunch",
  "provider": "mistral",
  "aiGenerated": true,
  "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
  "cacheHit": false,
  "generatedAt": "2026-07-28T10:45:00Z"
}
```

**Réponse 200 OK (depuis cache Redis) :** corps identique, `"cacheHit": true`

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 200 | Synthèse retournée depuis le cache Redis (hit) |
| 201 | Synthèse générée par Mistral (ou fallback OpenAI) |
| 400 | `article_id` absent ou malformé (non-UUID) |
| 401 | Non authentifié |
| 403 | Niveau NARRATIVE demandé par un utilisateur Free |
| 404 | `article_id` inconnu — l'article n'existe pas en base |
| 422 | `level` invalide |
| 429 | Quota journalier dépassé (Free) — corps RFC 7807 avec `resetAt` |
| 503 | Mistral et fallback OpenAI indisponibles (circuit breaker ouvert) |

---

### 5.5 Daily Briefs

#### GET /api/briefs

Liste des Daily Briefs disponibles, ordre chronologique décroissant.

**Authentification requise :** Session ou JWT
**Pagination :** Curseur

**Paramètres :**

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `limit` | integer | 10 | Max 30 |
| `cursor` | string | — | Curseur opaque |

**Réponse 200 OK :**
```json
{
  "@type": "hydra:Collection",
  "hydra:member": [
    {
      "@type": "Brief",
      "@id": "/api/briefs/2026-07-28",
      "id": "018f4e8b-0001-7abc-89de-000000000001",
      "date": "2026-07-28",
      "generatedAt": "2026-07-28T05:00:00Z",
      "lastUpdatedAt": "2026-07-28T08:30:00Z",
      "status": "published",
      "storyCount": 3
    }
  ],
  "briefly:pagination": {
    "nextCursor": "eyJkYXRlIjoiMjAyNi0wNy0yNyJ9",
    "hasMore": true
  }
}
```

---

#### GET /api/briefs/{date}

Daily Brief complet pour une date donnée.

**Authentification requise :** Aucune (endpoint public) — session ou JWT optionnel (enrichit la réponse avec les préférences utilisateur)
**Format date :** `YYYY-MM-DD`
**Cache :** Redis `brief:{date}` TTL 60 secondes + header `Cache-Control: max-age=60, s-maxage=300`
**Serialization group :** `brief:read`

**Réponse 200 OK :**
```json
{
  "@context": "/api/contexts/Brief",
  "@type": "Brief",
  "@id": "/api/briefs/2026-07-28",
  "id": "018f4e8b-0001-7abc-89de-000000000001",
  "date": "2026-07-28",
  "generatedAt": "2026-07-28T05:00:00Z",
  "lastUpdatedAt": "2026-07-28T08:30:00Z",
  "status": "published",
  "stories": [
    {
      "rank": 1,
      "editorialTitle": "L'IA multimodale franchit un nouveau cap avec GPT-5",
      "lastUpdatedAt": "2026-07-28T08:30:00Z",
      "clusterSize": 8,
      "synthesis": {
        "level": "CONCISE",
        "content": "BRIEFLY AI: OpenAI lance GPT-5, surpassant GPT-4 de 37% sur les benchmarks de raisonnement multimodal. Source: TechCrunch, MIT Tech Review",
        "aiGenerated": true,
        "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5"
      },
      "articles": [
        {
          "id": "018f4e8b-5678-7abc-89de-f012345678cd",
          "title": "OpenAI launches GPT-5",
          "sourceName": "TechCrunch",
          "publishedAt": "2026-07-28T07:15:00Z"
        }
      ]
    },
    { "rank": 2, "..." : "..." },
    { "rank": 3, "..." : "..." }
  ]
}
```

**Headers de réponse :**
```http
Cache-Control: max-age=60, s-maxage=300
ETag: "sha256-018f4e8b0001..."
X-Request-Id: 018f4f00-beef-7abc-89de-deadbeef0001
```

**Codes de statut :**
| Code | Condition |
|------|-----------|
| 200 | Brief trouvé |
| 304 | Not Modified (ETag conditionnel) |
| 404 | Aucun brief généré pour cette date |

---

### 5.6 Clés API (gestion)

Ces endpoints permettent aux utilisateurs Premium de gérer leurs clés API publiques.

#### GET /api/me/api-keys

Liste les clés API actives de l'utilisateur.

**Authentification requise :** Session ou JWT Premium

**Réponse 200 OK :**
```json
{
  "@type": "hydra:Collection",
  "hydra:member": [
    {
      "id": "018f5000-1234-7abc-89de-abcdef012345",
      "name": "Dashboard personnel Marc",
      "lastUsedAt": "2026-07-28T09:15:00Z",
      "createdAt": "2026-07-01T10:00:00Z",
      "revokedAt": null
    }
  ]
}
```

**Note :** La valeur brute de la clé n'est **jamais** retournée dans cette liste — uniquement lors de la création.

---

#### POST /api/me/api-keys

Création d'une nouvelle clé API.

**Authentification requise :** Session ou JWT Premium
**Voter :** `ROLE_PREMIUM`

**Requête :**
```http
POST /api/me/api-keys
Content-Type: application/json

{
  "name": "Dashboard personnel Marc"
}
```

**Réponse 201 Created :**
```json
{
  "id": "018f5000-1234-7abc-89de-abcdef012345",
  "name": "Dashboard personnel Marc",
  "key": "bai_live_aAbBcCdDeEfFgGhHiIjJkKlLmMnNoOpP",
  "createdAt": "2026-07-28T10:00:00Z"
}
```

**IMPORTANT :** Le champ `key` est affiché **une seule fois** à la création. En base, seul le SHA-256 du token est stocké (`api_keys.key_hash`). L'utilisateur doit le copier immédiatement.

**Codes de statut :** 201, 401, 403 (plan Free)

---

#### DELETE /api/me/api-keys/{id}

Révocation immédiate d'une clé API.

**Authentification requise :** Session ou JWT Premium
**Voter :** `ApiKeyVoter::DELETE` — vérifie que la clé appartient à l'utilisateur authentifié

**Réponse 204 No Content**

**Codes de statut :** 204, 401, 403, 404

---

### 5.7 Webhook Stripe

#### POST /api/webhook/stripe

Réception des événements Stripe (abonnements, paiements, annulations).

**Authentification :** Signature HMAC Stripe (`Stripe-Signature` header), vérifiée avant tout traitement
**Idempotence :** `stripe_events.event_id` UNIQUE — les doublons sont ignorés silencieusement
**Traitement :** Asynchrone via Symfony Messenger (queue `stripe_webhook`, worker dédié)

**Requête (envoyée par Stripe) :**
```http
POST /api/webhook/stripe
Stripe-Signature: t=1722175200,v1=abc123...
Content-Type: application/json

{
  "id": "evt_1234abc",
  "type": "customer.subscription.updated",
  "data": { "object": { ... } }
}
```

**Réponse 200 OK :** `{"received": true}`

**Codes de statut :** 200, 400 (signature invalide), 409 (doublon — idempotent)

---

## 6. Endpoints API publique /api/v1

Tous les endpoints `/api/v1/*` requièrent un **Bearer API Key** (uniquement Premium).

```http
Authorization: Bearer bai_live_aAbBcCdDeEfFgGhHiIjJkKlLmMnNoOpP
```

Le token est vérifié par `ApiKeyAuthenticator` : SHA-256 du token reçu comparé à `api_keys.key_hash` (recherche en base). La clé doit être non révoquée (`revoked_at IS NULL`).

### 6.1 GET /api/v1/daily-brief

Raccourci vers le brief du jour courant. Alias de `GET /api/v1/briefs/{today}`.

**Rate limit :** 100 req/h par clé API (Redis `rate:api:{keyHash}`)

**Réponse 200 OK :** identique à `GET /api/briefs/{date}` avec serialization group `brief:v1`

**Headers de réponse spécifiques :**
```http
API-Version: 1
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1722178800
X-RateLimit-Plan: premium
```

---

### 6.2 GET /api/v1/briefs

Liste des briefs accessibles, pagination curseur.

**Rate limit :** 100 req/h

**Paramètres :**

| Paramètre | Type | Défaut |
|-----------|------|--------|
| `limit` | integer | 10 (max 30) |
| `cursor` | string | — |

**Réponse 200 OK :**
```json
{
  "data": [
    {
      "id": "018f4e8b-0001-7abc-89de-000000000001",
      "date": "2026-07-28",
      "generatedAt": "2026-07-28T05:00:00Z",
      "lastUpdatedAt": "2026-07-28T08:30:00Z",
      "storiesCount": 3,
      "links": {
        "self": "/api/v1/briefs/2026-07-28"
      }
    }
  ],
  "pagination": {
    "nextCursor": "eyJkYXRlIjoiMjAyNi0wNy0yNyJ9",
    "hasMore": true,
    "limit": 10
  },
  "meta": {
    "apiVersion": "1",
    "generatedAt": "2026-07-28T12:00:00Z"
  }
}
```

---

### 6.3 GET /api/v1/briefs/{date}

Brief complet pour une date donnée, format JSON pur (sans JSON:LD hydra).

**Format date :** `YYYY-MM-DD`

**Réponse 200 OK :**
```json
{
  "id": "018f4e8b-0001-7abc-89de-000000000001",
  "date": "2026-07-28",
  "generatedAt": "2026-07-28T05:00:00Z",
  "lastUpdatedAt": "2026-07-28T08:30:00Z",
  "stories": [
    {
      "rank": 1,
      "editorialTitle": "L'IA multimodale franchit un nouveau cap avec GPT-5",
      "lastUpdatedAt": "2026-07-28T08:30:00Z",
      "clusterSize": 8,
      "aiSummary": {
        "content": "BRIEFLY AI: OpenAI lance GPT-5...",
        "aiGenerated": true,
        "provider": "mistral",
        "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5"
      },
      "topArticles": [
        {
          "id": "018f4e8b-5678-7abc-89de-f012345678cd",
          "title": "OpenAI launches GPT-5",
          "sourceName": "TechCrunch",
          "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
          "publishedAt": "2026-07-28T07:15:00Z"
        }
      ]
    }
  ]
}
```

**Codes de statut :** 200, 401, 404

---

### 6.4 GET /api/v1/articles

Flux d'articles paginé, filtrable par catégorie.

**Rate limit :** 100 req/h

**Paramètres :** identiques à `GET /api/articles` (§5.3), sans filtre utilisateur (thèmes non appliqués)

**Réponse 200 OK :**
```json
{
  "data": [
    {
      "id": "018f4e8b-5678-7abc-89de-f012345678cd",
      "title": "OpenAI launches GPT-5 with multimodal reasoning",
      "sourceUrl": "https://techcrunch.com/...",
      "sourceName": "TechCrunch",
      "category": "tech",
      "lang": "en",
      "publishedAt": "2026-07-28T07:15:00Z",
      "clusterSize": 8
    }
  ],
  "pagination": {
    "nextCursor": "eyJpZCI6IjAxOGY0ZThiLTU2NzgifQ",
    "hasMore": true,
    "limit": 20
  }
}
```

---

### 6.5 GET /api/v1/articles/{id}

Détail d'un article avec sa synthèse IA si disponible en cache.

**Réponse 200 OK :**
```json
{
  "id": "018f4e8b-5678-7abc-89de-f012345678cd",
  "title": "OpenAI launches GPT-5 with multimodal reasoning",
  "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
  "sourceName": "TechCrunch",
  "category": "tech",
  "lang": "en",
  "publishedAt": "2026-07-28T07:15:00Z",
  "synthesis": {
    "level": "CONCISE",
    "content": "BRIEFLY AI: ...",
    "aiGenerated": true,
    "provider": "mistral",
    "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5"
  }
}
```

**Codes de statut :** 200, 401, 404

---

### 6.6 POST /api/v1/synthesize

Génération d'une synthèse IA via l'API publique.

**Rate limit :** 200 synthèses/jour par clé API (Premium, Redis `quota:apikey:{keyId}:{date}`)
**Authentification requise :** Bearer API Key Premium

**SSRF — contrainte critique :** voir §7. L'endpoint n'accepte **jamais** d'URL. Seul un `article_id` UUID interne est accepté.

**Requête :**
```http
POST /api/v1/synthesize
Authorization: Bearer bai_live_...
Content-Type: application/json

{
  "article_id": "018f4e8b-5678-7abc-89de-f012345678cd",
  "level": "DETAILED"
}
```

**Réponse 201 Created :**
```json
{
  "id": "018f4e91-abcd-7abc-89de-aabbccddeeff",
  "articleId": "018f4e8b-5678-7abc-89de-f012345678cd",
  "level": "DETAILED",
  "content": "BRIEFLY AI: ...",
  "aiGenerated": true,
  "provider": "mistral",
  "sourceUrl": "https://techcrunch.com/2026/07/28/openai-gpt5",
  "cacheHit": false,
  "generatedAt": "2026-07-28T10:45:00Z"
}
```

**Codes de statut :** 200 (cache), 201 (généré), 400 (payload invalide), 401, 404 (article inconnu), 422, 429 (quota API dépassé)

---

## 7. Sécurité SSRF — protection /synthesize

### 7.1 Vecteur de risque (résolu)

L'intitulé US-010 "Synthèse IA à la demande sur URL" laissait initialement supposer que l'endpoint pouvait accepter une URL externe arbitraire. Cela constituerait un vecteur SSRF (Server-Side Request Forgery) critique — un attaquant pourrait forcer le serveur à requêter des ressources internes (`http://redis:6379`, `http://postgres:5432`, métadonnées cloud, etc.).

**Fix appliqué (Design Review C-05, T-PRE-02) :** L'endpoint accepte **exclusivement un `article_id` UUID interne**. L'article est récupéré depuis PostgreSQL par le `SynthesisVoter`. Aucune URL externe n'est jamais consommée dans ce flux.

### 7.2 Règles de validation — implémentation

```php
// src/Infrastructure/Api/Input/SynthesisInput.php
class SynthesisInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid(versions: [4, 7])]  // UUID interne uniquement
    public string $articleId;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['CONCISE', 'DETAILED', 'NARRATIVE'])]
    public string $level;
}
```

```php
// src/Infrastructure/Security/Voter/SynthesisVoter.php
protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
{
    // 1. Vérifier que l'article_id existe en base — JAMAIS appeler une URL externe
    $article = $this->articleRepository->findById(ArticleId::fromString($subject->articleId));
    if (null === $article) {
        return false;  // → 404
    }

    // 2. Vérifier le quota utilisateur
    if (!$this->quotaTracker->canGenerate($token->getUser()->getId())) {
        throw new QuotaExceededException();  // → 429
    }

    // 3. Vérifier le niveau vs plan
    if ('NARRATIVE' === $subject->level && !$token->getUser()->isPremium()) {
        return false;  // → 403
    }

    return true;
}
```

### 7.3 Test de sécurité obligatoire (T-PRE-02)

```php
// tests/Security/SsrfTest.php
it('rejects payload containing a URL instead of article_id', function () {
    $response = $this->client->request('POST', '/api/syntheses', [
        'json' => [
            'article_id' => 'http://internal.redis:6379/keys',
            'level' => 'CONCISE',
        ]
    ]);
    $this->assertResponseStatusCodeSame(422);
});

it('rejects payload containing a path instead of UUID', function () {
    $response = $this->client->request('POST', '/api/syntheses', [
        'json' => ['article_id' => '../etc/passwd', 'level' => 'CONCISE']
    ]);
    $this->assertResponseStatusCodeSame(422);
});

it('rejects unknown UUID (article not in database)', function () {
    $response = $this->client->request('POST', '/api/syntheses', [
        'json' => [
            'article_id' => '018f0000-0000-7abc-89de-000000000000',  // inconnu
            'level' => 'CONCISE',
        ]
    ]);
    $this->assertResponseStatusCodeSame(404);
});
```

### 7.4 Règle invariante (applicable à toute future évolution)

**RÈGLE :** Tout endpoint qui déclenche un appel LLM ou une requête réseau depuis le serveur ne doit **jamais** accepter une URL fournie par le client. Les seules entrées acceptées sont des identifiants internes (UUIDs référencés en base). Toute proposition d'endpoint acceptant une URL externe doit faire l'objet d'une analyse de sécurité et d'une whitelist explicite.

---

## 8. Contrôle d'accès — Voters Symfony

### 8.1 Principe : deny by default

Tous les endpoints sont sécurisés. L'accès non explicitement autorisé retourne **403 Forbidden** (ou **401** si non authentifié).

```yaml
# config/packages/security.yaml
security:
    access_decision_manager:
        strategy: unanimous  # Tous les voters doivent approuver
    access_control:
        - { path: ^/api/v1/, roles: API_KEY }
        - { path: ^/api/auth, roles: PUBLIC_ACCESS }
        - { path: ^/api/register, roles: PUBLIC_ACCESS }
        - { path: ^/api/briefs, roles: [ROLE_USER, PUBLIC_ACCESS] }
        - { path: ^/api/, roles: ROLE_USER }
```

### 8.2 Voters par ressource

| Voter | Attributs | Vérifie |
|-------|-----------|---------|
| `BriefVoter` | `VIEW` | Brief publié (status = published) |
| `ArticleVoter` | `VIEW` | Article non archivé, appartient au catalogue |
| `SynthesisVoter` | `CREATE` | article_id existe en base, quota utilisateur, plan pour NARRATIVE |
| `ApiKeyVoter` | `VIEW`, `CREATE`, `DELETE` | Plan Premium, appartenance (`user_id = currentUser.id`) |
| `DataExportVoter` | `EXPORT_MARKDOWN` | Plan Premium |
| `SubscriptionVoter` | `VIEW`, `MANAGE` | `subscription.user_id = currentUser.id` |

### 8.3 Row-Level Security systématique

```php
// Exemple ArticleVoter
protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
{
    // Vérification d'appartenance — jamais de confiance sur l'ID seul
    if (ArticleVoter::VIEW === $attribute) {
        return !$subject->isArchived();
    }
    return false;
}
```

**Règle critique (OWASP #1) :** La seule vérification que l'ID soit structurellement valide (UUID) ne suffit pas. Le voter doit toujours récupérer la ressource depuis le repository et vérifier son état ET son appartenance avant d'autoriser l'accès.

---

## 9. Sérialisation et groupes

### 9.1 Stratégie de serialization groups

Les groups sont déclarés dans les `ApiResource`, jamais dans les entités du domaine (séparation stricte Infrastructure / Domaine).

| Ressource | Group `list` | Group `read` | Group `write` | Group `v1` |
|-----------|-------------|-------------|--------------|-----------|
| Brief | `brief:list` | `brief:read` | — | `brief:v1` |
| Article | `article:list` | `article:read` | — | `article:v1` |
| Synthesis | — | `synthesis:read` | `synthesis:write` | `synthesis:v1` |
| User | — | `user:read` | `user:write` | — |
| ApiKey | `apikey:list` | `apikey:read` | `apikey:write` | — |

### 9.2 Champs jamais exposés

Les champs suivants sont **exclus de tout groupe de sérialisation** :

| Champ | Raison |
|-------|--------|
| `password_hash` | Sécurité critique |
| `api_keys.key_hash` | SHA-256 de la clé — seule la clé brute (une fois) est retournée |
| `refresh_tokens.*` | Jamais exposé via API |
| `oauth_subject` | Identifiant OAuth interne |
| `stripe_customer_id`, `stripe_sub_id` | PCI DSS — identifiants Stripe internes |
| `deleted_at` | Soft delete interne |

### 9.3 UUID exposés dans les réponses

Conformément à ADR-006 (T-PRE-01) :
- **UUID v4** (non séquentiels, `gen_random_uuid()`) pour toutes les ressources exposées dans les réponses API : `users.id`, `api_keys.id`, `subscriptions.id`
- **UUID v7** (time-ordered) pour les tables à fort volume interne : `articles.id`, `syntheses.id`, `stories.id` — ces UUIDs sont également exposés dans les réponses (leur timestamp ne révèle que la date de création, pas d'information personnelle)

---

## 10. Pagination par curseur

### 10.1 Principe

La pagination offset (`?page=3`) est interdite pour toutes les collections (instabilité sur gros volumes, performances dégradées). **La pagination par curseur est obligatoire** (FR-049, ADR-007).

### 10.2 Format du curseur

Le curseur est un **JSON base64url** encodant les critères de position. Il est opaque pour le client — sa structure interne peut changer sans breaking change.

```
cursor = base64url(JSON.stringify({ "id": "018f4e8b-5678-...", "publishedAt": "2026-07-28T07:15:00Z" }))
```

Exemple : `eyJpZCI6IjAxOGY0ZThiLTU2NzgifQ`

### 10.3 Construction des requêtes SQL (Doctrine)

```php
// src/Infrastructure/Repository/DoctrineArticleRepository.php
public function findByCursor(CursorFilter $filter): CursorResult
{
    $qb = $this->em->createQueryBuilder()
        ->from(ArticleORM::class, 'a')
        ->select('a')
        ->where('a.isArchived = false');

    if ($filter->cursor !== null) {
        // Keyset pagination : index composite (category, published_at, id)
        $qb->andWhere('(a.publishedAt, a.id) < (:cursorDate, :cursorId)')
           ->setParameter('cursorDate', $filter->cursor->publishedAt)
           ->setParameter('cursorId', $filter->cursor->id);
    }

    $qb->orderBy('a.publishedAt', 'DESC')
       ->addOrderBy('a.id', 'DESC')
       ->setMaxResults($filter->limit + 1);  // +1 pour détecter hasMore

    $results = $qb->getQuery()->getResult();
    $hasMore = count($results) > $filter->limit;

    return new CursorResult(
        items: array_slice($results, 0, $filter->limit),
        hasMore: $hasMore,
        nextCursor: $hasMore ? CursorEncoder::encode(end($results)) : null,
    );
}
```

### 10.4 Réponse unifiée

Toutes les collections retournent le même objet `briefly:pagination` :

```json
"briefly:pagination": {
  "nextCursor": "eyJpZCI6IjAxOGY0ZThiLTU2NzgifQ",
  "hasMore": true,
  "limit": 20
}
```

`totalItems` n'est **jamais** retourné (requête `COUNT(*)` coûteuse sur gros volumes, incompatible avec la pagination curseur).

---

## 11. Quotas, rate limiting et paywall

### 11.1 Synthèses IA — quotas par plan

| Plan | Synthèses/jour | Niveaux accessibles | Reset |
|------|---------------|--------------------|----|
| **Free** | 3 | CONCISE, DETAILED | Minuit UTC |
| **Premium** | Illimitées | CONCISE, DETAILED, NARRATIVE | — |
| **API Key Premium** | 200 synthèses/jour par clé | CONCISE, DETAILED, NARRATIVE | Minuit UTC |

Implémentation : Redis `INCR quota:{userId}:{YYYY-MM-DD}` avec TTL jusqu'à minuit UTC.

### 11.2 Rate limiting endpoints

| Endpoint | Limite | Fenêtre | Clé Redis |
|----------|--------|---------|-----------|
| `POST /api/register` | 10 | 1 heure par IP | `rate:register:{ip}` |
| `POST /api/login` | 5 | 15 minutes par IP + compte | `rate:login:{ip}`, `rate:login:acct:{userId}` |
| `POST /api/token/refresh` | 20 | 1 heure par famille de tokens | `rate:refresh:{familyId}` |
| `GET /api/me/data-export` | 1 | 1 heure par utilisateur | `rate:export:{userId}` |
| Tous `/api/v1/*` | 100 | 1 heure glissante par clé API | `rate:api:{keyHash}` |

### 11.3 Headers rate limit (RFC 6585)

Présents sur **chaque** réponse `/api/v1/*` et sur les endpoints à quota :

```http
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1722178800
X-RateLimit-Plan: premium
Retry-After: 3600          (uniquement sur 429)
```

### 11.4 Déclenchement du paywall (Free → Premium)

Lors de la 4e demande de synthèse pour un utilisateur Free, le voter `SynthesisVoter` lève `QuotaExceededException`. L'`ExceptionSubscriber` retourne une réponse RFC 7807 avec le code `QUOTA_EXCEEDED` :

```json
{
  "type": "https://briefly.ai/errors/quota-exceeded",
  "title": "Daily synthesis quota exceeded",
  "status": 429,
  "detail": "You have used your 3 free daily syntheses. Upgrade to Premium for unlimited access.",
  "instance": "/api/syntheses",
  "remainingQuota": 0,
  "resetAt": "2026-07-29T00:00:00Z",
  "upgradeUrl": "https://briefly.ai/premium"
}
```

Côté Twig : la réponse JSON 429 est interceptée par un controller Symfony qui affiche un **Turbo Frame** contenant la modale de paywall sans rechargement complet de la page (< 200 ms).

Côté Flutter : le BLoC `SynthesisBloc` intercepte le code 429 et dispatch un état `PaywallRequired` qui affiche l'écran d'upgrade in-app.

---

## 12. Format des erreurs (RFC 7807 — OWASP #7)

### 12.1 Principe OWASP #7

**Aucune stack trace ne doit apparaître dans les réponses d'API en production.** Les messages d'erreur sont génériques côté client mais précis dans les logs Sentry/Monolog côté serveur.

### 12.2 Format standard (RFC 7807 Problem Details)

```json
{
  "type": "https://briefly.ai/errors/{error-code}",
  "title": "Description courte lisible",
  "status": 422,
  "detail": "Message explicatif pour le développeur (pas de stack trace)",
  "instance": "/api/syntheses",
  "requestId": "018f4f00-beef-7abc-89de-deadbeef0001"
}
```

Le champ `requestId` correspond au header `X-Request-Id` — il permet de corréler l'erreur client avec les logs Sentry sans exposer d'information interne.

### 12.3 Catalogue des codes d'erreur

| `type` URI | HTTP | Contexte |
|-----------|------|---------|
| `errors/validation-error` | 400 | Payload mal formé |
| `errors/invalid-uuid` | 422 | `article_id` n'est pas un UUID valide |
| `errors/invalid-credentials` | 401 | Email/mot de passe incorrect (message générique) |
| `errors/token-expired` | 401 | JWT expiré |
| `errors/token-invalid` | 401 | JWT ou API Key invalide ou révoquée |
| `errors/forbidden` | 403 | Autorisation refusée (plan insuffisant, ressource d'un autre utilisateur) |
| `errors/not-found` | 404 | Ressource introuvable |
| `errors/conflict` | 409 | Email déjà enregistré, doublon Stripe event |
| `errors/quota-exceeded` | 429 | Quota de synthèses dépassé (+ `resetAt`, `upgradeUrl`) |
| `errors/rate-limit-exceeded` | 429 | Rate limit atteint (+ `Retry-After` header) |
| `errors/synthesis-unavailable` | 503 | Mistral et fallback OpenAI indisponibles |
| `errors/internal` | 500 | Erreur serveur générique (jamais de détail) |

### 12.4 Validation errors — format détaillé

Pour les erreurs 400/422, le champ `violations` liste les champs en erreur :

```json
{
  "type": "https://briefly.ai/errors/validation-error",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request contains invalid data.",
  "violations": [
    {
      "propertyPath": "article_id",
      "message": "This value is not a valid UUID.",
      "code": "INVALID_UUID"
    },
    {
      "propertyPath": "level",
      "message": "The value 'ULTRA' is not a valid choice.",
      "code": "INVALID_CHOICE"
    }
  ],
  "requestId": "018f4f00-beef-7abc-89de-deadbeef0001"
}
```

---

## 13. Headers de réponse standard

### 13.1 Headers présents sur chaque réponse API

```http
Content-Type: application/ld+json; charset=utf-8  (API privée)
Content-Type: application/json; charset=utf-8      (API v1)
X-Request-Id: 018f4f00-beef-7abc-89de-deadbeef0001
X-Content-Type-Options: nosniff
```

### 13.2 Headers de sécurité (FrankenPHP / Symfony)

```http
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
Content-Security-Policy: default-src 'none'; frame-ancestors 'none'
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Embedder-Policy: require-corp
Cross-Origin-Resource-Policy: same-origin
```

**Note :** Pour l'endpoint `/api/docs` (Swagger UI), la CSP est assouplie pour autoriser les ressources statiques de l'UI (`script-src 'self' 'unsafe-inline'`). Cette exception est limitée à la route `/api/docs` uniquement.

### 13.3 Headers de cache

| Endpoint | Cache-Control | ETag |
|----------|--------------|------|
| `GET /api/briefs/{date}` | `max-age=60, s-maxage=300` | SHA-256 du contenu sérialisé |
| `GET /api/v1/briefs/{date}` | `max-age=60, s-maxage=300` | Oui |
| `GET /api/articles` | `no-store` (personnalisé) | Non |
| `GET /api/me` | `no-store` | Non |
| Tous les POST | `no-store` | Non |

### 13.4 Header API-Version

Présent sur toutes les réponses `/api/v1/*` :

```http
API-Version: 1
```

---

## 14. CORS

### 14.1 Configuration stricte (OWASP #5)

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    defaults:
        allow_credentials: true
        allow_origin: []
        allow_headers: ['Content-Type', 'Authorization', 'X-API-Key', 'X-Client-Type']
        allow_methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        expose_headers: ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-Request-Id', 'API-Version']
        max_age: 3600
    paths:
        '^/api/':
            allow_origin:
                - 'https://briefly.ai'
                - 'https://www.briefly.ai'
                - 'https://app.briefly.ai'
            allow_credentials: true
        '^/api/v1/':
            allow_origin: ['*']       # API publique : accès depuis tout domaine (avec API Key)
            allow_credentials: false  # Pas de cookies sur l'API publique
```

**Règle :** L'API privée (`/api/`) n'autorise que les origines de la plateforme. L'API publique (`/api/v1/`) autorise `*` mais sans `allow_credentials` (les API Keys ne nécessitent pas de credentials cookies).

---

## 15. Flux OAuth2 Google / GitHub

### 15.1 Séquence d'authentification

```
Client (desktop/mobile)
  │
  │  1. GET /api/auth/oauth/{provider}/redirect
  │     → Génération d'un `state` CSRF (Redis TTL 10 min)
  │     ← 302 Redirect vers https://accounts.google.com/o/oauth2/v2/auth?
  │         client_id=...&redirect_uri=...&state={csrf}&scope=openid+email
  │
  │  2. Utilisateur s'authentifie chez Google/GitHub
  │
  │  3. Google → POST /api/auth/oauth/{provider}  (code + state)
  │     → Validation state CSRF (Redis)
  │     → Échange code → access_token (HTTP Google API)
  │     → Récupération profil (email, oauth_subject)
  │     → Création ou récupération User en base
  │     ← 200 OK (JWT pair pour mobile, cookie pour desktop)
```

### 15.2 Endpoints OAuth

| Méthode | Route | Description |
|---------|-------|-------------|
| `GET` | `/api/auth/oauth/{provider}/redirect` | Génère le state CSRF et redirige vers le provider |
| `POST` | `/api/auth/oauth/{provider}` | Reçoit le code d'autorisation, échange, crée/retrouve le compte |

### 15.3 Sécurité OAuth

- Le `state` est un token aléatoire 32 bytes, stocké en Redis avec TTL 10 minutes
- Le `redirect_uri` est **hardcodé** côté serveur — jamais lu depuis le payload client (protection open redirect)
- Les providers autorisés sont une **whitelist stricte** : `["google", "github"]`
- `oauth_subject` est stocké haché en base (unicité sans exposition de l'ID provider)

---

## 16. Flux JWT mobile (EdDSA)

### 16.1 Structure du JWT

```
Header : { "alg": "EdDSA", "typ": "JWT" }
Payload : {
  "sub": "018f4e8b-1234-7abc-89de-f012345678ab",  // user.id (UUID)
  "plan": "free",                                   // plan actuel
  "iat": 1722175200,
  "exp": 1722176100,                                // 15 minutes
  "jti": "018f4f00-0000-7abc-89de-000000000001"    // ID unique (protection rejeu)
}
Signature : EdDSA (Ed25519, clé privée dans Docker Secret)
```

**Champs exclus du JWT :** email, nom, historique, quotas — ces données sont récupérées via `GET /api/me` pour éviter la désynchronisation.

### 16.2 Rotation des refresh tokens

```
POST /api/token/refresh (refresh_token_A)
  → Validation token_hash en base (non révoqué, non expiré)
  → Révocation refresh_token_A (revoked = true)
  → Génération refresh_token_B (même family_id)
  ← Nouveau pair access_token + refresh_token_B

Si refresh_token_A réutilisé après révocation :
  → Détection : revoked = true pour un token de la famille
  → Invalidation de TOUS les refresh tokens de la family_id
  → Retour 403 — re-login obligatoire
```

### 16.3 Biométrie mobile (FR-032)

La biométrie (Face ID / Touch ID via `local_auth`) déverrouille le `refresh_token` stocké dans `flutter_secure_storage`. **Aucun appel serveur** lors de l'authentification biométrique locale — le déverrouillage est purement local. Le refresh token est ensuite utilisé normalement pour obtenir un nouvel access token via `POST /api/token/refresh`.

---

## 17. Gestion des clés API

### 17.1 Génération

Format de la clé brute : `bai_live_{32_bytes_base58url}` (préfixe identifiable, 48 caractères au total).

En base : seul le `SHA-256(keyBrute)` est stocké (`api_keys.key_hash`). La valeur brute n'est accessible qu'une seule fois, à la création.

### 17.2 Vérification

```php
// src/Infrastructure/Security/ApiKeyAuthenticator.php
public function authenticate(Request $request): Passport
{
    $rawKey = $request->headers->get('Authorization');
    $rawKey = str_replace('Bearer ', '', $rawKey);

    // Timing-safe comparison via hash
    $keyHash = hash('sha256', $rawKey);
    $apiKey = $this->apiKeyRepository->findByHash($keyHash);

    if (null === $apiKey || null !== $apiKey->getRevokedAt()) {
        throw new AuthenticationException();
    }

    // Mise à jour last_used_at (async via Messenger)
    $this->bus->dispatch(new UpdateApiKeyLastUsedMessage($apiKey->getId()));

    return new SelfValidatingPassport(new UserBadge($apiKey->getUserId()->toString()));
}
```

### 17.3 Révocation

La révocation est immédiate : `revoked_at = NOW()` en base. Pas de cache intermédiaire — chaque requête vérifie la base (avec un index `(key_hash, revoked_at)`). Délai de prise en compte : < 1 seconde (NFR critique EPIC-006).

---

## 18. Documentation OpenAPI automatique

### 18.1 Génération par API Platform

API Platform 4 génère automatiquement la spécification OpenAPI 3.1 à partir :
- Des attributs `#[ApiResource]` et `#[ApiProperty]`
- Des groups de sérialisation
- Des contraintes Symfony Validator
- Des `openapiContext` dans les opérations

Endpoint : `GET /api/docs.json` (spec JSON), `GET /api/docs` (Swagger UI)

### 18.2 Personnalisations OpenAPI

```php
// config/packages/api_platform.yaml
api_platform:
    openapi:
        contact:
            name: 'Briefly AI Developer Support'
            url: 'https://briefly.ai/developers'
            email: 'api@briefly.ai'
        license:
            name: 'Proprietary'
        terms_of_service: 'https://briefly.ai/terms'
        externalDocs:
            url: 'https://briefly.ai/developers'
            description: 'Getting Started Guide'
```

### 18.3 Validation en CI

```yaml
# .github/workflows/ci.yml
- name: Validate OpenAPI spec
  run: |
    php bin/console api:openapi:export --output=openapi.json
    npx @redocly/cli lint openapi.json --config=.redocly.yaml
```

Zero erreur de lint bloquante en CI (FR-047, critère 6 EPIC-006).

### 18.4 Page Getting Started (/developers)

Page Twig statique (`/developers`) décrivant :
1. Obtention d'un token API (section "Compte & API Keys")
2. Premier appel `GET /api/v1/daily-brief`
3. Exemple cURL et Python requests
4. Lien vers Swagger UI (`/api/docs`)

Cette page est accessible sans authentification et indexée par les moteurs de recherche (SEO).

---

## 19. Checklist sécurité API

### Par endpoint (à valider avant merge)

- [ ] Authentification vérifiée (session, JWT ou API Key selon le contexte)
- [ ] Voter appelé avant toute opération sur la ressource
- [ ] UUID validé structurellement ET existence vérifiée en base (jamais de confiance sur la forme seule)
- [ ] Aucune URL externe acceptée dans les payloads déclenchant des appels serveur (règle SSRF)
- [ ] Contraintes Symfony Validator sur tous les champs d'entrée
- [ ] Messages d'erreur génériques (jamais de stack trace)
- [ ] Rate limiting configuré (Redis)

### Sécurité transverse

- [ ] Headers de sécurité présents (CSP, HSTS, COOP, COEP, CORP, X-Frame-Options)
- [ ] CORS configuré strictement (origins whitelistées pour `/api/`, `*` pour `/api/v1/`)
- [ ] `HttpOnly; Secure; SameSite=Strict` sur tous les cookies
- [ ] JWT signé EdDSA (Ed25519) — jamais HS256
- [ ] SHA-256 des API Keys en base — jamais en clair
- [ ] Stripe webhook validé par signature HMAC avant traitement
- [ ] OAuth state CSRF vérifié avant échange de code
- [ ] Test SSRF `tests/Security/SsrfTest.php` en CI (T-PRE-02)

### RGPD

- [ ] Aucun email, IP directe ou identifiant personnel dans les logs (UUID uniquement)
- [ ] Aucun identifiant utilisateur dans les prompts LLM
- [ ] Consentement vérifié avant envoi de notification (BC-NOTIFICATIONS)
- [ ] Export RGPD disponible (`GET /api/me/data-export`)
- [ ] Suppression en cascade documentée (`DELETE /api/me`)

---

*Ce document est la référence normative pour l'implémentation des endpoints Briefly AI. Toute modification d'un endpoint doit être répercutée ici avant merge. Les gaps G-01 (table `reading_history`) et G-07 (spec CMP) restent ouverts et seront adressés respectivement avant Sprint 3 et Sprint 2 conformément à la Design Review.*
