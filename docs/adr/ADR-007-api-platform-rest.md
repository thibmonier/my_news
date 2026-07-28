# ADR-007 — API : API Platform 4 REST/OpenAPI + versionnement /v1 + rate limiting

**Statut :** Accepté — 2026-07-28
**Auteur :** Tech Lead (CSM)
**Décideurs :** Tech Lead, Product Owner
**Références :** PRD §4.6 (FR-045 à FR-050), PRD §5.1 (NFR-002), constraints.md (T1, T7, B4, §9.7), technical-options.md §§1,5, backlog EPIC-006 (US-050 à US-055), risks-opportunities.md (OPP-04)

---

## Contexte

Briefly AI expose une API REST pour deux consommateurs distincts :

### Consommateur 1 — Application Flutter mobile (interne)

- Tous les écrans Flutter appellent l'API via `dio` + intercepteurs JWT.
- Opérations : authentification, lecture des briefs, lecture des articles, synthèse IA à la demande, gestion du profil, quotas.
- Contrat API **stable mais évolutif** — la même API est partagée par mobile et (potentiellement) un futur frontend headless.
- Volume : ~5 000 utilisateurs actifs × ~20 appels/session = 100 000 requêtes/jour en régime M6.

### Consommateur 2 — API publique Premium (externe)

- P-003 (Marc) consomme l'API depuis son home server (Grafana, scripts Rust).
- Endpoints : `GET /v1/daily-brief`, `GET /v1/daily-brief/{date}`, `GET /v1/articles`, `GET /v1/articles/{id}`, `POST /v1/synthesize`.
- Rate limit : 100 requêtes/heure par clé API (FR-048).
- Documentation OpenAPI 3.1 auto-générée, accessible sur `/api/docs` (FR-047).

### Contraintes et tensions

1. **Versionnement** : l'API mobile (interne) et l'API publique Premium doivent coexister sous un préfixe versionné (`/v1`) pour permettre des évolutions cassantes sans impacter les consommateurs actuels (contrainte T7).
2. **Sécurité asymétrique** : l'API mobile utilise JWT Bearer ; l'API publique utilise une clé API Bearer. Les deux passent par le même routeur API Platform mais avec des authenticators différents.
3. **OpenAPI auto-générée** : les clients Premium doivent disposer d'une documentation toujours à jour — la documentation manuelle est prohibée (YAGNI + risque de désynchronisation).
4. **Performance** : P95 < 200 ms (NFR-002) sur les endpoints de lecture. Les endpoints de synthèse IA sont soumis à la latence LLM (< 8 s, NFR-003) — traités de façon asynchrone ou avec réponse en streaming.
5. **Droits d'accès** : les endpoints publics (brief du jour sans auth), les endpoints authentifiés (synthèses), et les endpoints Premium (API publique, export) ont des droits distincts. Symfony Security Voters gèrent cette granularité.

---

## Décision

**API Platform 4 comme couche API unique, REST/JSON-LD, versionnée sous `/api/v1`, avec OpenAPI 3.1 auto-générée, rate limiting Redis, et Symfony Security Voters pour le contrôle d'accès.**

### 1. API Platform 4 — configuration

**Mode d'utilisation :** API Platform est utilisé en mode **Resource-centric** avec des `ApiResource` Doctrine bien bornées — pas de CRUD automatique exposé globalement. Chaque opération est explicitement déclarée (`Get`, `GetCollection`, `Post`, `Patch`, `Delete`) avec ses groupes de sérialisation, ses voters, et ses filtres autorisés.

```php
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/v1/briefs/{date}',
            normalizationContext: ['groups' => ['brief:read']],
            security: "is_granted('BRIEF_VIEW', object)",
        ),
        new GetCollection(
            uriTemplate: '/v1/briefs',
            normalizationContext: ['groups' => ['brief:list']],
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 25,
        ),
    ]
)]
```

**Groupes de sérialisation :**

| Groupe | Contenu | Audience |
|--------|---------|----------|
| `brief:list` | titre, date, 3 histoires (résumé 1 phrase) | Public + Free + Premium |
| `brief:read` | détail complet + sources + métadonnées cluster | Free (limité) + Premium |
| `article:list` | titre, source, published_at, url_hash | Premium API |
| `article:read` | contenu + synthèse IA (si générée) + métriques | Premium API |
| `summary:read` | texte synthèse + niveau + provider + cached | Free (quota) + Premium |
| `user:profile` | email, préférences, quota_remaining | Authentifié uniquement |

