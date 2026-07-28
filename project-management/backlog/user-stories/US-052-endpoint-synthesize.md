# US-052 : Endpoint POST /v1/synthesize

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-052 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** soumettre l'URL d'un article via `POST /v1/synthesize` et recevoir une synthèse IA structurée et sourcée
**Afin de** automatiser ma veille technologique en enrichissant mon pipeline de données personnelles avec des résumés traçables, sans confier mes lectures à des tiers non déclarés.

---

## Conversation (Notes & Questions ouvertes)

- Synchrone ou asynchrone ? V1 = synchrone avec timeout 30s. Si dépassement → HTTP 202 + `job_id` pour polling (future story).
- Cache Redis : par `sha256(url)` uniquement, indépendamment du plan. TTL 24h. Si résultat caché, le quota N'est pas décrémenté.
- Modèle IA : Mistral (EU) côté serveur — aucun identifiant utilisateur dans le prompt (RGPD). Fallback OpenAI si Mistral indisponible (transparence : champ `model_used`).
- Scope token requis : `synthesize` (distinct de `read`).
- Payload v1 : `{"url": "https://..."}` uniquement. L'input texte brut est un Could have futur.
- Quotas : Free = 3/jour (aligné UI paywall), Premium = 200/jour ; rate limit burst = 10/min (fenêtre glissante Redis).
- Champs obligatoires dans la réponse : `ai_summary` préfixé "BRIEFLY AI:", `ai_generated: true`, `source_url`, `model_used`, `generated_at`, `cached`.
- Validation de l'URL : vérifier format ET accessibilité HTTP (timeout 5s) avant d'appeler Mistral.

---

## Validation INVEST

- [~] **Independent** : Dépend de US-050 (token auth, scope `synthesize`) ; le scope `synthesize` est distinct de `read` (US-051), développable en parallèle sur la même infrastructure token
- [x] **Negotiable** : Timeout synchrone (30s), modèle IA (Mistral/fallback OpenAI), quotas burst (10/min), format payload v1 (URL uniquement vs texte brut futur) — tous discutables
- [x] **Valuable** : Différenciateur fort de l'API — permet d'automatiser la veille avec des synthèses IA traçables et conformes RGPD (aucun identifiant utilisateur dans le prompt)
- [x] **Estimable** : 5 pts — `SynthesizeArticleHandler` Messenger + cache Redis url_hash + voter quota + `ApiResource` API Platform
- [x] **Sized** : 5 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant synthèse nominale, cache HIT, timeout 202, URL invalide, quota Free épuisé

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `synthesis_cache` (url_hash char 64, result_json, created_at) + `api_quota_usage` (token_id, date, endpoint, count) pour audit et facturation |
| **Redis** | Cache résultat par `sha256(url)` TTL 24h ; compteur burst `synthesize_burst:{token_id}` TTL 60s (fenêtre glissante) |
| **Symfony Messenger** | Synchrone v1 via `SynthesizeArticleHandler` ; si timeout Symfony interne > 30s → dispatch en queue async et retour HTTP 202 |
| **Mistral AI (EU)** | Prompt de synthèse sans PII, structuré (title, ai_summary ≥50 mots ≤200 mots, key_points 3-5 items) ; fallback OpenAI |
| **Symfony Security** | Voter `API_SYNTHESIZE` : vérification scope `synthesize` + quota journalier + rate limit burst |
| **API Platform 4** | Resource `SynthesisRequest` POST — validation contrainte `Url` + accessibilité — OpenAPI tag "AI" — badge "BRIEFLY AI:" dans la réponse |
| **RGPD** | Aucun user_id ni email dans le prompt Mistral ; seul le hash URL est stocké ; logs anonymisés |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Synthèse d'un article via URL valide

```gherkin
Scenario: Marc soumet une URL valide pour synthèse (Premium, hors cache)
  GIVEN Marc possède un token API actif avec scope "synthesize" (plan Premium)
  AND l'URL "https://techcrunch.com/article-xyz" n'a pas été synthétisée dans les 24 dernières heures
  WHEN Marc envoie POST /v1/synthesize {"url": "https://techcrunch.com/article-xyz"} avec header Authorization
  THEN l'API retourne HTTP 200 en moins de 30 secondes
  AND le corps contient : url, title (string), ai_summary (string ≥ 50 mots ≤ 200 mots, préfixé "BRIEFLY AI:"),
      key_points (array de 3 à 5 items string), ai_generated: true, source_url (identique à l'input),
      model_used (ex: "mistral-small-latest"), generated_at (ISO 8601), cached: false
  AND le résultat est stocké dans Redis (TTL 24h) et en base synthesis_cache
  AND le quota journalier de Marc est décrémenté de 1 dans api_quota_usage
```

### Scénario alternatif 1 — Résultat servi depuis le cache (quota non décrémenté)

```gherkin
Scenario: Marc soumet une URL déjà synthétisée il y a moins de 24 heures
  GIVEN l'URL "https://techcrunch.com/article-xyz" a été synthétisée il y a 6 heures (cache Redis valide)
  WHEN Marc envoie POST /v1/synthesize {"url": "https://techcrunch.com/article-xyz"}
  THEN l'API retourne HTTP 200 immédiatement (latence < 200ms)
  AND le champ "cached: true" est présent dans la réponse
  AND le quota journalier de Marc N'est PAS décrémenté (synthèse gratuite sur cache)
  AND aucun appel n'est émis vers Mistral ni OpenAI
```

### Scénario alternatif 2 — Timeout Mistral — réponse asynchrone HTTP 202

```gherkin
Scenario: Mistral répond en plus de 30 secondes (surcharge ou indisponibilité)
  GIVEN Mistral met plus de 30 secondes à répondre
  WHEN Marc envoie POST /v1/synthesize {"url": "https://..."}
  THEN l'API retourne HTTP 202 en moins de 31 secondes
  AND le corps contient : {"job_id": "<uuid v4>", "status": "processing", "poll_url": "/v1/synthesize/<uuid>"}
  AND le job est enregistré dans la queue Symfony Messenger pour traitement async
  AND Marc peut vérifier l'état via GET /v1/synthesize/<uuid> (not found retourne HTTP 202 si toujours en cours)
```

### Scénario d'erreur 1 — URL invalide ou inaccessible

```gherkin
Scenario: Marc soumet une URL malformée
  GIVEN Marc envoie POST /v1/synthesize {"url": "not-a-valid-url"}
  THEN l'API retourne HTTP 422 {"violations": [{"field": "url", "message": "L'URL doit être valide et accessible (HTTP 200 requis)"}]}
  AND aucun appel n'est émis vers Mistral ni OpenAI
  AND le quota journalier de Marc n'est pas décrémenté
  AND le compteur Redis n'est pas incrémenté
```

### Scénario d'erreur 2 — Quota journalier Free épuisé (3/jour)

```gherkin
Scenario: Marc (plan Free) tente une 4e synthèse dans la journée
  GIVEN Marc est sur plan Free et a déjà effectué 3 synthèses aujourd'hui
  WHEN Marc envoie POST /v1/synthesize {"url": "https://valid-article.com"}
  THEN l'API retourne HTTP 429 {"error": "synthesis_quota_exceeded", "plan": "free", "limit": 3, "used": 3, "reset_at": "<ISO 8601 minuit UTC>", "upgrade_url": "/pricing"}
  AND les headers contiennent : X-RateLimit-Limit: 3, X-RateLimit-Remaining: 0, Retry-After: <secondes avant minuit UTC>
  AND aucune synthèse n'est lancée (ni Mistral ni Messenger)
```
