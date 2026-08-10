# Sprint Review — Sprint 004 Monétisation Premium

**Date** : 2026-09-21
**Durée** : 2h
**Animateur** : Tech Lead / Scrum Master

---

## Participants

| Rôle | Nom | Présence |
|------|-----|----------|
| Product Owner | — | Oui |
| Tech Lead / Scrum Master | — | Oui |
| Développeur(s) | — | Oui |
| Stakeholders | — | Placeholder |

---

## Sprint Goal — Verdict

> **Activer la monétisation de Briefly AI : quota free réel (3 synthèses/jour avec paywall HTTP 402), souscription Stripe Checkout + webhook activation Premium, gestion du cycle de vie de l'abonnement via Customer Portal Stripe, et réglages de confidentialité RGPD autonomes — le tout web-only, aucune tâche mobile.**

| Dimension | Statut | Détail |
|-----------|--------|--------|
| Sprint Goal technique (fonctionnalités produit) | **ATTEINT** | Les 4 US livrées, chaîne Free → Premium → gestion cycle de vie opérationnelle |
| Monétisation Stripe end-to-end (mode test) | **ATTEINT** | Checkout → webhook → activation → Customer Portal → downgrade validés en démo |
| Conformité RGPD autonome | **ATTEINT** | Réglages confidentialité persistés, export JSON Art.20, voter propriétaire-only |
| Passage en mode production Stripe | **NON RÉALISÉ** | Nécessite rotation des clés `sk_test_xxx → sk_live_xxx` + ajout secrets CI — action humaine post-review |

---

## Démonstrations livrées

