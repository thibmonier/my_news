# US-021 : Gestion CRUD sources RSS/Atom par l'administrateur

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-021 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | Administrateur de la plateforme (bénéficiaire indirect : P-002 Priya — chercheuse en stratégie, 31 ans) |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant qu'** administrateur de la plateforme Briefly AI (ROLE_ADMIN),
**Je veux** ajouter, modifier, supprimer et rechercher des sources RSS/Atom depuis l'interface d'administration, avec validation automatique du flux avant activation
**Afin de** permettre à des utilisateurs comme P-002 Priya de bénéficier de sources premium pertinentes (The Economist, HBR, MIT Tech Review) correctement validées avant ingestion.

> Note : en v1 l'accès à cette interface est réservé à ROLE_ADMIN. La possibilité pour les utilisateurs premium de suggérer des sources est prévue dans une US future.

### Conversation

- Qui peut accéder à l'administration des sources ? Décision : ROLE_ADMIN uniquement en v1. Les utilisateurs premium (comme Priya) pourront suggérer des sources dans une US future.
- La validation de la source est-elle synchrone ou asynchrone ? Décision : asynchrone via Messenger (job `ValidateSourceMessage`) pour ne pas bloquer l'UI ; la source passe par les statuts `pending_validation` → `active` ou `validation_failed`.
- L'action groupée "TOUT METTRE À JOUR" enfile-t-elle des messages pour toutes les sources ou lance-t-elle un batch direct ? Décision : publication d'un `FetchSourceMessage` par source dans la queue Messenger (traitement asynchrone, pas de blocage UI).
- La recherche dans les sources est-elle une recherche full-text ou un filtre simple ? Décision : filtre simple `ILIKE '%query%'` sur name et url pour Sprint d'implémentation ; full-text PostgreSQL envisagé ultérieurement.
- Que se passe-t-il si l'admin supprime une source qui a déjà des articles ingérés ? Décision : soft delete (status=`deleted`, deleted_at). Les articles existants sont conservés et restent liés à la source (pas de cascade delete) pour la traçabilité.
- Comment gérer les doublons d'URL lors de l'ajout ? Décision : contrainte UNIQUE sur url en base + message d'erreur Symfony Form "Cette URL est déjà enregistrée".

### Validation INVEST

- [x] **I**ndependent : dépend du schéma Source créé en US-020 mais peut être développée en parallèle (migration partagée)
- [x] **N**egotiable : champs affichés dans la liste, filtres de recherche, workflow de validation
- [x] **V**aluable : donne à l'administrateur le contrôle complet des sources ingérées, permettant de couvrir les besoins de Priya (sources premium) ; sans CRUD admin fonctionnel, le pipeline reste limité aux fixtures initiales
- [x] **E**stimable : CRUD Symfony Form + Twig/Turbo + Messenger (workflow validation), estimé 5 pts
- [x] **S**ized : 5 pts < 8 pts
- [x] **T**estable : tests PHPUnit pour les use cases CRUD, Panther pour les formulaires Twig

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Routeur** | Symfony Router | Routes `/admin/sources` (GET list), `/admin/sources/new` (GET/POST), `/admin/sources/{id}/edit` (GET/POST), `/admin/sources/{id}` (DELETE), `/admin/sources/bulk-update` (POST) |
| **Controller** | `AdminSourceController` | Actions : index (liste + recherche), new, edit, delete (soft), bulkUpdate ; ROLE_ADMIN requis |
| **Formulaire** | `SourceType` (Symfony Form) | Champs : name (string), url (url, https uniquement), feed_type (choice: rss/atom), fetch_interval_minutes (int, min=5) |
| **Domain** | `Source` entity | Ajout champ status (enum: pending_validation/active/validation_failed/deleted), deleted_at (nullable datetime) |
| **Domain** | `ValidateSourceService` | HEAD request vers l'URL, vérification Content-Type (`application/rss+xml`, `application/atom+xml`, `text/xml`) |
| **Messenger** | `ValidateSourceMessage` / `ValidateSourceHandler` | Validation asynchrone : HEAD HTTP → mise à jour status source (active ou validation_failed) |
| **Messenger** | `BulkFetchMessage` | Publie un `FetchSourceMessage` par source active lors de "TOUT METTRE À JOUR" |
| **Repository** | `SourceRepository` | Méthodes : `findAllActive()`, `search(string $query)` (ILIKE), `softDelete(Source)` |
| **Vue** | Twig `admin/sources/*.html.twig` | Liste avec pagination + filtre recherche, formulaire ajout/édition, flash messages Turbo |
| **Turbo** | Symfony UX Turbo Streams | Mise à jour inline du statut source après validation asynchrone (polling ou Turbo Stream push) |
| **Sécurité OWASP** | CSRF + Voters | Token CSRF sur chaque formulaire POST/DELETE ; Voter `SourceVoter` vérifie ROLE_ADMIN + action |
| **Base de données** | PostgreSQL | Contrainte UNIQUE sur `sources.url` ; index sur `status`, `deleted_at` |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Ajout d'une source RSS valide avec validation réussie

