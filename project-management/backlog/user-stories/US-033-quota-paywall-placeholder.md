# US-033 : Quota quotidien de synthèses et paywall placeholder

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-033 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-001 Thomas, cadre dirigeant tech |
| Story Points | 5 |
| Priorité MoSCoW | Must Have |
| Sprint | sprint-001 (Walking Skeleton) |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech connecté avec un compte free
**Je veux** voir en temps réel le nombre de synthèses IA qu'il me reste aujourd'hui et recevoir un message clair lorsque j'atteins la limite
**Afin de** comprendre la valeur de Briefly Premium et décider de m'abonner en connaissance de cause, sans être surpris par un blocage silencieux.

---

## Conversation (points à clarifier / décisions ouvertes)

- Le compteur se réinitialise-t-il à minuit UTC (proposition retenue) ou minuit heure locale de l'utilisateur ?
- En Sprint 1, le paywall est-il un Turbo Frame modal ou une page dédiée /premium-placeholder ?
- L'indicateur de quota est-il visible en permanence dans le header ou uniquement sur la page de synthèse ?
- Le quota s'applique-t-il également aux utilisateurs OAuth (oui, même logique que email) ?
- Comportement si Redis est indisponible : autoriser silencieusement ou retourner HTTP 503 (proposition : HTTP 503 fail-safe) ?
- Le quota est-il lié à l'UUID utilisateur ou à l'IP (UUID uniquement — pas contournable par VPN) ?

---

## Validation INVEST

- [x] **Independent** : la logique Redis est autonome ; dépend uniquement de l'existence d'un compte (US-030)
- [x] **Negotiable** : l'affichage de l'indicateur peut être réduit à un badge simple en Sprint 1
- [x] **Valuable** : mécanisme monétisation principal — sans quota, pas de conversion Premium
- [x] **Estimable** : `QuotaService` Redis + Turbo Frame + paywall placeholder = 5 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin SMART définis ci-dessous

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **Redis** | Clé `quota:synthesis:{user_uuid}:{YYYY-MM-DD-UTC}` ; commande `INCR` + `EXPIREAT` à minuit UTC ; TTL calculé dynamiquement |
| **Backend Symfony** | `QuotaService::consumeOrDeny(User $user): bool` ; `SynthesisController` appelle `QuotaService` avant toute génération IA |
| **API Platform** | `SynthesisResource` : avant l'opération `generate`, le `QuotaStateProcessor` vérifie le quota ; HTTP 429 + header `X-Quota-Remaining: 0` si dépassé |
| **Twig / Turbo** | Turbo Frame `quota-indicator` dans le layout header : affiche "N / 3 synthèses utilisées" ; modale Turbo `paywall-modal` injectée au déclenchement HTTP 429 |
| **Sprint 1 – placeholder** | Le CTA "Passer à Briefly Premium" dans la modale est visible mais non fonctionnel (lien `#` ou `disabled`) — Stripe Billing implémenté en US-034 |
| **Sécurité** | Le compteur est lié à `user.id` (UUID) — impossible à contourner par VPN ou multi-onglets |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Synthèse générée dans le quota

```gherkin
Scenario: Thomas génère une 2e synthèse dans son quota quotidien free
  GIVEN Thomas est authentifié avec un compte free
  AND la clé Redis "quota:synthesis:{thomas-uuid}:2026-07-28" a la valeur 1 (1 synthèse utilisée)
  WHEN Thomas demande la génération d'une synthèse IA sur un article
  THEN `QuotaService::consumeOrDeny()` exécute INCR sur la clé Redis
  AND la clé Redis passe à la valeur 2
  AND la synthèse IA est générée et affichée avec le préfixe "BRIEFLY AI:"
  AND le Turbo Frame `quota-indicator` dans le header affiche "2 / 3 synthèses utilisées"
  AND la réponse HTTP est 200
```

### Scénario alternatif 1 — 3e et dernière synthèse avec message d'avertissement

```gherkin
Scenario: Thomas consomme sa 3e synthèse quotidienne
  GIVEN la clé Redis "quota:synthesis:{thomas-uuid}:2026-07-28" a la valeur 2
  WHEN Thomas génère une 3e synthèse
  THEN la clé Redis passe à 3
  AND la synthèse est générée normalement
  AND le Turbo Frame `quota-indicator` affiche "3 / 3 synthèses utilisées"
  AND un message d'incitation "Accès illimité avec Briefly Premium – 12€/mois" apparaît sous l'indicateur
  AND un lien "Découvrir Briefly Premium" est visible (non fonctionnel en Sprint 1 – placeholder)
```

### Scénario alternatif 2 — Compteur réinitialisé automatiquement le lendemain

```gherkin
Scenario: Le quota de Thomas se réinitialise à minuit UTC
  GIVEN Thomas a atteint la valeur 3 sur la clé "quota:synthesis:{thomas-uuid}:2026-07-27"
  AND le serveur horodate une requête de synthèse après minuit UTC (date = 2026-07-28)
  WHEN Thomas demande une nouvelle synthèse
  THEN `QuotaService` génère une nouvelle clé "quota:synthesis:{thomas-uuid}:2026-07-28"
  AND INCR crée la clé à la valeur 1
  AND EXPIREAT est positionné à la prochaine minuit UTC (timestamp précis)
  AND la synthèse est générée normalement
  AND l'indicateur affiche "1 / 3 synthèses utilisées"
```

### Scénario d'erreur 1 — Paywall déclenché à la 4e tentative

```gherkin
Scenario: Thomas tente de générer une 4e synthèse – paywall placeholder déclenché
  GIVEN la clé Redis "quota:synthesis:{thomas-uuid}:2026-07-28" est à 3
  WHEN Thomas tente de générer une synthèse supplémentaire
  THEN `QuotaService::consumeOrDeny()` retourne false sans incrémenter la clé Redis
  AND le serveur retourne HTTP 429
  AND l'en-tête de réponse contient "X-Quota-Remaining: 0"
  AND la modale Turbo `paywall-modal` s'affiche avec "Vous avez utilisé vos 3 synthèses gratuites aujourd'hui"
  AND le CTA "Passer à Briefly Premium – 12€/mois" est affiché (désactivé en Sprint 1 – placeholder)
  AND aucune synthèse n'est générée ni débitée
  AND la clé Redis reste à la valeur 3
```

### Scénario d'erreur 2 — Redis indisponible (fallback fail-safe)

```gherkin
Scenario: Redis est indisponible lors d'une demande de synthèse
  GIVEN le serveur Redis est temporairement inaccessible
  WHEN Thomas demande une synthèse IA
  THEN `QuotaService` lève une `QuotaServiceUnavailableException`
  AND l'exception est interceptée par `SynthesisController`
  AND le serveur retourne HTTP 503
  AND le message "Le service est temporairement indisponible. Veuillez réessayer dans quelques instants." s'affiche
  AND l'erreur est loggée en niveau WARN avec le message "QuotaService: Redis connection failed" (sans données utilisateur sensibles)
  AND aucune synthèse n'est générée (pas de bypass du quota)
```