**Opérations minimales** : API Platform est configuré avec `defaults: { openapiContext: ..., security: ... }` au niveau global — aucune opération n'est exposée sans voter explicite (deny by default, NFR-011).

### 2. Versionnement — préfixe `/api/v1`

- Toutes les routes API Platform sont préfixées `/api/v1/` via la configuration `routePrefix: '/v1'` dans `config/packages/api_platform.yaml`.
- L'UI OpenAPI est accessible sur `/api/docs` (API Platform Swagger UI natif).
- La version est portée par le **préfixe d'URL** (pas de header `Accept: application/vnd.briefly.v1+json`) — plus lisible, plus simple à cacher (Varnish/Nginx), compatible curl sans header spécial.
- La v2 future pourra coexister sur `/api/v2/` sans impact sur `/api/v1/`.
- Les endpoints desktop Twig (`/`, `/brief/...`, `/account/...`) ne sont **pas** des endpoints API Platform — ils sont gérés par des controllers Symfony classiques.

### 3. Authentification — deux mécanismes sur le même routeur

#### Endpoints mobiles (JWT Bearer)

- `Authorization: Bearer {access_token}` (JWT EdDSA, TTL 15 min).
- `JWTAuthenticator` Symfony Security appliqué sur les routes `/api/v1/*` avec attribut `security` non-public.
- Les endpoints publics (`GET /api/v1/briefs/{date}` sans auth) sont marqués `security: "is_granted('PUBLIC_ACCESS')"`.

#### Endpoints API publique Premium (clé API Bearer)

- Clé API générée à la création du compte Premium (UUID v4 préfixé `bai_`), stockée hashée en PostgreSQL.
- `Authorization: Bearer bai_{uuid}` — vérification via un `ApiKeyAuthenticator` Symfony custom.
- La clé API est liée à l'utilisateur Premium et hérite de ses droits via le Voter.
- Révocation : suppression de la clé en base (invalidation immédiate, pas de TTL JWT).
- Rate limit Redis sur la clé : `rl:api:{api_key_hash}` — fenêtre glissante 1h, compteur `INCR` + `EXPIREAT`.

### 4. Rate limiting — Redis + headers RFC 6585

```
Rate-Limit: 100
Rate-Limit-Remaining: 73
Rate-Limit-Reset: 1753711200
Retry-After: 3600  (si 429 Too Many Requests)
```

- Implémenté via un `RequestRateLimiter` Symfony appliqué sur les routes `/api/v1/*` en tant que `EventListener` (avant les opérations API Platform).
- La clé de rate limit est `api_key_hash` (Premium) ou `ip_address` (public anonyme, limite plus basse : 10 req/heure).
- Réponse en cas de dépassement : `HTTP 429 Too Many Requests` + header `Retry-After`.
- Les robots légitimes (monitoring, tests CI) peuvent demander un quota IP whitelist via configuration admin.

### 5. Pagination — cursor-based (FR-049)

Pour `GET /api/v1/articles` et `GET /api/v1/briefs` :

