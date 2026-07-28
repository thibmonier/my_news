# Conception Sécurité — Briefly AI

**Version :** 1.0.0
**Date :** 2026-07-28
**Statut :** Validé Tech Lead (post Design Review GO CONDITIONNEL)
**Références :** `prd.md` (NFR-011 à NFR-020) · `tech-spec.md §16 + §17` · `constitution.md §6` · `design-review.md §4.4` · `.claude/rules/11-security.md`
**Périmètre :** OWASP Top 10:2025, SSRF, Authentification, RGPD, AI Act, Headers HTTP 2026, Secrets, Checklist release

---

## Table des matières

1. [Posture de sécurité globale](#1-posture-de-sécurité-globale)
2. [Mapping OWASP Top 10:2025](#2-mapping-owasp-top-102025)
3. [SSRF — Risque Majeur](#3-ssrf--risque-majeur)
4. [Stratégie d'authentification](#4-stratégie-dauthentification)
5. [RGPD et AI Act](#5-rgpd-et-ai-act)
6. [Headers de sécurité 2026](#6-headers-de-sécurité-2026)
7. [Gestion des secrets](#7-gestion-des-secrets)
8. [Checklist sécurité par release](#8-checklist-sécurité-par-release)

---

## 1. Posture de sécurité globale

### 1.1 Principes fondateurs (non négociables — Constitution §6)

| Principe | Décision figée |
|----------|---------------|
| Deny by default | Toute ressource API Platform requiert un Voter explicite. HTTP 403 si non autorisé. |
| Privacy by design | Aucun identifiant utilisateur dans les prompts LLM (INV-6). Mode on-device crédible (P-003). |
| Cryptographie moderne | Argon2id (128 MiB, t=3, p=1) + JWT EdDSA (Ed25519). Interdit : MD5, SHA1, bcrypt en nouveau code, HS256. |
| SSRF neutralisé | Deux vecteurs identifiés et fermés by design (§3). |
| Supply chain auditée | SBOM CycloneDX à chaque build CI. Trivy + composer audit bloquants. |
| Logs sans PII | UUIDs non séquentiels. IPs hashées (salt rotatif 30j). Aucun email ni user.id en clair dans les logs. |
| Hébergement EU | Hetzner Cloud ou OVH uniquement. Données personnelles et prompts LLM ne quittent pas l'UE. |

### 1.2 Surface d'attaque identifiée

```
Internet
  │
  ├── FrankenPHP :443 (HTTPS only, TLS 1.3)
  │     ├── GET /brief/{date}          — Public, SSR, ETag
  │     ├── POST /api/login            — Rate limit 5/15min Redis
  │     ├── POST /api/token/refresh    — JWT rotation + détection de vol
  │     ├── POST /api/syntheses        — UUID interne uniquement (SSRF neutralisé)
  │     ├── POST /api/auth/oauth/*     — OAuth2 callback (state CSRF)
  │     ├── POST /api/webhook/stripe   — HMAC signature Stripe obligatoire
  │     └── GET /api/v1/*             — API Key Bearer (Premium)
  │
  ├── Pipeline RSS (workers internes)
  │     └── FeedIo::read(feedUrl)     — SSRF vecteur 2 (§3.2)
  │
  └── Flutter app (iOS/Android)
        └── JWT Bearer → /api/*       — Stockage flutter_secure_storage
```

Aucun port Redis ni PostgreSQL exposé hors réseau Docker interne.

---

## 2. Mapping OWASP Top 10:2025

### #1 — Broken Access Control (inclut SSRF consolidé)

**Risques pour Briefly AI :**
- Accès à une synthèse ou un article d'un autre utilisateur via UUID manipulé
- Escalade Free → Premium en manipulant le compteur de quota
- SSRF via URL RSS injectée ou payload `/api/syntheses`

**Mesures concrètes :**

```php
// Voter par ressource — deny by default
class SynthesisVoter extends Voter {
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool {
        $user = $token->getUser();
        if (!$user instanceof User) return false;

        return match($attribute) {
            self::GENERATE => $this->quotaTracker->canGenerate($user),
            self::VIEW      => $subject->getOwner()->equals($user->getId()),
            default         => false,
        };
    }
}

// ArticleVoter, BriefVoter, ApiKeyVoter — même pattern
```

| Mesure | Détail |
|--------|--------|
| Voters Symfony | `ArticleVoter`, `SynthesisVoter`, `BriefVoter`, `ApiKeyVoter`, `QuotaVoter` — un Voter par ressource sensible |
| Deny by default | `security.yaml` : `access_control: [{path: ^/api, roles: IS_AUTHENTICATED_FULLY}]` avec exceptions explicites (routes publiques listées) |
| UUIDs v4 non prédictibles | `Uuid::v4()` via `symfony/uid` — aucun ID auto-incrémenté exposé côté API |
| Row-level security | Chaque requête d'une entité personnelle vérifie `entity.user_id = current_user.id` dans le Voter (pas seulement l'authentification) |
| SSRF | Traitement dédié en §3 |

**Test requis (T-PRE-02 issu du Design Review) :**
```php
// tests/Security/AccessControlTest.php
it('denies access to synthesis belonging to another user', function () {
    $response = $this->client->request('GET', '/api/syntheses/' . $otherUserSynthesisId, [
        'auth_bearer' => $this->currentUserToken,
    ]);
    $this->assertResponseStatusCodeSame(403);
});
```

---

### #2 — Cryptographic Failures

**Risques pour Briefly AI :**
- Mot de passe haché avec algorithme faible (bcrypt, MD5)
- JWT signé HS256 avec secret faible partagé
- Refresh token stocké en clair dans la base
- Clé API stockée en clair et volée par une injection SQL

**Mesures concrètes :**

| Contexte | Algorithme | Paramètres |
|----------|-----------|------------|
| Mots de passe | Argon2id | 128 MiB RAM, t=3, p=1 — `sodium_crypto_pwhash` PHP natif |
| JWT mobile (access token) | EdDSA (Ed25519) | Expiration 15 min — `lexik/jwt-authentication-bundle` |
| JWT mobile (refresh token) | SHA-256 du token | Stocké hashé en base (`refresh_tokens.token_hash`), jamais en clair |
| Clés API | SHA-256 de la clé | `api_keys.key_hash` — clé affichée une seule fois à la création |
| Session desktop | `APP_SECRET` 32 bytes random | Cookie HttpOnly, Secure, SameSite=Strict |
| Secrets en production | Docker Secrets | Injectés en mémoire uniquement, jamais dans l'image |
| TLS | TLS 1.3 minimum | FrankenPHP (Caddy) — TLS 1.2 désactivé en prod |
| Sauvegardes DB | AES-256-GCM | `pg_dump` chiffré avant upload S3 EU |

```php
// src/Domain/Account/ValueObject/PasswordHash.php
final class PasswordHash {
    private function __construct(private readonly string $hash) {}

    public static function fromPlaintext(string $password): self {
        // Argon2id — sodium_crypto_pwhash_str
        $hash = \sodium_crypto_pwhash_str(
            $password,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, // t=3
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE  // 128 MiB
        );
        return new self($hash);
    }

    public function verify(string $plaintext): bool {
        return \sodium_crypto_pwhash_str_verify($this->hash, $plaintext);
    }
}
```

**Ce qui est strictement interdit (Constitution §4) :**
- `password_hash($pwd, PASSWORD_BCRYPT)` — remplacé systématiquement par Argon2id
- `md5()`, `sha1()` sur des données sensibles
- `JWT::encode($payload, 'secret', 'HS256')` — l'algorithme EdDSA est obligatoire
- `base64_encode($refreshToken)` stocké en base — seul le hash SHA-256 est stocké

---

### #3 — Injection

**Risques pour Briefly AI :**
- Injection SQL via le titre d'article ou l'URL d'une source RSS malveillante
- XSS via le contenu des synthèses IA affiché dans Twig
- Injection de commande via les paramètres du Scheduler

**Mesures concrètes :**

```php
// src/Infrastructure/Persistence/DoctrineArticleRepository.php
// TOUJOURS DQL paramétré — jamais de concaténation
public function findByCategory(string $category, \DateTimeImmutable $since): array {
    return $this->getEntityManager()
        ->createQuery('SELECT a FROM Article a WHERE a.categoryTag = :cat AND a.publishedAt > :since')
        ->setParameter('cat', $category)
        ->setParameter('since', $since)
        ->getResult();
}

// INTERDIT — exemple de ce qu'il ne faut jamais faire :
// "SELECT * FROM articles WHERE category = '" . $category . "'" ← SQL Injection
```

| Couche | Mesure | Implémentation |
|--------|--------|----------------|
| SQL | Doctrine ORM uniquement | Zéro concaténation SQL. QueryBuilder ou DQL paramétré. |
| Input serveur | Symfony Validator (whitelist) | UUID format strict sur `article_id`, email format sur `email`, longueur max sur tous les champs texte |
| Output Twig | Auto-escape activé | `twig.autoescape: true` dans `config/packages/twig.yaml`. Jamais de filtre `\|raw` sur données utilisateur |
| Flux RSS | FeedIo parsing | Entités HTML décodées par la bibliothèque. Contenu des articles tronqué à 500 chars avant stockage |
| Prompts LLM | Sanitisation du contenu éditorial | `article.contentSnippet` : suppression balises HTML, troncature 500 chars, pas d'interpolation de données utilisateur |
| Scheduler | Paramètres typés | `FetchSourceTask` reçoit un `Source::$id` (UUID validé), jamais une chaîne arbitraire |

**Test requis :**
```php
// tests/Security/SsrfTest.php (T-PRE-02 Design Review)
it('rejects URL payload in POST /api/syntheses', function () {
    $response = $this->client->request('POST', '/api/syntheses', [
        'json' => ['article_id' => 'https://evil.com/steal', 'level' => 'CONCISE'],
        'auth_bearer' => $this->validToken,
    ]);
    $this->assertResponseStatusCodeSame(422); // Validation error — not a UUID
});
```

---

### #4 — Insecure Design

**Risques pour Briefly AI :**
- Pipeline d'ingestion sans rate limiting permettant l'épuisement des ressources
- Génération concurrente de deux DailyBriefs pour la même date
- Contournement du quota Free via plusieurs comptes avec la même IP

**Mesures concrètes (Threat Model intégré dans les ADR 001-007) :**

| Menace | Mesure | Implémentation |
|--------|--------|----------------|
| Ingestion abusive | Rate limiter Redis par source | `rate:source:{sourceId}:{window}` — 4 req/heure max |
| Brief dupliqué | Mutex Redis (SET NX) | `lock:brief:{date}` TTL 5 min — un seul `GenerateBriefTask` actif |
| Quota contourné | Lié à `user.id` (Redis) | `quota:{userId}:{date}` — impossible à contourner sans voler un compte |
| Scraping masse | Rate limit API + clé API obligatoire | 100 req/h pour les clés Premium, CAPTCHA sur login |
| Brute force | 5 tentatives / 15 min / IP | `rate:login:{ip}` Redis + `rate:login:account:{userId}` |
| Webhook Stripe faux | HMAC-SHA256 signature | `stripe-signature` validée avant tout traitement |
| Accès admin non autorisé | Rôle `ROLE_ADMIN` + Voter | Routes `/admin/*` et `/api/admin/*` protégées distinctement |

---

### #5 — Security Misconfiguration

**Risques pour Briefly AI :**
- Mode debug actif en production (stack traces exposées)
- Headers de sécurité manquants
- Redis ou PostgreSQL accessibles depuis l'extérieur

**Mesures concrètes :**

```yaml
# config/packages/when@prod/framework.yaml
framework:
    debug: false

# Caddyfile (FrankenPHP) — Headers complets en §6
# Voir section dédiée §6 pour le détail exhaustif
```

| Mesure | Détail |
|--------|--------|
| `APP_ENV=prod` | Debug désactivé. Pages d'erreur génériques (ExceptionSubscriber — tech-spec §11.3) |
| Headers sécurité | CSP L3, HSTS preload, COOP, COEP, CORP, X-Frame-Options: DENY (§6) |
| Réseau Docker isolé | Redis et PostgreSQL sur réseau bridge interne uniquement. Ports non exposés sur l'hôte |
| Utilisateur non-root | `USER www-data` dans le Dockerfile prod (tech-spec §14.2) |
| Filesystem read-only | Workers en mode lecture seule, `/tmp` seul répertoire accessible en écriture |
| Fonctionnalités désactivées | `php.ini prod` : `expose_php=Off`, `display_errors=Off`, `error_reporting=E_ALL & ~E_DEPRECATED` |
| Métriques protégées | `/metrics` (Prometheus) accessible uniquement depuis IP allowlist interne |
| OpenAPI en prod | `/api/docs` conservé mais protégé par authentification (pas accessible anonymement en prod) |

---

### #6 — Software Supply Chain Failures

**Risques pour Briefly AI :**
- Dépendance Composer avec CVE critique non patchée
- Image Docker de base compromise
- Webhook GitHub Actions malveillant

**Mesures concrètes :**

```yaml
# .github/workflows/ci.yml — étape sécurité (bloquante)
php-security:
  steps:
    - name: Composer audit
      run: composer audit --format=json --no-dev
      # Bloque si vulnérabilité HIGH ou CRITICAL

    - name: Trivy image scan
      run: trivy image --exit-code 1 --severity HIGH,CRITICAL briefly/app:${{ github.sha }}

    - name: SBOM génération
      run: syft briefly/app:${{ github.sha }} -o cyclonedx-json > sbom-${{ github.sha }}.json

    - name: Upload SBOM as artifact
      uses: actions/upload-artifact@v4
      with:
        name: sbom-${{ github.sha }}
        path: sbom-${{ github.sha }}.json
```

| Mesure | Détail |
|--------|--------|
| `composer.lock` commité | Versions exactes pinées. Jamais de `^` sur les dépendances de sécurité |
| `composer audit` | CI bloquant si CVE HIGH/CRITICAL. Exécuté en job séparé (pas noyé dans `composer install`) |
| Dependabot | PRs auto sur les mises à jour de sécurité. Review Tech Lead obligatoire avant merge |
| Trivy image scan | Scan de l'image Docker complète (base + dépendances OS). Bloquant en CI |
| SBOM CycloneDX | Généré à chaque build via `syft`. Archivé comme artefact CI. Format SPDX 3 en option v2 |
| Images de base pinées | `FROM dunglas/frankenphp:1.x.y-php8.5-alpine` — version exacte, pas `latest` |
| GitHub Actions épinglées | Actions tierces référencées par commit SHA (`uses: actions/checkout@abc1234`) |
| Revue dépendances | Audit trimestriel des dépendances NPM (assets Symfony UX) et Pub (Flutter) |

---

### #7 — Mishandling of Exceptional Conditions

**Risques pour Briefly AI :**
- Stack trace PostgreSQL exposée si une requête échoue en production
- Timeout Mistral → exception non gérée → 500 sans fallback
- Flux RSS malformé → crash du worker d'ingestion

**Mesures concrètes (tech-spec §11 — intégrées ici pour exhaustivité) :**

```php
// src/Infrastructure/Api/ExceptionSubscriber.php
// Toutes les DomainException → RFC 7807 Problem Details
// Aucune stack trace dans la réponse JSON en prod

class ExceptionSubscriber implements EventSubscriberInterface {
    public function onKernelException(ExceptionEvent $event): void {
        $exception = $event->getThrowable();

        if ($exception instanceof DomainException) {
            $response = new JsonResponse([
                'type'   => "https://briefly.ai/errors/{$exception->getErrorCode()}",
                'title'  => $exception->getMessage(),
                'status' => $exception->getHttpStatus(),
            ], $exception->getHttpStatus());
            $event->setResponse($response);
        }
        // Les 5xx ne retournent qu'un message générique en prod
        // La stack trace va dans Sentry uniquement
    }
}
```

| Contexte | Comportement | Log |
|----------|-------------|-----|
| Timeout Mistral | Circuit breaker → `OpenAIFallbackProvider` — RTO < 30s | WARNING + métrique Sentry |
| Flux RSS malformé | `FeedFetchException` → circuit breaker source OPEN | WARNING avec source_id |
| Quota dépassé | HTTP 429 + RFC 7807 avec `resetAt` | INFO (événement métier) |
| Erreur 5xx inattendue | Message générique HTTP 500, stack trace → Sentry uniquement | ERROR |
| Token JWT expiré | HTTP 401 + `TokenExpiredException` → client déclenche refresh flow | INFO |
| Webhook Stripe invalide | HTTP 400, événement rejeté | WARNING avec `stripe_event_id` |

---

### #8 — Authentication Failures

**Traitement complet en §4 (Stratégie d'authentification).**

Récapitulatif des mesures anti-brute force :

```
Rate limiter Redis (tech-spec §9.1, FR-036) :
  rate:login:{ip}           → max 5 tentatives / 15 min / IP
  rate:login:account:{uid}  → max 5 tentatives / 15 min / compte
  Dépassement → CAPTCHA obligatoire (hCaptcha ou Cloudflare Turnstile)

Rotation refresh token :
  Chaque /api/token/refresh émet un nouveau refresh token
  L'ancien token est révoqué (revoked=true dans refresh_tokens)
  Si un token révoqué est réutilisé → invalider TOUTE la famille (family_id)
  → Détection de vol de session automatique
```

---

### #9 — Logging & Monitoring Failures

**Mesures concrètes (tech-spec §12 — intégrées ici) :**

Événements de sécurité obligatoirement loggés :

| Événement | Niveau | Champs |
|-----------|--------|--------|
| Tentative de connexion échouée | WARNING | `{ip_hash, account_id_hash, attempt_count, reason}` |
| Rate limit déclenché | WARNING | `{ip_hash, endpoint, limit}` |
| Token JWT rejeté (expiré ou signature invalide) | INFO | `{endpoint, reason}` |
| Refresh token révoqué (détection de vol) | ERROR | `{family_id, revoked_token_hash}` |
| Voter a refusé l'accès | INFO | `{voter, resource_type, resource_id}` |
| Webhook Stripe avec signature invalide | WARNING | `{stripe_event_id}` |
| Circuit breaker source OPEN | WARNING | `{source_id, error_count, window}` |
| Quota Free atteint | INFO | `{user_id_hash, quota_date}` |
| Suppression compte RGPD (hard delete) | INFO | `{user_id_hash, deletion_date}` |

Ce qui n'est **jamais** loggé :
- `user.email`, `user.id` réel (uniquement UUID hashé avec salt)
- Mots de passe, tokens JWT, refresh tokens
- IPs en clair (hashées avec salt rotatif 30 jours)
- Contenus des synthèses IA (donnée de traitement)

**Alertes critiques (tech-spec §12.4) :**
- Aucun brief généré avant 7h00 UTC → CRITICAL PagerDuty
- Taux erreurs 5xx > 1 % sur 5 min → ERROR Slack #ops
- Circuit breaker Mistral OPEN > 5 min → WARNING Slack #ops
- 5 refresh tokens de la même `family_id` révoqués → ERROR (attaque potentielle)

---

### #10 — Data Integrity Failures

**Risques pour Briefly AI :**
- Webhook Stripe falsifié déclenchant un upgrade Premium frauduleux
- Dépendance Composer remplacée par un package malveillant (typosquatting)

**Mesures concrètes :**

```php
// src/Infrastructure/Billing/StripeWebhookHandler.php
public function __invoke(Request $request): Response {
    $payload   = $request->getContent();
    $sigHeader = $request->headers->get('Stripe-Signature');

    try {
        // Vérification HMAC-SHA256 obligatoire AVANT tout traitement
        $event = Webhook::constructEvent(
            $payload,
            $sigHeader,
            $this->stripeWebhookSecret // Docker Secret
        );
    } catch (SignatureVerificationException $e) {
        return new Response('Invalid signature', 400);
    }

    // Idempotence : vérifier si stripe_event_id déjà traité
    if ($this->stripeEventRepository->isAlreadyProcessed($event->id)) {
        return new Response('Already processed', 200);
    }
    // ...
}
```

| Mesure | Détail |
|--------|--------|
| HMAC Stripe | `Webhook::constructEvent()` valide la signature avant tout traitement |
| Idempotence | Table `stripe_events` avec `event_id UNIQUE` — doublon ignoré sans erreur |
| Checksums on-device | Modèle Phi-3 Mini vérifié SHA-256 après téléchargement (tech-spec §8.5) |
| SBOM + signatures | CycloneDX + Sigstore/cosign sur les images Docker en v2 |
| Dépendances Composer | `composer.lock` commité. `composer audit` bloquant. Vérification `author/name` avant ajout |

---

## 3. SSRF — Risque Majeur

Le SSRF (Server-Side Request Forgery) est identifié comme **risque majeur** pour Briefly AI car deux vecteurs coexistent : l'ingestion RSS (URLs configurer par l'admin) et l'endpoint de synthèse (potentiellement interprété comme acceptant des URLs par les développeurs).

### 3.1 Vecteur 1 — Pipeline d'ingestion RSS

**Description du risque :** Un administrateur malveillant ou un compte admin compromis pourrait configurer une source RSS pointant vers `http://169.254.169.254/latest/meta-data/` (AWS metadata), un service interne Docker (`http://postgres:5432/`), ou un endpoint de l'infrastructure interne.

**Défense en profondeur :**

```php
// src/Domain/Ingestion/ValueObject/ArticleUrl.php (validation SSRF)
// Utilisé aussi pour valider les feedUrl lors de l'ajout de source

final class FeedUrl {
    // Schémas autorisés
    private const ALLOWED_SCHEMES = ['https']; // HTTP interdit

    // Plages IP privées interdites
    private const PRIVATE_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '127.0.0.0/8', '169.254.0.0/16', // Link-local (AWS metadata)
        '::1/128', 'fc00::/7',             // IPv6 loopback + ULA
    ];

    private function __construct(private readonly string $url) {}

    public static function fromString(string $raw): self {
        $parsed = parse_url($raw);

        // 1. Schéma obligatoirement HTTPS
        if (!in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
            throw new InvalidFeedUrlException("Only HTTPS feeds are allowed: {$raw}");
        }

        // 2. Pas de localhost ni d'adresse IP directe
        $host = $parsed['host'] ?? '';
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidFeedUrlException("IP addresses are not allowed as feed URLs: {$host}");
        }
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new InvalidFeedUrlException("Localhost is not allowed: {$host}");
        }

        // 3. Résolution DNS vérifiée AVANT la requête (pas d'IP privée après résolution)
        $resolvedIp = gethostbyname($host);
        self::assertNotPrivateIp($resolvedIp, $host);

        // 4. Port autorisé uniquement (443, 80, 8080) — pas de port exotique vers services internes
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443, 8080], true)) {
            throw new InvalidFeedUrlException("Port {$port} is not allowed");
        }

        return new self($raw);
    }

    private static function assertNotPrivateIp(string $ip, string $host): void {
        foreach (self::PRIVATE_RANGES as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                throw new InvalidFeedUrlException("Resolved IP {$ip} for host {$host} is in private range {$cidr}");
            }
        }
    }
}
```

**Contrôles supplémentaires au niveau FeedIo :**

```php
// src/Infrastructure/Ingestion/FeedIoFetcher.php
// Timeout strict + suivi des redirections limité
$client = new \GuzzleHttp\Client([
    'timeout'         => 10,         // Pas de connexion qui traîne
    'connect_timeout' => 5,
    'allow_redirects' => [
        'max'     => 3,              // Max 3 redirections
        'strict'  => true,           // Respect RFC 2616
        'referer' => false,
        'on_redirect' => function ($request, $response, $uri) {
            // Re-valider la destination après chaque redirection
            FeedUrl::fromString((string) $uri);
        },
    ],
    'verify'          => true,       // Vérification certificat SSL
]);
```

**Mesure complémentaire (profondeur) :** En production, les workers `worker_ingestion` sont isolés dans un réseau Docker qui **bloque** les requêtes vers le sous-réseau `app` et le sous-réseau `postgres`/`redis`. Seul l'accès Internet (sortant HTTPS 443) est autorisé.

---

### 3.2 Vecteur 2 — Endpoint `POST /api/syntheses`

**Description du risque :** US-010 est intitulée "Synthèse IA à la demande sur URL (Walking Skeleton web)", ce qui pourrait laisser croire qu'un développeur implémente un endpoint acceptant une URL arbitraire en paramètre. Ce serait un vecteur SSRF direct permettant à l'attaquant de faire effectuer une requête HTTP par le serveur vers n'importe quelle URL interne.

**Décision de conception (C-05, Design Review) :** L'endpoint `POST /api/syntheses` n'accepte **jamais** d'URL en paramètre. Il accepte exclusivement un `article_id` (UUID interne) et un `level`.

```
Corps de requête autorisé :
  {"article_id": "018e7a6c-1234-7f45-a123-0f2b3c4d5e6f", "level": "CONCISE"}

Corps rejeté (HTTP 422) :
  {"article_id": "https://evil.internal.company.com/secrets", "level": "DETAILED"}
  {"url": "http://169.254.169.254/latest/meta-data/", "level": "CONCISE"}
  {"article_id": "../../etc/passwd", "level": "CONCISE"}
```

**Implémentation de la validation :**

```php
// src/Application/Synthesis/Command/GenerateSynthesisCommand.php
final class GenerateSynthesisCommand {
    public function __construct(
        #[Assert\Uuid(versions: [4, 7])] // Format UUID strict — rejette toute URL ou chemin
        public readonly string $articleId,
        #[Assert\Choice(['CONCISE', 'DETAILED', 'NARRATIVE'])]
        public readonly string $level,
    ) {}
}

// src/Domain/Synthesis/Port/SynthesisProviderInterface.php
// Le SynthesisService récupère l'article depuis PostgreSQL via l'articleId
// Aucune URL externe ne transite par cette couche
public function generate(Article $article, SynthesisLevel $level): SynthesisContent;
```

**Le contenu de l'article est toujours récupéré depuis PostgreSQL**, jamais depuis une URL fournie par l'utilisateur. La chaîne est : `article_id → PostgreSQL → contentSnippet → prompt Mistral`.

**Test de non-régression obligatoire (T-PRE-02) :**

```php
// tests/Security/SsrfTest.php
describe('SSRF protection on POST /api/syntheses', function () {
    it('rejects HTTP URL as article_id', function () {
        $response = $this->client->request('POST', '/api/syntheses', [
            'json' => ['article_id' => 'http://internal-host/secret', 'level' => 'CONCISE'],
            'auth_bearer' => $this->freeUserToken,
        ]);
        $this->assertResponseStatusCodeSame(422);
    });

    it('rejects HTTPS URL as article_id', function () {
        $response = $this->client->request('POST', '/api/syntheses', [
            'json' => ['article_id' => 'https://evil.com/steal', 'level' => 'CONCISE'],
            'auth_bearer' => $this->freeUserToken,
        ]);
        $this->assertResponseStatusCodeSame(422);
    });

    it('accepts a valid UUID v4 as article_id', function () {
        $response = $this->client->request('POST', '/api/syntheses', [
            'json' => ['article_id' => '018e7a6c-1234-7f45-a123-0f2b3c4d5e6f', 'level' => 'CONCISE'],
            'auth_bearer' => $this->freeUserToken,
        ]);
        $this->assertResponseStatusCodeSame(201);
    });
});
```

---

### 3.3 Synthèse des défenses SSRF

| Vecteur | Couche de défense 1 | Couche de défense 2 | Couche de défense 3 |
|---------|-------------------|-------------------|-------------------|
| URL source RSS | `FeedUrl::fromString()` — HTTPS only, pas d'IP, résolution DNS pre-check | Timeout Guzzle 10s, max 3 redirections avec re-validation | Réseau Docker — workers sans accès aux sous-réseaux internes |
| `POST /api/syntheses` | Validation UUID strict — toute URL rejetée HTTP 422 | `SynthesisService` ne consomme que l'`articleId` → PostgreSQL | Voter `SynthesisVoter` vérifie l'existence de l'article en base avant tout |
| Webhooks utilisateur (v2+) | Hors périmètre v1 — à traiter lors de l'implémentation | | |

---

## 4. Stratégie d'authentification

### 4.1 Matrice des mécanismes

| Client | Mécanisme | Stockage | Durée | Remarques |
|--------|-----------|---------|-------|-----------|
| Navigateur desktop | Session HttpOnly | Cookie — serveur Redis | 30 min glissant | Pas de JWT exposé au JS — protection XSS |
| Application Flutter | JWT EdDSA (Ed25519) | `flutter_secure_storage` (Keychain iOS / Keystore Android) | Access 15 min, Refresh 7 jours | Rotation + détection de vol |
| API publique Premium | API Key Bearer | SHA-256 en base (jamais en clair) | Illimitée jusqu'à révocation | 1 clé active par compte, 100 req/h |
| OAuth2 (Google/GitHub) | KnpU OAuth2 Client Bundle | Access token OAuth non stocké — seul l'`oauth_subject` est conservé | Session courante | State CSRF obligatoire |
| Biométrie mobile | Déverrouillage local | Refresh token protégé par biométrie dans `flutter_secure_storage` | Pas de nouvel auth serveur | Ne remplace pas l'auth serveur — déverrouille uniquement l'accès local au refresh token |

### 4.2 Flux d'authentification desktop (session)

```
Navigateur → POST /api/login (email, password)
  │
  ├── [1] Rate limit Redis : rate:login:{ip} ≤ 5 / 15min
  │         Si dépassé : HTTP 429 + CAPTCHA requis
  │
  ├── [2] Doctrine : charger User par email (timing-safe — toujours $hash->verify() même si user inexistant)
  │
  ├── [3] PasswordHash::verify($plaintext) → Argon2id
  │         Échec : log WARNING + INCR rate:login:account:{userId}
  │
  ├── [4] Succès : Symfony Session créée (SESSION_ID aléatoire 128 bits)
  │         Session stockée Redis : session:{sessionId} TTL 30 min
  │
  └── [5] Cookie Set : Set-Cookie: PHPSESSID={id}; HttpOnly; Secure; SameSite=Strict; Path=/

Déconnexion : DELETE session:{sessionId} Redis + Set-Cookie avec Max-Age=0
```

**Timing-safe :** Même si l'utilisateur n'existe pas, le hash Argon2id est calculé sur un hash fictif pour éviter l'énumération de comptes via différence de temps de réponse.

### 4.3 Flux JWT mobile (Flutter)

```
Flutter App → POST /api/login (email, password)
  │
  ├── [1] Mêmes validations rate limit + Argon2id que desktop
  │
  ├── [2] Génération access token JWT (EdDSA, 15 min)
  │         Header : {"alg": "EdDSA", "typ": "JWT"}
  │         Payload : {"sub": "{uuid}", "plan": "free|premium", "exp": now+900, "jti": "{uuid}"}
  │         Jamais de données sensibles dans le payload
  │
  ├── [3] Génération refresh token (256 bits aléatoires — `random_bytes(32)`)
  │         Stocké en base : refresh_tokens.token_hash = SHA-256(token)
  │         family_id = UUID (chaîne de rotation)
  │
  └── [4] Réponse JSON : {"access_token": "...", "refresh_token": "...", "expires_in": 900}
            Flutter stocke les deux dans flutter_secure_storage

Rafraîchissement → POST /api/token/refresh (refresh_token)
  ├── SHA-256(token) → lookup refresh_tokens
  ├── Si revoked=true → INVALIDER toute la famille (détection de vol) → HTTP 401
  ├── Si expiré → HTTP 401
  └── Émettre nouveau access_token + nouveau refresh_token (rotation)
        Ancien token : revoked=true
```

### 4.4 Clés API (Premium)

```php
// Génération (une seule fois, affichée à l'utilisateur)
$rawKey   = bin2hex(random_bytes(32));  // 64 chars hex — jamais stocké
$keyHash  = hash('sha256', $rawKey);    // Stocké dans api_keys.key_hash
$keyPrefix = substr($rawKey, 0, 8);     // Affiché pour identification (ba37c2f1...)

// Authentification
// Header : Authorization: Bearer ba37c2f1...{64 chars}
$providedHash = hash('sha256', $request->bearerToken());
$apiKey = $this->apiKeyRepo->findByHash($providedHash); // Timing-safe lookup via hash constant-time
```

**Règles :**
- 1 clé API active par compte (révocation de l'ancienne avant création d'une nouvelle)
- Dernière utilisation trackée (`last_used_at`)
- Rate limit : `rate:api:{keyHash}` Redis 1h — 100 requêtes max
- Révocation immédiate possible depuis l'espace compte

### 4.5 OAuth2 (Google / GitHub)

```
[1] GET /api/auth/oauth/google → Redirection Google avec state CSRF (32 bytes random)
[2] Google redirect → GET /api/auth/oauth/google/callback?code=...&state=...
[3] Vérifier state CSRF (stored in session)
[4] Échanger code contre access_token Google (KnpU OAuthClientBundle)
[5] Récupérer profil (email, oauth_subject)
[6] Créer ou retrouver User (upsert sur oauth_provider + oauth_subject)
[7] Émettre session desktop OU JWT mobile selon le client
```

**Sécurité OAuth2 :**
- State CSRF vérifié à chaque callback (protection contre le CSRF OAuth)
- `oauth_subject` stocké, pas l'access token OAuth (pas de tokens Google en base)
- Contrainte `UNIQUE(oauth_provider, oauth_subject)` — pas de compte dupliqué

### 4.6 Biométrie mobile

```
Stockage :
  flutter_secure_storage stocke le refresh token chiffré par l'OS
  iOS → Keychain (protection level: kSecAttrAccessibleWhenUnlockedThisDeviceOnly)
  Android → Android Keystore

Flux déverrouillage :
  [1] Biométrie locale (local_auth) → déverrouille le Keychain/Keystore
  [2] Flutter lit le refresh token depuis flutter_secure_storage
  [3] POST /api/token/refresh → nouveau access_token
  Note : AUCUNE information biométrique ne quitte le téléphone
         La biométrie ne modifie PAS les droits serveur — elle déverrouille uniquement l'accès au refresh token local
```

### 4.7 MFA — Politique v1

Le MFA n'est **pas obligatoire en v1** (hors périmètre — PRD §8). Les mesures compensatoires sont :
- Rate limiting anti-brute force (5/15min)
- Détection de vol de refresh token (family_id)
- Notification email à chaque nouvelle connexion depuis un nouvel appareil/IP

**Roadmap MFA v2 :**
- TOTP (Google Authenticator / Authy) pour les comptes Premium
- WebAuthn / FIDO2 pour les accès admin

### 4.8 Rate limiting anti-brute force

```php
// src/Application/Auth/LoginRateLimiter.php
class LoginRateLimiter {
    public function check(string $ip, string $userId): void {
        $ipKey      = "rate:login:ip:{$ip}";
        $accountKey = "rate:login:account:{$userId}";

        $ipAttempts      = $this->redis->incr($ipKey);
        $accountAttempts = $this->redis->incr($accountKey);

        if ($ipAttempts === 1) {
            $this->redis->expire($ipKey, 900); // 15 min
        }
        if ($accountAttempts === 1) {
            $this->redis->expire($accountKey, 900);
        }

        if ($ipAttempts > 5 || $accountAttempts > 5) {
            throw new TooManyLoginAttemptsException(
                captchaRequired: true,
                resetAt: $this->redis->ttl($ipKey)
            );
        }
    }

    public function reset(string $ip, string $userId): void {
        // Appelé après succès — remet les compteurs à zéro
        $this->redis->del("rate:login:ip:{$ip}", "rate:login:account:{$userId}");
    }
}
```

---

## 5. RGPD et AI Act

### 5.1 Consentement granulaire (CMP)

**Implémentation (Gap G-07, T-PRE-03 Design Review) :**

```
Bandeau CMP — implémenté en Symfony/Twig + Stimulus Controller
  ├── Cookie first-party : briefly_consent (JSON, SameSite=Lax, Secure)
  │     {"analytics": true|false, "marketing": false, "notifications": true|false}
  │     Lisible par JS (Stimulus) pour blocage conditionnel des scripts tiers
  │     NON HttpOnly — nécessaire pour le blocage côté navigateur
  │
  ├── Base de données : consent_records (user_id, scope, granted, recorded_at)
  │     Source de vérité serveur (prevaut sur le cookie)
  │     Rétention 3 ans (obligation légale preuve de consentement)
  │
  └── Aucun script tiers (analytics, marketing) n'est chargé
        tant que le cookie briefly_consent n'est pas positionné avec valeur true

Stimulus Controller consent-controller.js :
  - Sur DOMContentLoaded : lire le cookie briefly_consent
  - Si absent ou expiré (> 13 mois) : afficher le bandeau
  - Bouton "Accepter tout" → set cookie + POST /api/me/consent (si authentifié)
  - Bouton "Refuser" → set cookie analytics=false,marketing=false + POST /api/me/consent
  - Bouton "Personnaliser" → modal granulaire par scope
```

**Mobile Flutter :**
```dart
// lib/features/consent/bloc/consent_bloc.dart
// Consentement demandé au premier lancement (dialog modal)
// Stocké dans SharedPreferences + synchronisé avec /api/me/consent si authentifié
// Pas de SDK analytics chargé avant consentement positif
```

**Scopes de consentement :**

| Scope | Obligatoire | Description |
|-------|------------|-------------|
| `analytics` | Non | Métriques agrégées anonymes (DAU/MAU, taux conversion) |
| `marketing` | Non | Emails promotionnels, newsletters thématiques |
| `notifications` | Non | Notifications push quotidiennes (Daily Brief) |
| `essential` | Oui (pas de consentement requis) | Session auth, quota, préférences — fonctionnement du service |

### 5.2 Droit à l'oubli — Suppression en cascade

```
Déclenchement : DELETE /api/me → soft delete (users.deleted_at = NOW())
Planification : Symfony Scheduler — app:gdpr:delete-user tous les jours à 2h UTC
Exécution à J+30 :

Ordre de suppression (respect des FK) :
  [1] consent_records WHERE user_id = :id
  [2] api_keys WHERE user_id = :id
  [3] reading_history WHERE user_id = :id (Sprint 3+)
  [4] refresh_tokens WHERE user_id = :id (CASCADE déjà défini)
  [5] subscriptions WHERE user_id = :id
       (stripe_events conservés 7 ans — obligation comptable)
  [6] syntheses liées à l'user (uniquement si synthèse privée — v2)
  [7] users WHERE id = :id (hard delete final)

Redis : DEL quota:{userId}:* session:*:{userId} rate:login:account:{userId}

Email confirmation : envoyé à J+0 (soft delete) et J+30 (hard delete)
Délai légal respecté : 30 jours max (FR-037 / NFR-017)

Exceptions (données conservées malgré effacement) :
  - stripe_events (7 ans, obligation comptable française)
  - consent_records anonymisés (user_id mis à NULL, scope + granted conservés)
  - Logs applicatifs (user_id_hash non réversible après J+30)
```

### 5.3 Portabilité des données (FR-038)

```json
// GET /api/me/data-export — format JSON structuré
// Généré à la demande, disponible en < 30 secondes (NFR)
{
  "export_date": "2026-07-28T10:30:00Z",
  "format_version": "1.0",
  "profile": {
    "email": "user@example.com",
    "plan": "premium_monthly",
    "created_at": "2025-03-15T08:00:00Z",
    "preferred_lang": "fr",
    "timezone": "Europe/Paris"
  },
  "consents": [
    {"scope": "analytics", "granted": true, "recorded_at": "..."},
    {"scope": "marketing", "granted": false, "recorded_at": "..."}
  ],
  "reading_history": [],
  "saved_articles": [],
  "api_keys": [
    {"name": "Mon dashboard", "created_at": "...", "last_used_at": "..."}
  ]
}
```

Export Markdown (`GET /api/me/data-export/markdown`) — Premium uniquement :
```markdown
# Mes briefs sauvegardés — Export Briefly AI
...
```

### 5.4 Pas d'identifiant utilisateur dans les prompts LLM (INV-6)

**Règle absolue (NFR-018, INV-6 Constitution) :**

```php
// src/Infrastructure/Ai/MistralProvider.php
// Template du prompt — ce qui ENTRE dans Mistral

private function buildPrompt(Article $article, SynthesisLevel $level): string {
    return <<<PROMPT
    You are a neutral news analyst. Summarize the following article in the language of the article.
    Prefix with "BRIEFLY AI:".

    Article title: {$article->getTitle()}
    Article content: {$article->getContentSnippet()}
    Source name: {$article->getSource()->getName()}
    Source URL: {$article->getUrlCanonical()}
    Category: {$article->getCategoryTag()->value}
    PROMPT;
}

// Ce qui N'EST JAMAIS dans le prompt :
// - $user->getId()       ← Interdit — INV-6
// - $user->getEmail()    ← Interdit — INV-6
// - $user->getPlan()     ← Interdit (même plan Free vs Premium)
// - $request->getClientIp() ← Interdit
// - $user->getReadingHistory() ← Interdit
// - tout paramètre de personnalisation lié à l'identité
```

**Vérification à la revue de code :** Toute PR touchant `MistralProvider`, `OpenAIFallbackProvider` ou `PhiOnDeviceAdapter` doit inclure une vérification explicite que le prompt ne contient aucune donnée utilisateur. PHPStan custom rule en v2 pour détecter les injections de `User` dans les classes de prompts.

### 5.5 DPA et sous-traitants

| Sous-traitant | Rôle | DPA | Localisation données |
|--------------|------|-----|---------------------|
| Mistral AI | Génération synthèses IA | Signé — `docs/legal/dpa-mistral.pdf` | UE (France) |
| Stripe | Paiements, abonnements | Signé (Standard Stripe DPA) | UE + USA (Standard Contractual Clauses) |
| Hetzner / OVH | Hébergement infra | DPA inclus dans CGV | UE (Allemagne / France) |
| Fournisseur email (Postmark/SendGrid) | Emails transactionnels | DPA à signer avant mise en prod | UE si possible |
| FCM (Google) / APNs (Apple) | Push notifications | Données minimales (device token uniquement) | Mondial |

**Règle :** Toute intégration d'un nouveau sous-traitant traitant des données personnelles EU requiert une DPA signée avant la mise en production.

### 5.6 AI Act — Conformité systèmes IA à risque limité

Briefly AI est un **système IA à risque limité** (Article 52, AI Act UE 2024/1689) car il génère du contenu textuel synthétisé susceptible d'influencer les décisions des utilisateurs.

**Obligations de transparence :**

| Obligation | Implémentation |
|-----------|---------------|
| Identifier le contenu IA | Badge émeraude `#10B981` + préfixe "BRIEFLY AI:" sur toute synthèse |
| Non uniquement couleur | Icône robot/étoile + texte "BRIEFLY AI:" toujours présents (INV-4, NFR-025) |
| Lien source | "OUVRIR L'ORIGINAL" vers `article.urlCanonical` — systématique (INV-3) |
| Page de transparence | `/ai-transparency` — publique — liste les modèles utilisés, leur rôle, leurs limites, les biais potentiels |
| Conservation des logs | `syntheses.provider` + `syntheses.generated_at` — 90 jours — sans données utilisateur |
| Notification droit de refus | L'utilisateur peut désactiver les synthèses IA (préférence compte) |

---

## 6. Headers de sécurité 2026

Configurés au niveau `Caddyfile` (FrankenPHP) pour s'appliquer à **toutes** les réponses HTTP sans exception.

### 6.1 Configuration complète Caddyfile

```caddyfile
# /etc/caddy/Caddyfile

{
    # FrankenPHP worker mode
    frankenphp
    order php_server before file_server
}

briefly.ai {
    # TLS 1.3 uniquement
    tls {
        protocols tls1.3
    }

    # PHP via FrankenPHP worker
    php_server

    # --- Headers de sécurité 2026 ---

    # HSTS avec preload — HTTPS obligatoire 1 an
    header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

    # Anti-clickjacking
    header X-Frame-Options "DENY"

    # Anti-MIME sniffing
    header X-Content-Type-Options "nosniff"

    # Referrer minimal
    header Referrer-Policy "strict-origin-when-cross-origin"

    # CSP Level 3 — adapté à la stack Twig + Turbo + Stimulus
    # Pas de 'unsafe-inline' — Turbo + Stimulus fonctionnent sans eval
    header Content-Security-Policy `
        default-src 'self';
        script-src 'self';
        style-src 'self';
        img-src 'self' data: https://cdn.briefly.ai;
        font-src 'self';
        connect-src 'self' https://api.briefly.ai;
        frame-ancestors 'none';
        form-action 'self' https://checkout.stripe.com;
        base-uri 'self';
        upgrade-insecure-requests;
        report-uri /api/csp-report
    `

    # Cross-Origin Isolation (protection Spectre — 2026 obligatoire)
    header Cross-Origin-Opener-Policy "same-origin"
    header Cross-Origin-Embedder-Policy "require-corp"
    header Cross-Origin-Resource-Policy "same-origin"

    # Permissions-Policy — aucune API navigateur non nécessaire
    header Permissions-Policy "geolocation=(), camera=(), microphone=(), payment=(self https://checkout.stripe.com), usb=(), serial=()"

    # Suppression headers d'information serveur
    header -Server
    header -X-Powered-By

    # Cache-Control — pages publiques seulement
    @public_brief path /brief/*
    header @public_brief Cache-Control "public, max-age=60, s-maxage=300"

    # Pas de cache sur les API authentifiées
    @api_auth path /api/*
    header @api_auth Cache-Control "no-store, no-cache, must-revalidate"
    header @api_auth Pragma "no-cache"
}
```

### 6.2 CSP — justification des directives

| Directive | Valeur | Justification |
|-----------|--------|---------------|
| `default-src 'self'` | Restrictif | Fallback pour tout type non spécifié |
| `script-src 'self'` | Sans `unsafe-inline` | Stimulus + Turbo ne requièrent pas d'inline scripts. Nonces en option v2 pour les scripts tiers |
| `style-src 'self'` | Sans `unsafe-inline` | CSS externalisé, pas de `style=""` inline dans les templates |
| `img-src 'self' data: cdn.briefly.ai` | CDN interne uniquement | Images des articles servent via proxy interne (pas d'URLs sources directes) |
| `connect-src 'self' api.briefly.ai` | API interne | Turbo Streams + appels AJAX vers l'API — pas de services tiers |
| `frame-ancestors 'none'` | Anti-clickjacking | Redondant avec X-Frame-Options: DENY mais requis pour CSP Level 3 |
| `form-action 'self' checkout.stripe.com` | Stripe Checkout | Seul cas de soumission de formulaire vers un tiers autorisé |
| `upgrade-insecure-requests` | HTTPS forcé | Toutes les requêtes HTTP sont upgradées en HTTPS |
| `report-uri /api/csp-report` | Monitoring | Violations CSP loggées (rate limité pour éviter le flood) |

### 6.3 Adaptation mobile Flutter

L'application Flutter communique exclusivement via HTTPS avec `api.briefly.ai`. Certificate pinning activé pour les builds production (v2 — hors périmètre v1) :

```dart
// lib/core/network/http_client.dart — v2
// Certificate pinning via ssl_pinning_plugin
// Pour v1 : TLS 1.3 + vérification certificat standard (trustAll = false)
final client = http.Client(); // flutter_secure_storage gère le token
```

### 6.4 Headers spécifiques aux endpoints sensibles

```http
# POST /api/login, POST /api/token/refresh
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 4
X-RateLimit-Reset: 1722175200
Retry-After: 900  (si rate limit atteint)

# GET /api/v1/* (API publique Premium)
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1722175200
X-Request-Id: {uuid-v4}  (traçabilité logs)

# GET /brief/{date} (page publique)
ETag: "sha256-{brief-content-hash}"
Cache-Control: public, max-age=60, s-maxage=300
Vary: Accept-Encoding
```

---

## 7. Gestion des secrets

### 7.1 Inventaire et rotation

| Secret | Source en prod | Rotation | Responsable |
|--------|---------------|----------|-------------|
| `DATABASE_URL` | Docker Secret | Trimestrielle (manuelle) | Tech Lead |
| `REDIS_URL` | Docker Secret | Trimestrielle (manuelle) | Tech Lead |
| `MISTRAL_API_KEY` | Docker Secret | Mensuelle (manuelle) | Tech Lead |
| `OPENAI_API_KEY` | Docker Secret | Trimestrielle | Tech Lead |
| `STRIPE_SECRET_KEY` | Docker Secret | Via Stripe rotation | Automatique |
| `STRIPE_WEBHOOK_SECRET` | Docker Secret | Via Stripe rotation | Automatique |
| `JWT_PRIVATE_KEY` (Ed25519 PEM) | Docker Secret | Annuelle | Tech Lead |
| `JWT_PUBLIC_KEY` | Config (non sensible) | Avec JWT_PRIVATE_KEY | Tech Lead |
| `APP_SECRET` (32 bytes random) | Docker Secret | Annuelle | Tech Lead |
| Clé chiffrement pg_dump | Docker Secret / HSM v2 | Annuelle | Tech Lead |

### 7.2 Hiérarchie des sources

```
Prod :   Docker Secrets (injectés en /run/secrets/)
           → lus par FrankenPHP au démarrage du container
           → JAMAIS dans les variables d'environnement du container (évite `docker inspect`)

Dev :    .env.local (gitignore absolu — jamais commité)
           → .env contient uniquement des exemples vides ou des valeurs non-sensibles

CI/CD :  GitHub Actions Secrets (chiffrés Vault GitHub)
           → Injectés comme env vars uniquement pendant les jobs de déploiement
```

### 7.3 Règles absolues

```
❌ INTERDIT :
  - Secret dans le code source (commit git)
  - Secret dans l'image Docker (COPY .env, ENV DATABASE_URL=...)
  - Secret en variable d'environnement dans docker-compose.prod.yml (en clair)
  - Secret dans les logs applicatifs
  - Secret dans les messages d'erreur retournés au client

✅ OBLIGATOIRE :
  - .env dans .gitignore (global + projet)
  - Audit git history si un secret a été commité par erreur (git filter-repo + rotation immédiate)
  - Rotation immédiate si un secret est compromis (sans attendre la prochaine rotation planifiée)
  - Alerter le Tech Lead immédiatement en cas de suspicion de compromission
```

### 7.4 Génération des secrets

```bash
# JWT Ed25519
openssl genpkey -algorithm ed25519 -out jwt_private.pem
openssl pkey -in jwt_private.pem -pubout -out jwt_public.pem

# APP_SECRET (32 bytes aléatoires)
php -r "echo bin2hex(random_bytes(32));"

# Docker Secret
echo "valeur_secrète" | docker secret create mistral_key -

# Vérification : aucun secret dans l'image
docker run --rm briefly/app:latest env | grep -E "(API_KEY|SECRET|PASSWORD|DATABASE|REDIS)"
# → Doit retourner vide
```

---

## 8. Checklist sécurité par release

### 8.1 Avant chaque merge (développeur)

- [ ] Aucun secret dans les fichiers modifiés (`git diff` + `git log` + `.env.example` uniquement)
- [ ] `composer audit` passe sans CVE HIGH/CRITICAL
- [ ] Tests de sécurité passent (`tests/Security/`)
- [ ] Aucune concaténation SQL introduite (vérification PHPStan + revue)
- [ ] Nouveaux endpoints protégés par Voter dédié (deny by default)
- [ ] Nouveaux inputs validés par Symfony Validator (whitelist, format UUID si applicable)
- [ ] Aucune donnée utilisateur ajoutée dans les templates de prompt LLM
- [ ] Logs des nouveaux événements sans PII (UUID uniquement)
- [ ] OWASP Checklist spécifique à la couche modifiée (voir §2)

### 8.2 Avant chaque déploiement en production (Tech Lead)

- [ ] `composer audit` vert sur la version à déployer
- [ ] Trivy image scan — 0 HIGH/CRITICAL
- [ ] SBOM CycloneDX généré et archivé
- [ ] Tests E2E sécurité passent en staging (inscription, login, quota, synthèse, SSRF test)
- [ ] Headers CSP vérifiés via [securityheaders.com](https://securityheaders.com) sur staging
- [ ] Rotation des secrets si expirés (voir tableau §7.1)
- [ ] Migrations Doctrine réversibles et backward-compatible
- [ ] Sauvegardes PostgreSQL vérifiées (snapshot < 24h)
- [ ] Circuit breakers Mistral et sources en état CLOSED (aucun OPEN en prod avant déploiement)

### 8.3 Après chaque déploiement (vérifications post-deploy)

- [ ] Smoke tests : `GET /brief/{today}` → HTTP 200 + ETag valide
- [ ] Smoke test auth : `POST /api/login` → session créée, cookie HttpOnly
- [ ] Smoke test JWT : `POST /api/login` Flutter → access_token EdDSA valide
- [ ] Vérification headers : `curl -I https://briefly.ai` → CSP, HSTS, COOP, COEP présents
- [ ] Taux erreurs 5xx dans Sentry < 0,1 % (fenêtre 15 min post-déploiement)
- [ ] Métriques Prometheus : `briefly_api_request_duration_seconds` P95 < 200 ms

### 8.4 Revue sécurité trimestrielle

- [ ] Audit des dépendances Composer + Flutter Pub (au-delà des CVE — aussi les licences)
- [ ] Revue des clés API actives (révoquer les inactives depuis > 90 jours)
- [ ] Rotation des secrets arrivés à échéance (§7.1)
- [ ] Vérification des DPA sous-traitants (Mistral, Stripe, hébergeur)
- [ ] Test de restauration depuis sauvegarde PostgreSQL (PITR < 1h)
- [ ] Revue des logs de sécurité (tentatives de brute force, tokens révoqués pour vol détecté)
- [ ] Mise à jour du document OWASP mapping si nouvelle version Top 10
- [ ] Vérification conformité AI Act (badge, page `/ai-transparency` à jour)

### 8.5 En cas d'incident de sécurité

```
Procédure d'urgence :

[0-15 min]
  → Identifier et contenir (rotation immédiate du secret compromis si applicable)
  → Révoquer les sessions/tokens impactés (si vol de token : invalider la famille)
  → Alerter le Tech Lead + PO

[15-60 min]
  → Analyser le vecteur d'attaque (logs Sentry + logs structurés)
  → Documenter les données potentiellement exposées (scope RGPD ?)
  → Si données personnelles exposées : notification CNIL obligatoire sous 72h (RGPD Art. 33)

[1-48h]
  → Déployer le correctif (hotfix branch)
  → Post-mortem documenté (blame-free — directive fondamentale rétrospective)
  → Mise à jour des tests de sécurité pour couvrir le vecteur

Contact CNIL si breach données personnelles :
  https://www.cnil.fr/fr/notifier-une-violation-de-donnees-personnelles
  Délai : 72h après prise de connaissance (Art. 33 RGPD)
```

---

## Annexe — Références croisées

| Exigence PRD/Constitution | Section security.md |
|--------------------------|---------------------|
| NFR-011 (Broken Access Control, UUIDs) | §2.#1, §4 |
| NFR-012 (Argon2id, EdDSA, secrets) | §2.#2, §4.2, §4.3, §7 |
| NFR-013 (Injection, Doctrine, Validator) | §2.#3 |
| NFR-014 (SBOM, Trivy, Dependabot) | §2.#6, §8.2 |
| NFR-015 (Headers CSP/HSTS/COOP/COEP/CORP) | §6 |
| NFR-016 (Hébergement EU) | §1.1, §5.5 |
| NFR-017 (Droit à l'oubli cascade 30j) | §5.2 |
| NFR-018 (Pas de PII dans prompts LLM) | §5.4 |
| NFR-019 (AI Act transparence) | §5.6 |
| NFR-020 (DPA sous-traitants) | §5.5 |
| FR-029 (Auth Argon2id + OAuth2) | §4.2, §4.5 |
| FR-030 (JWT EdDSA mobile) | §4.3 |
| FR-031 (Session HttpOnly desktop) | §4.2 |
| FR-032 (Biométrie Flutter) | §4.6 |
| FR-036 (Rate limit 5/15min) | §4.8 |
| FR-037 (Droit à l'oubli RGPD) | §5.2 |
| FR-038 (Export JSON portabilité) | §5.3 |
| FR-051/052 (CMP consentement) | §5.1 |
| INV-6 (Pas d'ID user dans LLM) | §5.4 |
| Constitution §6 (Socle immuable) | §1.1 |
| SSRF (Constitution §6, C-05 Design Review) | §3 |
| T-PRE-02 (Test SSRF /api/syntheses) | §3.2 |
| T-PRE-03 (Spec CMP) | §5.1 |

---

*Ce document constitue la référence sécurité de Briefly AI v1. Il est maintenu par le Tech Lead et mis à jour à chaque sprint touchant les couches authentification, ingestion, API ou RGPD. Prochaine revue : Sprint 1 Review (2026-08-10).*

*Aligné sur : OWASP Top 10:2025 · RGPD (UE) 2016/679 · AI Act (UE) 2024/1689 · rules/11-security.md v1.2.0*
