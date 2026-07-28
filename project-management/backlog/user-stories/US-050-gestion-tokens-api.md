# US-050 : Gestion des tokens API

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-050 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** créer, nommer, consulter et révoquer mes tokens d'API depuis mes paramètres de compte
**Afin de** intégrer Briefly AI dans mon dashboard personnel de façon sécurisée et contrôlée, sans exposer mes credentials de session web.

---

## Conversation (Notes & Questions ouvertes)

- Combien de tokens simultanés par compte ? Proposition : 5 max pour Free, 20 pour Premium.
- Le token est-il affiché une seule fois à la création ou consultable à tout moment ? Décision : affiché UNE seule fois à la création, puis stocké haché en base (SHA-256). Seul le préfixe (`briefly_XXXXXXXX`) reste lisible.
- Scopes nécessaires en v1 : `read` (accès Daily Brief) et `synthesize` (POST /v1/synthesize).
- Format du token : `briefly_<64 hex chars>` (72 caractères au total, haute entropie → SHA-256 suffisant).
- Expiration : pas d'expiration automatique en v1 ; révocation manuelle uniquement.
- `last_used_at` à tracer par token (timestamp Redis, persisté PostgreSQL chaque heure).
- Audit log des créations/révocations dans la table `api_token_events` (conformité RGPD).

---

## Validation INVEST

- [x] **Independent** : Fondation de l'EPIC-006 ; livrable et testable seul, aucune autre US de cet EPIC n'est prérequise
- [x] **Negotiable** : Quota de tokens (5 Free / 20 Premium), scopes v1 (read/synthesize), format du préfixe `briefly_`, absence d'expiration automatique — tous discutables
- [x] **Valuable** : Prérequis à toute intégration API sécurisée — sans token, les endpoints publics sont inaccessibles pour les développeurs tiers
- [x] **Estimable** : 5 pts — CRUD `ApiToken` (Symfony Security + API Platform + page Twig) périmètre bien délimité
- [x] **Sized** : 5 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant création, révocation, quota dépassé, validation nom vide, accès non authentifié

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `api_tokens` : uuid, user_id (FK), name (varchar 100), token_hash (char 64 SHA-256), prefix (char 16), scopes (jsonb), last_used_at, revoked_at, created_at |
| **Symfony Security** | `ApiTokenAuthenticator` (HttpHeaderTokenExtractor `Authorization: Bearer`) ; voter `API_TOKEN_MANAGE` pour CRUD des tokens |
| **API Platform 4** | Resource `ApiToken` — opérations : POST (création, retourne le token en clair une seule fois), GET collection (liste avec préfixe), DELETE (révocation) |
| **Symfony/Twig + Turbo** | Page `/account/api-tokens` : liste des tokens, formulaire création inline, bouton "Révoquer" avec confirmation Stimulus + Turbo Stream |
| **RGPD / Sécurité OWASP** | Token haché SHA-256 ; quota `max_tokens_per_user` configurable ; audit log api_token_events ; UUID v4 non séquentiel pour les IDs exposés |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Création d'un token API nommé

```gherkin
Scenario: Marc crée un token API avec le scope "read"
  GIVEN Marc est authentifié sur Briefly AI (compte Premium)
  AND Marc accède à "/account/api-tokens"
  WHEN Marc saisit le nom "Dashboard perso", sélectionne le scope "read" et clique "Générer le token"
  THEN un token de format "briefly_<64 hex chars>" s'affiche UNE SEULE FOIS dans une alerte non-dismissable
  AND le token apparaît dans la liste avec uniquement le préfixe masqué (ex : "briefly_a3f1c8...")
  AND le champ "Dernière utilisation" affiche "Jamais"
  AND en base, seul le SHA-256 du token est stocké (jamais le token en clair)
  AND un événement "token_created" est enregistré dans api_token_events avec le timestamp ISO 8601
```

### Scénario alternatif 1 — Révocation d'un token actif

```gherkin
Scenario: Marc révoque son token "Dashboard perso"
  GIVEN Marc possède un token actif "Dashboard perso" dans la liste
  WHEN Marc clique "Révoquer" et confirme la suppression dans la modale
  THEN le token disparaît immédiatement de la liste via Turbo Stream
  AND toute requête API ultérieure avec ce token retourne HTTP 401 {"error": "token_revoked"}
  AND revoked_at est renseigné en base avec le timestamp courant
  AND un événement "token_revoked" est enregistré dans api_token_events
```

### Scénario alternatif 2 — Limite de tokens atteinte (Free)

```gherkin
Scenario: Marc (Free) tente de créer un 6e token alors que la limite est 5
  GIVEN Marc est sur un compte Free avec 5 tokens actifs
  WHEN Marc tente de créer un nouveau token via le formulaire
  THEN l'API retourne HTTP 422 {"error": "quota_exceeded", "current": 5, "max": 5, "upgrade_url": "/pricing"}
  AND la page affiche "Limite de 5 tokens atteinte. Passez à Premium pour en créer davantage."
  AND aucun token supplémentaire n'est créé en base
```

### Scénario d'erreur 1 — Création sans nom

```gherkin
Scenario: Marc soumet le formulaire de création avec un nom vide
  GIVEN Marc est authentifié et accède à "/account/api-tokens"
  WHEN Marc soumet le formulaire avec le champ "Nom" vide
  THEN l'API retourne HTTP 422 {"violations": [{"field": "name", "message": "Le nom est obligatoire (1-100 caractères)"}]}
  AND aucun token n'est créé en base
  AND le formulaire reste visible avec le message d'erreur inline
```

### Scénario d'erreur 2 — Accès non authentifié à la gestion des tokens

```gherkin
Scenario: Un visiteur non connecté tente d'accéder à la page des tokens
  GIVEN un visiteur sans session active
  WHEN il accède directement à "/account/api-tokens"
  THEN il est redirigé vers "/login?redirect=/account/api-tokens"
  AND aucune donnée de token n'est exposée dans la réponse HTTP
  AND le code HTTP de la redirection est 302
```
