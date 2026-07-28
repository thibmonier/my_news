# US-014 : Fallback provider OpenAI en cas d'indisponibilité Mistral

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-014 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |

**Dépend de :** US-010 (MistralApiClient, SynthesisService)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que Briefly bascule automatiquement sur OpenAI si Mistral est indisponible
**Afin de** toujours obtenir ma synthèse quotidienne sans interruption de service, même en cas de panne du provider principal.

---

## Les 3 C

### Carte (résumé)

Circuit breaker pattern dans `SynthesisService` : si Mistral retourne 5xx ou timeout, basculement automatique vers `OpenAiApiClient` avec le même prompt adapté (GPT-4o-mini). Le fallback est transparent pour l'utilisateur sauf badge discret "via OpenAI". Aucun identifiant utilisateur n'est transmis à OpenAI. Le circuit breaker se réinitialise après 60s de succès Mistral.

### Conversation (notes & questions ouvertes)

- Quelle bibliothèque pour le circuit breaker ? Suggestion : `lcobucci/clock` + compteur Redis (simple) ou `resilience4j` (PHP : `ejsmont-artur/php-circuit-breaker`). À confirmer en refinement.
- Le fallback OpenAI doit-il être soumis au même TTL cache Redis ? Oui, la clé cache est identique (l'utilisateur ne doit pas voir de différence).
- Faut-il alerter l'équipe tech si le circuit breaker s'ouvre ? Oui, log ERROR + webhook Slack (monitoring).
- Le fallback est-il activé uniquement pour les erreurs 5xx Mistral ou aussi pour les timeouts ? Les deux.
- RGPD : OpenAI est hors UE ; s'assurer que le contenu de l'article (non nominatif) est conforme aux CGU OpenAI pour l'inférence. Jamais d'email/identifiant dans le prompt.
- Si les deux providers échouent, l'utilisateur reçoit HTTP 503 avec message explicite.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `AiProviderInterface` | Contrat commun : `synthesize(content: string, level: SynthesisLevel): SynthesisResult` |
| **Infrastructure** | `MistralApiClient` | Implémente `AiProviderInterface` (refactorisé depuis US-010) |
| **Infrastructure** | `OpenAiApiClient` | Implémente `AiProviderInterface`, modèle GPT-4o-mini, adapte les paramètres de prompt |
| **Infrastructure** | `CircuitBreakerSynthesisService` | Décorator de `SynthesisService` : essai Mistral → si erreur → OpenAI → si erreur → exception |
| **Infrastructure** | Redis circuit breaker | Compteur d'erreurs `cb:mistral:failures` + état `cb:mistral:state (open|closed|half-open)` |
| **API Platform** | Badge provider | Réponse étendue : `"provider": "mistral"|"openai"` ; l'UI affiche le badge uniquement si `openai` |
| **Monitoring** | Log + alerte | `logger->error('circuit_breaker_open', ['provider' => 'mistral'])` + notification équipe |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Mistral disponible, pas de fallback

```gherkin
Scenario: Mistral répond normalement, OpenAI non sollicité
  GIVEN Thomas demande une synthèse
  AND le service Mistral répond en moins de 15 secondes
  WHEN SynthesisService traite la requête
  THEN la synthèse est générée par Mistral
  AND le champ "provider" dans la réponse API vaut "mistral"
  AND aucun badge "via OpenAI" n'est affiché dans l'interface
  AND aucun appel HTTP vers l'API OpenAI n'est effectué
```

### Scénario alternatif 1 — Bascule automatique sur OpenAI

```gherkin
Scenario: Mistral retourne une erreur 503, bascule sur OpenAI
  GIVEN le service Mistral retourne HTTP 503 sur la requête de synthèse
  WHEN SynthesisService détecte l'erreur
  THEN OpenAiApiClient est sollicité avec le même contenu et le même niveau
  AND la synthèse est retournée à Thomas avec succès (HTTP 200)
  AND le champ "provider" dans la réponse vaut "openai"
  AND un badge discret "via OpenAI" est affiché dans le bloc de synthèse
  AND l'erreur Mistral est loguée (WARNING) avec url_hash et level (sans identifiant utilisateur)
```

### Scénario alternatif 2 — Circuit breaker ouvert (Mistral durablement hors ligne)

```gherkin
Scenario: Circuit breaker ouvert — Mistral est bypassé directement
  GIVEN Mistral a échoué 5 fois consécutives dans les 60 dernières secondes (circuit ouvert)
  WHEN Thomas demande une synthèse
  THEN SynthesisService ne tente pas d'appeler Mistral
  AND il appelle directement OpenAI
  AND la synthèse est retournée (HTTP 200) avec badge "via OpenAI"
  AND le temps de réponse est inférieur à 15 secondes (pas d'attente timeout Mistral)
```

### Scénario erreur 1 — Les deux providers indisponibles

```gherkin
Scenario: Mistral et OpenAI sont tous les deux indisponibles simultanément
  GIVEN Mistral retourne HTTP 503
  AND OpenAI retourne HTTP 429 (rate limit) ou timeout
  WHEN SynthesisService tente les deux providers
  THEN l'API retourne HTTP 503 à l'utilisateur
  AND le message est "Synthèse temporairement indisponible — réessayez dans quelques minutes"
  AND aucun identifiant utilisateur n'est loggé
  AND une alerte ERROR est émise vers le système de monitoring
```

### Scénario erreur 2 — Contenu non conforme transmis à OpenAI (contrôle RGPD)

```gherkin
Scenario: Le prompt envoyé à OpenAI contient un identifiant utilisateur par erreur
  GIVEN un bug de régression injecte l'email de Thomas dans le prompt OpenAI
  WHEN les tests de conformité RGPD s'exécutent en CI
  THEN le test détecte la présence d'un pattern email dans les fixtures de prompt
  AND le test échoue avec le message "PII detected in AI provider prompt"
  AND le déploiement est bloqué
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Should Have
- **Validation INVEST** :
  - [x] Independent — ajout d'un décorateur sans modifier US-010 ni US-011
  - [x] Negotiable — choix de la librairie circuit breaker, seuil d'ouverture (5 erreurs), délai de reset
  - [x] Valuable — résilience du service = confiance des utilisateurs (NPS)
  - [x] Estimable — pattern décorateur connu, deux clients HTTP à implémenter
  - [x] Sized — 3 pts, périmètre technique clairement borné
  - [x] Testable — mocking des clients HTTP, vérification provider dans la réponse, tests CI RGPD
