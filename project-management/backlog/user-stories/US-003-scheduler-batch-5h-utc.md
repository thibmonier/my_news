# US-003 : Planification automatique du Daily Brief — batch 5h UTC

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-003 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | sprint-001 (Walking Skeleton) |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** que le Daily Brief soit automatiquement régénéré chaque matin à 5h00 UTC sans intervention humaine,
**Afin de** trouver un brief à jour et frais lorsque je consulte l'application vers 6h locale, chaque jour de la semaine.

### Conversation

- Symfony Scheduler ou cron système externe ? Décision : Symfony Scheduler (RecurringMessage + CronExpressionTrigger "0 5 * * *") — pas de dépendance système externe, déployé dans le même container FrankenPHP.
- Comment éviter les double-exécutions (redémarrage container, multiple replicas) ? Décision : lock distribué Redis avec TTL 10 minutes (Symfony Lock component, store Redis).
- La commande manuelle de secours doit-elle être possible ? Décision : oui, `bin/console briefly:generate-daily-brief [--date=YYYY-MM-DD]` appelle le même service.
- Le Scheduler doit-il s'exécuter 7j/7 ? Décision : oui, l'actualité ne s'arrête pas le week-end.
- Faut-il un mécanisme de retry si le batch échoue à 5h00 UTC ? Décision : Symfony Messenger gère les retries (max 3, backoff exponentiel 5 min) ; une alerte est émise si les 3 tentatives échouent.
- Comment monitorer l'exécution du batch ? Décision : log structuré JSON avec `brief.batch_start`, `brief.batch_success`, `brief.batch_failed` + durée d'exécution en ms.

### Validation INVEST

- [x] **I**ndependent : configure le mécanisme de déclenchement, délègue la logique à US-002
- [x] **N**egotiable : fréquence (5h UTC), mécanisme de lock, stratégie de retry négociables
- [x] **V**aluable : sans ce mécanisme, US-001 et US-002 ne s'exécutent jamais automatiquement
- [x] **E**stimable : Symfony Scheduler est connu de l'équipe, Redis Lock aussi, estimé 3 pts
- [x] **S**ized : 3 pts, inférieur au seuil de 8 pts
- [x] **T**estable : tests unitaires du trigger, tests d'intégration avec horloge mockée

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Scheduler** | `BriefScheduleProvider` | Implémente `ScheduleProviderInterface`, retourne `RecurringMessage` avec `CronExpressionTrigger("0 5 * * *")` |
| **Message** | `GenerateDailyBriefMessage` | DTO sans données personnelles, transporte uniquement la date cible |
| **Messenger Transport** | Symfony Messenger + Doctrine transport | Persistance du message en queue pour consommation async |
| **Lock** | Symfony Lock (Redis store) | Clé `briefly.daily_brief_generation`, TTL 600s, non bloquant (TryLock) |
| **Command** | `GenerateDailyBriefCommand` | `bin/console briefly:generate-daily-brief` — dispatche le même message Messenger |
| **Handler** | `GenerateDailyBriefHandler` | Acquiert le lock → appelle `BriefSelectorService` → libère le lock → log JSON |
| **Infrastructure** | Docker / FrankenPHP | Worker Messenger démarré via `CMD messenger:consume async` dans le Dockerfile |
| **Observabilité** | Monolog | Canal `briefly.scheduler`, format JSON, niveau INFO/WARNING/ERROR |
| **Sécurité** | Isolation | Commande console protégée (accès SSH/container uniquement), aucune donnée personnelle dans les messages |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Exécution automatique à 5h00 UTC

```gherkin
Scenario: Déclenchement automatique du batch Daily Brief à 5h00 UTC
  GIVEN l'application est déployée avec FrankenPHP et le Scheduler activé
  AND un worker Symfony Messenger est en cours d'exécution (`messenger:consume async`)
  AND Redis est disponible pour le lock
  WHEN l'horloge système passe à 05:00:00 UTC (n'importe quel jour de la semaine)
  THEN GenerateDailyBriefMessage est dispatché dans la queue Messenger
  AND le message est consommé par GenerateDailyBriefHandler dans les 60 secondes
  AND BriefSelectorService::selectTopStories() est exécuté exactement une fois
  AND un log structuré JSON `{"event": "brief.batch_success", "duration_ms": <n>, "date": "YYYY-MM-DD"}` est enregistré
  AND la durée totale d'exécution est ≤ 120 secondes
```

### Scénario alternatif 1 — Exécution manuelle de secours via commande console

```gherkin
Scenario: Re-génération manuelle via commande Symfony
  GIVEN le batch automatique n'a pas pu s'exécuter (ex: redémarrage container à 5h00 UTC)
  WHEN un administrateur exécute `bin/console briefly:generate-daily-brief`
  THEN la commande dispatche GenerateDailyBriefMessage et attend sa consommation
  AND BriefSelectorService::selectTopStories() est exécuté pour la date du jour
  AND la commande retourne exit code 0 en cas de succès
  AND un log `{"event": "brief.manual_trigger", "operator": "console"}` est enregistré (sans identifiant utilisateur)
```

### Scénario alternatif 2 — Protection contre les double-exécutions (lock Redis)

```gherkin
Scenario: Tentative d'exécution simultanée (double trigger)
  GIVEN GenerateDailyBriefHandler est déjà en cours d'exécution et détient le lock Redis
  WHEN un second GenerateDailyBriefMessage est consommé (ex: retry prématuré)
  THEN le second handler détecte que le lock est déjà acquis (TryLock retourne false)
  AND le second message est ignoré sans erreur (log INFO "brief.lock_already_acquired: skipped")
  AND aucun DailyBrief dupliqué n'est créé
```

### Scénario d'erreur 1 — Redis indisponible au moment du déclenchement

```gherkin
Scenario: Lock Redis inaccessible (Redis en crash)
  GIVEN Redis est indisponible au moment où GenerateDailyBriefHandler tente d'acquérir le lock
  WHEN le handler tente TryLock
  THEN l'exception LockStorageException est capturée
  AND le handler continue l'exécution en mode dégradé (sans lock)
  AND un log WARNING `{"event": "brief.lock_unavailable", "action": "proceeding_without_lock"}` est enregistré
  AND BriefSelectorService::selectTopStories() est quand même exécuté (résilience)
```

### Scénario d'erreur 2 — Épuisement des 3 tentatives Messenger

```gherkin
Scenario: Échec persistant après 3 tentatives Messenger (max retries atteint)
  GIVEN BriefSelectorService::selectTopStories() lève une exception lors des 3 tentatives successives
  AND le backoff exponentiel Messenger (5 min, 10 min, 20 min) a été respecté
  WHEN la 3e tentative échoue
  THEN le message est déplacé dans la dead letter queue (`failed`)
  AND un BriefGenerationFailedEvent est dispatché
  AND un log ERROR `{"event": "brief.max_retries_exceeded", "date": "YYYY-MM-DD"}` est enregistré
  AND le DailyBrief de J-1 reste intact et consultable (pas de régression pour les visiteurs)
```
