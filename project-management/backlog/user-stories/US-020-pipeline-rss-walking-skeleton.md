# US-020 : Pipeline RSS Walking Skeleton (fetch + dédup SHA-256 + stockage)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-020 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 8 (Fibonacci) |
| **Priorité** | Must Have (MoSCoW) |
| **Sprint** | sprint-001 (Walking Skeleton) |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que le système récupère et stocke automatiquement les articles de sources RSS fiables
**Afin de** disposer d'un flux d'actualités actualisé sans avoir à parcourir plusieurs sites moi-même.

### Conversation

- Quelles sont les 3 sources publiques retenues pour le Walking Skeleton ? Décision : TechCrunch RSS (`https://techcrunch.com/feed/`), The Verge RSS (`https://www.theverge.com/rss/index.xml`), Ars Technica RSS (`https://feeds.arstechnica.com/arstechnica/index`). Configurées via fixtures Doctrine pour Sprint 1.
- La déduplication SHA-256 porte-t-elle sur l'URL brute ou canonique ? Décision : URL canonique (normalisée : suppression des paramètres UTM, fragment, trailing slash) avant hachage.
- Quelle fréquence de planification pour Sprint 1 ? Décision : toutes les 15 minutes via `#[AsScheduledTask]` Symfony Scheduler.
- Doit-on afficher les articles ingérés dans une UI pour Sprint 1 ? Décision : une page admin lecture seule `/admin/articles` (liste paginée) suffit pour valider l'ingestion sans investissement UI supplémentaire.
- En cas d'exception FeedIo, le worker doit-il stopper ou continuer avec les autres sources ? Décision : catch par source, log de l'erreur, continuation du batch ; aucune exception ne propage au-delà du handler.
- La contrainte UNIQUE PostgreSQL suffit-elle pour la déduplication dans Sprint 1 ? Décision : oui, `ON CONFLICT (content_hash) DO NOTHING` sur la table `articles` ; SimHash titre est planifié en US-022.

### Validation INVEST

- [x] **I**ndependent : ne dépend d'aucune autre US de cet EPIC pour son implémentation (sources seed en fixtures)
- [x] **N**egotiable : fréquence du Scheduler, sources initiales et champs stockés négociables en Sprint Review
- [x] **V**aluable : fournit les articles bruts qui alimentent EPIC-001 (Daily Brief) et EPIC-002 (Synthèse IA) — zero value sans cette US
- [x] **E**stimable : stack connue (FeedIo, Symfony Scheduler, Messenger, Doctrine, PostgreSQL), estimé 8 pts par l'équipe
- [x] **S**ized : 8 pts (limite haute acceptable pour un Walking Skeleton de pipeline)
- [x] **T**estable : critères Gherkin ci-dessous, testables via PHPUnit (unit + integration) avec base de données de test

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Planificateur** | `Symfony Scheduler` | `#[AsScheduledTask(every: '15 minutes')]` sur `FetchAllSourcesCommand` |
| **Messenger** | `FetchSourceMessage` / `FetchSourceHandler` | Un message par source publié dans la queue `async` ; traitement parallèle par N workers |
| **Domain — Source** | `Source` entity | Champs : id (UUID), name, url, feed_type (rss/atom), status, last_fetched_at, last_error_at, etag, last_modified |
| **Domain — Article** | `Article` entity | Champs : id (UUID), source_id (FK), title, url, content_hash (SHA-256, UNIQUE), published_at, raw_content, fetch_at |
| **Infrastructure** | `FeedIoSourceFetcher` | Adapter FeedIo 6.x : fetch HTTP + parse Atom/RSS → `ArticleDTO[]` |
| **Infrastructure** | `DoctrineArticleRepository` | `INSERT INTO articles … ON CONFLICT (content_hash) DO NOTHING` ; index UNIQUE sur content_hash |
| **Infrastructure** | Migration Doctrine | `migrations/` : création tables `sources`, `articles`, index UNIQUE content_hash |
| **Admin UI** | `AdminArticleController` + Twig | Route `/admin/articles` (GET, rôle ROLE_ADMIN) : liste paginée (50/page) title + source + published_at |
| **Sécurité** | Symfony Security | `/admin/*` protégé par ROLE_ADMIN ; workers Messenger sans accès HTTP externe non nécessaire |
| **Base de données** | PostgreSQL 16 | Tables `sources` (seed 3 fixtures) et `articles` ; contrainte UNIQUE sur content_hash |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Ingestion automatique d'une source RSS valide

