# US-004 : Condensé IA par article avec badge et traçabilité source

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-004 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** voir un condensé IA de 3 à 4 puces au début de chaque article du Daily Brief, avec le badge "BRIEFLY AI:" et l'icône auto_awesome, accompagné d'un lien "OUVRIR L'ORIGINAL" vers la source,
**Afin de** comprendre l'essentiel d'une histoire en moins de 30 secondes et décider en toute transparence si je veux approfondir en lisant la source originale.

### Conversation

- Quel modèle IA pour les condensés ? Décision : Mistral (EU, RGPD compliant) via API serveur pour tous les condensés ; Phi-3 Mini / Gemma 2B on-device (opt-in P-003 Marc) déclenché par flag utilisateur.
- Comment garantir la traçabilité RGPD ? Décision : aucun identifiant utilisateur dans les prompts ; le prompt contient uniquement le texte de l'article ; log de la version du modèle Mistral utilisée.
- Stratégie de cache ? Décision : Redis, clé = `sha256(article_id + "_" + summary_level)`, TTL 24h. Si cache hit → retour immédiat ; si miss → appel Mistral → cache.
- Fallback si Mistral est indisponible ? Décision : fallback vers OpenAI (GPT-4o-mini) après 2 timeouts (circuit breaker 60s) ; si les deux sont indisponibles → affichage de l'extrait RSS brut (dégradé visible, badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE").
- Le condensé doit-il être pré-généré au moment du batch 5h UTC ou à la demande ? Décision : pré-généré en batch pour les 3 histoires du brief (meilleure performance) ; à la demande pour les articles hors-brief.
- Format exact du condensé ? Décision : 3 puces minimum, 4 maximum, chacune ≤ 120 caractères. Préfixe obligatoire "BRIEFLY AI:" sur le conteneur. Accent émeraude #10B981 UNIQUEMENT sur les éléments IA.
- Comment afficher la source ? Décision : sous le condensé, lien texte "Source : [Nom de la source]" + bouton "OUVRIR L'ORIGINAL" (lien externe `rel="noopener noreferrer"`).

### Validation INVEST

