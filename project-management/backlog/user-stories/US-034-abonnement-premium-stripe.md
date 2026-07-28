# US-034 : ~~Abonnement Briefly Premium via Stripe Billing~~ [SPLIT]

> **Statut : SPLIT** — Cette US a été découpée en 2 US enfants pour séparer l'activation de l'abonnement de sa gestion (portail client). Elle n'est **pas comptabilisée** dans les totaux du backlog (ni en points ni en nombre d'US).

## En-tête (archivé)

| Champ | Valeur |
|-------|--------|
| **ID** | US-034 |
| **EPIC parent** | EPIC-004 — Comptes Utilisateurs & Premium |
| **Persona** | P-001 Thomas / P-002 Priya |
| **~~Story Points~~** | ~~8~~ (remplacés par les points des US enfants) |
| **Statut** | **SPLIT — non comptabilisée** |

---

## US enfants (à utiliser à la place)

| ID | Titre | Points | Sprint |
|----|-------|--------|--------|
| [US-034a](US-034a-stripe-checkout-webhook-subscription-created.md) | Stripe Checkout et activation de l'abonnement Premium (webhook subscription.created) | 5 | backlog |
| [US-034b](US-034b-customer-portal-webhook-downgrade-cancel.md) | Customer Portal Stripe et gestion du cycle de vie (downgrade/annulation) | 3 | backlog |
| **Total** | | **8** | |

---

## Raison du découpage

L'US originale de 8 pts (limite haute INVEST) combinait deux flux utilisateur distincts avec des handlers Messenger différents :

1. **Stripe Checkout + activation (US-034a — 5 pts)** : page `/premium`, redirection Stripe Checkout, webhook `checkout.session.completed`, `SubscriptionActivatedHandler`, bypass quota via `QuotaService::isPremium()`. Livrable autonome — Thomas peut souscrire et accéder à Premium sans avoir accès au portail de gestion.

2. **Customer Portal + cycle de vie (US-034b — 3 pts)** : `createPortalSession()`, lien "Gérer mon abonnement" dans `/profile`, handlers `SubscriptionCancelledHandler` / `SubscriptionUpdatedHandler` / `PaymentFailedHandler`, grace period 3 jours. Requiert US-034a (abonnement actif en base, infrastructure webhook opérationnelle).

Ce découpage permet de livrer la conversion Premium (US-034a, valeur business immédiate) avant la gestion du cycle de vie (US-034b), réduisant le time-to-revenue.