```gherkin
Scenario: Le Scheduler déclenche l'ingestion d'une source RSS active
  GIVEN la source "TechCrunch" est en base avec status="active" et url="https://techcrunch.com/feed/"
  AND le Scheduler Symfony est actif avec un intervalle de 15 minutes
  WHEN le cycle de 15 minutes est atteint et FetchAllSourcesCommand s'exécute
  THEN un message FetchSourceMessage est publié dans la queue Messenger pour chaque source active
  AND le FetchSourceHandler récupère le flux RSS via FeedIo (HTTP GET avec User-Agent "BrieflyAI/1.0")
  AND au moins 5 articles sont parsés et tentés d'insertion dans la table PostgreSQL "articles"
  AND chaque article inséré possède les champs : title (non vide), url (https), content_hash (SHA-256, 64 hex chars), published_at (datetime UTC), source_id, fetch_at (now())
  AND le champ last_fetched_at de la source est mis à jour avec le timestamp de fin de cycle
  AND les articles sont accessibles dans GET /admin/articles (liste paginée, role ROLE_ADMIN)
```

### Scénario alternatif 1 — Déduplication SHA-256 lors d'un second cycle

```gherkin
Scenario: Les articles déjà ingérés ne sont pas réinsérés
  GIVEN 10 articles ont été ingérés lors du cycle précédent avec leurs content_hash respectifs
  AND la contrainte UNIQUE sur content_hash est active en base
  WHEN le Scheduler déclenche un nouveau cycle d'ingestion sur la même source RSS
  AND FeedIo retourne le même flux (aucun nouvel article publié entre les deux cycles)
  THEN la requête INSERT … ON CONFLICT (content_hash) DO NOTHING est exécutée pour les 10 articles
  AND 0 nouvel article est inséré en base (count(articles) inchangé)
  AND aucune exception n'est levée
  AND le champ last_fetched_at de la source est mis à jour
```

### Scénario alternatif 2 — Source RSS avec articles partiellement nouveaux

```gherkin
Scenario: Seuls les nouveaux articles sont insérés lors d'un cycle mixte
  GIVEN une source RSS contient 20 articles dont 15 déjà en base (content_hash connus) et 5 nouveaux
  WHEN le FetchSourceHandler traite le flux
  THEN exactement 5 nouveaux articles sont insérés en base
  AND les 15 conflits sont ignorés sans erreur (ON CONFLICT DO NOTHING)
  AND le count total d'articles en base augmente de 5
```

### Scénario erreur 1 — Source RSS temporairement inaccessible (HTTP 503)

```gherkin
Scenario: Une source retourne une erreur HTTP 5xx lors de l'ingestion
  GIVEN la source "TheVerge" est en base avec status="active"
  AND le serveur RSS retourne HTTP 503 Service Unavailable
  WHEN le FetchSourceHandler tente de récupérer le flux via FeedIo
  THEN FeedIo lève une exception catchée dans le handler (try/catch)
  AND aucun article partiellement parsé n'est inséré en base
  AND un log ERROR est enregistré avec : source_id, url, http_code=503, timestamp
  AND le champ last_error_at de la source est mis à jour avec l'horodatage
  AND le worker Messenger se libère normalement pour le prochain message (pas de crash du process)
  AND les autres sources du même cycle sont traitées indépendamment
```

### Scénario erreur 2 — Flux RSS malformé (XML invalide)

```gherkin
Scenario: FeedIo reçoit un document XML non conforme à RSS/Atom
  GIVEN la source "ArsTechnica" retourne un corps HTTP 200 avec un document XML invalide (balises non fermées)
  WHEN FeedIo tente le parsing du flux
  THEN une FeedException (ou équivalent) est catchée dans le FetchSourceHandler
  AND 0 article n'est inséré en base pour ce cycle
  AND un log WARNING est enregistré avec : source_id, exception_message, raw_content_snippet (100 premiers chars)
  AND last_error_at de la source est mis à jour
  AND le Scheduler planifie normalement le prochain cycle à J+15 min
```
