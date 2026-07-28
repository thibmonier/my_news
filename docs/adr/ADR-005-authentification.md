# ADR-005 — Authentification : OAuth2 (Google/GitHub) + JWT mobile + Session desktop + Biométrie + Argon2id

**Statut :** Accepté — 2026-07-28
**Auteur :** Tech Lead (CSM)
**Décideurs :** Tech Lead, Product Owner
**Références :** PRD §4.4 (FR-029 à FR-038), PRD §5.3 (NFR-011, NFR-012), constraints.md (T7, R1, R6), technical-options.md §5, risks-opportunities.md (RIS-07)

---

## Contexte

Briefly AI gère deux canaux d'accès distincts avec des profils de sécurité différents :

1. **Desktop web** (Symfony/Twig + Turbo) — P-001 Thomas, P-002 Priya : contexte navigateur, SEO, cookies first-party.
2. **Mobile Flutter** (Android + iOS) — P-001 Thomas (iPhone), P-003 Marc (Pixel GrapheneOS) : contexte natif, pas de cookies navigateur, secure storage disponible.

Les exigences croisées sont les suivantes :

- **Inscription rapide** : OAuth2 Google/GitHub pour P-001 (réduction friction) + email/mot de passe pour P-003 (pas de compte Google/GitHub souhaité pour la vie privée).
- **Sécurité mots de passe** : Argon2id imposé par OWASP 2025 (NFR-012) — jamais bcrypt/MD5/SHA-1.
- **Tokens JWT mobile** : accès court (15 min) + refresh long (7 jours) pour limiter la surface d'exposition (FR-030).
- **Session desktop** : HttpOnly cookie (SameSite=Strict) — le token JWT ne doit jamais être exposé au JavaScript sur le bureau (FR-031), protection XSS native.
- **Biométrie mobile** : déverrouille le refresh token local sans appel réseau supplémentaire (FR-032) — P-003 accepte la biométrie mais refuse les flows intrusifs.
- **Rate limit connexion** : 5 tentatives / 15 min / IP + compte, CAPTCHA au-delà (FR-036).
- **RGPD** : droit à l'oubli en cascade (FR-037), export données (FR-038), pseudonymisation des logs (FR-055).

La principale tension est : **JWT ou session pour le desktop ?** Un JWT universel simplifierait le code commun mobile/desktop, mais exposerait un token bearer dans le stockage navigateur (localStorage) — surface XSS inacceptable pour un produit ciblant des utilisateurs à forte sensibilité sécurité (P-003).

---

## Décision

**Architecture d'authentification à trois couches selon le canal, unifiée côté serveur :**

### 1. Identité — trois mécanismes d'inscription/connexion

