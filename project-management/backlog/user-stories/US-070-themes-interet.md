# US-070 : Configuration de 3 thèmes d'intérêt pour le Daily Brief

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-070 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** configurer jusqu'à 3 thèmes d'intérêt dans mon profil
**Afin de** recevoir un Daily Brief pondéré sur mes sujets prioritaires dès le lendemain, sans parcourir des actualités non pertinentes.

---

## Conversation (3C)

### Points à clarifier
- Quelle est la granularité des thèmes proposés : libellés éditoriaux prédéfinis ou tags issus du moteur de clustering EPIC-002 ?
- La pondération du Daily Brief est-elle additive (plus d'articles sur le thème) ou substitutive (remplacement de rubriques généralistes) ?
- Peut-on modifier les thèmes plus d'une fois par jour ? Y a-t-il un délai de propagation affiché à l'utilisateur ?
- Les thèmes sont-ils synchronisés entre web et mobile ou gérés par plateforme ?

### Alternatives envisagées
- Laisser l'utilisateur saisir des mots-clés libres plutôt que de choisir dans une liste (plus flexible mais moins maîtrisable côté IA).
- Proposer une liste de 5 thèmes pré-cochés déduits du comportement de lecture passé (opt-in après consentement analytics US-075).

### Validation INVEST
- [x] **Independent** — ne dépend que d'EPIC-001 (auth) et EPIC-003 (sources indexées) ; les préférences sont orthogonales aux autres US de l'EPIC
- [x] **Negotiable** — granularité des thèmes et mode de pondération à affiner en refinement
- [x] **Valuable** — différenciateur fort : personnaliser le Daily Brief est la promesse de Briefly AI
- [x] **Estimable** — API PATCH + formulaire Twig/Turbo + modèle Flutter = 5 pts réalistes
- [x] **Sized** — ≤ 8 pts, pas de découpe nécessaire
- [x] **Testable** — critères Gherkin vérifiables end-to-end

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `PATCH /api/users/{uuid}/preferences` — payload `{"interests": ["IA","Cloud","Blockchain"]}` |
| **Domaine** | Entity `UserPreferences` | Champ `interests: array` (max 3 valeurs validées, liste blanche) |
| **Persistance** | PostgreSQL | Colonne JSONB `interests` dans `user_preferences` ; UUID non séquentiel |
| **Frontend web** | Twig + Turbo + Stimulus | Formulaire de sélection multi-tags (Live Component) ; feedback immédiat |
| **Frontend mobile** | Flutter | Écran "Mes intérêts" dans le profil ; `StatefulWidget` avec liste de chips |
| **Sécurité** | Symfony Voter | `UserPreferencesVoter::canEdit()` — seul l'utilisateur propriétaire modifie ses préférences ; JWT vérifié côté mobile |
| **i18n** | symfony/translation | Labels des thèmes en EN + FR (ICU) |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Sélection de 3 thèmes

```gherkin
Scenario: L'utilisateur configure 3 thèmes d'intérêt
  GIVEN Thomas est authentifié sur Briefly AI (session web)
    AND il n'a aucun thème d'intérêt configuré
  WHEN il accède à son profil > section "Mes intérêts"
    AND il sélectionne les thèmes "Intelligence Artificielle", "Cloud", "Cybersécurité"
    AND il valide avec le bouton "Enregistrer"
  THEN l'API retourne HTTP 200 avec le body {"interests": ["Intelligence Artificielle","Cloud","Cybersécurité"]}
    AND un message de confirmation "Vos préférences sont enregistrées. Votre Daily Brief sera personnalisé dès demain." est affiché
    AND le Daily Brief du lendemain 06h00 contient en priorité des articles taggés avec ces 3 thèmes
```

### Scénario alternatif 1 — Modification d'un thème existant

```gherkin
Scenario: L'utilisateur remplace un thème parmi ses 3 sélectionnés
  GIVEN Thomas est authentifié
    AND il a déjà configuré ["Intelligence Artificielle","Cloud","Cybersécurité"]
  WHEN il désélectionne "Cybersécurité" et sélectionne "Blockchain"
    AND il valide
  THEN l'API retourne HTTP 200 avec {"interests": ["Intelligence Artificielle","Cloud","Blockchain"]}
    AND la modification est effective pour le Daily Brief du lendemain
    AND la liste affichée dans le profil reflète immédiatement les nouveaux thèmes
```

### Scénario alternatif 2 — Configuration depuis l'application mobile

```gherkin
Scenario: L'utilisateur configure ses thèmes depuis l'app Flutter
  GIVEN Thomas est authentifié sur l'application mobile (JWT valide)
    AND il accède à l'écran Profil > Mes intérêts
  WHEN il sélectionne 1 seul thème "Économie Mondiale" et valide
  THEN l'API reçoit PATCH /api/users/{uuid}/preferences avec {"interests": ["Économie Mondiale"]}
    AND retourne HTTP 200
    AND l'écran affiche un bandeau de confirmation en couleur primaire
    AND les préférences sont synchronisées avec la version web (même endpoint)
```

### Scénario d'erreur 1 — Dépassement du quota de thèmes

```gherkin
Scenario: L'utilisateur tente de sélectionner plus de 3 thèmes
  GIVEN Thomas est authentifié (web ou mobile)
    AND il a déjà sélectionné 3 thèmes
  WHEN il tente d'en ajouter un 4e
  THEN le bouton de sélection du 4e thème est désactivé (disabled)
    AND un message inline "Maximum 3 thèmes autorisés" est affiché en rouge (#EF4444)
    AND aucun appel API n'est émis
    AND aucune modification n'est persistée en base
```

### Scénario d'erreur 2 — Accès non authentifié à l'endpoint

```gherkin
Scenario: Un appel non authentifié tente de modifier des préférences
  GIVEN aucun token d'authentification valide n'est présent
  WHEN une requête PATCH /api/users/{uuid}/preferences est émise
  THEN l'API retourne HTTP 401 Unauthorized avec {"error": "Authentication required"}
    AND aucune modification n'est effectuée en base
    AND l'événement est loggé (timestamp, IP, user-agent) pour audit sécurité
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — clé de différenciation produit |
| **Risque technique** | Faible — endpoint PATCH standard + formulaire Stimulus |
| **Dépendances** | EPIC-001 (auth), EPIC-003 (index des thèmes disponibles) |
