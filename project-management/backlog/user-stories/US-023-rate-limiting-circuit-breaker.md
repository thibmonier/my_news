# US-023 : Rate limiting Redis + circuit breaker par source

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-023 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | P-003 Marc — développeur indépendant privacy-first, 44 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant que** P-003 Marc, développeur indépendant qui exploite l'API Briefly AI,
**Je veux** que le pipeline d'ingestion respecte strictement les limites de débit de chaque source tierce et se protège automatiquement des sources défaillantes
**Afin de** garantir que Briefly AI ne se fasse pas bannir des éditeurs que je valorise, et que la disponibilité long-terme du service soit assurée sans intervention humaine.

### Conversation

- Quelle granularité pour le rate limiting : par source, par domaine, ou globale ? Décision : par source (chaque entité `Source` a son propre rate limit configurable en base via `max_requests_per_hour INT`).
- Quel backend pour le rate limiting ? Décision : Symfony Rate Limiter avec storage Redis (`cache.app` Redis) ; stratégie `sliding_window` par source_id.
- Quels paramètres par défaut pour le circuit breaker ? Décision : ouverture après 5 erreurs consécutives (HTTP 5xx / timeout / ConnectException), fenêtre d'évaluation 5 min, état OPEN pendant 30 min, puis HALF-OPEN pour une tentative de rétablissement.
- Le circuit breaker est-il stocké en Redis ou en base ? Décision : Redis (clés TTL) pour la performance ; les états sont recréés après un redémarrage Redis (fail-open acceptable : le premier fetch post-redémarrage tentera la source).
- Comment gérer le header `Retry-After` retourné par une source HTTP 429 ? Décision : parsé dans le handler, la valeur Retry-After (seconds ou date HTTP) est utilisée comme délai minimum de requeue (prévaut sur le back-off exponentiel si plus long).
- Le rafraîchissement conditionnel ETag/Last-Modified est-il inclus ici ? Décision : oui. Lorsqu'une source retourne ETag ou Last-Modified lors d'un fetch réussi, ces valeurs sont stockées sur l'entité `Source` et renvoyées via `If-None-Match` / `If-Modified-Since` au cycle suivant. Une réponse 304 est loggée et compte comme un cycle réussi (circuit breaker non incrémenté).

### Validation INVEST

- [x] **I**ndependent : enrichit le `FetchSourceHandler` d'US-020 de manière additive ; livrable et déployable séparément
- [x] **N**egotiable : seuils du circuit breaker, stratégie rate limiter, gestion ETag/Last-Modified en scope optionnel
- [x] **V**aluable : protège la pérennité des sources ingérées, essentielle pour Marc qui valorise la fiabilité du service
- [x] **E**stimable : Symfony Rate Limiter Redis + circuit breaker state machine Redis + gestion ETag, estimé 5 pts
- [x] **S**ized : 5 pts < 8 pts
- [x] **T**estable : tests unitaires avec Redis mock (ArrayAdapter), tests d'intégration avec un serveur RSS bouchonné (retournant 429, 503, 304)

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain Service** | `RateLimiterService` | Facade Symfony Rate Limiter : `allow(string $sourceId): bool`, stratégie `sliding_window`, limite configurable `max_requests_per_hour` par Source |
| **Domain Service** | `CircuitBreakerService` | State machine Redis : CLOSED → OPEN (après 5 erreurs) → HALF-OPEN (après 30 min) → CLOSED (si succès). Clés Redis : `circuit:{source_id}:state`, `circuit:{source_id}:failures`, TTL=30min pour OPEN |
| **Infrastructure** | `FeedIoSourceFetcher` enrichi | Headers conditionnels : `If-None-Match: {etag}` et `If-Modified-Since: {last_modified}` si stockés. Retour `FetchResultDTO(status: ok|not_modified|error, articles[], etag, lastModified)` |
| **Messenger Handler** | `FetchSourceHandler` enrichi | Intègre : (1) vérification circuit breaker, (2) vérification rate limiter, (3) fetch conditionnel, (4) mise à jour circuit breaker et Source après résultat |
| **Domain** | `Source` entity enrichie | Ajout champs : `max_requests_per_hour INT DEFAULT 4`, `etag VARCHAR(255) NULL`, `last_modified VARCHAR(255) NULL`, `circuit_state ENUM(closed/open/half_open)`, `consecutive_errors INT DEFAULT 0` |
| **Migration** | Doctrine Migration | Ajout colonnes Source ci-dessus |
| **Infrastructure** | Redis (Symfony Cache) | Rate limiter keys : `rl:{source_id}` (TTL sliding 1h) ; Circuit breaker keys : `cb:{source_id}:{state|failures}` (TTL 30min pour OPEN) |
| **Logging** | Monolog | Channel dédié `ingestion` ; log structuré JSON (source_id, action, circuit_state, rate_limit_remaining, retry_after) |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Rate limiter Redis bloque un fetch excessif

