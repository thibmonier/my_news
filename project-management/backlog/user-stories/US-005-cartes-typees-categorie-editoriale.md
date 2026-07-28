# US-005 : Cartes typées par catégorie éditoriale

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-005 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** que chaque histoire du Daily Brief affiche un badge de catégorie éditoriale visible (parmi : AI INSIGHT, GEOPOLITICS, PRODUCTIVITY, RESEARCH, SUSTAINABILITY),
**Afin de** repérer en un coup d'œil les sujets qui concernent directement mon secteur et décider en 2 secondes quelles histoires approfondir.

### Conversation

- Qui attribue la catégorie : l'IA (Mistral) ou un système de règles ? Décision v1 : classification Mistral (zéro-shot, liste fermée de 5 catégories), résultat persisté sur l'article. Si Mistral indisponible → règle de fallback par mots-clés (liste fournie par l'équipe éditorial).
- La catégorie est-elle attribuée à l'article ou à la BriefStory ? Décision : à l'article (colonne `category ENUM` sur la table `articles`), héritée par la BriefStory à l'affichage.
- Les couleurs de badge sont-elles différentes par catégorie ? Décision : couleurs distinctes (définies dans le design system) mais PAS l'émeraude #10B981 (réservé à l'IA). Exemple : AI INSIGHT → violet, GEOPOLITICS → rouge, PRODUCTIVITY → bleu, RESEARCH → orange, SUSTAINABILITY → vert foncé.
- Que faire si la classification IA est incertaine (score < 0.7) ? Décision : attribuer la catégorie avec le score le plus élevé ; mettre à jour la catégorie si le score passe en dessous de 0.5 → fallback règles-métier.
- La catégorie est-elle modifiable manuellement par un éditeur ? Décision : non pour v1 (pas de back-office éditorial). À prévoir dans EPIC-005.
- Une histoire peut-elle avoir plusieurs catégories ? Décision : non pour v1, une catégorie principale seulement (KISS).

### Validation INVEST

- [x] **I**ndependent : dépend de US-002 (articles en base) et de la colonne `category` pré-calculée par EPIC-002 (ingestion + classification)
- [x] **N**egotiable : palette de couleurs, liste des catégories (extensible v2), seuil de confiance IA
- [x] **V**aluable : P-001 Thomas gagne du temps en filtrage visuel immédiat (+signal, -bruit)
- [x] **E**stimable : colonne ENUM en base + badge Twig, estimé 3 pts (UI principalement)
- [x] **S**ized : 3 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères visuels vérifiables (badge présent, couleur correcte, catégorie dans la réponse API)

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `ArticleCategory` (enum PHP 8.1) | `AI_INSIGHT`, `GEOPOLITICS`, `PRODUCTIVITY`, `RESEARCH`, `SUSTAINABILITY` + méthode `label(): string` et `badgeColor(): string` |
| **Base de données** | PostgreSQL | Colonne `articles.category ENUM('ai_insight','geopolitics','productivity','research','sustainability') NOT NULL DEFAULT 'productivity'` |
| **Classification IA** | `ArticleClassifierService` (EPIC-002) | Appel Mistral (exécuté à l'ingestion, pas à l'affichage) — US-005 consomme le résultat déjà persisté |
| **Repository** | `DailyBriefRepository` (enrichi) | JOIN sur `articles.category` pour inclure la catégorie dans le DTO brief |
| **DTO** | `BriefStoryDTO` | Champ `category: ArticleCategory` inclus |
| **API Platform** | `GET /api/brief/today` | Champ `category` dans la réponse JSON:API (pour Flutter) |
| **Template Twig** | `components/_brief_card.html.twig` | Badge `<span class="badge badge--{{ story.category.value }}">{{ story.category.label }}</span>` |
| **CSS** | Design tokens | `.badge--ai_insight { color: var(--color-violet); }` etc. Émeraude #10B981 non utilisé ici |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Badge de catégorie affiché sur chaque histoire

```gherkin
Scenario: Affichage des badges de catégorie éditoriale sur le Daily Brief
  GIVEN les 3 histoires du Daily Brief ont toutes une catégorie assignée (colonne articles.category renseignée)
  WHEN Thomas accède à /brief
  THEN chaque histoire affiche exactement 1 badge de catégorie
  AND le badge affiche l'un des libellés suivants : "AI INSIGHT", "GEOPOLITICS", "PRODUCTIVITY", "RESEARCH" ou "SUSTAINABILITY"
  AND la couleur du badge correspond au design token de la catégorie (ex: AI INSIGHT = violet, pas émeraude)
  AND le badge est visible sur mobile (viewport < 768px) sans être tronqué
```

### Scénario alternatif 1 — 3 histoires de 3 catégories différentes (diversité éditoriale)

```gherkin
Scenario: Diversité des catégories dans le brief (signal > bruit)
  GIVEN l'algorithme de sélection (US-002) a produit 3 histoires issues de 3 clusters distincts
  AND chaque article de chaque cluster appartient à une catégorie différente
  WHEN Thomas consulte /brief
  THEN les 3 badges affichent 3 catégories différentes (ex: "AI INSIGHT", "GEOPOLITICS", "SUSTAINABILITY")
  AND aucune catégorie n'est dupliquée dans le brief du jour (cas nominal de diversité)
```

### Scénario alternatif 2 — Fallback règles-métier si catégorie non assignée

```gherkin
Scenario: Catégorie manquante sur un article — application du fallback par mots-clés
  GIVEN un article ingéré n'a pas de catégorie assignée (ex: classification IA échouée)
  WHEN BriefStoryDTO est construit pour cet article
  THEN la catégorie est déduite par règles-métier (matching liste de mots-clés par catégorie)
  AND si aucune règle ne correspond, la catégorie par défaut "PRODUCTIVITY" est appliquée
  AND un log INFO "category.fallback_applied" est enregistré avec l'article_id (UUID)
```

### Scénario d'erreur 1 — Valeur de catégorie inconnue reçue de l'API (défense en profondeur)

```gherkin
Scenario: Catégorie invalide dans la base de données (corruption ou migration partielle)
  GIVEN un article possède une valeur category = "BREAKING_NEWS" non présente dans l'enum
  WHEN le DailyBriefRepository tente de construire le DTO
  THEN une InvalidCategoryException est levée et loggée
  AND la BriefStory concernée est exclue du brief (pas d'affichage de valeur inconnue)
  AND les 2 autres histoires du brief sont affichées normalement
  AND le log ERROR contient l'article_id (UUID) et la valeur invalide, sans données personnelles
```

### Scénario d'erreur 2 — Catégorie absente du rendu Twig (régression CSS)

```gherkin
Scenario: Badge de catégorie invisible à cause d'un CSS manquant (test de non-régression UI)
  GIVEN un nouveau déploiement sans le fichier CSS des badges de catégorie
  WHEN Thomas accède à /brief (test E2E Panther)
  THEN chaque histoire affiche quand même le texte du badge (ex: "AI INSIGHT") même sans style
  AND le texte est lisible (pas de color: transparent ni de display: none dans le style inline)
  AND le test Panther `assertSelectorTextContains('.badge', 'AI INSIGHT')` passe
```
