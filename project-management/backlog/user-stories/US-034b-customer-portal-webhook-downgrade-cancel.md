# US-034b : Customer Portal Stripe et gestion du cycle de vie de l'abonnement (downgrade/annulation)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-034b |
| **EPIC parent** | EPIC-004 — Comptes Utilisateurs & Premium |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-034 — Abonnement Briefly Premium via Stripe Billing |

**Dépend de :** US-034a (abonnement Premium actif en base, infrastructure webhook `POST /stripe/webhook` opérationnelle)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** gérer mon abonnement Briefly Premium (modifier le plan, changer de moyen de paiement, annuler) depuis un Customer Portal Stripe dédié
**Afin de** contrôler mon abonnement en toute autonomie sans contacter le support, et que les changements soient immédiatement reflétés dans mon accès à l'application.

---

## Les 3 C

### Carte (résumé)

Bouton "Gérer mon abonnement" dans `/profile` → `GET /profile/manage-subscription` → `SubscriptionService::createPortalSession()` génère une session Stripe Customer Portal (natif, sans portail custom) → redirection. Les événements retour Stripe (`customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`) sont reçus via le webhook existant `POST /stripe/webhook` (US-034a). Trois nouveaux handlers Messenger : `SubscriptionCancelledHandler`, `SubscriptionUpdatedHandler`, `PaymentFailedHandler`. En cas d'annulation : `cancel_at_period_end = true` → Premium actif jusqu'à `current_period_end`. Grace period 3 jours sur échec de paiement (`status = past_due`).

### Conversation (notes & questions ouvertes)

- En cas d'annulation via le portail, le downgrade est-il immédiat ou en fin de période ? Décision : fin de période payée (`cancel_at_period_end = true`). `isPremium()` reste vrai jusqu'à `current_period_end`.
- Grace period en cas d'échec de paiement ? 3 jours (`status = past_due`) avant de revenir au quota free. Géré par `invoice.payment_failed` + `PaymentFailedHandler`.
- Un utilisateur peut-il re-souscrire après annulation ? Via un nouveau Stripe Checkout (US-034a) — pas de gestion de resubscription spécifique en v1.
- La confirmation d'annulation est-elle envoyée par email custom ? Non — email automatique Stripe (pas d'email custom en v1).
- Les sessions Customer Portal expirent-elles ? Oui, après 5 minutes (comportement Stripe natif). L'utilisateur peut régénérer une session depuis `/profile`.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Backend** | `SubscriptionService::createPortalSession()` | Crée session Stripe Customer Portal avec `return_url = /profile` ; utilise `stripe_customer_id` de la table `subscriptions` |
| **Backend** | `ProfileController::manageSubscription()` | `GET /profile/manage-subscription` → génère URL portail → redirection 302 |
| **Messenger** | `SubscriptionCancelledHandler` | Traite `customer.subscription.deleted` : `subscriptions.status = "cancelled"` |
| **Messenger** | `SubscriptionUpdatedHandler` | Traite `customer.subscription.updated` : met à jour `plan`, `status`, `current_period_end`, `cancel_at_period_end` |
| **Messenger** | `PaymentFailedHandler` | Traite `invoice.payment_failed` : `subscriptions.status = "past_due"` (grace period 3 jours) |
| **Twig / Turbo** | Page `/profile` | Lien "Gérer mon abonnement" → `GET /profile/manage-subscription` ; badge "Premium" disparaît après `status = "cancelled"` ET `current_period_end < NOW()` |
| **Domain** | `QuotaService::isPremium()` | Vérifie `status IN ('active', 'past_due') AND current_period_end > NOW()` pour grace period ; downgrade effectif à expiration |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Annulation de l'abonnement via le Customer Portal avec fin de période

```gherkin
Scenario: Thomas annule son abonnement depuis le Customer Portal Stripe
  GIVEN Thomas est authentifié avec un abonnement Premium actif (status="active", current_period_end = dans 15 jours)
  WHEN il clique sur "Gérer mon abonnement" dans /profile
  THEN SubscriptionService::createPortalSession() génère une URL de session Stripe Customer Portal
  AND Thomas est redirigé vers le Stripe Customer Portal
  WHEN il annule son abonnement depuis le Customer Portal et est redirigé vers /profile
  THEN le webhook "customer.subscription.updated" (cancel_at_period_end=true) a été reçu et traité
  AND subscriptions.status reste "active" et cancel_at_period_end = true en base
  AND QuotaService::isPremium(thomas) retourne encore true jusqu'à current_period_end (accès Premium maintenu)
  AND le badge "Premium" s'affiche toujours jusqu'à l'expiration de la période payée
```

