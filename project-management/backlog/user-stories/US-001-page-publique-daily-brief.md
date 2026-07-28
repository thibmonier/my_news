# US-001 : Page web publique du Daily Brief (Walking Skeleton)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-001 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | sprint-001 (Walking Skeleton) |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas, visiteur non authentifié,
**Je veux** accéder à une page web publique affichant les 3 histoires majeures du jour numérotées 01/02/03 avec l'horodatage "LAST UPDATED [date] [heure]"
**Afin de** m'informer sur l'essentiel de l'actualité tech en moins de 15 minutes, sans créer de compte ni me connecter.

### Conversation

- La route est-elle `/brief` ou `/` (page d'accueil) ? Décision : `/brief` en v1, `/` redirige vers `/brief` côté SEO.
- Le horodatage "LAST UPDATED" doit-il afficher l'heure UTC ou locale du visiteur ? Décision : heure UTC affichée, annotation "(UTC)" pour éviter la confusion.
- Que doit afficher la page si le brief n'a pas encore été généré ce matin (accès avant 5h UTC) ? Décision : afficher le brief de la veille avec son horodatage original (jamais de page vide).
- Y a-t-il un état transitoire pendant la génération (batch en cours) ? Décision : non pour la v1 (le batch s'exécute en moins de 2 min, le brief précédent reste affiché).
- Les 3 histoires affichent-elles le texte complet de l'article ou un extrait ? Décision : titre + source + extrait (description RSS, max 280 caractères) pour le Walking Skeleton.
- Faut-il un lien "OUVRIR L'ORIGINAL" dès Sprint 1 ? Décision : oui, lien externe tracé (attribut `rel="noopener noreferrer"`) sur chaque histoire.

### Validation INVEST

- [x] **I**ndependent : ne dépend pas de l'authentification ni d'autres EPICs (lecture seule)
- [x] **N**egotiable : contenu des cartes (extrait vs plein texte) négocié en Sprint Review
- [x] **V**aluable : premier point de contact démontrant la promesse produit, testable par des vrais utilisateurs dès Sprint 1
- [x] **E**stimable : architecture connue (Symfony Controller + Twig + Turbo + PostgreSQL), estimé 5 pts par l'équipe
- [x] **S**ized : 5 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères Gherkin ci-dessous, testables via PHPUnit + Panther (E2E)

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Routeur** | Symfony Router | Route `/brief` → `BriefController::index()` (GET, publique, pas de firewall) |
| **Controller** | `BriefController` | Requête `DailyBriefRepository::findLatest()`, passe DTO à la vue |
| **Domain** | `DailyBrief` + `BriefStory` | Entités Doctrine, lecture seule ici |
| **Repository** | `DailyBriefRepository` | Query PostgreSQL : dernière entrée DailyBrief avec status = "ready", JOINed sur les 3 BriefStories |
| **Template** | Twig `brief/index.html.twig` | Affichage 01/02/03, horodatage, extraits, liens OUVRIR L'ORIGINAL |
| **Navigation** | Symfony UX Turbo | Page chargée via Turbo Drive (navigation SPA-like) |
| **SEO** | Meta tags Twig | `<title>`, `<meta description>`, `og:title`, `og:description`, `og:url` |
| **Sécurité** | Symfony Security | Route publique (IS_AUTHENTICATED_ANONYMOUSLY), pas de CSRF sur GET |
| **Headers HTTP** | NelmioCorsBundle / NelmioSecurityBundle | CSP, X-Frame-Options, HSTS, X-Content-Type-Options |
| **Base de données** | PostgreSQL | Tables : `daily_briefs`, `brief_stories`, `articles` (read-only) |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Consultation du brief du jour

```gherkin
Scenario: Affichage du Daily Brief du jour
  GIVEN un DailyBrief avec status "ready" existe en base pour la date du jour
  AND ce brief contient exactement 3 BriefStories numérotées 1, 2, 3
  WHEN Thomas (visiteur non authentifié) effectue GET /brief
  THEN la réponse HTTP est 200 avec Content-Type text/html
  AND la page affiche le titre "DAILY BRIEF"
  AND le texte "LAST UPDATED" suivi de la date et heure UTC (format "DD MMM YYYY HH:MM UTC") est visible
  AND 3 blocs sont affichés, numérotés visuellement "01", "02", "03"
  AND chaque bloc contient : titre de l'histoire, nom de la source, extrait (≤ 280 caractères)
  AND chaque bloc contient un lien "OUVRIR L'ORIGINAL" avec rel="noopener noreferrer" pointant vers l'URL source
  AND les meta tags SEO sont présents : <title>, <meta name="description">, og:title, og:url
```

### Scénario alternatif 1 — Aucun brief généré ce jour, brief de la veille affiché

```gherkin
Scenario: Brief de la veille affiché avant la génération du matin
  GIVEN aucun DailyBrief n'existe pour la date du jour
  AND un DailyBrief avec status "ready" existe pour la date d'hier (J-1)
  WHEN un visiteur effectue GET /brief
  THEN la réponse HTTP est 200
  AND la page affiche le brief de J-1 avec son horodatage "LAST UPDATED [date J-1] HH:MM UTC"
  AND aucun message d'erreur n'est affiché
```

### Scénario alternatif 2 — Navigation Turbo (SPA-like, mobile inclus)

```gherkin
Scenario: Navigation Turbo Drive depuis une autre page du site
  GIVEN Thomas consulte la page d'accueil "/" sur son téléphone mobile (viewport < 768px)
  WHEN il clique sur un lien interne vers "/brief"
  THEN Turbo Drive intercepte la navigation (pas de rechargement complet de page)
  AND la page /brief s'affiche correctement en layout responsive (1 colonne sur mobile)
  AND aucune erreur JavaScript n'est présente dans la console
  AND le rendu est complet en moins de 300ms (performance perçue Turbo)
```

### Scénario d'erreur 1 — Base de données vide (premier démarrage)

```gherkin
Scenario: Aucun brief en base (premier démarrage application)
  GIVEN la table daily_briefs est vide (aucun brief jamais généré)
  WHEN un visiteur effectue GET /brief
  THEN la réponse HTTP est 200 (pas de 404 ni de 500)
  AND la page affiche un message "Brief en cours de préparation — revenez dans quelques instants"
  AND aucune stacktrace PHP n'est visible dans la réponse HTML
  AND un log INFO "no_daily_brief_available" est enregistré (sans données personnelles)
```

### Scénario d'erreur 2 — Indisponibilité PostgreSQL

```gherkin
Scenario: Erreur de connexion PostgreSQL pendant le rendu de la page
  GIVEN la connexion à PostgreSQL est interrompue (timeout ou crash)
  WHEN Thomas effectue GET /brief
  THEN la réponse HTTP est 503
  AND la page affiche une erreur générique "Service temporairement indisponible" (sans message technique)
  AND aucune stacktrace, DSN ou credential n'est exposé dans la réponse
  AND un log ERROR est enregistré côté serveur avec le contexte technique (sans données personnelles)
  AND le header Retry-After: 60 est présent dans la réponse HTTP
```