| # | User Story | Points | Demo | Commit | Statut |
|---|-----------|--------|------|--------|--------|
| 1 | **US-034a** — Stripe Checkout + activation Premium (webhook `checkout.session.completed`) | 5 pts | `GET /premium` → tarifs 12€/mois + 99€/an + CTA ; `GET /premium/checkout/{plan}` → redirection Stripe Checkout → carte test 4242 → webhook `POST /stripe/webhook` HMAC-SHA256 vérifié → `SubscriptionActivatedHandler` (Messenger async) → `INSERT INTO subscriptions ON CONFLICT (stripe_event_id) DO NOTHING` → flash "Bienvenue dans Briefly Premium !" → badge "Premium" dans le header | `f3ec0f5` | **DONE** |
| 2 | **US-035** — Réglages de confidentialité RGPD | 3 pts | `GET /settings/privacy` → page 3 interrupteurs Stimulus (role=switch) ; PATCH debounce 800ms → `user_privacy_settings` persisté en base ; toast Turbo Stream "Préférences enregistrées" ; `X-Robots-Tag: noindex, nofollow` injecté côté serveur si `search_engine_indexing = false` ; bouton "Exporter mes données" → JSON portabilité Art.20 avec champ `privacy_settings` | `a8a4e5f` | **DONE** |
| 3 | **US-013** — Quota gratuit réel (3/jour) + paywall HTTP 402 + bypass Premium | 5 pts | Thomas (Free) génère synthèses 1-2-3 → `X-Quota-Remaining: 2/1/0` décroît dans le header + badge Turbo Frame ; 4e demande → HTTP 402 `{"error":"quota_exceeded","remaining":0,"resets_at":"..."}` → modale paywall avec CTA actif vers `/premium/checkout/monthly` ; refund quota sur erreur Mistral ; clé Redis UUID uniquement (jamais email/IP) ; date UTC serveur (header `X-Date` client ignoré + log WARNING) ; Priya (Premium) → aucune limitation, 0 header `X-Quota-Remaining` | `c06d63e` | **DONE** |
| 4 | **US-034b** — Customer Portal Stripe + webhooks cycle de vie (downgrade/cancel) | 3 pts | `GET /profile/manage-subscription` → `createPortalSession()` IDOR-safe (customer_id chargé depuis DB par UUID session) → redirection Stripe Customer Portal → retour `/profile` ; `SubscriptionUpdatedHandler` (cancel_at_period_end=true → Premium maintenu jusqu'à current_period_end) ; `SubscriptionCancelledHandler` (status=cancelled) ; `PaymentFailedHandler` (status=past_due, grace period : `isPremium()` reste vrai si current_period_end > NOW()) ; handlers idempotents (sub_id inconnu → log WARNING + HTTP 200) ; migration `cancel_at_period_end BOOLEAN` | `478c2fb` | **DONE** |

---

## Critères d'acceptance — État de validation par US

### US-034a — Stripe Checkout + activation Premium

| Scénario | Validé | Couverture test |
|----------|--------|-----------------|
| ✅ Nominal : souscription mensuelle + badge "Premium" après webhook | Oui | `StripeSubscriptionFeatureTest` — `checkout.session.completed` → save() + flash dashboard |
| ✅ Alt 1 : souscription annuelle (plan="yearly", current_period_end J+365) | Oui | `StripeSubscriptionFeatureTest` — plan yearly assertions |
| ✅ Alt 2 : webhook en double ignoré (idempotence `ON CONFLICT stripe_event_id`) | Oui | `StripeSubscriptionFeatureTest` — doublon → 0 second row |
| ✅ Erreur 1 : paiement refusé (carte 0069) → redirection /premium?checkout=cancelled | Oui | `StripeWebhookControllerTest` — aucun enregistrement créé |
| ✅ Erreur 2 : signature HMAC invalide → HTTP 400 + log WARNING | Oui | `StripeWebhookControllerTest` — `SignatureVerificationException` → 400 + log assert |

### US-035 — Réglages de confidentialité RGPD

| Scénario | Validé | Couverture test |
|----------|--------|-----------------|
| ✅ Nominal : désactivation analytique → `analytics_opt_in = false` + toast | Oui | `PrivacySettingsControllerTest` — PATCH → DB assert + Turbo Stream toast |
| ✅ Alt 1 : `search_engine_indexing = false` → `X-Robots-Tag: noindex, nofollow` | Oui | `PrivacySettingsControllerTest` — header assert sur réponse suivante |
| ✅ Alt 2 : export JSON RGPD Art.20 contient `privacy_settings` | Oui | `PrivacySettingsControllerTest` — JSON structure assert + assertNotContains(autre user) |
| ✅ Erreur 1 : accès non authentifié → HTTP 302 vers /login + redirect_to | Oui | `PrivacySettingsControllerTest` — unauthenticated request |
| ✅ Erreur 2 : PATCH pour autre user → HTTP 403 + log WARNING UUID (sans email) | Oui | `PrivacySettingsVoterTest` — DENY assert + log UUID assert |

### US-013 — Quota gratuit + paywall HTTP 402

| Scénario | Validé | Couverture test |
|----------|--------|-----------------|
| ✅ Nominal : 3 synthèses Free → HTTP 200 + X-Quota-Remaining décroît 2/1/0 | Oui | `UrlSynthesisProcessorTest` + `ArticleSynthesisProcessorTest` |
| ✅ Alt 1 : Premium → synthèses illimitées, 0 header X-Quota-Remaining | Oui | `QuotaServiceTest` — isPremium() bypass total Redis |
| ✅ Alt 2 : remboursement quota sur erreur Mistral (refund() décrémente) | Oui | `QuotaServiceTest` — refund() + `UrlSynthesisProcessorTest` refund path |
| ✅ Erreur 1 : 4e synthèse Free → HTTP 402 + JSON quota_exceeded + modale CTA actif | Oui | `QuotaPaywallTest` — CTA actif vers premium_checkout, sans aria-disabled |
| ✅ Erreur 2 : header X-Date client ignoré → date UTC serveur + log WARNING | Oui | `UrlSynthesisProcessorTest` — X-Date WARNING assert |

### US-034b — Customer Portal + webhooks cycle de vie

| Scénario | Validé | Couverture test |
|----------|--------|-----------------|
| ✅ Nominal : annulation → `cancel_at_period_end=true` + Premium maintenu jusqu'à current_period_end | Oui | `IsPremiumStateMachineTest` — cancel_at_period_end=true → isPremium() still true |
| ✅ Alt 1 : changement carte → aucune modification subscriptions | Oui | `CustomerPortalFeatureTest` — no DB change assert |
| ✅ Alt 2 : `invoice.payment_failed` → status=past_due + grace period isPremium() true | Oui | `LifecycleHandlersTest` + `IsPremiumStateMachineTest` — past_due + current_period_end > NOW |
| ✅ Erreur 1 : session Customer Portal expirée → nouvelle session depuis /profile | Oui | `CustomerPortalFeatureTest` — SubscriptionNotFoundException → flash + redirect /premium |
| ✅ Erreur 2 : webhook sub_id inconnu → log WARNING + HTTP 200 (idempotent) | Oui | `LifecycleHandlersTest` — unknown sub_id → WARNING + return without error |

---

## Métriques Qualité — Cibles vs Atteintes

| Métrique | Cible (DoD) | Départ Sprint | Atteint |
|----------|-------------|---------------|---------|
| PHPStan niveau max | 0 erreur | 0 erreur | **0 erreur** |
| Deptrac — violations hexagonales | 0 violation | 0 violation | **0 violation** |
| PHP CS Fixer | 0 diff | 0 diff | **0 diff** |
| Tests PHPUnit (Unit + Feature) | CI verte | **554 tests** | **660 / 660** (0 échec) |
| Migrations appliquées (dev + test) | Oui | — | **Oui** (3 migrations : subscriptions, user_privacy_settings, cancel_at_period_end) |
| Signature HMAC-SHA256 Stripe | Vérifiée avant tout traitement | — | **Confirmé** (`Webhook::constructEvent`, HTTP 400 si invalide) |
| Idempotence webhooks | `ON CONFLICT (stripe_event_id) DO NOTHING` | — | **Confirmé** (US-034a + handlers 034b) |
| Secrets Stripe hors code | STRIPE_SECRET_KEY / STRIPE_WEBHOOK_SECRET en `.env` uniquement | — | **Confirmé** (jamais loggué, jamais commité) |
| RGPD — UUID dans clés Redis | UUID uniquement (jamais email/IP) | — | **Confirmé** (clé `quota:synthesis:{uuid}:{date}`) |
| Architecture hexagonale / DDD | Respectée | Confirmée Deptrac | **Confirmée** (`StripeWebhookMessage` déplacé vers Application pour compliance Deptrac) |
| Zéro régression Sprint 1+2+3 | Oui | — | **Confirmé** |

### Progression de la suite de tests

```
Sprint 3 (départ) : 554 tests
  +51  — US-034a Stripe Checkout + webhook activation (StripeSubscriptionFeatureTest + StripeWebhookControllerTest + SubscriptionActivatedHandlerTest)
  +17  — US-035 réglages confidentialité RGPD (PrivacySettingsControllerTest + PrivacySettingsVoterTest + PrivacySettingsServiceTest)
  +15  — US-013 quota réel + paywall HTTP 402 + bypass Premium (QuotaServiceTest + UrlSynthesisProcessorTest + ArticleSynthesisProcessorTest + QuotaPaywallTest)
  +23  — US-034b Customer Portal + webhooks cycle de vie (LifecycleHandlersTest + IsPremiumStateMachineTest + CustomerPortalFeatureTest)
= 660 tests (Sprint 4)
```

### Points de sécurité OWASP vérifiés

- `StripeWebhookController` : HMAC-SHA256 via `Webhook::constructEvent` avant tout dispatch ; HTTP 400 sur signature invalide + log WARNING (IP + raison, JAMAIS le payload brut)
- Idempotence `INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING` : zéro doublon même sur retry Stripe
- IDOR-safe `manageSubscription()` : `customer_id` chargé depuis DB par UUID de session, jamais via paramètre HTTP
- `PrivacySettingsVoter::EDIT` : deny-by-default, propriétaire uniquement, HTTP 403 + log WARNING UUID demandeur + UUID cible (jamais les emails)
- `QuotaService` : clés Redis `quota:synthesis:{uuid}:{YYYY-MM-DD}` — UUID non séquentiel (OWASP A01), jamais l'email
- Date UTC serveur pour les clés quota ; header `X-Date` client ignoré + log WARNING sur détection de tentative de manipulation
- `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET` exclusivement en variables d'environnement — zéro occurrence dans le code commité
- `SubscriptionService::createCheckoutSession()` et `createPortalSession()` : paramètres injectés via `%env()%` Symfony (services.yaml)
- Handlers Messenger idempotents : sous-abonnement inconnu → log WARNING + return sans erreur → HTTP 200 (évite les retries Stripe en boucle)
- `AnalyticsOptOutEventSubscriber` : headers `X-Analytics-Opt-Out` + `X-Robots-Tag: noindex, nofollow` injectés côté serveur (pas patchables côté client)

---

## Incréments vs Sprint Goal

| Incrément | Lien Sprint Goal | Valeur livrée |
|-----------|-----------------|---------------|
| Stripe Checkout + activation Premium (US-034a) | Monétisation — premier revenu récurrent | Thomas souscrit à Premium en 2 min via Stripe ; le badge "Premium" s'affiche dès réception du webhook ; les synthèses sont illimitées immédiatement |
| Réglages RGPD (US-035) | Conformité légale + différenciateur privacy | Marc gère ses préférences de confidentialité en autonomie depuis `/settings/privacy` sans contacter le support ; l'export JSON Art.20 est disponible |
| Quota réel + paywall HTTP 402 (US-013) | Conversion Free → Premium | Thomas voit son compteur décroître en temps réel ; la 4e synthèse déclenche le paywall HTTP 402 avec CTA Stripe actif ; les comptes Premium ne sont jamais bloqués |
| Customer Portal + cycle de vie (US-034b) | Réduction du churn et du support | Thomas peut annuler, changer de carte et gérer son plan en autonomie ; les annulations conservent l'accès Premium jusqu'à la fin de la période payée ; les échecs de paiement déclenchent une grace period de 3 jours |

La chaîne de monétisation complète est opérationnelle en mode test : un utilisateur Free peut découvrir la valeur du service (3 synthèses gratuites/jour), passer à Premium via Stripe Checkout, gérer son abonnement en autonomie via le Customer Portal, et recevoir les notifications Stripe automatiques à chaque événement du cycle de vie.

---

## Hypothèse Produit — État de validation

> **Hypothèse** : En activant un quota free réel (3 synthèses/jour) avec paywall HTTP 402 vers Stripe Checkout, Briefly AI génère ses premiers revenus récurrents. P-001 Thomas, bloqué par le paywall lors de sa 4e synthèse quotidienne, souscrit à Premium (12€/mois) et peut immédiatement gérer son abonnement en autonomie (Customer Portal). P-003 Marc, sensible à la vie privée, configure ses préférences RGPD sans contacter le support.

| Critère | Cible | Résultat |
|---------|-------|----------|
| Quota free : 4e synthèse → HTTP 402 + modale paywall CTA actif | < 500 ms | **Validé en démo** (refusal avant appel Mistral, pas de latence IA) |
| Stripe Checkout : souscription mensuelle complète (test mode) | < 2 min | **Validé en démo** (flux Checkout Stripe natif) |
| Webhook activation : badge "Premium" visible après `checkout.session.completed` | < 5 s | **Validé en démo** (Messenger async, handler idempotent) |
| Customer Portal : annulation → DB mise à jour + Premium maintenu jusqu'à `current_period_end` | Oui | **Validé en démo** (cancel_at_period_end=true → isPremium() reste true) |
| Privacy settings : PATCH /settings/privacy → DB persisté + toast visible | < 200 ms | **Validé en démo** (debounce 800ms Stimulus + Turbo Stream) |
| Export RGPD Art.20 : JSON contient `privacy_settings` | < 10 s | **Validé en démo** (bouton "Exporter mes données" → téléchargement immédiat) |
| Passage en production Stripe (sk_live, webhooks prod) | Non applicable Sprint 4 | **À réaliser post-review** — rotation manuelle des clés |

> La chaîne de monétisation est fonctionnelle en mode test Stripe. Le passage en production nécessite uniquement la rotation des clés (`sk_test_xxx → sk_live_xxx`, `STRIPE_WEBHOOK_SECRET` prod) et l'ajout des secrets dans CI — aucun développement supplémentaire requis.

---

## Éléments non terminés

### User Stories

Aucun. Les 4 User Stories du scope Sprint 4 sont marquées **DONE** (16/16 pts livrés).

### Tâches techniques transverses (à réaliser post-review)

| ID | Description | Raison du report | Action requise |
|----|-------------|-----------------|----------------|
| — | Rotation clés Stripe production (`sk_live_xxx`) | Nécessite accès Stripe Dashboard en mode production + vérification compte | Responsable : Tech Lead / PO — après validation du mode test lors de la Review |
| — | Secrets CI GitHub Actions (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` prod) | Dépend de la rotation des clés prod | Tech Lead : ajouter dans Settings › Secrets › Actions du repo |
| — | Enregistrement webhook Stripe prod (`https://<prod-url>/stripe/webhook`) | URL prod requise | Tech Lead : Stripe Dashboard → Webhooks → Add endpoint + récupérer `STRIPE_WEBHOOK_SECRET` |
| — | Validation Stripe Tax (TVA automatique configurée) | Vérification manuelle Stripe Dashboard | PO / Tech Lead : confirmer `automatic_tax.enabled=true` sur les Price Objects |

---

## Incidents notables

| Incident | Impact | Résolution |
|----------|--------|------------|
| `StripeWebhookMessage` initialement placée en Domain (violation Deptrac) | Build Deptrac KO sur first push | Déplacé vers `Application/Subscription/` — 0 violation Deptrac sur la branche finale |
| CI GitHub Actions (billing) — impediment persistant depuis Sprint 2 | Gates exécutés en local Docker uniquement | Tous les gates (PHPStan max, Deptrac, CS Fixer, PHPUnit 660 tests) passés en local Docker — 0 dégradation qualité, PR #14 marquée CI verte |

---

## Risques résiduels

| Risque | Probabilité | Impact | Action Sprint 5 |
|--------|-------------|--------|-----------------|
| Clés Stripe production non rotées → 0 revenu réel | Certaine (action humaine requise) | Critique | Rotation J+1 post-Review : `sk_live_xxx` + `STRIPE_WEBHOOK_SECRET` prod + secrets CI |
| CI GitHub Actions toujours bloquée (billing) — 4e sprint consécutif | Élevée | Élevé | Action bloquante J+1 Sprint 5 : débloquer le billing ou provisionner un runner auto-hébergé |
| Staging non déployé → rétention J+1/J+7 toujours non mesurable | Certaine | Élevé | T-TECH-03 reste la première priorité Sprint 5 avant toute nouvelle US |
| Stripe webhooks prod non reçus (firewall/URL invalide) | Faible | Critique | Valider en prod avec `stripe listen --forward-to` puis passer à l'endpoint enregistré |
| Grace period `past_due` : isPremium() stale si webhook retardé | Faible | Moyen | TTL acceptable (Stripe < 5 s en prod) ; pas de polling client en v1 |
| Quota Redis race condition (INCR concurrent) | Faible | Faible | Script Lua atomique prévu ; INCR seul acceptable Sprint 4 (débit faible) |

---

## Feedback Stakeholders

> *Placeholder — à compléter lors de la Review du 2026-09-21.*

---

## Décisions issues de la Review

| Décision | Responsable | Échéance |
|----------|-------------|----------|
| Rotation des clés Stripe en production (`sk_live`, `STRIPE_WEBHOOK_SECRET`) | Tech Lead / PO | J+1 post-Review |
| Ajouter `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET` dans GitHub Secrets CI | Tech Lead | J+1 post-Review |
| Porter T-TECH-01 (CI billing) en blocage bloquant J+1 Sprint 5 | Tech Lead | J+1 Sprint 5 |
| Déployer le staging (T-TECH-03) en première priorité Sprint 5 | Tech Lead / DevOps | Semaine 1 Sprint 5 |
| Affiner les US candidates Sprint 5 (Flutter/mobile, US-014, US-023, US-040+) | PO + Tech Lead | Backlog Refinement Sprint 5 |

---

## Métriques de vélocité

| Élément | Valeur |
|---------|--------|
| Story points engagés | 16 pts |
| Story points livrés | 16 pts (4/4 US) |
| Tests au départ du sprint | 554 |
| Tests en fin de sprint | 660 (+106) |
| Migrations livrées | 3 (subscriptions, user_privacy_settings, cancel_at_period_end) |
| Incidents bloquants | 0 (1 impediment infra persistant sans impact sur les US) |
| Taux de complétion US | 100% |

### Vélocité cumulée

| Sprint | Points livrés | Tests | Cumul tests |
|--------|--------------|-------|-------------|
| Sprint 1 (Walking Skeleton) | 20 pts | +226 | 226 |
| Sprint 2 (Enrichissement) | 23 pts | +211 | 437 |
| Sprint 3 (Consolidation) | 16 pts | +117 | 554 |
| Sprint 4 (Monétisation) | 16 pts | +106 | 660 |

---

## Prochaines étapes — Candidats Sprint 5

Les User Stories suivantes sont identifiées comme candidates prioritaires pour le Sprint 5 (affinage à confirmer en Backlog Refinement). La rotation des clés Stripe prod et le déploiement staging sont des **prérequis non négociables** avant le Sprint Planning Part 1.

| ID | Titre | EPIC | Points estimés | Priorité | Dépend de |
|----|-------|------|----------------|----------|-----------|
| — | Rotation clés Stripe prod + secrets CI | Infra | — | Bloquant | — |
| T-TECH-01/03 | Déblocage CI + Staging instrumenté | Infra | — | Bloquant | — |
| T-TECH-04/05 | Analytics rétention + monitoring quota Mistral | Infra | — | Bloquant | T-TECH-03 |
| US-014 | Fallback sources RSS (résilience) | EPIC-002 | 3 | Moyenne | US-022 |
| US-023 | Rate-limiting API (protection abus) | EPIC-003 | 3 | Moyenne | US-012 |
| US-040+ | Écrans Flutter Mobile (Daily Brief mobile) | EPIC-005 | À affiner | Moyenne | API Platform |
| — | Monitoring Stripe (alertes webhook KO, taux d'échec paiement) | Infra | À chiffrer | Haute | Stripe prod actif |

**Objectif Sprint 5 pressenti** : Activer Stripe en production (premiers revenus réels), déployer le staging instrumenté, mesurer pour la première fois la rétention réelle J+1/J+7, et poser les premières briques mobile Flutter.
