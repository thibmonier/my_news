# US-034a : Stripe Checkout et activation de l'abonnement Premium (webhook subscription.created)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-034a |
| **EPIC parent** | EPIC-004 — Comptes Utilisateurs & Premium |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-034 — Abonnement Briefly Premium via Stripe Billing |

**Dépend de :** US-030 (compte utilisateur créé), US-033 (QuotaService existant avec paywall placeholder)
**Requis par :** US-034b (Customer Portal — requiert un abonnement actif en base)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** souscrire à Briefly Premium (mensuel 12 €/mois ou annuel 99 €/an) via une interface Stripe Checkout sécurisée
**Afin d'** accéder immédiatement aux synthèses IA illimitées et de ne plus interrompre mon flux de lecture quotidien.

---

## Les 3 C

### Carte (résumé)

Page `/premium` (tarifs, avantages, CTA) → redirection vers Stripe Checkout (`GET /premium/checkout/{plan}`) → paiement Stripe → webhook `checkout.session.completed` reçu sur `POST /stripe/webhook` → signature HMAC-SHA256 vérifiée → dispatch `StripeWebhookMessage` via Symfony Messenger → `SubscriptionActivatedHandler` crée l'enregistrement dans la table `subscriptions` (status="active") via `INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING` → `QuotaService::isPremium()` retourne true → badge "Premium" visible dans le header Twig.

### Conversation (notes & questions ouvertes)

