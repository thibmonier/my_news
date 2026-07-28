# US-041 : Consultation du Daily Brief sur mobile

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-041 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-001 — Thomas, cadre dirigeant tech |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** consulter le Daily Brief du jour (3 histoires majeures numérotées 01/02/03 avec horodatage "LAST UPDATED") et lire un article complet avec actions retour / favori / partage,
**Afin de** couvrir mon secteur tech en moins de 15 minutes depuis mon téléphone, avec des synthèses IA sourcées et directement partageables avec mes équipes.

---

## Les 3 C

### Carte (résumé)

Onglet Flux : affichage du Daily Brief du jour en tête de liste (`GET /api/daily-briefs/latest`), 3 histoires numérotées avec titre, catégorie et horodatage "LAST UPDATED". Tap sur une histoire → lecteur d'article full-screen : titre, synthèse IA préfixée "BRIEFLY AI:" (accent émeraude #10B981), corps de l'article, lien "OUVRIR L'ORIGINAL". Actions en AppBar : retour (`←`), favori (`bookmark_border`/`bookmark`), partage natif (`share`). Pull-to-refresh sur la liste.

### Conversation

- La synthèse IA doit-elle être chargée à l'ouverture ou à la demande (lazy) ? (v1 : lazy, indicateur de chargement)
- Faut-il un CTA "Lire le brief complet" sur la card résumé ? (oui, conformément à la vision produit)
- Le partage natif doit-il partager l'URL web Briefly ou l'article source ? (URL article Briefly avec fallback source originale)
- Les synthèses des articles 4e et suivants sont-elles bloquées pour les comptes Free ? (oui, paywall EPIC-004)
- L'horodatage "LAST UPDATED" doit-il être relatif (il y a 2h) ou absolu (14:30) ? (relatif sur mobile, absolu au survol)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `DailyBriefCard`, `ArticleReader`, `ShareSheet` | Affichage, interaction |
| **Flutter** | `Riverpod` (AsyncNotifier) | State management, cache mémoire |
| **API Platform** | `GET /api/daily-briefs/latest` | Brief du jour (titre, 3 stories, horodatage) |
| **API Platform** | `GET /api/articles/{id}` | Corps article + synthèse IA |
| **API Platform** | `POST /api/articles/{id}/bookmark` | Sauvegarde favori |
| **Symfony** | `ArticleController`, `AiSummaryCache` | Synthèse IA (Redis 24h par article+niveau) |
| **PostgreSQL** | Tables `daily_briefs`, `articles`, `ai_summaries` | Persistance |
| **Sécurité OWASP** | Aucun identifiant utilisateur dans les prompts IA | RGPD, M8 Security Decisions via API |
| **IA Traçabilité** | Badge "BRIEFLY AI:", lien "OUVRIR L'ORIGINAL" | Vision produit |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Affichage du Daily Brief et lecture d'un article

```gherkin
Scenario: Consulter le Daily Brief du jour et lire la synthèse IA
  GIVEN l'utilisateur est authentifié et sur l'onglet "Flux"
    AND le Daily Brief du jour est disponible (GET /api/daily-briefs/latest retourne HTTP 200)
  WHEN l'écran Flux se charge
  THEN 3 histoires majeures sont affichées numérotées 01, 02, 03
    AND chaque histoire affiche : titre, catégorie, horodatage relatif "LAST UPDATED il y a Xh"
    AND un CTA "Lire le brief complet" est visible en bas du bloc Daily Brief
  WHEN l'utilisateur tape sur l'histoire numéro 01
  THEN l'écran lecteur s'ouvre en moins de 200 ms
    AND le titre de l'article est affiché en haut
    AND un indicateur de chargement apparaît le temps de récupérer la synthèse IA
    AND la synthèse IA s'affiche préfixée "BRIEFLY AI:" avec l'accent émeraude #10B981
    AND un lien "OUVRIR L'ORIGINAL" est visible, pointant vers l'URL source de l'article
    AND les actions retour (←), favori (bookmark_border) et partage (share) sont accessibles dans l'AppBar
```

### Scénario alternatif 1 — Brief en cours de génération

```gherkin
Scenario: Daily Brief non encore disponible en début de journée
  GIVEN l'utilisateur est authentifié et ouvre l'onglet "Flux"
    AND GET /api/daily-briefs/latest retourne HTTP 404 (brief non encore généré)
  WHEN l'écran Flux se charge
  THEN un message "Votre Daily Brief est en cours de préparation" s'affiche
    AND une animation d'attente (shimmer ou spinner) est visible
    AND les articles récents du flux RSS sont affichés en dessous comme contenu de substitution
```

### Scénario alternatif 2 — Partage natif d'un article

```gherkin
Scenario: Partager un article via la feuille de partage système
  GIVEN l'utilisateur lit un article dans le lecteur
    AND l'article a une URL canonique Briefly (https://briefly.ai/articles/{uuid})
  WHEN l'utilisateur tape l'icône partage (share) dans l'AppBar
  THEN la feuille de partage native iOS/Android s'ouvre
    AND le texte pré-rempli est : "[Titre de l'article] — via Briefly AI\n[URL Briefly]"
    AND l'utilisateur peut choisir n'importe quelle application de partage disponible
```

### Scénario d'erreur 1 — Erreur API au chargement du Flux

```gherkin
Scenario: Afficher le contenu en cache si l'API est indisponible
  GIVEN l'utilisateur est authentifié et tente d'ouvrir l'onglet "Flux"
    AND GET /api/daily-briefs/latest retourne HTTP 503 (service indisponible)
    AND un Daily Brief en cache local (Hive/Isar) existe pour la journée en cours
  WHEN le chargement échoue
  THEN le brief en cache est affiché avec une bannière "Contenu hors connexion — dernière mise à jour [horodatage]"
    AND les actions de lecture sont disponibles normalement
    AND le pull-to-refresh est disponible pour retenter le chargement
```

### Scénario d'erreur 2 — Synthèse IA indisponible

```gherkin
Scenario: Afficher l'article source si la synthèse IA n'est pas disponible
  GIVEN l'utilisateur a ouvert le lecteur d'article
    AND GET /api/articles/{id} retourne HTTP 200 mais le champ ai_summary est null
  WHEN le lecteur tente d'afficher la synthèse IA
  THEN aucun bloc "BRIEFLY AI:" n'est affiché
    AND un message discret "Synthèse IA non disponible" apparaît à la place
    AND l'article source complet est affiché normalement
    AND le lien "OUVRIR L'ORIGINAL" reste toujours présent et fonctionnel
```

---

## Estimation

- **Story Points** : 5
- **MoSCoW** : Should Have
- **Complexité** : State management Riverpod + lazy loading IA + partage natif multi-plateforme

## Validation INVEST

- [x] **Independent** : Dépend de US-040 (navigation) et EPIC-001/EPIC-003 (contenu/brief) mais découpage vertical valide
- [x] **Negotiable** : Contenu CTA, partage URL, positionnement des actions discutables
- [x] **Valuable** : Fonctionnalité principale de l'app (brief quotidien mobile)
- [x] **Estimable** : 5 points, périmètre Flutter + API bien délimité
- [x] **Sized** : 5 points <= 8 pts
- [x] **Testable** : Tests widget DailyBriefCard, tests intégration GET /api/daily-briefs/latest, golden tests lecteur
