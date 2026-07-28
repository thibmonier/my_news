# US-034 : Abonnement Briefly Premium via Stripe Billing

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-034 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-001 Thomas, cadre dirigeant tech / P-002 Priya, chercheuse stratégie |
| Story Points | 8 |
| Priorité MoSCoW | Must Have |
| Sprint | backlog |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech
**Je veux** souscrire à Briefly Premium (12 €/mois ou 99 €/an) via une interface Stripe sécurisée et gérer mon abonnement depuis un portail dédié
**Afin d'** accéder à des synthèses IA illimitées et ne plus interrompre mon flux de lecture quotidien.

---

## Conversation (points à clarifier / décisions ouvertes)

- Une période d'essai (trial) est-elle prévue en v1 ? (proposition : non, différée en v1.1)
- En cas d'échec de paiement Stripe, une grace period de 3 jours est-elle accordée avant de revenir au quota free ?
- Downgrade Premium → free : le quota s'applique-t-il immédiatement ou à la fin de la période payée ?
- Stripe Customer Portal natif ou portail custom ? (proposition : Customer Portal natif en v1 pour réduire la complexité)
- Les webhooks Stripe doivent-ils être idempotents via `stripe_event_id` UNIQUE en base ? (oui — obligatoire)
- La TVA est-elle gérée par Stripe Tax en v1 ? (proposition : oui, configuration Stripe Tax)

---

## Validation INVEST

