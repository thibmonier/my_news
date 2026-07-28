# US-054 : Documentation développeur OpenAPI / Swagger UI

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-054 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 3 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** accéder à une documentation interactive OpenAPI listant tous les endpoints publics avec leurs schémas, exemples et informations d'authentification, sans avoir à créer de compte
**Afin de** intégrer l'API Briefly AI de façon complètement autonome, générer des clients dans mon langage de choix et comprendre les limites de l'API avant de m'inscrire.

---

## Conversation (Notes & Questions ouvertes)

- URL Swagger UI : `/api/docs` (standard API Platform) — accessible sans authentification.
- Spec OpenAPI JSON : `/api/docs.json` ; YAML : `/api/docs.yaml` — auto-générés par API Platform 4 (OpenAPI 3.1).
- Page Getting Started distincte : `/developers` en Twig, avec guide pas-à-pas (curl + Python + PHP), table des quotas, lien vers Swagger UI.
- Exemples de réponse : fictifs mais réalistes (article TechCrunch fictif "The Future of AI in 2026").
- Versionnement dans la spec : `info.version: "1.0.0"`, `info.title: "Briefly AI Public API"`.
- Seuls les endpoints sous le préfixe `/v1/` et le groupe OpenAPI "Public API" sont exposés dans la spec. Les routes internes (admin, webhook, ingestion) sont exclues via `#[ApiResource(openapi: false)]` ou groupe non exposé.
- Codes d'erreur documentés : 200, 202, 401, 422, 429, 503.
- La spec doit passer `openapi-generator validate` sans erreur ni warning en CI.

---

## Validation INVEST

- [~] **Independent** : Dépend logiquement de US-051 et US-052 (les endpoints doivent exister pour être documentés) ; les annotations OpenAPI peuvent être posées en avance et la page `/developers` développée indépendamment — livrable une fois US-051 et US-052 mergées
- [x] **Negotiable** : Exemples de réponse (fictifs vs live), profondeur du Getting Started, codes d'erreur documentés (warnings bloquants CI ou non), modèles d'exemples d'URL — tous discutables
- [x] **Valuable** : Permet l'adoption autonome de l'API sans support humain — condition d'ouverture à des intégrateurs B2B et différenciateur de qualité
- [x] **Estimable** : 3 pts — annotations API Platform + page `/developers` Twig + intégration CI `openapi-generator validate`
- [x] **Sized** : 3 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant Swagger UI accessible, spec JSON téléchargeable, Getting Started, exclusion endpoints internes, validation CI sans erreur

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **API Platform 4** | Annotations `#[ApiResource]`, `#[OA\Tag(name: "Brief")]`, `#[OA\Tag(name: "AI")]`, `#[OA\Example]` sur chaque Resource publique — spec JSON/YAML auto-générée sur `/api/docs.json` et `/api/docs.yaml` |
| **Swagger UI** | Bundle ngen/swagger-ui-bundle intégré API Platform — configuré sur `/api/docs` — accessible sans firewall |
| **Symfony/Twig** | Page `/developers` (layout Briefly AI) : getting started numéroté, table quotas Free/Premium, examples curl/Python/PHP, lien "Consulter la documentation complète" → `/api/docs` |
| **Symfony Router** | Routes `/api/docs`, `/api/docs.json`, `/api/docs.yaml`, `/developers` toutes en accès public (hors firewall authentifié) |
| **CI** | Étape `openapi-generator validate -i /api/docs.json` dans le pipeline — bloque le merge si erreur |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Accès à Swagger UI sans authentification

```gherkin
Scenario: Un développeur accède à la documentation interactive sans compte Briefly AI
  GIVEN un développeur non authentifié sur n'importe quel navigateur
  WHEN il accède à "/api/docs"
  THEN Swagger UI s'affiche avec deux sections : "Brief" (GET /v1/daily-brief) et "AI" (POST /v1/synthesize)
  AND chaque endpoint affiche : description fonctionnelle, paramètres (nom, type, obligatoire/optionnel), exemple de requête, exemple de réponse réaliste, codes HTTP possibles (200, 401, 422, 429)
  AND la section "Security" documente le schéma BearerAuth avec la note "Format du token : briefly_<64 hex chars>"
  AND la page charge en moins de 3 secondes depuis un réseau standard
```

### Scénario alternatif 1 — Téléchargement de la spec OpenAPI JSON pour génération de client

```gherkin
Scenario: Marc télécharge la spec OpenAPI pour générer un client Python avec openapi-generator
  GIVEN Marc accède à "/api/docs.json"
  THEN la réponse est HTTP 200 avec Content-Type "application/json"
  AND le JSON est valide conformément à la spécification OpenAPI 3.1
  AND le champ "info.version" vaut "1.0.0"
  AND le champ "info.title" vaut "Briefly AI Public API"
  AND la spec contient exactement 2 paths : /v1/daily-brief et /v1/synthesize avec leurs schémas de réponse complets
  AND aucun path interne (/admin/*, /api/webhooks/*, /api/internal/*) n'est présent dans la spec
```

### Scénario alternatif 2 — Page /developers avec Getting Started complet

```gherkin
Scenario: Marc consulte la page Getting Started pour sa première intégration
  GIVEN Marc accède à "/developers"
  THEN la page affiche 3 étapes numérotées :
       1. "Créer un token API" avec lien vers /account/api-tokens
       2. "Premier appel" avec exemple curl GET /v1/daily-brief
       3. "Gérer les quotas" avec description des headers X-RateLimit-*
  AND des exemples de code sont présents pour curl, Python (requests) et PHP (Guzzle)
  AND la table des quotas affiche les valeurs Free et Premium pour les deux endpoints
  AND un lien "Consulter la documentation complète" redirige vers "/api/docs"
```

### Scénario d'erreur 1 — Endpoints internes absents de la documentation publique

```gherkin
Scenario: Un développeur cherche un endpoint d'administration dans Swagger UI
  GIVEN la spec OpenAPI est générée par API Platform avec les groupes configurés
  WHEN le développeur consulte "/api/docs" et cherche les routes admin ou webhook
  THEN seuls les endpoints sous le groupe "Public API" (préfixe /v1/) sont visibles
  AND les routes /admin/*, /api/webhooks/stripe, /api/ingestion/* sont absentes de la spec
  AND la spec ne contient aucune référence aux entités internes (Source, DailyBriefJob, etc.)
```

### Scénario d'erreur 2 — Validation CI de la spec OpenAPI (régression)

```gherkin
Scenario: La CI valide la spec OpenAPI avant chaque merge
  GIVEN la spec est accessible sur "/api/docs.json" dans l'environnement de test
  WHEN le pipeline CI exécute "openapi-generator validate -i <url>/api/docs.json"
  THEN la commande se termine avec le code de sortie 0
  AND la sortie ne contient aucun message "ERROR" ni "WARNING"
  AND tous les $ref dans la spec sont résolus correctement
  AND le pipeline bloque le merge si le code de sortie est non nul
```
