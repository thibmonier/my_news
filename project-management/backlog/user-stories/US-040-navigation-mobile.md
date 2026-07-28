# US-040 : Squelette Flutter + Navigation 4 onglets

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-040 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-001 — Thomas, cadre dirigeant tech |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** disposer d'une application mobile avec une navigation claire en 4 onglets (Flux / Explorer / Sources / Sauvegardés) et une barre supérieure (logo Briefly AI, bascule thème, icône notifications),
**Afin de** passer d'une section à l'autre instantanément sans effort cognitif pendant mes 15 minutes de veille quotidienne.

---

## Les 3 C

### Carte (résumé)

Squelette Flutter fonctionnel : `Scaffold` + `NavigationBar` (Material 3) avec 4 destinations en bas, `AppBar` en haut (logo, bascule thème, cloche notifications). Routing via GoRouter avec deep links. Authentification JWT vérifiée au démarrage (redirection login si token absent/expiré). L'onglet Profil (`person`) est accessible depuis l'écran Compte (pas dans la barre principale).

### Conversation

- L'ordre des onglets est-il figé ou configurable par l'utilisateur ? (v1 : figé)
- Le badge notifications doit-il afficher un compteur non-lu ? (v1 : icône uniquement, pas de badge)
- Doit-on mémoriser le dernier onglet ouvert entre sessions ? (v1 : toujours démarrer sur Flux)
- Quelle est la stratégie de deep link pour les notifications push ? (GoRouter `redirect` sur `/brief/:id`)
- L'AppBar doit-elle disparaître au scroll de liste ? (v1 : non, toujours visible)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `NavigationBar` (Material 3), `AppBar`, GoRouter | Navigation, routing, deep links |
| **Flutter** | `flutter_secure_storage` | Lecture JWT au démarrage |
| **API Platform** | `GET /api/me` | Vérification token (401 → redirection login) |
| **PostgreSQL** | Table `users` | Session utilisateur (via EPIC-002) |
| **Sécurité** | Certificate pinning (production) | OWASP Mobile Top 10 - M3 |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Navigation entre les 4 onglets

```gherkin
Scenario: Navigation fluide entre les onglets de l'application
  GIVEN l'utilisateur est authentifié (JWT valide stocké dans flutter_secure_storage)
    AND l'application est ouverte sur l'onglet "Flux" (onglet par défaut)
  WHEN l'utilisateur tape sur l'onglet "Sauvegardés" dans la barre de navigation basse
  THEN l'écran "Sauvegardés" s'affiche en moins de 100 ms
    AND l'onglet "Sauvegardés" est visuellement actif (accent #10B981)
    AND l'onglet "Flux" n'est plus actif
    AND la barre supérieure affiche toujours le logo Briefly AI, l'icône thème et l'icône notifications
```

### Scénario alternatif 1 — Retour arrière Android

```gherkin
Scenario: Retour arrière Android revient à l'onglet précédent
  GIVEN l'utilisateur est sur l'onglet "Explorer"
    AND il a navigué depuis l'onglet "Flux"
  WHEN l'utilisateur appuie sur le bouton retour système Android
  THEN l'application revient à l'onglet "Flux"
    AND l'application ne se ferme pas
```

### Scénario alternatif 2 — Accès au Profil depuis l'écran Compte

```gherkin
Scenario: Profil accessible depuis l'écran Compte (pas dans la barre principale)
  GIVEN l'utilisateur est sur l'onglet "Flux"
  WHEN l'utilisateur navigue vers l'écran Compte (via lien dans un onglet ou settings)
  THEN l'écran Compte affiche l'icône Profil (person) et les informations utilisateur
    AND la barre de navigation basse reste visible avec les 4 onglets principaux
    AND l'onglet Profil n'apparaît pas dans la barre de navigation basse
```

### Scénario d'erreur 1 — Token JWT absent au démarrage

```gherkin
Scenario: Redirection vers login si aucun token valide
  GIVEN aucun JWT n'est stocké dans flutter_secure_storage (premier lancement ou déconnexion)
  WHEN l'application démarre
  THEN l'utilisateur est redirigé vers l'écran de connexion (EPIC-002)
    AND les 4 onglets de navigation ne sont pas accessibles
    AND aucune donnée utilisateur n'est chargée
```

### Scénario d'erreur 2 — Token expiré lors du démarrage

```gherkin
Scenario: Refresh automatique du token expiré au démarrage
  GIVEN un JWT expiré est stocké dans flutter_secure_storage
    AND un refresh token valide est disponible
  WHEN l'application démarre et appelle GET /api/me
  THEN l'API retourne HTTP 401
    AND l'application tente un refresh silencieux via POST /api/token/refresh
    AND si le refresh réussit, l'utilisateur accède directement à l'onglet "Flux"
    AND si le refresh échoue (refresh token expiré), l'utilisateur est redirigé vers la page de connexion avec le message "Votre session a expiré. Veuillez vous reconnecter."
```

---

## Estimation

- **Story Points** : 5
- **MoSCoW** : Should Have
- **Complexité** : GoRouter configuration + Material 3 NavigationBar + certificate pinning

## Validation INVEST

- [x] **Independent** : Fondation de l'EPIC, indépendant des autres US (bloque les suivantes)
- [x] **Negotiable** : Ordre des onglets et comportement du back button discutables
- [x] **Valuable** : Sans navigation, aucune autre US mobile n'est livrable
- [x] **Estimable** : Scope Flutter bien délimité (5 pts)
- [x] **Sized** : 5 points, dans la limite des 8 pts
- [x] **Testable** : Tests widget NavigationBar + GoRouter, tests d'intégration deep links