- [x] **Independent** : dépend de US-030 (compte) et US-033 (quota) mais le module Stripe est isolé
- [x] **Negotiable** : Customer Portal natif Stripe évite un portail custom ; trial différable
- [x] **Valuable** : source de revenu récurrent principale du produit ; accès illimité pour l'utilisateur
- [x] **Estimable** : Stripe Checkout + webhooks + Messenger handlers + bypass quota = 8 pts (limite haute)
- [x] **Sized** : ≤ 8 points (limite atteinte — pas de découpage supplémentaire nécessaire)
- [x] **Testable** : critères Gherkin ci-dessous ; webhooks testés via Stripe CLI + stubs d'intégration

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `subscriptions` : `id` (UUID), `user_id` (FK → users.id ON DELETE CASCADE), `stripe_customer_id` (VARCHAR UNIQUE), `stripe_subscription_id` (VARCHAR UNIQUE), `plan` (ENUM: monthly, yearly), `status` (ENUM: active, past_due, cancelled, trialing), `current_period_end` (TIMESTAMPTZ), `stripe_event_id` (VARCHAR UNIQUE, idempotence) |
| **Stripe** | Produits : "Briefly Premium Mensuel" (12 €/mois, Price ID = price_monthly) + "Briefly Premium Annuel" (99 €/an, Price ID = price_yearly) ; Customer Portal activé dans le Dashboard Stripe |
| **Backend Symfony** | `SubscriptionService::createCheckoutSession()`, `StripeCheckoutController` (GET /premium/checkout/{plan}), `StripeWebhookController` (POST /stripe/webhook) |
| **Symfony Messenger** | `StripeWebhookMessage` dispatchée depuis le webhook ; handlers : `SubscriptionActivatedHandler`, `SubscriptionCancelledHandler`, `PaymentFailedHandler`, `SubscriptionUpdatedHandler` |
| **Quota bypass** | `QuotaService::isPremium(User $user): bool` vérifie `subscriptions.status = 'active' AND current_period_end > NOW()` ; si Premium, `consumeOrDeny()` retourne toujours true sans incrémenter Redis |
| **Twig / Turbo** | Page `/premium` (tarifs, avantages, CTA Checkout) ; lien "Gérer mon abonnement" → Stripe Customer Portal (redirection server-side) ; badge "Premium" dans le header |
| **Sécurité OWASP** | Signature HMAC-SHA256 Stripe vérifiée via `stripe_webhook_secret` (variable d'environnement) ; idempotence via `INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING` |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Souscription mensuelle réussie

```gherkin
Scenario: Thomas souscrit au plan mensuel Briefly Premium
  GIVEN Thomas est authentifié avec un compte free ayant atteint la limite de 3 synthèses
  AND il est sur la page /premium
  WHEN il clique sur "Choisir l'offre mensuelle – 12€/mois"
  AND est redirigé vers Stripe Checkout
  AND complète le paiement avec une carte valide (4242 4242 4242 4242 en mode test)
  THEN Stripe émet l'événement "checkout.session.completed"
  AND le webhook POST /stripe/webhook est reçu avec la signature HMAC valide
  AND `StripeWebhookMessage` est dispatchée dans Symfony Messenger
  AND `SubscriptionActivatedHandler` crée un enregistrement dans `subscriptions` (status="active", plan="monthly")
  AND `QuotaService::isPremium(thomas)` retourne true dès la prochaine synthèse
  AND Thomas est redirigé vers /dashboard avec le badge "Premium" visible dans le header
  AND Thomas reçoit l'email de confirmation Stripe automatique
```

### Scénario alternatif 1 — Souscription annuelle avec économie affichée

```gherkin
Scenario: Priya choisit le plan annuel
  GIVEN Priya est sur la page /premium
  AND la page affiche "Offre annuelle – 99€/an (économisez 45€ soit 17%)"
  WHEN elle clique sur "Choisir l'offre annuelle"
  AND complète le paiement Stripe
  THEN la souscription est créée avec plan="yearly"
  AND `current_period_end` est positionné à J+365 (date exacte)
  AND Priya est redirigée vers /dashboard avec le badge "Premium Annuel"
```

### Scénario alternatif 2 — Gestion de l'abonnement via Customer Portal Stripe

```gherkin
Scenario: Thomas accède au Customer Portal Stripe pour modifier son abonnement
  GIVEN Thomas est authentifié avec un abonnement Premium actif
  WHEN il clique sur "Gérer mon abonnement" dans /profile
  THEN `SubscriptionService::createPortalSession()` génère une URL de session Stripe Customer Portal
  AND Thomas est redirigé vers le Stripe Customer Portal
  AND peut modifier sa méthode de paiement, changer de plan ou annuler son abonnement
  WHEN il annule son abonnement depuis le Customer Portal et revient sur Briefly AI
  THEN le webhook "customer.subscription.updated" a été traité
  AND `subscriptions.status` = "cancelled" en base
  AND `QuotaService::isPremium(thomas)` retourne false
  AND le badge "Premium" n'apparaît plus dans le header
```

### Scénario d'erreur 1 — Paiement refusé par Stripe lors du checkout

```gherkin
Scenario: Paiement refusé – carte expirée
  GIVEN Thomas tente de souscrire au plan mensuel
  WHEN il saisit une carte expirée dans Stripe Checkout
  THEN Stripe refuse le paiement et affiche un message d'erreur natif dans le Checkout
  AND aucun événement "checkout.session.completed" n'est émis
  AND aucun enregistrement n'est créé dans `subscriptions`
  WHEN Thomas est redirigé vers l'URL d'annulation /premium?checkout=cancelled
  THEN le message "Le paiement n'a pas pu être traité. Vérifiez vos informations de carte." s'affiche
  AND son quota reste limité à 3 synthèses/jour
```

### Scénario d'erreur 2 — Webhook Stripe reçu en double (idempotence garantie)

```gherkin
Scenario: Stripe renvoie un événement déjà traité
  GIVEN l'événement Stripe "checkout.session.completed" avec id "evt_1ABCDEF" a déjà été traité
  AND un enregistrement existe dans `subscriptions` avec `stripe_event_id = "evt_1ABCDEF"`
  WHEN Stripe renvoie le même événement "evt_1ABCDEF" sur POST /stripe/webhook
  THEN la signature HMAC est vérifiée et valide
  AND `SubscriptionActivatedHandler` détecte le conflit via `INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING`
  AND le handler retourne sans erreur
  AND le webhook controller retourne HTTP 200
  AND aucun doublon n'est créé dans `subscriptions`
  AND l'idempotence est loggée en DEBUG (event_id, action="skipped-duplicate")
```
