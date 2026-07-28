# US-036 : Suppression de compte conforme RGPD

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-036 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-003 Marc, développeur indépendant privacy-first |
| Story Points | 3 |
| Priorité MoSCoW | Must Have |
| Sprint | backlog |

---

## Carte (User Story)

**En tant que** P-003 Marc, développeur indépendant
**Je veux** pouvoir supprimer définitivement mon compte et l'ensemble de mes données personnelles depuis une zone sécurisée et isolée de mes paramètres
**Afin d'** exercer mon droit à l'oubli RGPD (Article 17) et quitter la plateforme avec la certitude que mes données ne sont plus conservées.

---

## Conversation (points à clarifier / décisions ouvertes)

- Soft delete (30 jours) ou hard delete immédiat ? (proposition : hard delete immédiat — droit à l'oubli RGPD sans délai ; les statistiques agrégées non identifiantes sont conservées)
- Double confirmation : saisir le mot de passe + taper "SUPPRIMER" dans un champ libre ?
- Comportement si l'utilisateur a un abonnement Premium actif : annulation Stripe avant suppression, avec avertissement de non-remboursement ?
- Un email de confirmation de suppression est-il envoyé à l'adresse avant l'effacement (oui, obligatoire) ?
- Les données pseudonymisées dans les logs analytiques (non identifiantes) sont-elles conservées ? (oui, légal RGPD)
- Si l'annulation Stripe échoue, la suppression est-elle abandonnée (rollback) ou forcée ? (proposition : rollback + message d'erreur + log CRITICAL)

---

## Validation INVEST

- [x] **Independent** : fonctionne pour les comptes free (sans US-034) ; pour les comptes Premium, dépend de la table `subscriptions`
- [x] **Negotiable** : délai de rétention (soft delete vs hard delete) est une décision produit/légal
- [x] **Valuable** : obligation légale RGPD Article 17 ; confiance utilisateur ; non-conformité = amende CNIL
- [x] **Estimable** : zone danger + cascade DELETE + annulation Stripe conditionnelle = 3 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin ci-dessous

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | `ON DELETE CASCADE` sur toutes les FK référençant `users.id` : `oauth_accounts`, `subscriptions`, `user_privacy_settings`, `syntheses`, `bookmarks`, `user_feeds` ; log interne horodaté dans une table `account_deletions_log` (user_id hashed, deleted_at) — log non supprimé pour audit |
| **Backend Symfony** | `AccountDeletionController` (GET /settings/danger, POST /settings/danger/confirm), `AccountDeletionService::deleteAccount(User $user)` encapsulant la transaction ; `AccountDeletionVoter::DELETE` (propriétaire uniquement) |
| **Stripe** | `StripeService::cancelSubscriptionImmediately(string $subscriptionId)` appelé avant suppression si `subscriptions.status = 'active'` ; en cas d'échec Stripe → `AccountDeletionException` → rollback |
| **Redis** | `QuotaService::purgeUserKeys(User $user)` : suppression de toutes les clés `quota:synthesis:{uuid}:*` via `SCAN` + `DEL` dans la même transaction de service |
| **Session** | Invalidation de la session courante et de toutes les sessions actives de l'utilisateur (Symfony session storage) |
| **Twig / Turbo** | Bouton "Supprimer mon compte" dans une zone "Danger" isolée (bordure rouge) sur /settings/danger ; modale de confirmation Stimulus avec double validation (mot de passe + saisie "SUPPRIMER") ; page /goodbye après succès |
| **Email** | Email de confirmation de suppression envoyé AVANT l'effacement des données (adresse encore disponible) via `AccountDeletionMailer` |
| **RGPD** | Log d'audit anonymisé (hash SHA-256 de l'user_id, date, motif si fourni) conservé pour obligations légales, non lié aux données personnelles |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Suppression réussie d'un compte free

