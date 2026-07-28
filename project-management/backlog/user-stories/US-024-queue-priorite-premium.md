# US-024 : File de priorité Messenger (premium avant gratuit)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-024 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | P-002 Priya — chercheuse en stratégie, 31 ans (plan premium) |
| **Story Points** | 3 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant que** P-002 Priya, abonnée premium à Briefly AI,
**Je veux** que les sources de ma veille soient ingérées en priorité par rapport aux utilisateurs du plan gratuit
**Afin de** bénéficier d'un flux d'actualités systématiquement plus frais et d'accéder aux derniers articles avant les utilisateurs non-payants, justifiant ainsi la valeur de mon abonnement.

### Conversation

- Comment Messenger distingue-t-il une source premium d'une source gratuite ? Décision : deux transports Messenger distincts — `async_premium` (priorité haute) et `async_free` (priorité normale). Le handler `FetchSourceHandler` reste identique pour les deux ; seul le message dispatch change selon `source.plan_tier`.
- La priorité est-elle portée par la source ou par l'utilisateur ? Décision : par l'utilisateur propriétaire de la source. Une source "partagée" (globale, sans owner) est publiée dans `async_free`. Une source ajoutée par un admin pour un utilisateur premium est publiée dans `async_premium`.
- Que se passe-t-il lors du downgrade d'un abonnement (non-paiement) ? Décision : le webhook Stripe `customer.subscription.deleted` met à jour `user.plan_tier='free'` ; les prochains dispatches de sources de cet utilisateur basculent vers `async_free`. Le changement est effectif dans les 5 minutes suivant la réception du webhook.
- Y a-t-il un SLA documenté pour les sources premium ? Décision : SLA cible < 5 min entre le déclenchement Scheduler et la fin d'ingestion pour les sources premium (mesuré via les timestamps `scheduled_at` et `last_fetched_at`).
- Dépendance sur EPIC-005 (Billing) : comment connaître le plan d'un utilisateur au moment du dispatch ? Décision : le `Source` entity a un champ `plan_tier ENUM(free/premium) DEFAULT free` mis à jour lors de l'upgrade/downgrade Stripe. Le dispatcher lit ce champ.

### Validation INVEST

- [x] **I**ndependent : dépend de US-023 (infrastructure Messenger opérationnelle) et de EPIC-004 (plan user — Comptes Utilisateurs & Premium) ; séparable techniquement via feature flag
- [x] **N**egotiable : SLA 5 min, nombre de workers premium dédiés, stratégie de bascule en cas de worker down
- [x] **V**aluable : différenciateur clé du plan premium, renforce la proposition de valeur de l'abonnement payant pour Priya
- [x] **E**stimable : configuration Messenger (2 transports) + champ plan_tier Source + dispatcher conditionnel, estimé 3 pts
- [x] **S**ized : 3 pts < 8 pts
- [x] **T**estable : tests Messenger avec transports in-memory distincts, test de SLA via timestamps en base de test

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `Source` entity enrichie | Ajout champ `plan_tier ENUM('free','premium') NOT NULL DEFAULT 'free'` |
| **Migration** | Doctrine Migration | Ajout colonne `plan_tier` sur la table `sources` |
| **Messenger Config** | `messenger.yaml` | Déclaration de deux transports : `async_premium` (Redis stream `ingestion_premium`) et `async_free` (Redis stream `ingestion_free`) |
| **Dispatcher** | `SourceDispatcherService` | `dispatch(Source $source)` : si `source.plan_tier === 'premium'` → bus `async_premium`, sinon → `async_free` |
| **Messenger Workers** | Supervisord / Docker | Worker dédié `async_premium` (1-2 workers) ; worker partagé `async_free` (N workers). Priorité via séquence de consommation : le worker premium drains sa queue avant de prendre des messages free |
| **EPIC-004 Integration** | `BillingWebhookHandler` | Webhook Stripe `customer.subscription.deleted` → `source.plan_tier='free'` pour toutes les sources de l'utilisateur |
| **EPIC-004 Integration** | `UpgradeSubscriptionHandler` | Webhook Stripe `customer.subscription.created/updated` (plan=premium) → `source.plan_tier='premium'` |
| **Monitoring** | Métriques Redis | Queue depth `ingestion_premium` et `ingestion_free` exposées via Prometheus/Grafana pour alerting (alerte si premium > 100 messages) |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Messages premium consommés avant les messages gratuits

