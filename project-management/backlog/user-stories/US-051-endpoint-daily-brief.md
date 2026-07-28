# US-051 : Endpoint GET /v1/daily-brief

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-051 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** interroger `GET /v1/daily-brief` avec mon token API pour obtenir le Daily Brief du jour au format JSON
**Afin de** intégrer automatiquement les 3 histoires majeures du jour dans mon dashboard personnel, sans passer par l'interface web ni exposer de cookies de session.

---

## Conversation (Notes & Questions ouvertes)

- Paramètre `?date=YYYY-MM-DD` pour les archives ? Proposition v1 : today uniquement. Les archives restent un Could have futur.
- Format de réponse : JSON natif API Platform avec option JSON-LD via header `Accept: application/ld+json`.
- Les 3 histoires incluent-elles le résumé IA ? Oui : champ `ai_summary` (string|null) avec flag `ai_generated: true` et `source_url` (traçabilité BRIEFLY AI).
- Scope de token requis : `read`.
- Cache Redis : TTL dynamique jusqu'à minuit UTC du jour courant (évite les recalculs coûteux).
- La réponse inclut `last_updated` en ISO 8601, aligné avec le CTA "LAST UPDATED" de l'interface.
- Pas de pagination : le Daily Brief est toujours 3 histoires fixes (max).
- Header `API-Version: 1` présent sur toutes les réponses pour le versionnement.

---

## Validation INVEST

- [~] **Independent** : Dépend de US-050 (token auth) et d'EPIC-001 (contenu Daily Brief) ; les deux peuvent être disponibles avant cette story — développable en isolation technique
- [x] **Negotiable** : Paramètre `?date` (archives), pagination (v1 : 3 histoires fixes), format JSON vs JSON-LD, TTL du cache Redis — tous discutables
- [x] **Valuable** : Endpoint cœur de l'API publique — permet l'intégration du Daily Brief dans tout système tiers sans interface web
- [x] **Estimable** : 5 pts — `ApiResource` API Platform lecture seule + cache Redis TTL dynamique + tests `ApiTestCase`
- [x] **Sized** : 5 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant lecture nominale, cache HIT, brief en cours de génération, token manquant, quota épuisé

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Lecture `daily_brief` (date, status, last_updated) + `brief_stories` (rank, title, summary, ai_summary, ai_generated, source_url, published_at) — domaine EPIC-001 |
| **Redis** | Cache clé `api:daily_brief:{date}` — TTL = secondes restantes jusqu'à minuit UTC — sérialisé JSON |
| **Symfony Security** | `ApiTokenAuthenticator` — vérification scope `read` — voter `DAILY_BRIEF_API_VIEW` |
| **API Platform 4** | Resource `DailyBriefApiResponse` (lecture seule) — opération GetCollection — filtre automatique sur date courante — tag OpenAPI "Brief" — header `API-Version: 1` via EventSubscriber |
| **RGPD / Sécurité OWASP** | Aucune donnée personnelle dans la réponse ; IDs UUID v4 non séquentiels ; CORS restreint aux origins déclarées ; quota journalier vérifié avant traitement |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Lecture du Daily Brief avec token valide

```gherkin
Scenario: Marc récupère le Daily Brief du jour via l'API
  GIVEN Marc possède un token API actif avec le scope "read" (plan Premium)
  WHEN Marc envoie GET /v1/daily-brief avec le header "Authorization: Bearer briefly_<token>"
  THEN l'API retourne HTTP 200 avec Content-Type "application/json"
  AND le corps contient exactement 3 objets dans le tableau "stories", chacun avec les champs :
      id (UUID v4), rank (entier 1 à 3), title (string), summary (string),
      ai_summary (string préfixé "BRIEFLY AI:" ou null), ai_generated (boolean),
      source_url (URL valide), published_at (ISO 8601), last_updated (ISO 8601)
  AND la réponse contient les headers : API-Version: 1, X-Cache: MISS, X-RateLimit-Remaining (décrémenté de 1)
  AND le résultat est stocké dans Redis avec TTL jusqu'à minuit UTC
```

### Scénario alternatif 1 — Réponse servie depuis le cache (second appel dans la journée)

```gherkin
Scenario: Marc appelle /v1/daily-brief deux fois le même jour
  GIVEN le premier appel a déjà peuplé le cache Redis pour la date du jour
  WHEN Marc envoie une seconde requête GET /v1/daily-brief
  THEN l'API retourne HTTP 200 avec le header "X-Cache: HIT"
  AND le contenu est identique au premier appel (même last_updated, mêmes IDs)
  AND aucune requête PostgreSQL supplémentaire n'est émise (vérifié en test d'intégration)
  AND le compteur de rate limit est tout de même décrémenté de 1
```

### Scénario alternatif 2 — Daily Brief non encore généré avant sa publication matinale

```gherkin
Scenario: Marc appelle l'API à 4h30 UTC, avant la génération du brief (5h UTC)
  GIVEN aucun Daily Brief n'a été généré pour la date courante
  WHEN Marc envoie GET /v1/daily-brief
  THEN l'API retourne HTTP 200 avec le tableau "stories" vide []
  AND le champ "status" vaut "pending" (et non "published")
  AND le champ "last_updated" est null
  AND le compteur de rate limit est décrémenté de 1 (appel comptabilisé)
```

### Scénario d'erreur 1 — Token manquant

```gherkin
Scenario: Un développeur envoie GET /v1/daily-brief sans header Authorization
  GIVEN aucun header Authorization n'est présent dans la requête
  WHEN la requête est reçue par l'API
  THEN l'API retourne HTTP 401 {"error": "missing_token", "message": "Un token API est requis. Consultez /api/docs pour commencer."}
  AND aucune donnée du brief n'est exposée dans la réponse
  AND aucun compteur de quota n'est modifié
```

### Scénario d'erreur 2 — Quota journalier Free épuisé

```gherkin
Scenario: Marc (plan Free) a effectué 100 appels /v1/daily-brief dans la journée
  GIVEN Marc a atteint exactement 100 appels à /v1/daily-brief aujourd'hui
  WHEN Marc envoie un 101e appel
  THEN l'API retourne HTTP 429 {"error": "rate_limit_exceeded", "plan": "free", "limit": 100, "reset_at": "<ISO 8601 minuit UTC>"}
  AND les headers contiennent : X-RateLimit-Limit: 100, X-RateLimit-Remaining: 0, Retry-After: <secondes avant minuit UTC>
  AND aucune donnée du brief n'est retournée
```
