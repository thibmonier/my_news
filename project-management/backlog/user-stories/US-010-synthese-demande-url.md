# US-010 : Synthèse IA à la demande sur URL (Walking Skeleton web)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-010 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | sprint-001 (Walking Skeleton) |

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** générer une synthèse IA d'un article depuis son URL en un clic
**Afin de** comprendre l'essentiel d'un contenu en 30 secondes sans lecture complète, avec traçabilité de la source.

---

## Les 3 C

### Carte (résumé)

Bouton "GENERATE AI SUMMARY" sur la page article web. Appel Mistral (1 requête serveur). Retourne un condensé préfixé "BRIEFLY AI:" (~200 mots) + 3 points clés numérotés + sources citées + lien "OUVRIR L'ORIGINAL". Sans quota en Sprint 1.

### Conversation (notes & questions ouvertes)

- Comment gérer le spinner/skeleton UI pendant l'appel Mistral (max 10s avant timeout affiché) ?
- Si l'article est partiellement derrière un paywall, synthétiser le contenu accessible et signaler "Contenu partiel".
- Quel prompt système utiliser pour garantir la concision (~200 mots) et les 3 points clés ? À affiner en refinement avec le Tech Lead.
- La langue de sortie doit-elle correspondre à la langue de l'article ou être forcée en français ? (décision : langue de l'article en v1).
- Le cache Redis et le fallback OpenAI seront ajoutés dans US-012 et US-014 (backlog). Sprint 1 = appel direct Mistral sans filet.
- Le quota (3/jour Free) sera géré dans US-013 (backlog). Sprint 1 = sans restriction.
- Faut-il persister le résultat en base même sans cache ? Oui, pour traçabilité et analytics.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API Platform** | `POST /api/v1/synthesis` | DTO `SynthesisRequest { url: string }`, réponse `SynthesisResponse { content, keyPoints[], sources[], originalUrl }` |
| **Domain** | `SynthesisService` | Orchestration : fetch contenu → prompt Mistral → parse réponse |
| **Infrastructure** | `MistralApiClient` | HTTP client (Symfony HttpClient), timeout 15s, log sans identifiant utilisateur |
| **PostgreSQL** | `synthesis_results` | `id UUID, url_hash VARCHAR(64), level VARCHAR(16), content TEXT, key_points JSONB, sources JSONB, created_at TIMESTAMPTZ` |
| **Frontend Web** | Twig + Stimulus + Turbo | Bouton "GENERATE AI SUMMARY" → fetch API → Turbo Frame update → affichage bloc "BRIEFLY AI:" |
| **Sécurité** | CSRF + validation URL | Token CSRF sur endpoint, validation format URL (filter_var FILTER_VALIDATE_URL), OWASP A01 (pas de SSRF vers IP privées) |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Génération réussie

```gherkin
Scenario: Génération d'une synthèse IA sur URL valide et accessible
  GIVEN Thomas est connecté sur la plateforme web Briefly
  AND il consulte la fiche d'un article dont l'URL source est accessible publiquement
  WHEN il clique sur le bouton "GENERATE AI SUMMARY"
  THEN une synthèse est affichée en moins de 10 secondes
  AND elle débute par le préfixe "BRIEFLY AI:"
  AND elle contient un condensé entre 180 et 220 mots
  AND elle liste exactement 3 points clés numérotés (01 / 02 / 03)
  AND elle cite au moins une source nommée
  AND un lien cliquable "OUVRIR L'ORIGINAL" pointant vers l'URL source originale est présent
  AND le résultat est persisté dans la table synthesis_results avec url_hash et created_at
```

### Scénario alternatif 1 — Article partiellement accessible (paywall)

```gherkin
Scenario: Synthèse sur article partiellement derrière un paywall
  GIVEN Thomas fournit l'URL d'un article dont seul le chapeau (lead) est accessible sans abonnement
  WHEN il clique sur "GENERATE AI SUMMARY"
  THEN une synthèse est générée à partir du contenu accessible
  AND une mention "Contenu partiel — accès limité à la source" est affichée sous la synthèse
  AND le préfixe "BRIEFLY AI:" et le lien "OUVRIR L'ORIGINAL" sont toujours présents
```

### Scénario alternatif 2 — Article en langue étrangère

```gherkin
Scenario: Synthèse d'un article rédigé en anglais
  GIVEN Thomas fournit l'URL d'un article rédigé en anglais
  WHEN il clique sur "GENERATE AI SUMMARY"
  THEN la synthèse est générée dans la même langue que l'article (anglais)
  AND le préfixe "BRIEFLY AI:" est conservé
  AND les 3 points clés sont rédigés en anglais
```

### Scénario erreur 1 — URL invalide ou malformée

```gherkin
Scenario: URL malformée soumise au générateur
  GIVEN Thomas saisit manuellement une URL malformée (ex. "httpp://invalid..com")
  WHEN il clique sur "GENERATE AI SUMMARY"
  THEN un message d'erreur "URL invalide — vérifiez le format de l'adresse" est affiché sous le bouton
  AND aucun appel HTTP vers Mistral n'est effectué
  AND le code HTTP 422 est retourné par l'API
```

### Scénario erreur 2 — Timeout ou indisponibilité Mistral

```gherkin
Scenario: Mistral ne répond pas dans le délai imparti
  GIVEN Thomas clique sur "GENERATE AI SUMMARY" sur une URL valide et accessible
  AND le service Mistral met plus de 15 secondes à répondre
  WHEN la requête expire côté serveur
  THEN un message "Service temporairement indisponible — réessayez dans quelques instants" est affiché
  AND le code HTTP 503 est retourné par l'API
  AND l'erreur est loguée avec l'url_hash (jamais l'identifiant utilisateur)
  AND aucune stack trace n'est exposée dans la réponse HTTP (OWASP A05)
```

---

## Estimation & Références

- **Story Points** : 5
- **MoSCoW** : Must Have
- **Validation INVEST** :
  - [x] Independent — pas de dépendance à d'autres US en cours
  - [x] Negotiable — périmètre Sprint 1 délibérément sans cache ni quota
  - [x] Valuable — valeur immédiate : Walking Skeleton démontre le flux de bout en bout
  - [x] Estimable — flux technique connu (Symfony HttpClient + Mistral API)
  - [x] Sized — 5 pts, dans la limite de 8
  - [x] Testable — critères Gherkin vérifiables en intégration (PHPUnit + Panther)
