# US-002 : Sélection algorithmique des 3 histoires majeures du Daily Brief

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-002 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | sprint-001 (Walking Skeleton) |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** que le système sélectionne automatiquement les 3 histoires majeures du jour parmi les articles ingérés, en garantissant diversité thématique et fraîcheur,
**Afin de** recevoir un brief pertinent et non redondant sans aucun effort de curation manuelle de ma part.

### Conversation

- Qu'est-ce qu'une "histoire majeure" au sens algorithmique ? Décision v1 : score composite = fraîcheur (0–40 pts, décroissant sur 24h) + diversité thématique (cluster distinct = +30 pts, même cluster = 0) + signal source (source premium = +20 pts, autres = +10 pts) + engagement proxy (longueur article > 800 mots = +10 pts).
- Le clustering est-il synchrone dans la sélection ou pré-calculé à l'ingestion ? Décision : pré-calculé par EPIC-002 (tag `cluster_id` sur chaque article), la sélection lit ce tag.
- Que faire si moins de 3 clusters thématiques sont disponibles dans les dernières 24h ? Décision : sélectionner les meilleurs articles disponibles (2 si 2 clusters, 1 si 1 seul), émettre un log WARNING, ne jamais bloquer l'affichage.
- La sélection est-elle idempotente en cas de re-run dans la même journée ? Décision : oui — `UPDATE ... WHERE date = TODAY`, pas de doublon `INSERT`.
- Faut-il persister les scores pour analytics futures ? Décision : oui, colonne `selection_score FLOAT` sur `brief_stories`.
- Les articles derrière paywall sont-ils inclus ? Décision : non pour v1 (filtre `is_full_text_accessible = true`).

### Validation INVEST

- [x] **I**ndependent : dépend de EPIC-003 (articles ingérés en base par le pipeline RSS) mais pas d'autres US de EPIC-001 hors US-003
- [x] **N**egotiable : algorithme de scoring (v1 : règles, v2 : ML) à affiner en Sprint Review
- [x] **V**aluable : sans cette US, US-001 n'a rien à afficher — valeur métier directe
- [x] **E**stimable : score composite simple, Doctrine query connue, estimé 5 pts
- [x] **S**ized : 5 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères mesurables (3 histoires distinctes par cluster, score persisté, idempotence)

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain Service** | `BriefSelectorService` | Logique de sélection pure (algorithme de scoring, isolation du domaine) |
| **Repository** | `ArticleRepository::findCandidatesForBrief()` | Query PostgreSQL : articles des 24h, `is_full_text_accessible = true`, triés par cluster_id + score |
| **Repository** | `DailyBriefRepository::upsertForToday()` | INSERT ... ON CONFLICT (date) DO UPDATE (idempotence) |
| **Entités Doctrine** | `DailyBrief`, `BriefStory` | DailyBrief(id UUID, date DATE, status ENUM, updated_at) / BriefStory(id UUID, brief_id FK, article_id FK, position SMALLINT 1-3, selection_score FLOAT) |
| **Messenger Handler** | `GenerateDailyBriefHandler` | Reçoit `GenerateDailyBriefMessage`, appelle `BriefSelectorService`, persiste via repositories |
| **Event** | `BriefGenerationFailedEvent` | Dispatché si sélection impossible (0 articles disponibles) |
| **Base de données** | PostgreSQL | Tables : `daily_briefs`, `brief_stories`, `articles` (lecture + écriture) |
| **Sécurité OWASP** | Isolation | Aucune donnée personnelle dans les queries de sélection ; UUID non séquentiels pour les IDs |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Sélection réussie de 3 histoires distinctes

```gherkin
Scenario: Sélection de 3 histoires issues de clusters thématiques distincts
  GIVEN au moins 15 articles sont disponibles en base avec is_full_text_accessible = true
  AND ces articles couvrent au moins 3 cluster_id distincts (ex: "tech", "geopolitics", "economy")
  AND tous ont été publiés dans les 24 dernières heures
  WHEN BriefSelectorService::selectTopStories() est appelé pour la date du jour
  THEN exactement 3 BriefStories sont créées (positions 1, 2, 3)
  AND chaque BriefStory référence un article appartenant à un cluster_id différent des deux autres
  AND un enregistrement DailyBrief avec status = "ready" et updated_at = NOW() est persisté (ou mis à jour si déjà existant)
  AND le champ selection_score de chaque BriefStory est renseigné (valeur > 0)
```

### Scénario alternatif 1 — Seulement 2 clusters thématiques disponibles

```gherkin
Scenario: Brief incomplet (2 clusters seulement disponibles)
  GIVEN seulement 8 articles disponibles en base pour les dernières 24h
  AND ces articles couvrent uniquement 2 cluster_id distincts
  WHEN BriefSelectorService::selectTopStories() est appelé
  THEN 2 BriefStories sont créées (positions 1 et 2)
  AND le DailyBrief est persisté avec status = "ready" (pas "error") pour ne pas bloquer l'affichage
  AND un log WARNING est émis avec le message "brief.incomplete: 2/3 stories available" (sans données personnelles)
```

### Scénario alternatif 2 — Re-sélection idempotente (batch re-run dans la journée)

```gherkin
Scenario: Re-exécution du service le même jour (idempotence)
  GIVEN un DailyBrief existe déjà en base pour la date du jour avec 3 BriefStories
  AND de nouveaux articles plus pertinents ont été ingérés depuis la dernière sélection
  WHEN BriefSelectorService::selectTopStories() est ré-exécuté pour la même date
  THEN les 3 BriefStories existantes sont MISES À JOUR (pas dupliquées)
  AND le champ DailyBrief.updated_at reflète l'heure de recalcul
  AND aucun doublon n'existe dans la table brief_stories pour cette date
```

### Scénario d'erreur 1 — Aucun article disponible en base

```gherkin
Scenario: Sélection impossible (aucun article ingéré dans les 24h)
  GIVEN la table articles contient 0 entrées avec is_full_text_accessible = true et published_at > NOW() - INTERVAL '24h'
  WHEN BriefSelectorService::selectTopStories() est appelé
  THEN aucun DailyBrief n'est créé ou modifié pour la date du jour
  AND un BriefGenerationFailedEvent est dispatché via Symfony EventDispatcher
  AND un log ERROR "brief.generation_failed: no_articles_available" est enregistré (sans données personnelles)
  AND le DailyBrief de J-1 (si existant) reste intact et consultable via US-001
```

### Scénario d'erreur 2 — Timeout PostgreSQL pendant le calcul du scoring

```gherkin
Scenario: Timeout base de données pendant la sélection
  GIVEN la query de scoring dépasse le timeout configuré (30s)
  WHEN BriefSelectorService::selectTopStories() est appelé
  THEN une QueryTimeoutException est capturée par GenerateDailyBriefHandler
  AND le message Messenger est marqué comme "failed" pour retry automatique (max 3 tentatives, backoff 5 min)
  AND un log ERROR est enregistré avec le contexte technique mais sans stacktrace exposée à l'extérieur
  AND le DailyBrief existant (J-1 ou J précédent) n'est pas altéré
```
