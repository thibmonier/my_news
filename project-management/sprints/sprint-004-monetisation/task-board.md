# Task Board — Sprint 004 Monétisation Premium

> **Sprint Goal** : Activer la monétisation de Briefly AI — quota free réel (HTTP 402), Stripe Checkout Premium, Customer Portal + gestion cycle de vie, réglages RGPD autonomes — WEB-ONLY.

**Sprint** : 2026-09-08 → 2026-09-21 | **Vélocité cible** : 16 points

---

## Légende Statuts

| Icône | Statut | Description |
|-------|--------|-------------|
| 🔲 | A faire | Pas encore commencé |
| 🔄 | En cours | Développement en cours |
| 👀 | Review | Code review / QA |
| ✅ | Done | Critères DoD validés |
| 🚫 | Bloqué | Impediment identifié |

---

## Kanban — Niveau User Stories

### 🔲 A faire

| US | Titre | EPIC | Points | Assignee | Dépend de |
|----|-------|------|--------|----------|-----------|
| US-034a | Stripe Checkout + webhook activation Premium | EPIC-004 | 5 | — | T-TECH-01/02/03 |
| US-013 | Quota gratuit (3/jour) et paywall progressif | EPIC-002 | 5 | — | US-034a T-034a-04 |
| US-034b | Customer Portal + gestion cycle de vie | EPIC-004 | 3 | — | US-034a T-034a-07 |
| US-035 | Réglages confidentialité RGPD | EPIC-004 | 3 | — | US-030, US-032 (done) |

**Total A faire : 16 pts / 4 US**

---

## Kanban — Niveau Tâches techniques

### 🔲 A faire — Tâches transverses (démarrer J1)

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-TECH-01 | [OPS] | SDK stripe/stripe-php:^13.0 + services.yaml bindings | 1h | — |
| T-TECH-02 | [OPS] | Variables env Stripe .env.dist + .env.test | 0.5h | — |
| T-TECH-03 | [OPS] | security.yaml route /stripe/webhook PUBLIC_ACCESS + CSRF off | 0.5h | — |
| T-TECH-04 | [OPS] | Stripe CLI docs + make stripe-listen | 0.5h | T-TECH-03 |

**Sous-total transverses : 4 tâches — 2.5h**

---

### 🔲 A faire — US-034a : Stripe Checkout + webhook activation Premium

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-034a-01 | [DB] | DoctrineSubscriptionEntity (id, user_id FK, stripe_*, plan, status, current_period_end, stripe_event_id) | 1.5h | T-TECH-01 |
| T-034a-02 | [DB] | Migration subscriptions (FK CASCADE, UNIQUE stripe_*, index status+period_end) | 0.5h | T-034a-01 |
| T-034a-03 | [BE] | Domain Subscription aggregate + SubscriptionPlan + SubscriptionStatus enums | 2h | — |
| T-034a-04 | [BE] | DoctrineSubscriptionRepository (isPremium + save + findBy*) | 1.5h | T-034a-01, T-034a-03 |
| T-034a-05 | [BE] | SubscriptionService::createCheckoutSession (Stripe SDK + Stripe Tax) | 2h | T-034a-04, T-TECH-01, T-TECH-02 |
| T-034a-06 | [BE] | StripeCheckoutController GET /premium + GET /premium/checkout/{plan} | 1.5h | T-034a-05 |
| T-034a-07 | [BE] | StripeWebhookController POST /stripe/webhook HMAC-SHA256 + dispatch | 2h | T-TECH-01, T-TECH-03 |
| T-034a-08 | [BE] | StripeWebhookMessage + SubscriptionActivatedHandler (ON CONFLICT DO NOTHING) | 2h | T-034a-04, T-034a-07 |
| T-034a-09 | [FE-WEB] | Template /premium pricing (12€/mois, 99€/an, CTA checkout) | 2h | T-034a-06 |
| T-034a-10 | [FE-WEB] | Flash success + badge Premium base.html.twig | 1h | T-034a-08, T-013-07 |
| T-034a-11 | [TEST] | TEST Unit SubscriptionActivatedHandler idempotence | 1.5h | T-034a-08 |
| T-034a-12 | [TEST] | TEST Unit StripeWebhookController HMAC (valide/invalide/absent) | 1.5h | T-034a-07 |
| T-034a-13 | [TEST] | TEST WebTestCase /premium + webhook + idempotence | 2h | T-034a-06, T-034a-08 |
| T-034a-14 | [DOC] | PHPDoc WebhookController + Handler + SubscriptionService | 0.5h | T-034a-08 |
| T-034a-15 | [REV] | Code review US-034a | 1h | T-034a-14 |

