# US-030 : Inscription par email avec mot de passe sécurisé

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-030 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-001 Thomas / P-002 Priya / P-003 Marc (tout visiteur) |
| Story Points | 5 |
| Priorité MoSCoW | Must Have |
| Sprint | sprint-001 (Walking Skeleton) |

---

## Carte (User Story)

**En tant que** P-001 Thomas / P-002 Priya / P-003 Marc — tout visiteur non authentifié de Briefly AI
**Je veux** créer un compte avec mon adresse email et un mot de passe fort
**Afin de** accéder à mon espace personnel et commencer à utiliser les fonctionnalités de la plateforme.

---

## Conversation (points à clarifier / décisions ouvertes)

- La vérification email (lien de confirmation) est-elle obligatoire avant le premier accès en Sprint 1 ? (proposition : différée en v1.1, accès immédiat en Sprint 1)
- Le formulaire expose-t-il un indicateur de force de mot de passe côté client (Stimulus) dès Sprint 1 ?
- Faut-il afficher le lien "Vous avez déjà un compte ? Se connecter" pour éviter les doublons ?
- Quel wording exact pour la case CGU + politique de confidentialité (RGPD) ? Lien vers les pages légales ?
- Le toggle "Afficher/masquer le mot de passe" est-il requis dès Sprint 1 ou différable ?
- En cas de compte OAuth existant avec le même email : fusion automatique ou message d'erreur distinct ?

---

## Validation INVEST

- [x] **Independent** : aucune dépendance sur une autre US de ce sprint
- [x] **Negotiable** : vérification email et indicateur de force différables en v1.1
- [x] **Valuable** : porte d'entrée de toute la plateforme — sans inscription, zéro rétention
- [x] **Estimable** : formulaire + hachage Argon2id + session HttpOnly + CSRF = 5 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin SMART définis ci-dessous

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `users` : `id` (UUID v7, PK), `email` (VARCHAR 255, UNIQUE NOT NULL), `password_hash` (VARCHAR 255), `full_name` (VARCHAR 255), `created_at` (TIMESTAMPTZ), `consent_at` (TIMESTAMPTZ) |
| **Backend Symfony** | `RegistrationController::register()`, `UserFactory`, hachage Argon2id via `UserPasswordHasher`, `RegistrationFormType`, ouverture de session `UserAuthenticator` |
| **Symfony Validator** | Contraintes : `NotBlank`, `Email`, `Length(min:12)`, `Regex` (majuscule + chiffre + caractère spécial), `UniqueEntity(email)` |
| **Twig / Turbo** | Vue `/register`, toggle mot de passe (contrôleur Stimulus `password-toggle`), message flash succès, lien vers /login |
| **Sécurité OWASP** | Token CSRF sur le formulaire, rate-limit 10 inscriptions/h/IP via Redis (RateLimiterFactory), UUID non séquentiel (UUID v7) |
| **RGPD** | Case à cocher `consent_cgu` obligatoire, horodatage `consent_at` en base, lien vers /legal/cgu et /legal/privacy |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Inscription réussie

```gherkin
Scenario: Inscription réussie avec email valide et mot de passe fort
  GIVEN un visiteur non authentifié est sur la page GET /register
  AND il saisit l'email "thomas@example.com"
  AND il saisit le mot de passe "Briefly#2026!"
  AND il coche la case "J'accepte les Conditions Générales d'Utilisation"
  WHEN il soumet le formulaire (POST /register)
  THEN un enregistrement est créé dans `users` avec un UUID v7 non séquentiel
  AND `password_hash` contient un hash Argon2id (128 MiB, t=3, p=1)
  AND `consent_at` est renseigné avec l'horodatage UTC courant
  AND une session HttpOnly est ouverte (cookie `PHPSESSID` SameSite=Strict, Secure)
  AND le visiteur est redirigé (HTTP 302) vers /dashboard
  AND la réponse /dashboard contient le message flash "Bienvenue sur Briefly AI !"
```

### Scénario alternatif 1 — Toggle affichage/masquage du mot de passe

```gherkin
Scenario: L'utilisateur bascule l'affichage du mot de passe
  GIVEN le visiteur est sur la page /register
  AND le champ mot de passe `type="password"` contient "Briefly#2026!"
  WHEN il clique sur l'icône "Afficher le mot de passe" (contrôleur Stimulus)
  THEN l'attribut `type` du champ passe à "text" et le contenu est lisible
  WHEN il clique à nouveau sur l'icône "Masquer le mot de passe"
  THEN l'attribut `type` revient à "password" et le contenu est masqué
  AND aucune requête réseau n'est émise lors de ces deux actions
```

### Scénario alternatif 2 — Email déjà utilisé (sans fuite d'information)

```gherkin
Scenario: Inscription avec un email déjà enregistré
  GIVEN un compte existe en base avec l'email "thomas@example.com"
  AND un nouveau visiteur tente de s'inscrire avec ce même email
  WHEN le formulaire est soumis (POST /register)
  THEN le serveur retourne HTTP 200 avec le formulaire affiché
  AND le message "Un compte existe déjà pour cet email. Connectez-vous ou réinitialisez votre mot de passe." s'affiche
  AND le message ne distingue pas un compte email d'un compte OAuth (pas de fuite)
  AND aucun nouvel enregistrement n'est créé dans `users`
```

### Scénario d'erreur 1 — Mot de passe ne respectant pas la politique de sécurité

```gherkin
Scenario: Soumission avec un mot de passe trop faible
  GIVEN le visiteur est sur la page /register
  AND il saisit le mot de passe "simple123" (8 caractères, sans majuscule ni caractère spécial)
  WHEN il soumet le formulaire
  THEN le serveur retourne HTTP 422 avec le formulaire invalide
  AND le champ mot de passe affiche "Le mot de passe doit contenir au minimum 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial"
  AND aucun enregistrement n'est créé dans `users`
```

### Scénario d'erreur 2 — Rate limit dépassé (protection anti-spam)

```gherkin
Scenario: Rate limit inscription atteint depuis la même IP
  GIVEN 10 tentatives de POST /register ont été effectuées depuis l'IP "192.168.1.10" dans l'heure écoulée
  WHEN une 11e tentative d'inscription arrive depuis la même IP
  THEN le serveur retourne HTTP 429
  AND l'en-tête `Retry-After` indique le nombre de secondes avant déblocage
  AND le corps contient "Trop de tentatives. Réessayez dans 60 minutes."
  AND aucun enregistrement n'est créé dans `users`
  AND aucune information sur les tentatives précédentes n'est exposée
```
