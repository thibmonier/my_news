# US-073 : Filtrage et organisation de la bibliothèque personnelle

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-073 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-002 Priya — chercheuse en stratégie, 31 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-002 Priya, chercheuse en stratégie,
**Je veux** filtrer ma bibliothèque personnelle entre "Tous les articles" et "Synthèses uniquement" et consulter les métadonnées complètes de chaque entrée (source, ancienneté, badge IA)
**Afin de** retrouver rapidement les contenus pertinents pour ma veille transverse et distinguer les synthèses IA des articles bruts.

---

## Conversation (3C)

### Points à clarifier
- Y a-t-il un tri par défaut ? (date de sauvegarde desc recommandé)
- Les métadonnées incluent-elles le nom de la source (ex: "The Economist") et le lien vers le domaine original ?
- L'ancienneté est-elle affichée en relatif ("il y a 3 h") ou absolu (date ISO) ? Les deux ?
- La page de bibliothèque est-elle accessible offline sur mobile (cache Hive/Isar) avec les métadonnées ?
- Le filtre "SUMMARIES ONLY" est-il limité aux utilisateurs Premium ou ouvert à tous ?

### Alternatives envisagées
- Ajouter un tri par source ou par catégorie en plus du filtre type — utile pour P-002 mais complexifie l'UI (phase 2).
- Pagination infinie (scroll) vs pagination classique (numéros de page) — scroll recommandé pour une bibliothèque.

### Validation INVEST
- [x] **Independent** — dépend de US-072 (sauvegarde) mais peut être développé après en isolation
- [x] **Negotiable** — critères de tri et métadonnées affichées négociables
- [x] **Valuable** — sans filtres, la bibliothèque devient inutilisable dès 20+ articles (P-002)
- [x] **Estimable** — endpoint GET avec query params + Twig tabs + composant Flutter = 5 pts
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — filtres et métadonnées vérifiables end-to-end

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `GET /api/saved-items?filter=all\|summaries&sort=saved_at_desc&cursor=` |
| **Domaine** | `SavedItemCollection` | Jointure `saved_items` + `articles` + `ai_summaries` ; projection filtrée |
| **Persistance** | PostgreSQL | Requête avec `LEFT JOIN ai_summaries` ; filtre `WHERE ai_summaries.id IS NOT NULL` pour "SUMMARIES ONLY" ; index couvrant `(user_id, saved_at DESC)` |
| **Frontend web** | Twig + Turbo + Stimulus | Page `/library` : tabs "ALL ARTICLES" / "SUMMARIES ONLY" (Turbo Frame) ; chaque carte : titre, source, ancienneté relative, badge émeraude "AI SUMMARY" si disponible |
| **Frontend mobile** | Flutter | `DefaultTabController` avec 2 onglets ; `ListView.builder` paginé ; chip de badge IA en `Color(0xFF10B981)` |
| **Sécurité** | Symfony Voter | `SavedItemVoter::canRead()` — propriétaire uniquement ; pagination curseur obligatoire (max 50 items — OWASP A05) |
| **Traçabilité IA** | Affichage | Badge "AI SUMMARY" sur 100 % des entrées avec synthèse disponible ; lien "OUVRIR L'ORIGINAL" rattaché à la source (US-074) |
| **i18n** | symfony/translation | Libellés tabs, métadonnées, messages vides EN + FR |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Affichage de la bibliothèque avec tous les articles

```gherkin
Scenario: Priya consulte sa bibliothèque en mode "Tous les articles"
  GIVEN Priya est authentifiée et a 12 articles sauvegardés (dont 7 avec synthèse IA)
  WHEN elle accède à /library avec le filtre par défaut "ALL ARTICLES"
  THEN l'API GET /api/saved-items?filter=all&sort=saved_at_desc retourne les 12 articles paginés (max 50/page)
    AND chaque entrée affiche : titre, nom de la source, ancienneté relative ("il y a 2 h")
    AND les 7 articles avec synthèse IA portent le badge émeraude "AI SUMMARY" (#10B981)
    AND le tab "ALL ARTICLES" est actif (souligné) et le tab "SUMMARIES ONLY" est visible
```

### Scénario alternatif 1 — Filtrage sur "Synthèses uniquement"

```gherkin
Scenario: Priya filtre sa bibliothèque sur les synthèses IA uniquement
  GIVEN Priya a 12 articles sauvegardés dont 7 avec synthèse IA
    AND elle est sur la page /library
  WHEN elle clique sur le tab "SUMMARIES ONLY"
  THEN l'URL est mise à jour (/library?filter=summaries) sans rechargement complet (Turbo Frame)
    AND seuls les 7 articles ayant une synthèse IA sont affichés
    AND chaque entrée affiche le badge "AI SUMMARY" et la mention "BRIEFLY AI:" préfixant la synthèse
    AND un compteur "7 synthèses IA sauvegardées" est affiché en haut de la liste
```

### Scénario alternatif 2 — Bibliothèque vide après filtre

```gherkin
Scenario: Priya filtre sur "SUMMARIES ONLY" avec 0 synthèse sauvegardée
  GIVEN Priya a 5 articles bruts sauvegardés (aucune synthèse IA)
  WHEN elle clique sur le tab "SUMMARIES ONLY"
  THEN l'API retourne HTTP 200 avec une liste vide {"items": [], "total": 0}
    AND la page affiche l'état vide : "Aucune synthèse IA dans votre bibliothèque. Explorez les briefs pour en sauvegarder."
    AND un lien "Explorer les briefs" pointe vers /explore
    AND aucune erreur n'est affichée
```

### Scénario d'erreur 1 — Valeur de filtre invalide dans l'URL

```gherkin
Scenario: Appel API avec une valeur de filtre non reconnue
  GIVEN Priya est authentifiée
  WHEN une requête GET /api/saved-items?filter=invalide est émise
  THEN l'API retourne HTTP 422 Unprocessable Entity avec {"error": "Valeur de filtre invalide. Valeurs acceptées : all, summaries"}
    AND aucune donnée n'est retournée
    AND la page affiche un message d'erreur générique sans exposer le détail technique
```

### Scénario d'erreur 2 — Accès non authentifié à la bibliothèque

```gherkin
Scenario: Tentative d'accès à la bibliothèque sans session active
  GIVEN aucune session ni JWT valide n'est présent
  WHEN l'utilisateur accède à /library
  THEN il est redirigé vers /login?redirect_uri=/library
    AND un appel API GET /api/saved-items sans token retourne HTTP 401 avec {"error": "Authentication required"}
    AND aucune donnée de bibliothèque n'est exposée
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — sans filtres P-002 ne peut pas utiliser sa bibliothèque efficacement |
| **Risque technique** | Faible — jointure SQL standard ; pagination curseur maîtrisée |
| **Dépendances** | US-072 (sauvegarde), EPIC-002 (synthèses IA disponibles), US-074 (lien source originale) |