**Sous-total US-034a : 15 tâches — 23h**

---

### 🔲 A faire — US-013 : Quota gratuit + paywall progressif

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-013-01 | [BE] | Domain SubscriptionRepositoryInterface (isPremium) | 0.5h | T-034a-03 |
| T-013-02 | [BE] | QuotaService::isPremium (bypass quota Premium) | 1h | T-013-01, T-034a-04 |
| T-013-03 | [BE] | QuotaService::refund (décrémente Redis sur erreur Mistral) | 0.5h | T-013-04 |
| T-013-04 | [BE] | QuotaCounterInterface::decrement + RedisQuotaCounter DECR planché | 1h | — |
| T-013-05 | [BE] | HTTP 402 + X-Quota-Remaining dans ArticleSynthesisProcessor + UrlSynthesisProcessor | 2h | T-013-02 |
| T-013-06 | [BE] | Refund quota sur SynthesisUnavailableException dans les processors | 1h | T-013-03, T-013-05 |
| T-013-07 | [FE-WEB] | Indicateur quota header Twig (Turbo Frame quota-indicator) | 1.5h | T-013-02 |
| T-013-08 | [FE-WEB] | PaywallModal CTA actif (href /premium/checkout/monthly) + template Twig | 1.5h | T-034a-06 |
| T-013-09 | [FE-WEB] | synthesis_controller.js intercept HTTP 402 + mise à jour quota-indicator | 1h | T-013-08 |
| T-013-10 | [TEST] | TEST Unit QuotaService isPremium + refund + plancher 0 | 1.5h | T-013-02, T-013-03 |
| T-013-11 | [TEST] | TEST Unit processors HTTP 402 + refund + Premium bypass | 2h | T-013-05, T-013-06 |
| T-013-12 | [TEST] | TEST WebTestCase /api/v1/quota + paywall 402 + resets_at | 1.5h | T-013-05, T-013-07 |
| T-013-13 | [DOC] | PHPDoc SubscriptionRepositoryInterface + QuotaService isPremium/refund | 0.5h | T-013-06 |
| T-013-14 | [REV] | Code review US-013 | 1h | T-013-13 |

**Sous-total US-013 : 14 tâches — 16.5h**

---

### 🔲 A faire — US-034b : Customer Portal + gestion cycle de vie

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-034b-01 | [BE] | SubscriptionService::createPortalSession (BillingPortal\Session) | 1.5h | T-034a-04, T-TECH-01 |
| T-034b-02 | [BE] | ProfileController::manageSubscription GET /profile/manage-subscription | 1h | T-034b-01 |
| T-034b-03 | [BE] | SubscriptionUpdatedHandler (customer.subscription.updated) | 1.5h | T-034a-04, T-034a-07 |
| T-034b-04 | [BE] | SubscriptionCancelledHandler (customer.subscription.deleted) | 1h | T-034a-04, T-034a-07 |
| T-034b-05 | [BE] | PaymentFailedHandler (invoice.payment_failed → status=past_due) | 1h | T-034a-04, T-034a-07 |
| T-034b-06 | [BE] | QuotaService::isPremium grace period past_due + findByUserId interface | 1h | T-034b-03, T-034b-04, T-034b-05, T-013-02 |
| T-034b-07 | [BE] | DoctrineSubscriptionRepository::updateByStripeSubscriptionId | 1h | T-034a-04 |
| T-034b-08 | [FE-WEB] | Template profile/edit.html.twig bouton Gérer mon abonnement + badge état | 1h | T-034b-02 |
| T-034b-09 | [TEST] | TEST Unit 3 handlers (cancelled/updated/payment_failed) idempotence | 2h | T-034b-03, T-034b-04, T-034b-05 |
| T-034b-10 | [TEST] | TEST Unit QuotaService::isPremium state machine (active/past_due/cancelled) | 1h | T-034b-06 |
| T-034b-11 | [TEST] | TEST WebTestCase manage-subscription + webhook handlers | 1.5h | T-034b-02, T-034b-03 |
| T-034b-12 | [DOC] | PHPDoc 3 handlers + ProfileController::manageSubscription | 0.5h | T-034b-05 |
| T-034b-13 | [REV] | Code review US-034b | 1h | T-034b-12 |

