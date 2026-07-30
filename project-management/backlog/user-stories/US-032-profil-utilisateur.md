# US-032 : Gestion du profil utilisateur

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-032 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-002 Priya, chercheuse en stratégie |
| Story Points | 3 |
| Priorité MoSCoW | Should Have |
| Sprint | sprint-003-consolidation |

---

## Carte (User Story)

**En tant que** P-002 Priya, chercheuse en stratégie
**Je veux** modifier mon nom complet, mon adresse email et ma bio professionnelle et enregistrer explicitement mes changements
**Afin de** que mon profil reflète mon identité professionnelle et que mes exports et partages d'insights soient correctement attribués à ma fonction.

---

## Conversation (points à clarifier / décisions ouvertes)

- La modification de l'email doit-elle déclencher un email de confirmation avant application (proposition : oui, double opt-in) ?
- Limite de caractères pour la bio professionnelle : 280 caractères (aligné Twitter/X) ou 500 ?
- Avatar en v1 : initiales générées côté serveur ou Gravatar ? (proposition : initiales en v1, upload en v1.1)
- Changer l'email invalide-t-il les sessions OAuth liées à l'email précédent ?
- Faut-il un champ "Organisation / Entreprise" distinct de la bio dès v1 ?

---

## Validation INVEST

- [x] **Independent** : peut être développée indépendamment de US-031 et US-034
- [x] **Negotiable** : avatar et champ organisation différables en v1.1
- [x] **Valuable** : crédibilité des partages et personnalisation — critère clé pour P-002
- [x] **Estimable** : formulaire CRUD avec validation = 3 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin ci-dessous

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Colonnes dans `users` : `full_name` (VARCHAR 255), `bio` (VARCHAR 280), `email` (contrainte UNIQUE déjà présente), `email_pending` (VARCHAR, email en cours de validation) |
| **Backend Symfony** | `ProfileController::edit()` (GET/POST /profile/edit), `ProfileFormType`, `ProfileVoter::EDIT`, `EmailChangeService` (envoi email de confirmation) |
| **Symfony Validator** | `NotBlank` sur `full_name`, `Email` sur `email`, `Length(max:280)` sur `bio`, `UniqueEntity(email)` |
| **Twig / Turbo** | Vue `/profile/edit` rendue dans un Turbo Frame `profile-form` ; message flash persisté hors Turbo ; compteur de caractères temps réel sur le champ bio (Stimulus) |
| **Sécurité OWASP** | `ProfileVoter::EDIT` : l'utilisateur courant doit être le propriétaire du profil (HTTP 403 sinon) ; token CSRF sur le formulaire |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Mise à jour du nom et de la bio

```gherkin
Scenario: Priya met à jour son nom complet et sa bio professionnelle
  GIVEN Priya est authentifiée et accède à GET /profile/edit
  AND le formulaire affiche ses valeurs actuelles (full_name="Priya Kapoor", bio="")
  WHEN elle remplace full_name par "Dr. Priya Kapoor" et saisit bio="Chercheuse en stratégie technologique – MIT"
  AND elle clique sur "Enregistrer les modifications"
  THEN la réponse est HTTP 302 ou Turbo Frame 200
  AND `users.full_name = "Dr. Priya Kapoor"` et `users.bio = "Chercheuse en stratégie technologique – MIT"` sont persistés en base
  AND le message flash "Profil mis à jour avec succès" s'affiche sans rechargement complet (Turbo Frame)
  AND le header de la page affiche désormais "Dr. Priya Kapoor"
```

### Scénario alternatif 1 — Changement d'email avec confirmation

```gherkin
Scenario: Priya change son adresse email
  GIVEN Priya est sur /profile/edit avec email actuel "priya@institution.fr"
  WHEN elle remplace l'email par "priya.kapoor@newlab.fr" et enregistre
  THEN un email de confirmation est envoyé à "priya.kapoor@newlab.fr" avec un lien sécurisé (token UUID, TTL 24h)
  AND `users.email_pending = "priya.kapoor@newlab.fr"` est enregistré en base
  AND `users.email` reste "priya@institution.fr" jusqu'à validation du nouveau
  AND un message "Un email de confirmation a été envoyé à priya.kapoor@newlab.fr" s'affiche
```

### Scénario alternatif 2 — Bio dépassant la limite de 280 caractères

```gherkin
Scenario: Bio saisie dépassant la limite de caractères
  GIVEN Priya est sur /profile/edit
  AND elle saisit une bio de 295 caractères dans le champ bio
  THEN le compteur sous le champ affiche "295 / 280" en rouge
  WHEN elle soumet le formulaire
  THEN le serveur retourne HTTP 422 avec le formulaire invalide
  AND le message "La bio ne peut pas dépasser 280 caractères" s'affiche sous le champ
  AND aucune modification n'est persistée en base
```

### Scénario d'erreur 1 — Email de modification vers un email déjà utilisé

```gherkin
Scenario: Changement vers un email déjà associé à un autre compte
  GIVEN un compte existe avec l'email "existing@example.com"
  WHEN Priya tente de changer son email vers "existing@example.com" et enregistre
  THEN le formulaire retourne une erreur de validation
  AND le message "Cette adresse email est déjà associée à un compte Briefly AI" s'affiche
  AND `users.email` et `users.email_pending` de Priya ne sont pas modifiés
```

### Scénario d'erreur 2 — Accès non autorisé au profil d'un autre utilisateur

```gherkin
Scenario: Tentative d'édition du profil d'un autre utilisateur par manipulation d'URL
  GIVEN P-001 Thomas est authentifié (user_id=uuid-thomas)
  WHEN il accède directement à POST /profile/edit avec les données d'un autre UUID
  THEN le `ProfileVoter` retourne DENY
  AND le serveur retourne HTTP 403 Forbidden
  AND aucune modification n'est effectuée en base
  AND l'accès non autorisé est loggué en WARN (user_id demandeur, ressource ciblée, timestamp)
```
