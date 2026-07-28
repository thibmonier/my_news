# US-031 : Authentification déléguée Google / GitHub (OAuth2)

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-031 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-001 Thomas, cadre dirigeant tech / P-002 Priya, chercheuse stratégie |
| Story Points | 5 |
| Priorité MoSCoW | Must Have |
| Sprint | backlog |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech
**Je veux** me connecter à Briefly AI avec mon compte Google ou GitHub en un seul clic
**Afin de** accéder rapidement à la plateforme sans créer ni mémoriser un mot de passe supplémentaire, et réduire la friction à l'inscription.

---

## Conversation (points à clarifier / décisions ouvertes)

- Fusion de compte : si un email OAuth correspond à un compte email/password existant, lie-t-on automatiquement ou affiche-t-on un message de confirmation ?
- GitHub n'expose pas toujours l'email principal (privacy mode) : faut-il utiliser l'email noreply généré par GitHub ou bloquer l'inscription ?
- Avatar : stocker localement en base64 ou conserver l'URL du provider (risque de rupture) ?
- Révocation du token OAuth côté provider : quel comportement ? (session déjà ouverte reste valide jusqu'à expiration naturelle)
- LinkedIn ou Apple ID sont-ils prévus en v1 ? (non, différés)

---

## Validation INVEST

- [x] **Independent** : logique OAuth isolée dans des Authenticators dédiés ; dépend de la table `users` (US-030) mais pas de la logique de quota
- [x] **Negotiable** : providers additionnels (LinkedIn, Apple) différables
- [x] **Valuable** : réduit la friction pour les personas tech-savvy ; augmente le taux de conversion inscription
- [x] **Estimable** : KnpU OAuth2 bundle + deux providers + mapping email = 5 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin ci-dessous (avec mocks OAuth en tests d'intégration)

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `oauth_accounts` : `id` (UUID), `user_id` (FK → users.id ON DELETE CASCADE), `provider` (ENUM: google, github), `provider_id` (VARCHAR, UNIQUE par provider), `email_provider` (VARCHAR), `created_at` |
| **Backend Symfony** | `knpuniversity/oauth2-client-bundle` ; `GoogleAuthenticator`, `GithubAuthenticator` implémentant `OAuth2Authenticator` ; `OAuthUserProvider::loadUserByIdentifier()` |
| **Mapping email** | Si email existe déjà en `users` : lier sans doublon via `oauth_accounts.user_id` ; si absent : créer un nouveau compte `users` |
| **Session** | Session HttpOnly ouverte après callback OAuth (même mécanique que US-030) |
| **Twig / Turbo** | Boutons "Continuer avec Google" et "Continuer avec GitHub" sur /login et /register ; Turbo Drive pour navigation sans rechargement |
| **Sécurité OWASP** | Paramètre `state` généré aléatoirement et vérifié en session (protection CSRF OAuth) ; secrets OAuth en variables d'environnement, jamais loggués |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Connexion Google réussie, nouvel utilisateur

```gherkin
Scenario: Premier accès via Google – création de compte automatique
  GIVEN un visiteur non authentifié est sur la page /login
  AND aucun compte n'existe pour l'email "thomas@gmail.com"
  WHEN il clique sur "Continuer avec Google"
  AND Google redirige vers /oauth/callback/google avec un code valide et le state attendu
  AND Briefly AI échange le code contre un access_token Google
  THEN un enregistrement est créé dans `users` avec email="thomas@gmail.com"
  AND un enregistrement est créé dans `oauth_accounts` avec provider="google" et provider_id="google-user-id-123"
  AND une session HttpOnly est ouverte
  AND le visiteur est redirigé vers /dashboard
  AND la page affiche "Bienvenue sur Briefly AI !"
```

### Scénario alternatif 1 — Email OAuth déjà enregistré (fusion de compte)

```gherkin
Scenario: Connexion Google avec un email déjà lié à un compte email/password
  GIVEN un compte existe dans `users` avec email="thomas@example.com" (inscription email/password)
  AND aucun enregistrement n'existe dans `oauth_accounts` pour cet email et provider="google"
  WHEN Thomas se connecte via Google avec le compte "thomas@example.com"
  THEN un enregistrement est créé dans `oauth_accounts` liant provider_id Google au user existant
  AND aucun doublon n'est créé dans `users`
  AND la session ouverte correspond au même compte que l'accès email/password
  AND Thomas est redirigé vers /dashboard normalement
```

### Scénario alternatif 2 — Connexion GitHub avec email masqué (privacy mode)

```gherkin
Scenario: Connexion GitHub avec email privé
  GIVEN un visiteur GitHub a activé le mode confidentialité (email masqué)
  WHEN il clique sur "Continuer avec GitHub" et autorise l'accès
  AND GitHub fournit l'email noreply "123456+user@users.noreply.github.com"
  THEN un compte est créé avec cet email noreply dans `users`
  AND le provider_id GitHub est stocké dans `oauth_accounts`
  AND un message "Pour recevoir les notifications, renseignez votre email dans votre profil" est affiché
  AND le compte est pleinement fonctionnel
```

### Scénario d'erreur 1 — Refus d'autorisation OAuth par l'utilisateur

```gherkin
Scenario: L'utilisateur refuse l'accès depuis la page Google
  GIVEN le visiteur est redirigé vers la page d'autorisation Google
  WHEN il clique sur "Refuser" ou ferme la fenêtre
  AND Google redirige vers /oauth/callback/google avec error="access_denied"
  THEN le visiteur est redirigé vers /login
  AND le message "Connexion annulée. Vous pouvez vous connecter avec votre email." s'affiche
  AND aucun compte n'est créé dans `users` ni `oauth_accounts`
```

### Scénario d'erreur 2 — Paramètre state OAuth invalide (protection CSRF)

```gherkin
Scenario: Callback OAuth reçu avec un state invalide ou absent
  GIVEN une requête arrive sur GET /oauth/callback/google
  AND le paramètre "state" est absent ou ne correspond pas à la valeur stockée en session
  THEN le serveur retourne HTTP 400
  AND le message "La requête de connexion est invalide. Veuillez recommencer." s'affiche sur /login
  AND aucune session n'est ouverte
  AND l'incident est loggué en WARN (IP, user-agent, timestamp) sans données personnelles
```