**Sous-total US-034b : 13 tâches — 14.5h**

---

### 🔲 A faire — US-035 : Réglages confidentialité RGPD

| ID | Type | Description courte | Heures | Dépend de |
|----|------|--------------------|--------|-----------|
| T-035-01 | [DB] | DoctrineUserPrivacySettingsEntity (PK=user_id, FK CASCADE, 3 BOOL) | 1h | — |
| T-035-02 | [DB] | Migration user_privacy_settings (PK, FK, DEFAULT TRUE) | 0.5h | T-035-01 |
| T-035-03 | [BE] | Domain UserPrivacySettings readonly + PrivacySettingsRepositoryInterface | 1h | — |
| T-035-04 | [BE] | DoctrinePrivacySettingsRepository (findByUserId défaut + upsert) | 1.5h | T-035-01, T-035-03 |
| T-035-05 | [BE] | PrivacySettingsService (get + update + exportAsJson RGPD Art.20) | 1.5h | T-035-04 |
| T-035-06 | [BE] | PrivacySettingsVoter EDIT (UUID, jamais email, log WARNING) | 1h | T-035-05 |
| T-035-07 | [BE] | PrivacySettingsController GET/PATCH/export + CSRF + redirect_to | 2.5h | T-035-05, T-035-06 |
| T-035-08 | [BE] | AnalyticsOptOutEventSubscriber (X-Robots-Tag + X-Analytics-Opt-Out) | 1.5h | T-035-05 |
| T-035-09 | [FE-WEB] | Template settings/privacy.html.twig (Stimulus toggle + debounce 800ms) | 2h | T-035-07 |
| T-035-10 | [TEST] | TEST Unit PrivacySettingsVoter (grant/deny/log UUID) | 1h | T-035-06 |
| T-035-11 | [TEST] | TEST Unit PrivacySettingsService (défaut/update/export) | 1h | T-035-05 |
| T-035-12 | [TEST] | TEST WebTestCase /settings/privacy (PATCH/403/302/export) | 2h | T-035-07, T-035-08, T-035-09 |
| T-035-13 | [DOC] | PHPDoc Voter + Controller + Subscriber (X-Robots-Tag, RGPD) | 0.5h | T-035-08 |
| T-035-14 | [REV] | Code review US-035 | 1h | T-035-13 |

**Sous-total US-035 : 14 tâches — 16.5h**

---

## Métriques du Sprint

### Vue d'ensemble

| Métrique | Valeur |
|----------|--------|
| Story points | 16 pts |
| Total US features | 4 US |
| Total tâches features | 56 |
| Total tâches transverses | 4 |
| **Total tâches sprint** | **60** |
| Total heures estimées features | 70.5h |
| Total heures transverses | 2.5h |
| **Total heures sprint** | **73h** |

### Répartition par type

| Type | Tâches features | Heures features |
|------|----------------|-----------------|
| [DB] | 6 | 5h |
| [BE] | 32 | 45.5h |
| [FE-WEB] | 8 | 10h |
| [TEST] | 7 | 12h |
| [DOC] | 4 | 2h |
| [REV] | 4 | 4h |
| [OPS] | 4 (transverses) | 2.5h |

### Répartition par US

| US | Tâches | Heures | % heures |
|----|--------|--------|----------|
| US-034a | 15 | 23h | 32% |
| US-013 | 14 | 16.5h | 23% |
| US-034b | 13 | 14.5h | 20% |
| US-035 | 14 | 16.5h | 23% |
| T-TECH | 4 | 2.5h | 3.5% |
| **Total** | **60** | **73h** | **100%** |

### Point de synchronisation J3

> **T-034a-04 (`DoctrineSubscriptionRepository`) est le point de synchronisation critique.**
> Si cette tâche est bloquée, US-013 et US-034b ne peuvent pas démarrer leurs tâches [BE] principales.
> Monitorer quotidiennement jusqu'à livraison (objectif : J3 du sprint).

---

## En cours / Review / Done

> Colonnes à remplir au fil du sprint — vides au démarrage

### 🔄 En cours
*(aucune tâche au démarrage du sprint)*

### 👀 Review
*(aucune tâche au démarrage du sprint)*

### ✅ Done
*(aucune tâche au démarrage du sprint)*

### 🚫 Bloqué
*(aucun impediment identifié au démarrage du sprint)*