### Scénario alternatif 1 — Changement de moyen de paiement via le Customer Portal

```gherkin
Scenario: Thomas met à jour sa carte bancaire depuis le Customer Portal Stripe
  GIVEN Thomas est authentifié avec un abonnement Premium actif
  WHEN il accède au Customer Portal Stripe et met à jour sa méthode de paiement
  THEN Stripe enregistre la nouvelle carte sans émettre d'événement subscription.updated ou subscription.deleted
  AND l'abonnement continue normalement (status reste "active")
  AND aucun enregistrement dans subscriptions n'est modifié
  AND Thomas est redirigé vers /profile après la mise à jour
```

### Scénario alternatif 2 — Paiement échoué avec grace period de 3 jours

```gherkin
Scenario: Le renouvellement mensuel échoue — grace period avant downgrade vers Free
  GIVEN Thomas a un abonnement mensuel actif dont le renouvellement échoue (carte expirée)
  WHEN Stripe émet l'événement "invoice.payment_failed"
  THEN PaymentFailedHandler met subscriptions.status = "past_due"
  AND QuotaService::isPremium(thomas) retourne encore true pendant 3 jours (grace period)
  AND Thomas reçoit l'email automatique Stripe invitant à mettre à jour son moyen de paiement
  AND si aucun paiement réussi dans les 3 jours, subscriptions.status passe à "cancelled" et le quota free s'applique
```

### Scénario erreur 1 — Session Customer Portal expirée à l'accès

```gherkin
Scenario: La session Stripe Customer Portal est expirée (> 5 minutes après génération)
  GIVEN Thomas a généré une URL de Customer Portal il y a plus de 5 minutes
  WHEN il tente d'accéder à l'URL du Customer Portal
  THEN Stripe retourne une page d'erreur native "Lien expiré"
  AND Thomas peut revenir sur /profile et cliquer à nouveau "Gérer mon abonnement" pour obtenir une nouvelle session
  AND aucune donnée dans subscriptions n'est modifiée par cette tentative
```

### Scénario erreur 2 — Webhook de downgrade reçu pour un abonnement inconnu

```gherkin
Scenario: Webhook customer.subscription.deleted reçu pour un stripe_subscription_id inconnu en base
  GIVEN Stripe envoie "customer.subscription.deleted" avec stripe_subscription_id = "sub_UNKNOWN"
  WHEN SubscriptionCancelledHandler cherche l'abonnement via stripe_subscription_id dans subscriptions
  THEN aucun enregistrement correspondant n'est trouvé
  AND le handler se termine sans erreur (comportement idempotent)
  AND un log WARNING est enregistré : { "stripe_subscription_id": "sub_UNKNOWN", "action": "skip-not-found" }
  AND le webhook controller retourne HTTP 200 (évite un retry Stripe inutile)
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Must Have
- **Parent SPLIT** : US-034

### Validation INVEST

- [x] **I**ndependent : Customer Portal + handlers cancel/update/payment_failed isolés de l'activation (US-034a) ; l'infrastructure webhook est opérationnelle dès US-034a
- [x] **N**egotiable : portail custom vs Customer Portal natif (choix natif en v1), grace period (3 jours négociable), comportement `cancel_at_period_end` vs annulation immédiate
- [x] **V**aluable : sans gestion du cycle de vie, Thomas est bloqué si il veut annuler → risque de litiges et charge support ; P-002 Priya en a aussi besoin pour son plan annuel
- [x] **E**stimable : `createPortalSession()` + 3 handlers Messenger + `QuotaService` state machine (past_due) = 3 pts, scope clairement borné
- [x] **S**ized : 3 pts ≤ 8 pts ✓
- [x] **T**estable : Stripe CLI pour `customer.subscription.updated/deleted`, `invoice.payment_failed` ; `isPremium()` state machine (active/past_due/cancelled) testable unitairement ; session expirée simulable en test d'intégration