- Pagination par curseur sur `created_at DESC` + `id DESC` (stable même en cas d'insertions concurrentes).
- Format : `?after={cursor}` où `cursor` est un token opaque base64(published_at:id).
- API Platform propose `CursorBasedPagination` — configuré comme pagination par défaut sur les collections de grande taille.
- La pagination par offset (`?page=N`) est désactivée sur les endpoints publics (instable sur les grandes tables, favorise les scans complets).

### 6. OpenAPI 3.1 — documentation auto-générée

- Générée nativement par API Platform depuis les annotations/attributs PHP 8.
- Accessible sur `/api/docs` (Swagger UI) et `/api/docs.json` (JSON brut).
- Les `#[OA\Parameter]`, `#[OA\Response]`, et `#[OA\Schema]` enrichissent la doc sans duplication de code.
- La CI vérifie que le fichier `docs/openapi.json` est à jour (commande `api:openapi:export` dans le pipeline).
- La doc est publique pour les endpoints publics, authentifiée (cookie session) pour les endpoints Premium.

### 7. Sécurité des opérations — Symfony Security Voters

Chaque opération API Platform passe par un Voter dédié :

| Voter | Condition |
|-------|-----------|
| `BriefVoter::VIEW` | Public (anonyme ou authentifié) |
| `ArticleVoter::VIEW` | Authentifié (Free ou Premium) |
| `SummaryVoter::CREATE` | Authentifié + quota restant > 0 (Free) ou Premium |
| `ApiKeyVoter::USE` | Clé API valide + abonnement Premium actif |
| `ExportVoter::CREATE` | Premium uniquement |

Les voters consultent Redis pour le quota (clé `quota:free:{user_uuid}:{date}`) et PostgreSQL pour le statut d'abonnement (cache Redis 5 min sur le statut Premium pour éviter N+1 sur chaque requête).

---

## Alternatives considérées

### A1 — GraphQL (via API Platform) à la place de REST

**Pour :**
- Requêtes flexibles côté client — Flutter peut demander exactement les champs nécessaires, réduisant la sur-sérialisation.
- Un seul endpoint, pas de versionnement de routes.
- Utile pour P-002 (Priya) qui veut des exports sélectifs de champs.

**Contre :**
- GraphQL est plus difficile à cacher efficacement (les requêtes POST avec corps JSON ne sont pas cachées par défaut par les CDN/Varnish).
- La documentation GraphQL (introspection) est moins lisible pour P-003 (Marc) qui veut `curl /v1/daily-brief` immédiatement — REST est plus universel.
- Le rate limiting sur GraphQL est complexe (on limite par requête, pas par opération — un client peut faire une requête très coûteuse en un seul appel).
- API Platform propose GraphQL, mais la configuration des permissions par champ est plus verbeuse que les Voters REST.
- L'hypothèse de "client flexible" est sur-dimensionnée pour les 6 endpoints v1 — la réduction du payload peut être obtenue avec des groupes de sérialisation REST.
- N+1 en GraphQL côté Doctrine sans DataLoader — complexité supplémentaire.

**Rejetée :** Le gain de flexibilité est insuffisant face aux pertes sur le cache, le rate limiting, et la simplicité d'accès pour P-003. REST + groupes de sérialisation suffit pour les 6 endpoints v1.

---

### A2 — API Platform désactivé, controllers Symfony purs

**Pour :**
- Contrôle total sur la sérialisation et le routing.
- Pas de "magie" API Platform à déboguer.
- Moins de dépendances.

**Contre :**
- Réécriture de tout ce qu'API Platform fournit gratuitement : OpenAPI auto-générée, pagination, filtres, sérialisation Symfony, IRI, format JSON-LD/Hydra.
- La documentation OpenAPI (FR-047) devient un travail manuel permanent — désynchronisation garantie à mesure que l'API évolue.
- Les Voters et la sécurité par opération sont disponibles nativement dans API Platform mais nécessitent un câblage custom avec des controllers purs.
- La contrainte T1 (Symfony 8 + API Platform 4) est non négociable.

**Rejetée :** Contrainte T1. API Platform 4 est la couche API de référence du projet — ne pas l'utiliser serait contre-productif et contraire aux décisions d'architecture.

---

### A3 — Versionnement par header (`Accept: application/vnd.briefly.v1+json`)

**Pour :**
- URL propres sans version (`/api/briefs` au lieu de `/api/v1/briefs`).
- Standard REST "puriste" (Roy Fielding).

**Contre :**
- Impossible à cacher efficacement par un CDN ou Nginx sans configuration avancée (Vary header).
- Difficile à tester en curl sans ajouter `-H "Accept: application/vnd.briefly.v1+json"` — friction pour P-003.
- API Platform supporte le versionnement par header mais la configuration est plus complexe.
- Le versionnement par préfixe d'URL est le choix retenu par la majorité des APIs REST modernes (Stripe, GitHub, Twilio) pour des raisons pratiques.

**Rejetée :** Le versionnement par préfixe URL est plus simple, plus cacheable, et plus accessible pour les développeurs tiers (P-003 OPP-04).

---

### A4 — API Platform + gRPC (Flutter consomme gRPC)

**Pour :**
- Protocole binaire — réduction du payload et de la latence réseau pour le mobile.
- Streaming natif — idéal pour les synthèses IA longues (streaming progressif des tokens Mistral).
- Typage strict via Protobuf.

**Contre :**
- API Platform ne supporte pas gRPC nativement — nécessite un proxy gRPC (Envoy) ou un serveur gRPC séparé (FrankenPHP/Caddy supporte HTTP/2 mais pas gRPC Protobuf sans configuration spécifique).
- Le plugin Dart `grpc` ajoute une complexité significative à la couche réseau Flutter.
- La documentation OpenAPI ne couvre pas gRPC — impossible de satisfaire FR-047 (doc OpenAPI auto-générée) sans un système parallèle.
- L'API publique Premium (P-003) serait inaccessible facilement sans client gRPC — incompatible avec l'usage `curl` ou Grafana.
- Les gains de latence gRPC vs REST JSON sous HTTPS/2 sont négligeables pour les payloads de l'ordre de quelques Ko (briefs, synthèses).

**Rejetée :** Surcoût d'infrastructure disproportionné. Le streaming des tokens LLM peut être géré par Server-Sent Events (SSE) sur HTTP, supporté nativement par API Platform + Flutter `dio`.

---

### A5 — Rate limiting applicatif (PHP) sans Redis

**Pour :**
- Pas de dépendance Redis pour le rate limiting.
- Symfony Rate Limiter peut stocker son état dans n'importe quel cache Symfony (APCu, fichier).

**Contre :**
- APCu est local au processus PHP — avec FrankenPHP en worker mode et 10 workers potentiels, le compteur n'est pas partagé entre les workers (un utilisateur peut envoyer 100 × N requêtes en contournant le rate limit par worker).
- Le stockage fichier est non adapté à la fréquence élevée (lock files, I/O).
- Redis est déjà requis pour les sessions, les quotas Free, et les streams Messenger — ajouter le rate limiting Redis est sans surcoût.

**Rejetée :** Le mode worker FrankenPHP exige un état partagé entre workers → Redis est la seule option correcte.

---

## Conséquences

### Positives

- **Documentation OpenAPI toujours à jour** (FR-047) : générée automatiquement depuis les attributs PHP, la CI détecte toute divergence.
- **Contrat stable pour Flutter et l'API publique** : le préfixe `/api/v1/` garantit que les évolutions futures ne cassent pas les clients existants.
- **Sécurité granulaire** via Voters : chaque opération a ses prérequis explicites, vérifiés à chaque requête (deny by default, NFR-011).
- **Scalabilité du rate limiting** : Redis Streams + INCR atomique tient la charge de 100 req/h × N clés API sans contention.
- **P-003 satisfait** : `GET /api/v1/briefs/2026-07-28` accessible en curl avec `Authorization: Bearer bai_...` — intégration Grafana/script immédiate (OPP-04).
- **Pagination curseur** : stable sur les grandes collections d'articles en insertion continue — pas de skips imprévisibles comme avec l'offset.

### Négatives / Points d'attention

- **Verbosité des attributs** API Platform sur les entités Doctrine — les classes `Article`, `Brief`, `Summary` deviennent chargées d'annotations. Mitigation : séparer les `ApiResource` dans des classes DTO dédiées (pattern State Provider/Processor) pour ne pas polluer les entités domaine (architecture hexagonale).
- **Complexité des groupes de sérialisation** : maintenir la cohérence entre `brief:list`, `brief:read`, `article:list`, `article:read` demande de la discipline. Un test de contrat (`PHPUnit ApiTestCase`) sur chaque endpoint est obligatoire pour détecter les régressions.
- **JSON-LD / Hydra** natif dans API Platform : les clients Flutter doivent ignorer les champs `@context`, `@type`, `@id` ou la sérialisation doit être configurée en `jsonld:false` pour les endpoints mobiles (format `json` pur). À trancher en Sprint 2 lors de l'implémentation Flutter.
- **OpenAPI export en CI** : la commande `php bin/console api:openapi:export > docs/openapi.json` doit être intégrée dans le pipeline CI et commitée — ajoute un artefact à gérer dans git (ou exclure et régénérer à chaque CI run).
- **Endpoint POST /v1/synthesize** : la synthèse IA n'est pas synchrone (latence jusqu'à 8 s). En v1, la réponse est synchrone (le client attend). En v2 (si la latence devient problématique) : basculer vers une réponse `202 Accepted` + polling ou SSE streaming. Ce choix doit être documenté dans l'OpenAPI (`x-async: true`).

---

## Implémentation — points d'architecture

- Bundle : `api-platform/core:^4.0`, `symfony/security-bundle`, `lexik/jwt-authentication-bundle`.
- Configuration : `config/packages/api_platform.yaml` avec `route_prefix: '/v1'`, `formats: {json: ['application/json'], jsonld: ['application/ld+json']}`.
- Voters : `src/Infrastructure/Security/Voter/` (un fichier par ressource métier).
- Rate limiter : `symfony/rate-limiter` + store Redis, configuré dans `config/packages/rate_limiter.yaml`.
- Pagination : `CursorBasedPagination` déclarée comme `paginationClientItemsPerPage: true, paginationMaximumItemsPerPage: 25`.
- Tests : `ApiTestCase` (API Platform) pour chaque endpoint — statuts HTTP, groupes de sérialisation, Voter rejections, rate limit 429.

---

*ADR validé en Sprint Planning Sprint 1 — 2026-07-28*