```gherkin
Scenario: Le rate limiter empêche un 5ème fetch d'une source limitée à 4/heure
  GIVEN la source "HBR" est configurée avec max_requests_per_hour=4
  AND 4 requêtes ont déjà été effectuées sur cette source dans l'heure glissante courante (clé Redis active)
  WHEN le Scheduler publie un nouveau FetchSourceMessage pour "HBR"
  THEN RateLimiterService::allow("hbr-source-id") retourne FALSE
  AND le FetchSourceHandler ne contacte PAS l'URL de la source (0 requête HTTP émise)
  AND le message Messenger est requeue avec un délai exponentiel de 2^attempt minutes
  AND un log INFO est enregistré : source_id="hbr-source-id", action="rate_limited", tokens_remaining=0, retry_at={timestamp}
```

### Scénario alternatif 1 — Circuit breaker s'ouvre après 5 erreurs consécutives

```gherkin
Scenario: Le circuit breaker passe en état OPEN et protège la source défaillante
  GIVEN la source "TheEconomist" a retourné 4 erreurs HTTP 503 consécutives (consecutive_errors=4)
  WHEN le FetchSourceHandler reçoit une 5ème réponse HTTP 503
  THEN CircuitBreakerService incrémente consecutive_errors à 5
  AND le circuit passe en état OPEN (clé Redis `cb:economist-id:state`=OPEN, TTL=1800s)
  AND les cycles Scheduler suivants pendant 30 min détectent l'état OPEN
  AND aucun fetch n'est émis vers "TheEconomist" pendant ces 30 minutes
  AND un log WARNING est enregistré : source_id, circuit_state=OPEN, open_since, consecutive_errors=5
```

### Scénario alternatif 2 — Fetch conditionnel HTTP 304 (source non modifiée)

```gherkin
Scenario: Une source non modifiée retourne 304 et les ressources réseau sont économisées
  GIVEN la source "ArsTechnica" a été fetchée à 14h00 et son etag="abc123" est stocké en base
  WHEN le cycle de 14h15 déclenche le fetch de "ArsTechnica" avec header "If-None-Match: abc123"
  AND le serveur retourne HTTP 304 Not Modified (corps vide)
  THEN FeedIoSourceFetcher retourne FetchResultDTO(status=not_modified, articles=[])
  AND 0 article n'est inséré ou comparé en base
  AND last_fetched_at de la source est mis à jour
  AND le circuit breaker ne compte PAS ce 304 comme une erreur (consecutive_errors inchangé)
  AND un log DEBUG est enregistré : source_id, action="not_modified", etag="abc123"
```

### Scénario erreur 1 — Source retourne HTTP 429 avec header Retry-After

```gherkin
Scenario: Un HTTP 429 est géré proprement avec respect du Retry-After
  GIVEN la source "Reuters" retourne HTTP 429 avec header "Retry-After: 3600"
  WHEN FetchSourceHandler reçoit la réponse 429
  THEN le message Messenger est requeue avec délai de max(3600, backoff_exponentiel) secondes
  AND CircuitBreakerService incrémente consecutive_errors
  AND le header Retry-After parsé (3600s) est loggé : source_id, retry_after=3600, requeue_at={timestamp}
  AND aucun article n'est inséré en base pour ce cycle
```

### Scénario erreur 2 — Redis indisponible (fail-open pour continuité d'ingestion)

```gherkin
Scenario: Redis est temporairement inaccessible, le pipeline continue sans rate limiting
  GIVEN le serveur Redis est down (ConnectionRefusedException au moment du fetch)
  WHEN RateLimiterService::allow() et CircuitBreakerService::getState() lèvent une RedisException
  THEN les exceptions sont catchées dans FetchSourceHandler (fail-open)
  AND le fetch de la source s'exécute normalement sans rate limiting
  AND un log CRITICAL est enregistré : service="redis", exception_message, source_id
  AND aucun circuit breaker n'est mis à jour (état conservé à sa dernière valeur connue)
  AND une alerte PagerDuty (ou équivalent) est déclenchée si Redis reste inaccessible > 5 min
```