```gherkin
Scenario: Le worker premium drains sa queue avant les messages gratuits
  GIVEN la queue async_premium contient 5 messages FetchSourceMessage (sources Priya, premium)
  AND la queue async_free contient 50 messages FetchSourceMessage (sources plan gratuit)
  WHEN les workers Messenger démarrent (1 worker async_premium, 2 workers async_free)
  THEN le worker async_premium consomme les 5 messages premium en premier
  AND les workers async_free ne traitent les 50 messages qu'après que le worker premium ait terminé ses 5 messages
  AND le SLA pour les sources premium est < 5 min entre scheduled_at et last_fetched_at (mesuré sur les 5 sources)
```

### Scénario alternatif 1 — Upgrade d'un utilisateur vers le plan premium

```gherkin
Scenario: Les sources d'un utilisateur passent en premium après upgrade Stripe
  GIVEN l'utilisateur Priya a 3 sources configurées avec plan_tier="free"
  WHEN le webhook Stripe "customer.subscription.created" (plan=premium) est reçu par BillingWebhookHandler
  THEN les 3 sources de Priya sont mises à jour en base avec plan_tier="premium"
  AND le prochain dispatch de ces 3 sources publie des messages dans async_premium
  AND le délai entre réception du webhook et mise à jour effective est < 5 minutes
  AND un log INFO est enregistré : user_id (UUID, pas d'email), sources_upgraded=3
```

### Scénario alternatif 2 — Queue premium vide, workers traitent async_free sans interruption

```gherkin
Scenario: Le worker premium se rabat sur async_free si sa queue est vide
  GIVEN la queue async_premium est vide
  AND la queue async_free contient 30 messages
  WHEN le worker async_premium vérifie sa queue et la trouve vide
  THEN le worker peut être configuré pour consommer aussi async_free (configuration Messenger `failure_transport` ou `prioritized`)
  AND les 30 messages async_free sont traités normalement sans timeout de worker
  AND aucune alerte n'est déclenchée (queue vide est une situation normale)
```

### Scénario erreur 1 — Worker async_premium down (accumulation non traitée)

```gherkin
Scenario: L'accumulation de messages premium déclenche une alerte si le worker est arrêté
  GIVEN le worker async_premium est stoppé (crash process)
  WHEN des messages premium s'accumulent dans async_premium et dépassent 100 messages en attente
  THEN une alerte Prometheus/Grafana est déclenchée : metric="ingestion_premium_queue_depth" > 100
  AND les messages premium restent dans la queue Redis (pas de perte, TTL > 24h)
  AND les messages async_free continuent d'être traités par les workers free (pas de cascade failure)
  AND aucun message premium n'est consommé par les workers async_free (isolation stricte des queues)
```

### Scénario erreur 2 — Downgrade après non-paiement (expiration abonnement Stripe)

```gherkin
Scenario: Le downgrade Stripe bascule les sources de premium vers gratuit
  GIVEN Priya a 3 sources avec plan_tier="premium"
  WHEN le webhook Stripe "customer.subscription.deleted" est reçu (abonnement expiré)
  THEN les 3 sources de Priya sont mises à jour en base avec plan_tier="free"
  AND les prochains dispatches de ces sources publient dans async_free
  AND le changement est effectif dans les 5 minutes suivant la réception du webhook
  AND un log INFO est enregistré : user_id (UUID uniquement, RGPD), sources_downgraded=3, reason="subscription_deleted"
  AND les articles déjà ingérés et indexés restent accessibles (aucune suppression de données)
```
