# US-042 : Sauvegarde d'articles et cache offline

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-042 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-002 — Priya, chercheuse en stratégie |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-002 Priya, chercheuse en stratégie,
**Je veux** sauvegarder des articles en un geste (icône bookmark) et les retrouver dans l'onglet Sauvegardés, consultables hors connexion et synchronisés dès que le réseau revient,
**Afin de** constituer ma base de veille offline consultable en déplacement, sans perdre aucun article entre deux connexions.

---

## Les 3 C

### Carte (résumé)

Tap sur l'icône `bookmark_border` → article sauvegardé localement (Hive/Isar) + `POST /api/bookmarks` en file d'attente si hors-ligne. Onglet Sauvegardés : liste des articles sauvegardés par date de sauvegarde, accessible sans réseau. Un-save : suppression locale immédiate + `DELETE /api/bookmarks/{id}` en file d'attente. Synchronisation différée lors du retour de connexion (Connectivity Plus + Workmanager). Brief du jour également mis en cache automatiquement à chaque chargement réussi.

### Conversation

- Quelle taille maximale de cache tolérer ? (500 Mo — images exclues en v1)
- La synchronisation différée doit-elle fonctionner en arrière-plan (background sync) ? (oui, Workmanager)
- Doit-on afficher un indicateur visuel sur les articles déjà sauvegardés dans la liste Flux ? (oui, icône `bookmark` rempli)
- En cas de conflit (article supprimé côté serveur mais sauvegardé localement) : conserver local ou supprimer ? (conserver local jusqu'à la prochaine sync manuelle)
- L'export Markdown (besoin P-002) est-il dans cette US ou dans une US dédiée ? (US dédiée hors périmètre v1)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `BookmarkButton`, `SavedArticlesScreen` | Interaction, affichage offline |
| **Flutter** | Hive / Isar (base de données locale) | Stockage articles + briefs en cache |
| **Flutter** | `connectivity_plus`, `workmanager` | Détection réseau, sync différée background |
| **Flutter** | File d'attente locale (Hive) | Opérations en attente (bookmark/un-bookmark) |
| **API Platform** | `POST /api/bookmarks`, `DELETE /api/bookmarks/{id}` | Persistance bookmark côté serveur |
| **API Platform** | `GET /api/bookmarks` | Chargement initial des favoris utilisateur |
| **PostgreSQL** | Table `user_bookmarks` | Bookmark persisté par compte |
| **RGPD** | Données locales chiffrées (flutter_secure_storage pour clé Hive) | Données personnelles protégées on-device |
| **OWASP M2** | Chiffrement de la base locale (AES-256 via clé sécurisée) | Protection données au repos |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Sauvegarder un article et le retrouver hors connexion

```gherkin
Scenario: Sauvegarder un article depuis le Flux et le lire en mode avion
  GIVEN l'utilisateur est authentifié et connecté au réseau
    AND l'utilisateur consulte l'article "Les enjeux du LLM en entreprise" dans le lecteur
  WHEN l'utilisateur tape l'icône bookmark_border dans l'AppBar
  THEN l'icône passe à bookmark (rempli) en moins de 50 ms
    AND l'article est persisté localement dans Hive/Isar (texte complet + métadonnées)
    AND POST /api/bookmarks est appelé avec succès (HTTP 201)
    AND l'article apparaît dans l'onglet "Sauvegardés"
  WHEN l'utilisateur active le mode avion
    AND navigue vers l'onglet "Sauvegardés"
    AND tape sur l'article sauvegardé
  THEN l'article s'affiche intégralement depuis le cache local
    AND une bannière discrète "Mode hors connexion" est visible
    AND la synthèse IA (si précédemment chargée) est également disponible en cache
```

### Scénario alternatif 1 — Sauvegarde hors connexion avec sync différée

```gherkin
Scenario: Sauvegarder un article sans connexion réseau
  GIVEN l'utilisateur est en mode avion
    AND il lit un article dans le lecteur (depuis le cache)
  WHEN il tape l'icône bookmark_border
  THEN l'icône passe à bookmark (rempli) immédiatement
    AND l'article est sauvegardé localement dans Hive/Isar
    AND l'opération POST /api/bookmarks est ajoutée à la file d'attente locale
    AND une icône de synchronisation en attente (horloge) est visible sur l'article dans Sauvegardés
  WHEN la connexion réseau est rétablie
  THEN workmanager déclenche la synchronisation différée en arrière-plan
    AND POST /api/bookmarks est exécuté (HTTP 201)
    AND l'icône de synchronisation disparaît sur l'article
```

### Scénario alternatif 2 — Supprimer un article des sauvegardés

```gherkin
Scenario: Un-save un article depuis l'onglet Sauvegardés
  GIVEN l'utilisateur est sur l'onglet "Sauvegardés"
    AND l'article "Quantum computing 2025" est dans sa liste
  WHEN l'utilisateur swipe à gauche sur l'article (iOS) ou maintient appuyé et tape "Supprimer" (Android)
  THEN l'article est retiré de la liste Sauvegardés immédiatement (animation de disparition)
    AND l'article est supprimé du cache local Hive/Isar
    AND DELETE /api/bookmarks/{id} est appelé (ou mis en file d'attente si hors ligne)
    AND dans l'onglet Flux, l'icône bookmark de cet article repasse à bookmark_border (non sauvegardé)
```

### Scénario d'erreur 1 — Stockage local plein

```gherkin
Scenario: Gestion du dépassement de la limite de cache (500 Mo)
  GIVEN l'utilisateur a déjà 490 Mo de contenu en cache local
  WHEN il tente de sauvegarder un article de 15 Mo (articles longs avec contenu enrichi)
  THEN un dialog d'avertissement s'affiche : "Espace de stockage presque plein (500 Mo maximum). Supprimez des articles sauvegardés pour continuer."
    AND l'article n'est PAS sauvegardé
    AND un bouton "Gérer mes sauvegardes" redirige vers l'onglet Sauvegardés avec un tri par taille
```

### Scénario d'erreur 2 — Échec de synchronisation différée après 3 tentatives

```gherkin
Scenario: Abandon de la synchronisation différée après échec répété
  GIVEN l'utilisateur a 5 opérations de bookmark en file d'attente locale
    AND le réseau est disponible mais l'API retourne HTTP 503 (maintenance)
  WHEN workmanager tente la synchronisation et échoue 3 fois consécutives (back-off exponentiel : 1min, 5min, 15min)
  THEN une notification in-app discrète s'affiche : "Synchronisation en attente. Vos sauvegardes sont conservées localement."
    AND les opérations restent en file d'attente (non supprimées) pour la prochaine tentative
    AND les articles sauvegardés localement restent accessibles hors connexion normalement
```

---

## Estimation

- **Story Points** : 5
- **MoSCoW** : Should Have
- **Complexité** : File d'attente locale + Workmanager background sync + chiffrement Hive

## Validation INVEST

- [x] **Independent** : Dépend de US-040 (navigation) et US-041 (lecteur) mais verticalement indépendant
- [x] **Negotiable** : Taille max cache, stratégie de conflit, export Markdown (v2) discutables
- [x] **Valuable** : Besoin clé P-002 (veille offline en déplacement)
- [x] **Estimable** : 5 points, Hive/Workmanager scope connu
- [x] **Sized** : 5 points <= 8 pts
- [x] **Testable** : Tests unitaires file d'attente, tests widget offline mode, tests intégration sync