- [x] **I**ndependent : dépend de US-002 (articles sélectionnés en base), indépendant des autres US backlog
- [x] **N**egotiable : modèle IA (Mistral vs OpenAI), nombre de puces (3-4), TTL cache, pré-génération vs on-demand
- [x] **V**aluable : différenciant produit central — "fort signal, faible bruit" ; P-001 Thomas économise 80% du temps de lecture
- [x] **E**stimable : Mistral client connu, Redis cache pattern connu, estimé 5 pts
- [x] **S**ized : 5 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères vérifiables (3-4 puces, badge présent, lien source, cache Redis, fallback)

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain Service** | `ArticleSummaryService` | Orchestration : cache check → appel IA → cache set → retour DTO `ArticleSummary` |
| **AI Client** | `MistralSummaryClient` | HTTP client Symfony vers Mistral API EU ; prompt sans identifiant utilisateur ; log `model_version` |
| **AI Client** | `OpenAiSummaryClient` | Fallback ; même interface `SummaryClientInterface` (DIP SOLID) |
| **Cache** | Redis | Clé `briefly:summary:{sha256(article_id)}`, TTL 86400s (24h) ; Symfony Cache (PSR-16) |
| **Circuit Breaker** | `SummaryCircuitBreaker` | 2 timeouts successifs → open 60s → fallback automatique |
| **API Platform** | `GET /api/articles/{id}/summary` | Endpoint JSON:API pour les clients Flutter (mobile) |
| **Symfony Controller** | `BriefController` (enrichi) | Appelle `ArticleSummaryService` pour les 3 histoires avant le rendu Twig |
| **Template Twig** | `components/_article_summary.html.twig` | Badge "BRIEFLY AI:" (couleur #10B981), icône `auto_awesome`, 3-4 puces `<ul>`, lien "OUVRIR L'ORIGINAL" |
| **RGPD** | Isolation des prompts | Assert unitaire : aucun `user_id`, `email`, `ip` dans les paramètres envoyés à Mistral |
| **Sécurité OWASP** | Output escaping | Contenu IA échappé via `twig/twig` `{{ summary | e }}` ; pas de rendu HTML brut de la réponse Mistral |
| **Base de données** | PostgreSQL | Table `article_summaries` (id, article_id FK, content JSONB, model_version, cached_at, expires_at) |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Affichage du condensé IA pré-généré pour une histoire du brief

```gherkin
Scenario: Condensé IA affiché en tête d'article avec badge et lien source
  GIVEN US-001 est en production et affiche les 3 histoires du Daily Brief
  AND un condensé IA a été pré-généré pour chaque article via ArticleSummaryService (cache Redis chaud)
  WHEN Thomas accède à la page /brief
  THEN chaque histoire affiche un bloc "BRIEFLY AI:" avec le badge émeraude #10B981 et l'icône auto_awesome
  AND le bloc contient exactement 3 ou 4 puces (liste <ul>), chacune ≤ 120 caractères
  AND un lien "OUVRIR L'ORIGINAL" avec rel="noopener noreferrer" pointe vers l'URL source de l'article
  AND le texte du condensé est correctement échappé (pas de HTML brut injecté)
  AND aucun identifiant personnel n'est visible dans la réponse HTML
```

### Scénario alternatif 1 — Cache Redis froid, génération à la demande lors de la première consultation

```gherkin
Scenario: Premier chargement de la page après un reset du cache Redis
  GIVEN le cache Redis est vide pour les 3 articles du brief du jour
  WHEN Thomas accède à /brief
  THEN ArticleSummaryService appelle Mistral API pour chaque article (max 3 appels séquentiels ou parallèles)
  AND les condensés générés sont stockés en cache Redis (TTL 24h)
  AND les condensés s'affichent sur la page dans un délai total ≤ 3 secondes
  AND un log INFO `{"event": "summary.cache_miss", "article_id": "<uuid>", "model": "mistral-..."}` est enregistré (sans données personnelles)
```

### Scénario alternatif 2 — Mode dégradé visible (Mistral et OpenAI indisponibles)

```gherkin
Scenario: Fallback affichage extrait brut quand tous les fournisseurs IA sont indisponibles
  GIVEN Mistral API retourne timeout sur 2 tentatives consécutives
  AND OpenAI API retourne également une erreur 503
  WHEN ArticleSummaryService tente de générer le condensé
  THEN la page affiche l'extrait RSS brut de l'article (description, max 280 caractères) à la place du condensé IA
  AND un badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" remplace le badge "BRIEFLY AI:"
  AND aucun message d'erreur technique n'est exposé à Thomas
  AND un log WARNING `{"event": "summary.all_providers_failed", "article_id": "<uuid>"}` est enregistré
```

### Scénario d'erreur 1 — Violation RGPD : identifiant utilisateur dans le prompt (test de non-régression)

```gherkin
Scenario: Garantie RGPD — aucun identifiant personnel dans les prompts Mistral
  GIVEN l'utilisateur Thomas est connecté (future fonctionnalité auth)
  WHEN ArticleSummaryService prépare le prompt pour Mistral
  THEN le payload JSON envoyé à l'API Mistral ne contient aucun champ user_id, email, ip ou session_id
  AND un test d'assertion unitaire `assertNotContains($userId, $prompt)` passe en CI
  AND le log de l'appel Mistral enregistre uniquement article_id (UUID non-séquentiel) et model_version
```

### Scénario d'erreur 2 — Injection de contenu malveillant dans la réponse Mistral (prompt injection)

```gherkin
Scenario: Réponse Mistral contenant du HTML ou du JavaScript malveillant
  GIVEN Mistral retourne une réponse contenant la chaîne "<script>alert('xss')</script>"
  WHEN ArticleSummaryService reçoit et traite la réponse
  THEN le contenu est stocké brut en base (JSONB) sans interprétation
  AND lors du rendu Twig, le contenu est échappé via `| e` : "&#60;script&#62;alert(&#39;xss&#39;)&#60;/script&#62;"
  AND aucun script n'est exécuté dans le navigateur de Thomas (vérifié par test Panther)
```
