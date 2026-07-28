# US-053 : Rate limit headers et quotas par plan

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-053 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 3 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** que chaque réponse de l'API inclue des headers `X-RateLimit-*` indiquant ma consommation, mes limites et mon plan en cours
**Afin de** adapter dynamiquement la fréquence de mes appels dans mon pipeline automatisé et éviter des erreurs 429 qui bloqueraient mon dashboard.

---

## Conversation (Notes & Questions ouvertes)

- Headers standard à implémenter sur toutes les réponses API (2xx, 4xx, 5xx) : `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (timestamp UNIX), `X-RateLimit-Plan`.
- Granularité : par endpoint (`daily-brief` vs `synthesize`) avec deux compteurs indépendants. Un même appel ne décrémente qu'un seul compteur.
- Fenêtre de quota : journalière (minuit UTC) pour les limites de plan. Fenêtre glissante 60s pour le burst anti-DDOS (non exposée dans les headers v1).
- Comptage : Redis pour la fenêtre courante (INCR + EXPIREAT minuit UTC) ; PostgreSQL pour l'agrégat journalier (audit, facturation).
- Quotas v1 définis :
  - Free : 100 req/jour `/v1/daily-brief` ; 3 req/jour `/v1/synthesize`
  - Premium : 1 000 req/jour `/v1/daily-brief` ; 200 req/jour `/v1/synthesize`
- Changement de plan en cours de journée : la limite appliquée bascule immédiatement sur le nouveau plan ; le compteur existant est conservé (pas de remise à zéro).
- Headers `X-RateLimit-*` absents sur les réponses HTTP 401 (token absent ou révoqué).

---

## Validation INVEST

- [x] **Independent** : Transverse implémentée comme `ApiRateLimitSubscriber` injecté sur les routes `/v1/*` — développable et testable sans que US-051 et US-052 soient finalisées (les routes peuvent être mockées)
- [x] **Negotiable** : Granularité des compteurs (par endpoint vs globale), exposition du burst dans les headers (v1 : non), comportement sur changement de plan en cours de journée — tous discutables
- [x] **Valuable** : Permet aux intégrateurs de piloter leurs appels sans subir de 429 inattendus — condition de fiabilité critique pour l'adoption de l'API publique
- [x] **Estimable** : 3 pts — `ApiRateLimitSubscriber` + compteurs Redis `INCR/EXPIREAT` + flush PostgreSQL Scheduler
- [x] **Sized** : 3 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant premiers appels Premium, partage Free, changement de plan, 429 avec headers, 401 sans headers

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **Redis** | Compteurs `api_rl:{token_id}:{endpoint}:{date}` — INCR + EXPIREAT calculé (minuit UTC) — lecture atomique avant incrément |
| **PostgreSQL** | Table `api_daily_usage` (token_id, endpoint, date, count) — flush Redis → PostgreSQL chaque heure par Scheduler |
| **Symfony EventSubscriber** | `ApiRateLimitSubscriber` sur `kernel.response` — injecte les 4 headers sur toutes les réponses des routes `/v1/*` authentifiées |
| **Symfony Security** | Lecture `user.subscriptionPlan` (Free|Premium) pour calculer la limite applicable à l'instant de la requête |
| **API Platform 4** | Pas de modification des Resources — les headers sont injectés transversalement par l'EventSubscriber |
| **RGPD** | Les compteurs Redis n'exposent que le token_id (haché), jamais l'email ou le nom utilisateur |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Headers corrects sur GET /v1/daily-brief (Premium, premier appel)

```gherkin
Scenario: Marc (Premium) effectue son premier appel de la journée à /v1/daily-brief
  GIVEN Marc est authentifié avec un token scope "read" (plan Premium, limite 1 000/jour)
  AND Marc n'a effectué aucun appel à /v1/daily-brief aujourd'hui
  WHEN Marc envoie GET /v1/daily-brief
  THEN la réponse contient exactement les headers suivants :
       X-RateLimit-Limit: 1000
       X-RateLimit-Remaining: 999
       X-RateLimit-Reset: <timestamp UNIX entier correspondant à minuit UTC du jour courant>
       X-RateLimit-Plan: premium
  AND le compteur Redis est incrémenté à 1 pour la clé "api_rl:{token_id}:daily-brief:{date}"
```

### Scénario alternatif 1 — Headers sur POST /v1/synthesize (Free, quota partiel)

```gherkin
Scenario: Marc (Free) effectue sa 2e synthèse de la journée
  GIVEN Marc est sur plan Free (limite 3/jour synthesize) et a déjà effectué 1 synthèse aujourd'hui
  WHEN Marc envoie POST /v1/synthesize {"url": "https://valid-article.com"}
  THEN la réponse HTTP 200 contient :
       X-RateLimit-Limit: 3
       X-RateLimit-Remaining: 1
       X-RateLimit-Reset: <timestamp UNIX minuit UTC>
       X-RateLimit-Plan: free
  AND le compteur Redis est incrémenté à 2 pour la clé "api_rl:{token_id}:synthesize:{date}"
```

### Scénario alternatif 2 — Changement de plan Free → Premium en cours de journée

```gherkin
Scenario: Marc passe de Free à Premium en cours de journée puis appelle l'API
  GIVEN Marc a effectué 2 appels /v1/daily-brief avec le plan Free (limite 100)
  AND Marc souscrit Premium à 14h00 (webhook Stripe traité et user.subscriptionPlan mis à jour)
  WHEN Marc envoie GET /v1/daily-brief à 14h30
  THEN la réponse contient :
       X-RateLimit-Limit: 1000
       X-RateLimit-Remaining: 997
       X-RateLimit-Plan: premium
  AND le compteur existant (2 appels antérieurs) est conservé
  AND la limite appliquée est immédiatement celle du plan Premium (1 000)
```

### Scénario d'erreur 1 — Headers informatifs présents même sur réponse HTTP 429

```gherkin
Scenario: Marc (Free) a atteint son quota de 100 appels /v1/daily-brief aujourd'hui
  GIVEN Marc a effectué exactement 100 appels /v1/daily-brief dans la journée
  WHEN Marc envoie un 101e appel
  THEN l'API retourne HTTP 429
  AND la réponse contient les headers :
       X-RateLimit-Limit: 100
       X-RateLimit-Remaining: 0
       X-RateLimit-Reset: <timestamp UNIX minuit UTC>
       X-RateLimit-Plan: free
       Retry-After: <nombre entier de secondes jusqu'à minuit UTC>
  AND le corps contient {"error": "rate_limit_exceeded", "plan": "free", "reset_at": "<ISO 8601>"}
```

### Scénario d'erreur 2 — Absence de headers X-RateLimit-* sur une réponse HTTP 401

```gherkin
Scenario: Appel avec un token révoqué — pas d'injection de headers de quota
  GIVEN Marc a révoqué son token API il y a 1 minute
  WHEN Marc envoie GET /v1/daily-brief avec ce token révoqué
  THEN l'API retourne HTTP 401 {"error": "token_revoked"}
  AND les headers X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset et X-RateLimit-Plan sont ABSENTS de la réponse
  AND le compteur Redis n'est pas modifié
```
