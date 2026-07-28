# US-071 : Exploration des sujets chauds par catégorie

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-071 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** explorer une page dédiée aux sujets chauds classés par catégorie éditoriale avec leur compteur d'articles récents
**Afin de** détecter rapidement les signaux faibles de mon secteur et les tendances émergentes sans passer par un moteur de recherche externe.

---

## Conversation (3C)

### Points à clarifier
- Fenêtre temporelle du "chaud" : dernières 24 h ? 48 h ? Configurable ?
- Le compteur affiche-t-il les articles agrégés ou uniquement les synthèses IA disponibles ?
- La recherche transverse (barre de recherche) couvre-t-elle uniquement les titres ou aussi le contenu complet des articles indexés ?
- Les catégories principales (Technologie, Science, Économie, Politique Monde) et secondaires (Culture, Santé, Sports, Espace, Crypto, Climat) sont-elles figées v1 ou configurables par l'admin ?
- Dépend-il de US-070 (préférences) pour mettre en avant les catégories de l'utilisateur en premier ?

### Alternatives envisagées
- Afficher directement les sujets chauds sur la page d'accueil (Home) plutôt que dans une page dédiée — moins de friction mais moins de profondeur.
- Générer des "trending topics" basés sur le clustering HDBSCAN (EPIC-002) pour des sujets plus fins et moins éditoriaux.

### Validation INVEST
- [x] **Independent** — peut être livré indépendamment de US-072/073/074 ; dépend de US-070 pour le tri personnalisé (optionnel)
- [x] **Negotiable** — fenêtre temporelle et profondeur de la recherche à affiner
- [x] **Valuable** — donne accès à toute la richesse de l'index sans passer par la recherche Google
- [x] **Estimable** — materialized view PostgreSQL + endpoint GET + page Twig/Turbo + écran Flutter = 5 pts
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — compteurs vérifiables, catégories testables, recherche end-to-end

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `GET /api/topics?category=&sort=trending&window=24h` ; `GET /api/search?q=&categories=` |
| **Domaine** | `Topic`, `TrendingTopicQuery` | Agrégation articles par topic + fenêtre temporelle |
| **Persistance** | PostgreSQL | Vue matérialisée `mv_trending_topics` rafraîchie toutes les heures via Symfony Scheduler ; index GIN sur `articles.topics` |
| **Frontend web** | Twig + Turbo + Stimulus | Page `/explore` : grille catégories, compteur "124 nouveaux briefs", barre de recherche transverse (Turbo Frame) |
| **Frontend mobile** | Flutter | Onglet "Explorer" : `ListView` par catégorie, chips thèmes, champ de recherche |
| **Sécurité** | Symfony | Endpoint en lecture ; authentification requise (session/JWT) ; pagination curseur obligatoire (max 50 items/page — OWASP) |
| **Performance** | Redis | Cache de la vue matérialisée `mv_trending_topics` (TTL 1 h) |
| **i18n** | symfony/translation | Libellés de catégories EN + FR (ICU) |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Affichage des sujets chauds

```gherkin
Scenario: L'utilisateur consulte la page Exploration
  GIVEN Thomas est authentifié sur Briefly AI (web)
    AND la vue matérialisée mv_trending_topics a été rafraîchie dans les 60 dernières minutes
  WHEN il accède à la page /explore
  THEN il voit les catégories principales : "Technologie", "Science", "Économie", "Politique Monde"
    AND les catégories secondaires : "Culture", "Santé", "Sports", "Espace", "Crypto", "Climat"
    AND chaque sujet chaud affiche son compteur d'articles des dernières 24 h (ex: "Intelligence Artificielle — 124 nouveaux briefs")
    AND les sujets sont triés par nombre d'articles décroissant au sein de chaque catégorie
    AND le temps de réponse de la page est inférieur à 500 ms (P95)
```

### Scénario alternatif 1 — Filtrage par catégorie unique

```gherkin
Scenario: L'utilisateur filtre sur la catégorie "Technologie"
  GIVEN Thomas est sur la page /explore
  WHEN il clique sur le filtre "Technologie"
  THEN seuls les sujets chauds de la catégorie Technologie sont affichés
    AND le compteur de résultats se met à jour immédiatement (Turbo Frame, sans rechargement)
    AND l'URL reflète le filtre actif (/explore?category=technologie)
    AND un lien "Réinitialiser les filtres" est visible
```

### Scénario alternatif 2 — Recherche transverse

```gherkin
Scenario: L'utilisateur effectue une recherche transverse
  GIVEN Thomas est sur la page /explore
  WHEN il saisit "quantum computing" dans la barre de recherche et valide
  THEN l'API GET /api/search?q=quantum+computing retourne les articles et briefs correspondants
    AND les résultats sont affichés toutes catégories confondues
    AND chaque résultat affiche : titre, catégorie, source, ancienneté (ex: "il y a 3 h")
    AND si un résultat a une synthèse IA disponible, le badge émeraude "BRIEFLY AI:" est affiché
    AND la pagination est limitée à 50 résultats par page
```

### Scénario d'erreur 1 — Catégorie inexistante dans l'URL

```gherkin
Scenario: Accès à une catégorie inexistante via URL directe
  GIVEN Thomas accède à /explore?category=inexistante
  WHEN la requête GET /api/topics?category=inexistante est émise
  THEN l'API retourne HTTP 404 avec {"error": "Catégorie inconnue : inexistante"}
    AND la page affiche un message "Cette catégorie n'existe pas" avec un lien "Voir toutes les catégories"
    AND aucune trace d'erreur interne (stack trace) n'est exposée à l'utilisateur
```

### Scénario d'erreur 2 — Utilisateur non authentifié

```gherkin
Scenario: Accès à la page Exploration sans authentification
  GIVEN aucune session ni JWT valide n'est présent
  WHEN l'utilisateur accède à /explore
  THEN il est redirigé vers /login avec le paramètre redirect_uri=/explore
    AND après connexion réussie il est renvoyé vers /explore
    AND un appel API GET /api/topics sans token retourne HTTP 401
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — porte d'entrée vers la richesse de l'index Briefly |
| **Risque technique** | Moyen — vue matérialisée + invalidation cache Redis |
| **Dépendances** | EPIC-003 (index articles + topics), US-070 (pour tri personnalisé optionnel) |
