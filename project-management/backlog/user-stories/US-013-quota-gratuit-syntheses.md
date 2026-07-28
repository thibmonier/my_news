# US-013 : Quota gratuit (3 synthèses/jour) et paywall progressif

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-013 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans (compte Free) |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |

**Dépend de :** US-010, US-011 (synthèse opérationnelle), EPIC-003 (UserEntity / Plan Free/Premium)

---

## User Story — Carte

**En tant que** P-001 Thomas en compte Free,
**Je veux** accéder à 3 synthèses gratuites par jour avant qu'un paywall s'affiche
**Afin d'** évaluer concrètement la valeur du service sur ma journée de travail avant de décider de souscrire.

---

## Les 3 C

### Carte (résumé)

Compteur Redis par utilisateur et par jour (clé `quota:synthesis:{userId}:{YYYY-MM-DD}`, TTL jusqu'à minuit UTC). À la 4e demande sur un compte Free, l'API retourne HTTP 402 et l'UI affiche une modale paywall avec CTA "Passer à Premium — 12€/mois". Les comptes Premium ne sont pas limités. La vérification quota est antérieure à l'appel Mistral.

### Conversation (notes & questions ouvertes)

- Le compteur doit s'incrémenter avant l'appel Mistral pour éviter la race condition (pré-débit).
- En cas d'échec Mistral après pré-débit, faut-il rembourser le quota ? Oui, on décrémente si l'erreur est côté serveur IA.
- Les synthèses servies depuis le cache Redis (X-Cache: HIT) comptent-elles dans le quota ? Décision : oui (consistance de l'expérience utilisateur).
- Faut-il afficher le compteur résiduel ("2 synthèses restantes aujourd'hui") à l'utilisateur ? Oui, dans le header de l'interface.
- Reset du compteur à minuit UTC (TTL dynamique Redis).
- RGPD : l'userId stocké dans Redis est un UUID non séquentiel (OWASP A01), jamais l'email.
- La modale paywall renvoie vers le parcours Stripe Billing (EPIC lié au billing).

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `SynthesisQuotaService` | `canGenerate(User): bool` + `consume(User): void` + `remaining(User): int` |
| **Infrastructure** | Redis quota | Clé `quota:synthesis:{userId}:{YYYY-MM-DD}`, INCR atomique, TTL = secondes restantes jusqu'à 23:59:59 UTC |
| **API Platform** | `SynthesisController` | Vérifie quota avant appel Mistral ; retourne HTTP 402 + `{"error": "quota_exceeded", "remaining": 0, "resets_at": "2025-XX-XXT00:00:00Z"}` |
| **PostgreSQL** | `users.plan` | Enum `free|premium`, chargé via UserEntity (EPIC-003) |
| **Frontend Web** | Twig + Turbo Modal | Compteur "X synthèses restantes" dans le header ; modale paywall sur HTTP 402 avec CTA Stripe |
| **Sécurité** | UUID utilisateur | L'UUID (non séquentiel, OWASP A01) est utilisé comme identifiant quota, jamais l'email |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Accès aux 3 premières synthèses (Free)

```gherkin
Scenario: Un utilisateur Free génère ses 3 synthèses quotidiennes
  GIVEN Thomas est connecté avec un compte Free
  AND son compteur quota pour aujourd'hui est à 0
  WHEN il génère successivement 3 synthèses sur 3 articles différents
  THEN chacune des 3 synthèses est retournée avec succès (HTTP 200)
  AND le compteur affiché dans l'interface décroît de 3 à 2, puis 1, puis 0
  AND les headers de réponse incluent "X-Quota-Remaining: 2", puis "1", puis "0"
```

### Scénario alternatif 1 — Utilisateur Premium sans limite

```gherkin
Scenario: Un utilisateur Premium génère plus de 3 synthèses dans la journée
  GIVEN Priya est connectée avec un compte Premium
  WHEN elle génère sa 4e, 5e et 10e synthèse de la journée
  THEN chaque synthèse est retournée avec succès (HTTP 200)
  AND aucune modale paywall n'est affichée
  AND le header "X-Quota-Remaining" n'est pas présent dans la réponse
```

### Scénario alternatif 2 — Remboursement quota en cas d'échec Mistral

```gherkin
Scenario: La 2e synthèse échoue côté Mistral après pré-débit du quota
  GIVEN Thomas est à 1 synthèse consommée aujourd'hui (quota = 2 restantes)
  AND le service Mistral retourne une erreur 500 lors de la 2e demande
  WHEN l'API Briefly reçoit l'erreur Mistral
  THEN le compteur quota est décrémenté (remboursé) : Thomas récupère ses 2 synthèses restantes
  AND l'API retourne HTTP 503 à l'utilisateur avec le message d'erreur approprié
```

### Scénario erreur 1 — Blocage paywall à la 4e demande Free

```gherkin
Scenario: Un utilisateur Free tente une 4e synthèse dans la journée
  GIVEN Thomas a déjà consommé ses 3 synthèses gratuites du jour
  WHEN il clique sur "GENERATE AI SUMMARY" pour un 4e article
  THEN l'API retourne HTTP 402 Payment Required
  AND le corps de la réponse contient { "error": "quota_exceeded", "remaining": 0, "resets_at": "..." }
  AND l'UI affiche une modale "Vous avez utilisé vos 3 synthèses gratuites — Passer à Premium pour un accès illimité (12€/mois)"
  AND aucun appel Mistral n'est effectué
```

### Scénario erreur 2 — Tentative de contournement du quota par manipulation de date

```gherkin
Scenario: Requête API avec en-tête de date manipulé pour tromper le quota
  GIVEN Thomas a épuisé son quota du jour
  AND il envoie une requête avec un header "X-Date: demain"
  WHEN la requête atteint SynthesisQuotaService
  THEN la date utilisée pour la clé quota est celle du serveur (UTC), jamais un header client
  AND l'API retourne HTTP 402 Payment Required
  AND l'incident est loggé comme tentative de manipulation (niveau WARNING)
```

---

## Estimation & Références

- **Story Points** : 5
- **MoSCoW** : Must Have
- **Validation INVEST** :
  - [x] Independent — dépend de l'entité User (EPIC-004 Comptes Utilisateurs) mais pas d'une autre US de l'EPIC
  - [x] Negotiable — seuil (3/jour), comportement remboursement, UX modale paywall ajustables
  - [x] Valuable — revenu direct (conversion Free → Premium) et signal de valeur pour l'utilisateur
  - [x] Estimable — pattern quota Redis connu, intégration UserPlan maîtrisée
  - [x] Sized — 5 pts, dans la limite de 8
  - [x] Testable — HTTP 402, compteur Redis, absence d'appel Mistral vérifiables en PHPUnit