| Mécanisme | Implémentation | Personas cibles |
|-----------|---------------|-----------------|
| Email + mot de passe | Symfony Security + Argon2id (128 MiB, t=3, p=1) | P-003 Marc (privacy-first, pas d'OAuth) |
| OAuth2 Google | KnpU OAuth2 Client Bundle (`knpuniversity/oauth2-client-bundle`) | P-001 Thomas (rapidité), P-002 Priya |
| OAuth2 GitHub | KnpU OAuth2 Client Bundle | P-003 Marc (si compte GitHub professionnel) |

Le flux OAuth2 produit une **identité locale** (entité `User` en base) — il n'y a jamais de dépendance runtime sur Google/GitHub pour valider une session active. Cela garantit la résilience si un provider tiers est indisponible.

### 2. Session desktop — Cookie HttpOnly (Symfony natif)

- Session PHP gérée par Symfony Security, stockée **en Redis** (session handler Redis, TTL 30 min inactivité).
- Cookie : `HttpOnly=true`, `Secure=true`, `SameSite=Strict`, pas de `Domain` trop large.
- Le JavaScript côté Twig/Stimulus n'a **jamais accès au cookie de session** (protection XSS native).
- Protection CSRF via Symfony CSRF token sur tous les formulaires POST.
- Renouvellement automatique de session à chaque requête active (sliding expiry).
- Logout : invalidation serveur de la session + suppression Redis + cookie expiré immédiatement.

### 3. Authentification mobile — JWT (EdDSA Ed25519) via API Platform

- **Access token** : JWT signé EdDSA (Ed25519), TTL 15 minutes.
- **Refresh token** : JWT signé EdDSA, TTL 7 jours, stocké dans `flutter_secure_storage` (Keychain iOS / Keystore Android).
- Algorithme **EdDSA (Ed25519)** imposé — supérieur à RS256 (signature plus compacte, résistance quantique partielle) et à HS256 (secret partagé inadapté).
- Les tokens sont signés côté Symfony avec une clé Ed25519 (via `lcobucci/jwt` ou `lexik/jwt-authentication-bundle`), jamais exposés côté client web.
- Le refresh token est **rotation systématique** : chaque utilisation produit un nouveau refresh token (invalidation de l'ancien en Redis). Une tentative de réutilisation d'un token révoqué déclenche l'invalidation de toute la famille de tokens (détection de vol).
- L'API Platform expose `POST /api/v1/auth/token` (échange credentials → JWT) et `POST /api/v1/auth/refresh` (refresh → nouveau pair de tokens).

### 4. Biométrie mobile — déverrouillage local du refresh token

- Le refresh token est chiffré dans `flutter_secure_storage` avec une clé dérivée de la biométrie (`local_auth` Flutter).
- La biométrie (Face ID / Touch ID / Empreinte Android) déverrouille le **déchiffrement local** du token — aucun appel réseau à ce stade.
- Le refresh token déchiffré est ensuite utilisé pour obtenir un nouvel access token (appel réseau vers `POST /api/v1/auth/refresh`).
- Ce mécanisme **ne remplace pas l'authentification serveur** — il améliore l'UX sans affaiblir le modèle de sécurité.
- Si la biométrie est désactivée sur l'appareil, l'utilisateur entre son mot de passe normalement.

### 5. Sécurité mot de passe — Argon2id

Paramètres conformes OWASP 2025 :

```
memory_cost = 131_072  (128 MiB)
time_cost   = 3
parallelism = 1
```

- PHP `password_hash(PASSWORD_ARGON2ID, $options)` via Symfony `PasswordHasher`.
- Migration automatique vers Argon2id lors de la prochaine connexion si un ancien hash bcrypt existe (aucun en v1, mais prévention pour la migration d'une base existante future).

### 6. Rate limiting et protection brute-force

- **5 tentatives** de connexion échouées par IP et par compte dans une fenêtre glissante de 15 minutes (Redis, clé composée `login_fail:{ip}:{account_id}`).
- À la 6e tentative : CAPTCHA obligatoire (hCaptcha — respectueux de la vie privée, pas Google reCAPTCHA pour P-003).
- L'API mobile (JWT) est soumise au même rate limit sur `POST /api/v1/auth/token`.
- Les webhooks Stripe (`/api/v1/stripe/webhook`) sont exemptés du rate limit général mais validés par signature Stripe (`Stripe-Signature` header).

---

## Alternatives considérées

### A1 — JWT universel (mobile + desktop)

**Pour :**
- Un seul système d'authentification pour toutes les surfaces.
- Simplifie le code commun (`JWTAuthenticator` unique, pas de `SessionAuthenticator` séparé).
- Stateless côté serveur (pas de session Redis).

**Contre :**
- Sur le desktop, le JWT doit être stocké quelque part côté client. Les options sont : `localStorage` (vulnérable XSS), `sessionStorage` (idem), ou cookie HttpOnly. Si on le met en cookie HttpOnly, on retrouve exactement la même implémentation que la session PHP — sans gain, avec la complexité de gérer la rotation et la révocation JWT en plus.
- La révocation d'un JWT access token avant son expiration (logout, compromission) nécessite une blacklist Redis — on retrouve la statefulness que JWT était censé éliminer.
- L'API Platform gère bien JWT pour les clients machine (mobile, API publique), mais la documentation Symfony Security recommande les sessions pour les clients navigateur.
- Exposer un JWT dans le DOM (via méta-tag ou variable JS) pour les appels Stimulus/Turbo crée une surface XSS permanente.

**Rejetée :** Le gain de simplicité est illusoire car la sécurité desktop avec JWT oblige à recréer une statefulness (blacklist). La session HTTP est la solution native, éprouvée, pour le navigateur.

---

### A2 — Session uniquement (desktop + mobile via cookie WebView)

**Pour :**
- Un seul mécanisme d'authentification.
- Gestion de la révocation native (invalidation serveur immédiate).

**Contre :**
- Les cookies session HTTP ne fonctionnent pas nativement avec `flutter_http` ou `dio` sans gestion manuelle complexe du cookie jar.
- Incompatible avec les environnements non-navigateur (API publique Premium pour P-003, accès depuis home server).
- Impossible de faire de la biométrie native Flutter avec des cookies — le refresh token JWT dans `flutter_secure_storage` est le mécanisme standard.
- GrapheneOS (P-003) a des restrictions WebView qui peuvent invalider les cookies selon la configuration.

**Rejetée :** Architecturalement inadapté au mobile natif Flutter et à l'API publique.

---

### A3 — Passkeys uniquement (WebAuthn FIDO2) sans mot de passe

**Pour :**
- Sécurité maximale — élimine les risques de phishing et de credential stuffing.
- Standard recommandé 2025 par FIDO Alliance et OWASP.
- Meilleure UX pour P-001 (Thomas) et P-003 (Marc).

**Contre :**
- Support limité sur Android < 9 et iOS < 16 — fragilise l'audience mobile cible.
- Pas de support Passkeys dans Symfony sans bundle tiers encore immature en 2026.
- La récupération de compte sans mot de passe de secours est complexe (email OTP, codes de secours).
- L'OAuth2 (Google/GitHub) couvre déjà les avantages UX de Passkeys pour P-001.
- P-003 (GrapheneOS) n'a pas Google Play Services — les Passkeys Google sont inaccessibles.

**Rejetée :** La maturité d'implémentation en 2026 sur la stack Symfony est insuffisante pour le Sprint 1. Candidat solide pour ADR-v2 (Sprint 3+).

---

### A4 — Authentification déléguée totale (Auth0 / Keycloak) sans gestion maison

**Pour :**
- Zéro code d'authentification à écrire.
- MFA natif, Passkeys, logs d'audit, gestion des sessions.

**Contre :**
- Auth0 Cloud : hébergement US par défaut — problème RGPD Art. 46 (même avec option EU tenant, la traçabilité des données est complexe).
- Keycloak self-hosted : complexité opérationnelle disproportionnée pour une petite équipe (Res1) — un service de plus à maintenir, sécuriser, mettre à jour.
- Dépendance à un SaaS tiers pour la feature la plus critique (authentification) : vendor lock-in élevé.
- La stack Symfony Security + KnpU OAuth2 couvre 100 % des besoins sans ce surcoût.

**Rejetée :** Sur-ingénierie pour les besoins v1. La pile Symfony native est suffisante et maîtrisée.

---

### A5 — Argon2id avec paramètres OWASP 2021 minimaux (64 MiB, t=2)

**Pour :**
- Hashage plus rapide (latence connexion < 100 ms vs ~400 ms avec 128 MiB/t=3).
- Compatible avec des serveurs à 1 Go RAM.

**Contre :**
- OWASP 2025 recommande explicitement 128 MiB / t=3 comme paramètres cibles sur le matériel actuel.
- Le coût de hashage est conçu pour être élevé — c'est la protection contre le brute force GPU.
- La latence de connexion de 400 ms est acceptable et invisible pour l'utilisateur (opération rare vs lecture quotidienne).

**Rejetée :** Les paramètres OWASP 2025 (128 MiB, t=3) sont la référence de sécurité cible. La légère surcharge serveur est négligeable face au gain de sécurité.

---

## Conséquences

### Positives

- **Surface XSS minimale** sur le desktop : le JWT n'est jamais dans le DOM ou le localStorage. La session HttpOnly est la défense la plus robuste disponible dans un navigateur.
- **Révocation instantanée** : les sessions Redis sont invalidées côté serveur en temps réel (logout, compromission, droit à l'oubli RGPD FR-037). Les JWT mobiles ont une TTL de 15 min + révocation par rotation de refresh token.
- **UX sans friction** pour P-001 et P-002 via OAuth2 (1 clic) sans sacrifier la privacy de P-003 (email/mot de passe).
- **Biométrie native** : P-003 accepte la biométrie, P-001 l'appréciera sur son iPhone — UX premium sans risque sécurité.
- **Conformité OWASP 2025 complète** : Argon2id, EdDSA, HttpOnly, rate limiting, CAPTCHA.
- **Droit à l'oubli RGPD** : invalidation de tous les tokens/sessions via la suppression de l'utilisateur en base suffit (les sessions Redis sont liées à l'user_id).

### Négatives / Points d'attention

- **Deux authenticators Symfony** à maintenir (`SessionAuthenticator` pour le desktop, `JWTAuthenticator` pour l'API mobile) — surface de code plus large, mais bien isolée.
- **Complexité du refresh token JWT** : la rotation systématique + la détection de réutilisation nécessitent une table `refresh_tokens` en PostgreSQL et une clé Redis par famille. C'est du code de sécurité à tester exhaustivement.
- **hCaptcha** : dépendance externe pour le CAPTCHA. Si hCaptcha est indisponible, le rate limit s'applique mais le CAPTCHA ne se déclenche pas — acceptable car le rate limit seul est déjà dissuasif.
- **Gestion de la révocation côté mobile** : si un refresh token est compromis avant expiration (vol physique du téléphone), la révocation de la famille de tokens est correcte mais l'accès à toutes les sessions de l'utilisateur doit être invalidé. Un endpoint `POST /api/v1/auth/revoke-all` est requis (à implémenter).
- **Clé Ed25519** : doit être stockée en secret Docker/Vault (jamais en variable d'environnement en clair). Rotation annuelle de la clé de signature à planifier.

---

## Implémentation — points d'architecture

- Bundle Symfony : `knpuniversity/oauth2-client-bundle` + providers `league/oauth2-google` et `league/oauth2-github`.
- JWT : `lexik/jwt-authentication-bundle` configuré pour EdDSA. Clé pair Ed25519 générée via `openssl genpkey -algorithm ed25519`.
- Biométrie Flutter : `local_auth` + `flutter_secure_storage` (AES-256-GCM, backing Keychain/Keystore).
- Rate limit : `symfony/rate-limiter` avec store Redis.
- Session store Redis : `symfony/cache` adapter Redis pour `framework.session.handler_id`.
- Tests : `ApiTestCase` pour tous les flux auth (login email, OAuth callback, refresh token, révocation, rate limit).

---

*ADR validé en Sprint Planning Sprint 1 — 2026-07-28*
