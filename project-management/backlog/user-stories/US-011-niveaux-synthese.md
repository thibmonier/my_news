# US-011 : Niveaux de synthèse multi-niveaux (Concise / Detailed / Narrative)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-011 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-002 Priya — Chercheuse stratégie, 31 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |

**Dépend de :** US-010 (endpoint de synthèse), US-012 (cache Redis par niveau)

---

## User Story — Carte

**En tant que** P-002 Priya, chercheuse en stratégie,
**Je veux** choisir le niveau de synthèse (Concise / Detailed / Narrative) avant de générer
**Afin d'** adapter la profondeur d'analyse à mon contexte : survol rapide ou lecture analytique approfondie.

---

## Les 3 C

### Carte (résumé)

Sélecteur 3 niveaux sur l'UI web. Chaque niveau correspond à un prompt Mistral distinct et à une clé de cache indépendante. Concise (~200 mots, 3 points), Detailed (~500 mots, 5 points, contexte élargi), Narrative (prose éditoriale ~800 mots, angle d'analyse). Coexiste avec US-010 ; le niveau est passé dans le DTO `SynthesisRequest`.

### Conversation (notes & questions ouvertes)

- Les prompts système pour Detailed et Narrative doivent être co-définis avec le Tech Lead lors du refinement.
- Narrative : adopter le ton éditorial "fort signal, faible bruit" de Briefly (voir vision produit).
- La clé de cache Redis doit intégrer le niveau : `sha256(url + level)`.
- Doit-on afficher le niveau utilisé dans le bloc de synthèse affiché à l'utilisateur ? Oui, en badge discret.
- US-012 doit être livré en même sprint pour que le cache couvre les 3 niveaux.
- Le niveau Detailed et Narrative seront-ils réservés aux comptes Premium ? À arbitrer avec US-013 ; pour l'instant tous les niveaux sont disponibles dans le quota 3/jour.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API Platform** | `POST /api/v1/synthesis` | DTO étendu : `level: enum(concise, detailed, narrative)`, défaut = `concise` |
| **Domain** | `SynthesisLevel` value object | Enum PHP `SynthesisLevel::CONCISE`, `DETAILED`, `NARRATIVE` + `promptInstructions(): string` |
| **Infrastructure** | `MistralApiClient` | Sélection du prompt système selon le niveau ; timeout adapté (15s concise / 30s detailed / 45s narrative) |
| **PostgreSQL** | `synthesis_results.level` | Colonne VARCHAR(16) déjà prévue en US-010 |
| **Frontend Web** | Twig + Stimulus | Sélecteur radio/tabs "Concise / Detailed / Narrative" au-dessus du bouton "GENERATE AI SUMMARY" |
| **Cache** | Redis (US-012) | Clé distincte par niveau : `synthesis:{sha256(url + level)}` |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Sélection niveau Detailed

```gherkin
Scenario: Génération d'une synthèse au niveau Detailed
  GIVEN Priya est connectée et consulte la fiche d'un article
  AND le sélecteur de niveau est positionné sur "Detailed"
  WHEN elle clique sur "GENERATE AI SUMMARY"
  THEN une synthèse est affichée en moins de 30 secondes
  AND elle est préfixée "BRIEFLY AI:"
  AND elle contient entre 450 et 550 mots
  AND elle liste 5 points clés numérotés
  AND un badge "Detailed" est visible dans le bloc de synthèse
  AND le lien "OUVRIR L'ORIGINAL" est présent
```

### Scénario alternatif 1 — Niveau Narrative

```gherkin
Scenario: Génération d'une synthèse au niveau Narrative
  GIVEN Priya sélectionne le niveau "Narrative" sur un article long-format (>2000 mots)
  WHEN elle clique sur "GENERATE AI SUMMARY"
  THEN une synthèse en prose éditoriale est affichée en moins de 45 secondes
  AND elle contient entre 750 et 850 mots
  AND le ton est analytique et non factuel-brut
  AND le badge "Narrative" est visible
  AND les sources sont citées en fin de synthèse
```

### Scénario alternatif 2 — Niveau par défaut si non sélectionné

```gherkin
Scenario: Aucun niveau explicitement sélectionné
  GIVEN Thomas arrive sur une fiche article sans interagir avec le sélecteur
  WHEN il clique sur "GENERATE AI SUMMARY"
  THEN la synthèse est générée au niveau "Concise" (comportement par défaut)
  AND le badge "Concise" est visible dans le bloc de synthèse
```

### Scénario erreur 1 — Valeur de niveau invalide dans l'API

```gherkin
Scenario: Valeur de niveau non reconnue soumise à l'API
  GIVEN un client API envoie POST /api/v1/synthesis avec level = "ultra"
  WHEN la requête est reçue par API Platform
  THEN le code HTTP 422 est retourné
  AND le message d'erreur indique "level must be one of: concise, detailed, narrative"
  AND aucun appel Mistral n'est effectué
```

### Scénario erreur 2 — Timeout sur niveau Narrative (article très long)

```gherkin
Scenario: Timeout Mistral dépassé sur un article de 10 000 mots au niveau Narrative
  GIVEN Priya sélectionne "Narrative" sur un article de 10 000 mots
  AND le service Mistral met plus de 45 secondes à répondre
  WHEN la requête expire
  THEN un message "Synthèse Narrative indisponible pour ce contenu — essayez le niveau Detailed" est affiché
  AND le code HTTP 503 est retourné
  AND l'erreur est loguée avec level et url_hash (sans identifiant utilisateur)
```

---

## Estimation & Références

- **Story Points** : 5
- **MoSCoW** : Must Have
- **Validation INVEST** :
  - [x] Independent — nécessite US-010 livré (dépendance technique, pas de couplage fort)
  - [x] Negotiable — nombre de niveaux, wording et ton éditorial peuvent évoluer
  - [x] Valuable — Priya ne peut pas faire sa veille analytique avec uniquement le niveau Concise
  - [x] Estimable — extension du DTO + 3 prompts + sélecteur UI, charge maîtrisée
  - [x] Sized — 5 pts, dans la limite de 8
  - [x] Testable — critères Gherkin vérifiables en intégration (longueur, badge, timeout)
