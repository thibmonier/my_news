# US-072 : Sauvegarde d'articles dans la bibliothèque personnelle

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-072 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-002 Priya — chercheuse en stratégie, 31 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-002 Priya, chercheuse en stratégie,
**Je veux** sauvegarder en un clic un article ou une synthèse IA dans ma bibliothèque personnelle
**Afin de** constituer une base de veille organisée accessible à tout moment, sans dépendre de mon historique de navigation.

---

## Conversation (3C)

### Points à clarifier
- Sauvegarde-t-on l'article brut, la synthèse IA ou les deux (choix utilisateur) ?
- La bibliothèque est-elle accessible hors ligne (mobile) ? Si oui, quel contenu est mis en cache (titre + métadonnées vs contenu complet) ?
- Y a-t-il une limite au nombre d'éléments sauvegardés par utilisateur (Free vs Premium) ?
- La sauvegarde déclenche-t-elle un événement pour US-075 (analytics comportementaux) si l'utilisateur a donné son consentement ?

### Alternatives envisagées
- Listes de lecture nommées (type "dossiers") plutôt qu'une bibliothèque plate — plus puissant mais hors scope v1 (YAGNI).
- Export automatique vers Notion/Obsidian — hors scope v1, dépend de EPIC-006 (API publique).

### Validation INVEST
- [x] **Independent** — ne dépend pas de US-073/074 ; peut être livré seul
- [x] **Negotiable** — limite Free/Premium et contenu mis en cache à définir
- [x] **Valuable** — P-002 constitue sa veille, P-001 retrouve des articles partageables
- [x] **Estimable** — endpoint POST/DELETE + bouton Twig + état Flutter = 5 pts
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — actions CRUD vérifiables, état du bouton testable en UI

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `POST /api/saved-items` body `{"article_uuid": "..."}` ; `DELETE /api/saved-items/{uuid}` |
| **Domaine** | Entity `SavedItem` | Champs : `uuid` (UUID v7 non séquentiel), `user_id`, `article_id`, `saved_at` |
| **Persistance** | PostgreSQL | Table `saved_items` ; contrainte UNIQUE `(user_id, article_id)` ; index sur `user_id + saved_at DESC` |
| **Frontend web** | Twig + Turbo + Stimulus | Bouton "Sauvegarder" (icône signet) sur chaque carte article ; basculement visuel via Turbo Stream (sans rechargement) |
| **Frontend mobile** | Flutter | Bouton favori (icône étoile) dans la vue article ; état persisté via `SavedItemsNotifier` (Riverpod) ; optimistic update |
| **Sécurité** | Symfony Voter | `SavedItemVoter::canCreate/canDelete()` — propriétaire uniquement ; UUID non séquentiel sur l'item (OWASP A01) |
| **Événement** | Symfony EventDispatcher | `SavedItemCreated` dispatché si consentement analytics actif (opt-in US-075) |
| **i18n** | symfony/translation | Labels "Sauvegarder" / "Retirer" EN + FR |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Sauvegarde d'un article depuis le Daily Brief

```gherkin
Scenario: Priya sauvegarde un article depuis le Daily Brief
  GIVEN Priya est authentifiée sur Briefly AI (web)
    AND elle consulte le Daily Brief du jour
    AND l'article "IA Act : nouvelles obligations pour les modèles fondamentaux" est affiché
  WHEN elle clique sur le bouton "Sauvegarder" (icône signet) de cet article
  THEN l'API POST /api/saved-items retourne HTTP 201 avec {"uuid": "<uuid_item>", "saved_at": "<ISO8601>"}
    AND le bouton bascule visuellement en état "Sauvegardé" (icône pleine, couleur primaire) sans rechargement de page
    AND l'article apparaît dans sa bibliothèque personnelle (/library) immédiatement
    AND aucun identifiant utilisateur n'est inclus dans les éventuels logs IA liés à l'article
```

### Scénario alternatif 1 — Suppression d'un article déjà sauvegardé

```gherkin
Scenario: Priya retire un article de sa bibliothèque
  GIVEN Priya a l'article "IA Act : nouvelles obligations..." dans sa bibliothèque
    AND le bouton est en état "Sauvegardé"
  WHEN elle clique à nouveau sur le bouton "Sauvegardé"
  THEN l'API DELETE /api/saved-items/{uuid} retourne HTTP 204
    AND le bouton repasse en état "Sauvegarder" (icône vide)
    AND l'article disparaît de la bibliothèque sans rechargement de page
```

### Scénario alternatif 2 — Sauvegarde depuis l'application mobile avec optimistic update

```gherkin
Scenario: Priya sauvegarde un article depuis l'app Flutter sans connexion momentanée
  GIVEN Priya est authentifiée sur l'application mobile
    AND elle est en mode offline (connexion perdue)
  WHEN elle tape sur l'icône étoile d'un article
  THEN l'interface bascule immédiatement en état "Sauvegardé" (optimistic update)
    AND la requête POST /api/saved-items est mise en file d'attente locale
    AND dès que la connexion est rétablie, la requête est rejouée automatiquement
    AND en cas d'échec persistant (> 3 tentatives), un snackbar "Impossible de sauvegarder — réessayez" est affiché
    AND l'état optimiste est annulé
```

### Scénario d'erreur 1 — Double sauvegarde du même article

```gherkin
Scenario: L'utilisateur tente de sauvegarder un article déjà en bibliothèque
  GIVEN Priya a déjà sauvegardé l'article "IA Act : nouvelles obligations..."
  WHEN un second appel POST /api/saved-items avec le même article_uuid est émis (race condition possible)
  THEN l'API retourne HTTP 409 Conflict avec {"error": "Cet article est déjà dans votre bibliothèque"}
    AND aucun doublon n'est créé en base (contrainte UNIQUE respectée)
    AND l'interface reste en état "Sauvegardé" sans message d'erreur visible pour l'utilisateur
```

### Scénario d'erreur 2 — Tentative de suppression d'un item appartenant à un autre utilisateur

```gherkin
Scenario: Tentative de suppression cross-utilisateur
  GIVEN Marc est authentifié avec son propre compte
    AND l'uuid_item "abc-123" appartient à la bibliothèque de Priya
  WHEN Marc émet DELETE /api/saved-items/abc-123 avec son JWT valide
  THEN l'API retourne HTTP 403 Forbidden avec {"error": "Accès interdit"}
    AND l'item de Priya n'est pas supprimé
    AND l'événement de sécurité est loggé (user_id de Marc, ressource ciblée, timestamp)
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — crée l'habitude de retour et la fidélisation de P-002 |
| **Risque technique** | Faible — CRUD standard ; complexité mobile (optimistic update) maîtrisée |
| **Dépendances** | EPIC-001 (auth), EPIC-003 (articles indexés), US-073 (filtres bibliothèque) |