```gherkin
Scenario: Marc supprime son compte free avec double confirmation
  GIVEN Marc est authentifié et accède à GET /settings/danger
  AND une zone "Danger" avec fond rouge pale et le titre "Zone de danger" est affichée
  WHEN Marc clique sur "Supprimer mon compte définitivement"
  AND une modale de confirmation s'ouvre
  AND Marc saisit son mot de passe correct dans le champ "Confirmer avec votre mot de passe"
  AND Marc saisit "SUPPRIMER" dans le champ texte de confirmation
  AND Marc clique sur "Confirmer la suppression"
  THEN un email de confirmation est envoyé à l'adresse de Marc avant toute suppression
  AND toutes les lignes référençant `users.id = marc-uuid` sont supprimées en cascade (oauth_accounts, user_privacy_settings, syntheses, bookmarks, user_feeds)
  AND toutes les clés Redis `quota:synthesis:{marc-uuid}:*` sont supprimées
  AND la session courante de Marc est invalidée
  AND une ligne anonymisée est insérée dans `account_deletions_log` (hash de l'UUID, deleted_at, plan="free")
  AND Marc est redirigé vers GET /goodbye avec le message "Votre compte a bien été supprimé. Vos données ont été définitivement effacées."
```

### Scénario alternatif 1 — Suppression avec abonnement Premium actif

```gherkin
Scenario: Thomas supprime son compte avec un abonnement Premium mensuel actif
  GIVEN Thomas est authentifié avec `subscriptions.status = "active"` et `stripe_subscription_id = "sub_TEST123"`
  WHEN il initie la suppression depuis /settings/danger et fournit la double confirmation
  THEN avant la suppression en base, la modale affiche l'avertissement "Votre abonnement Briefly Premium sera annulé immédiatement. Aucun remboursement au prorata ne sera effectué."
  WHEN Thomas confirme malgré l'avertissement
  THEN `StripeService::cancelSubscriptionImmediately("sub_TEST123")` est appelé avec succès
  AND la suppression en cascade s'effectue (users + toutes tables liées)
  AND Thomas reçoit deux emails : confirmation de résiliation Stripe ET confirmation de suppression de compte
  AND `account_deletions_log` enregistre la ligne avec plan="monthly"
```

### Scénario alternatif 2 — Annulation de la procédure avant confirmation

```gherkin
Scenario: Marc ferme la modale de confirmation sans valider
  GIVEN Marc est sur la modale de confirmation de suppression (/settings/danger)
  WHEN il clique sur le bouton "Annuler" ou appuie sur la touche Échap
  THEN la modale se ferme
  AND aucune action de suppression n'est déclenchée côté serveur
  AND Marc reste sur la page /settings/danger avec son compte intact
```

### Scénario d'erreur 1 — Mot de passe de confirmation incorrect

```gherkin
Scenario: Marc saisit un mot de passe incorrect dans la modale de confirmation
  GIVEN Marc est sur la modale de confirmation de suppression
  WHEN il saisit "mauvaisMotDePasse" dans le champ "Confirmer avec votre mot de passe"
  AND saisit "SUPPRIMER" correctement
  AND clique sur "Confirmer la suppression"
  THEN le serveur vérifie le mot de passe via `UserPasswordHasher::isPasswordValid()`
  AND retourne HTTP 422 avec le corps de la modale
  AND le message "Mot de passe incorrect. La suppression n'a pas été effectuée." s'affiche dans la modale
  AND aucune donnée n'est supprimée
  AND la tentative est loggée en WARN (user_id, timestamp, raison="wrong_password") sans le mot de passe saisi
```

### Scénario d'erreur 2 — Échec de l'annulation Stripe lors de la suppression (rollback)

```gherkin
Scenario: L'annulation Stripe échoue lors de la suppression de compte
  GIVEN Thomas a un abonnement Premium actif avec stripe_subscription_id="sub_TEST456"
  AND l'API Stripe est temporairement indisponible
  WHEN Thomas initie la suppression avec double confirmation
  THEN `AccountDeletionService` ouvre une transaction PostgreSQL
  AND appelle `StripeService::cancelSubscriptionImmediately("sub_TEST456")`
  AND Stripe retourne une erreur réseau (timeout)
  THEN une `StripeServiceException` est levée
  AND la transaction PostgreSQL est rollback-ée
  AND aucune donnée n'est supprimée dans `users` ni les tables liées
  AND le message "Une erreur technique s'est produite. Votre compte n'a pas été supprimé. Veuillez réessayer ou contacter le support." s'affiche
  AND l'erreur est loggée en CRITICAL avec l'user_id hashé, stripe_subscription_id, et le message d'erreur Stripe
```