- Une période d'essai (trial) est-elle prévue en v1 ? Non, différée en v1.1.
- Stripe Tax est-il configuré en v1 ? Oui, configuration Stripe Tax pour la TVA automatique (Stripe gère le calcul).
- URL de succès après checkout : `/dashboard?checkout=success` avec flash message "Bienvenue dans Briefly Premium !" affiché via Turbo.
- URL d'annulation : `/premium?checkout=cancelled` avec message d'erreur non bloquant.
- Idempotence sur `stripe_event_id` : `INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING` — garantit zéro doublon même si Stripe renvoie l'événement en double.
- La signature HMAC-SHA256 est vérifiée via la clé `STRIPE_WEBHOOK_SECRET` (variable d'environnement, jamais dans le code).

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Table `subscriptions` | `id UUID, user_id FK→users.id ON DELETE CASCADE, stripe_customer_id VARCHAR UNIQUE, stripe_subscription_id VARCHAR UNIQUE, plan ENUM(monthly/yearly), status ENUM(active/past_due/cancelled), current_period_end TIMESTAMPTZ, stripe_event_id VARCHAR UNIQUE` |
| **Stripe Dashboard** | Produits & Prices | "Briefly Premium Mensuel" 12 €/mois (price_monthly) + "Briefly Premium Annuel" 99 €/an (price_yearly) ; Stripe Tax configuré |
| **Backend** | `StripeCheckoutController` | `GET /premium/checkout/{plan}` → `SubscriptionService::createCheckoutSession()` → redirection Stripe Checkout |
| **Backend** | `StripeWebhookController` | `POST /stripe/webhook` : vérification HMAC-SHA256 via `stripe_webhook_secret` → dispatch `StripeWebhookMessage` |
| **Messenger** | `SubscriptionActivatedHandler` | Traite `checkout.session.completed` : `INSERT INTO subscriptions ... ON CONFLICT (stripe_event_id) DO NOTHING` |
| **Domain** | `QuotaService::isPremium()` | Vérifie `subscriptions.status = 'active' AND current_period_end > NOW()` → bypass décompte quota |
| **Twig / Turbo** | Page `/premium` + badge header | Tarifs, avantages, CTA Checkout mensuel/annuel ; badge "Premium" dans le header Twig dès activation |
| **Sécurité** | HMAC-SHA256 + idempotence | `stripe_webhook_secret` en `.env` (jamais commité) ; `ON CONFLICT (stripe_event_id) DO NOTHING` pour idempotence |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Souscription mensuelle réussie et activation Premium immédiate

```gherkin
Scenario: Thomas souscrit au plan mensuel Briefly Premium
  GIVEN Thomas est authentifié avec un compte free ayant atteint la limite de 3 synthèses/jour
  AND il est sur la page /premium
  WHEN il clique sur "Choisir l'offre mensuelle – 12€/mois"
  AND est redirigé vers Stripe Checkout
  AND complète le paiement avec une carte valide (4242 4242 4242 4242 en mode test)
  THEN Stripe émet l'événement "checkout.session.completed"
  AND le webhook POST /stripe/webhook est reçu avec la signature HMAC-SHA256 valide
  AND StripeWebhookMessage est dispatchée dans Symfony Messenger
  AND SubscriptionActivatedHandler crée un enregistrement dans subscriptions (status="active", plan="monthly")
  AND QuotaService::isPremium(thomas) retourne true dès la prochaine synthèse
  AND Thomas est redirigé vers /dashboard avec le badge "Premium" visible dans le header
  AND Thomas reçoit l'email de confirmation Stripe automatique
```

### Scénario alternatif 1 — Souscription annuelle avec économie affichée

```gherkin
Scenario: Priya choisit le plan annuel Briefly Premium
  GIVEN Priya est sur la page /premium
  AND la page affiche "Offre annuelle – 99€/an (économisez 45€ soit 17%)"
  WHEN elle clique sur "Choisir l'offre annuelle"
  AND complète le paiement Stripe
  THEN la souscription est créée dans subscriptions avec plan="yearly"
  AND current_period_end est positionné à J+365 (date exacte)
  AND Priya est redirigée vers /dashboard avec le badge "Premium Annuel"
```

### Scénario alternatif 2 — Webhook en double ignoré (idempotence garantie)

```gherkin
Scenario: Stripe renvoie un événement checkout.session.completed déjà traité
  GIVEN l'événement Stripe "checkout.session.completed" avec id "evt_1ABCDEF" a déjà été traité
  AND un enregistrement existe dans subscriptions avec stripe_event_id = "evt_1ABCDEF"
  WHEN Stripe renvoie le même événement "evt_1ABCDEF" sur POST /stripe/webhook
  THEN la signature HMAC-SHA256 est vérifiée et valide
  AND SubscriptionActivatedHandler détecte le conflit via INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING
  AND le handler retourne sans erreur et le webhook controller retourne HTTP 200
  AND aucun doublon n'est créé dans subscriptions
```

### Scénario erreur 1 — Paiement refusé par Stripe (carte expirée)

```gherkin
Scenario: Paiement refusé lors du Stripe Checkout — carte expirée
  GIVEN Thomas tente de souscrire au plan mensuel
  WHEN il saisit une carte expirée dans Stripe Checkout (4000 0000 0000 0069 en mode test)
  THEN Stripe refuse le paiement et affiche un message d'erreur natif dans le Checkout
  AND aucun événement "checkout.session.completed" n'est émis par Stripe
  AND aucun enregistrement n'est créé dans subscriptions
  WHEN Thomas est redirigé vers /premium?checkout=cancelled
  THEN le message "Le paiement n'a pas pu être traité. Vérifiez vos informations de carte." s'affiche
  AND son quota reste limité à 3 synthèses/jour
```

### Scénario erreur 2 — Webhook Stripe avec signature HMAC invalide (tentative de rejeu)

```gherkin
Scenario: Un webhook arrive sur POST /stripe/webhook avec une signature invalide
  GIVEN un webhook POST /stripe/webhook est reçu avec un header Stripe-Signature corrompu ou absent
  WHEN StripeWebhookController vérifie la signature HMAC-SHA256 avec stripe_webhook_secret
  THEN la vérification échoue (SignatureVerificationException Stripe SDK)
  AND le webhook controller retourne HTTP 400 sans traiter l'événement
  AND aucun StripeWebhookMessage n'est dispatché dans Messenger
  AND l'incident est loggé (WARNING) : { "ip": "...", "reason": "invalid_signature" }
```

---

## Estimation & Références

- **Story Points** : 5
- **MoSCoW** : Must Have
- **Parent SPLIT** : US-034

### Validation INVEST

- [x] **I**ndependent : module Stripe Checkout isolé ; livrable autonome — Premium activable et utilisable sans le portail de gestion (US-034b)
- [x] **N**egotiable : trial différé en v1.1, Stripe Tax configurable, URL de succès/annulation, plan "enterprise" différé
- [x] **V**aluable : source de revenu récurrent principale du produit ; Thomas et Priya passent Premium sans friction dès la livraison de cette US
- [x] **E**stimable : Stripe Checkout + `StripeWebhookController` + `SubscriptionActivatedHandler` Messenger + `QuotaService` bypass = 5 pts calibré
- [x] **S**ized : 5 pts ≤ 8 pts ✓
- [x] **T**estable : Stripe CLI pour simuler `checkout.session.completed`, carte test 4242/0069, HMAC invalide, `QuotaService::isPremium()` testable unitairement, idempotence vérifiable via fixtures