```gherkin
Scenario: L'admin ajoute une source RSS valide et elle s'active automatiquement
  GIVEN un utilisateur avec ROLE_ADMIN est authentifié sur /admin/sources/new
  WHEN il soumet le formulaire avec name="MIT Tech Review", url="https://www.technologyreview.com/feed/", feed_type="rss", fetch_interval_minutes=30
  THEN Symfony valide le formulaire (url HTTPS, feed_type valide, name non vide)
  AND la source est créée en base avec status="pending_validation"
  AND un message ValidateSourceMessage est publié dans la queue Messenger
  AND le ValidateSourceHandler effectue un HEAD vers "https://www.technologyreview.com/feed/"
  AND la réponse HTTP est 200 avec Content-Type contenant "rss" ou "xml"
  AND la source est mise à jour en base avec status="active"
  AND un flash message Turbo "Source MIT Tech Review activée avec succès" s'affiche dans /admin/sources
  AND la source apparaît dans la liste avec badge statut "Actif" et le nom/url configurés
```

### Scénario alternatif 1 — Action groupée "TOUT METTRE À JOUR"

```gherkin
Scenario: L'admin déclenche la mise à jour de toutes les sources actives
  GIVEN 7 sources avec status="active" sont listées dans /admin/sources
  WHEN l'admin clique le bouton "TOUT METTRE À JOUR" (POST /admin/sources/bulk-update avec token CSRF valide)
  THEN 7 messages FetchSourceMessage sont publiés dans la queue Messenger (un par source)
  AND un flash message Turbo "7 sources mises en file de mise à jour" s'affiche immédiatement
  AND aucun fetch synchrone n'est effectué (l'UI ne se bloque pas)
  AND les workers Messenger traitent les messages de façon asynchrone
```

### Scénario alternatif 2 — Recherche de sources par nom ou URL

```gherkin
Scenario: L'admin filtre la liste de sources par mot-clé
  GIVEN 20 sources sont en base dont 3 contenant "tech" dans leur nom (TechCrunch, MIT Tech Review, TechRadar)
  WHEN l'admin saisit "tech" dans le champ de recherche et soumet le filtre (GET /admin/sources?q=tech)
  THEN la liste affiche exactement 3 sources correspondantes
  AND les sources ne contenant pas "tech" dans name ou url ne sont pas affichées
  AND la pagination s'applique aux résultats filtrés (50 résultats par page max)
```

### Scénario erreur 1 — URL non HTTPS refusée à la validation formulaire

```gherkin
Scenario: Le formulaire rejette une URL HTTP non sécurisée
  GIVEN un admin tente d'ajouter une source avec url="http://insecure-feed.example.com/rss.xml"
  WHEN le formulaire est soumis (POST /admin/sources/new)
  THEN Symfony Form retourne une erreur de validation immédiate (HTTP 422 ou re-render du formulaire)
  AND le message d'erreur "Seules les sources HTTPS sont autorisées" est affiché dans le formulaire
  AND aucune entité Source n'est créée en base
  AND aucun message Messenger n'est publié
```

### Scénario erreur 2 — URL de flux inaccessible (validation asynchrone échoue)

```gherkin
Scenario: La validation asynchrone échoue car l'URL est inaccessible
  GIVEN un admin a soumis une source avec url="https://feed-inexistant.example.com/rss" (status="pending_validation")
  WHEN le ValidateSourceHandler effectue un HEAD vers l'URL et reçoit HTTP 404 ou une ConnectException
  THEN la source est mise à jour en base avec status="validation_failed"
  AND un log ERROR est enregistré : source_id, url, raison de l'échec
  AND la source apparaît dans /admin/sources avec badge "Échec de validation" en rouge
  AND la source n'est PAS incluse dans les cycles d'ingestion Scheduler
  AND l'admin peut modifier l'URL et re-déclencher la validation manuellement
```
