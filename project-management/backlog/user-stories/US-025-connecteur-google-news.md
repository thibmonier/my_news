# US-025 : Connecteur Google News (sous-canaux À la une / Technologie / Science)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-025 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | backlog |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** accéder aux actualités Google News filtrées par thème (À la une, Technologie, Science) automatiquement agrégées dans Briefly AI
**Afin de** bénéficier d'une couverture éditoriale large et cross-sources sans avoir à configurer manuellement chaque éditeur individuellement.

### Conversation

- Google News propose-t-il des flux RSS publics et stables ? Décision : oui, via `https://news.google.com/rss/topics/{TOPIC_ID}?hl=fr&gl=FR&ceid=FR:fr` pour les topics canoniques. Les IDs de topic sont stables mais peuvent évoluer — la configuration est stockée en base (pas en dur dans le code).
- Les 3 sous-canaux sont-ils des entités `Source` distinctes ou un connecteur unique ? Décision : 3 entités `Source` distinctes en base (source_type='google_news', topic='top_stories'|'technology'|'science'), chacune avec son propre cycle rate limiter et circuit breaker. Cela réutilise intégralement l'infrastructure US-020 + US-023.
- Les URLs d'articles Google News pointent vers google.com ou vers l'éditeur original ? Décision : Google News RSS fournit les URLs des éditeurs originaux dans `<link>`. La déduplication SHA-256 porte sur cette URL canonique de l'éditeur, permettant de détecter les doublons avec les sources RSS directes (TechCrunch via RSS direct ET via Google News = même content_hash).
- Comment gérer le `source_name` d'un article Google News (plusieurs éditeurs mélangés dans un même flux) ? Décision : le nom de l'éditeur est extrait du champ `<source>` RSS Google News (ou du domaine de l'URL si absent) et stocké dans `article.publisher_name`.
- Faut-il enregistrer la provenance "Google News" en plus de l'éditeur ? Décision : oui, `article.aggregator='google_news'` (nullable varchar) pour la traçabilité éditoriale. Les articles ingérés directement depuis RSS éditeur ont `aggregator=NULL`.
- RGPD : Google News collecte-t-il des données utilisateur via le flux RSS ? Décision : non, le flux RSS est public et sans cookie/tracking. Aucune donnée utilisateur Briefly AI n'est transmise à Google dans ce flux.

### Validation INVEST

- [x] **I**ndependent : réutilise l'infrastructure US-020 (pipeline) + US-023 (rate limiter) sans modifier leur code. Livrable séparément en ajoutant 3 sources en base et le champ `publisher_name`.
- [x] **N**egotiable : choix des 3 topics initiaux, fréquence de fetch par topic, ajout du champ `aggregator`
- [x] **V**aluable : multiplie la couverture de Thomas sans configuration manuelle ; signal fort pour la proposition de valeur Briefly AI
- [x] **E**stimable : migration (publisher_name, aggregator), seed 3 sources Google News, test avec RSS réel, estimé 5 pts
- [x] **S**ized : 5 pts < 8 pts
- [x] **T**estable : tests d'intégration avec RSS Google News mocké (fixture XML), vérification dédup cross-source via SHA-256

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Migration** | Doctrine Migration | Ajout colonnes `article.publisher_name VARCHAR(255) NULL` et `article.aggregator VARCHAR(50) NULL` (valeur 'google_news' ou NULL) |
| **Domain** | `Source` entity enrichie | Ajout champ `source_type ENUM('rss','atom','google_news') DEFAULT 'rss'` et `topic VARCHAR(100) NULL` |
| **Infrastructure** | `FeedIoSourceFetcher` enrichi | Extraction `publisher_name` depuis `<source>` RSS Google News (tag `<source url="…">NomEditeur</source>`) ou fallback `parse_url(article_url).host` |
| **Infrastructure** | Fixtures / DataFixture | Seed de 3 entités Source Google News : {name:'Google News - À la une', topic:'top_stories', url:'https://news.google.com/rss/…'}, {name:'Google News - Technologie', topic:'technology'}, {name:'Google News - Science', topic:'science'} ; plan_tier='free' |
| **Déduplication** | `FetchSourceHandler` | Déduplication SHA-256 sur l'URL canonique de l'éditeur (pas l'URL google.com) — même logique qu'US-020, cross-source naturelle |
| **Rate Limiting** | `RateLimiterService` (US-023) | Rate limit spécifique Google News : max 6 requêtes/heure par topic (3 sources × 6 = 18 req/h vers Google) |
| **Circuit Breaker** | `CircuitBreakerService` (US-023) | Un circuit breaker par entité Source Google News (indépendant par topic) |
| **Logging** | Monolog | Log avec champ `aggregator='google_news'`, `topic`, `publisher_name`, `articles_new`, `articles_deduped` |
| **Sécurité OWASP** | Validation URL | Les URLs générées pour Google News sont whitelistées (host=news.google.com) et non modifiables par l'utilisateur |
| **RGPD** | Logs | Aucun identifiant utilisateur dans les logs d'ingestion Google News ; `aggregator` field est éditorial, pas personnel |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Ingestion du sous-canal Technologie Google News

```gherkin
Scenario: Le pipeline ingère les articles du sous-canal Technologie Google News
  GIVEN la source "Google News - Technologie" est en base avec status="active", source_type="google_news", topic="technology"
  AND son url="https://news.google.com/rss/topics/{TECH_TOPIC_ID}?hl=fr&gl=FR&ceid=FR:fr"
  WHEN le Scheduler déclenche le FetchSourceMessage pour cette source
  THEN FeedIo récupère le flux RSS Google News avec User-Agent "BrieflyAI/1.0"
  AND au moins 10 articles sont parsés depuis le flux
  AND chaque article inséré possède : title (non vide), url (vers l'éditeur original, pas google.com), publisher_name (nom de l'éditeur extrait ou domaine), published_at, content_hash (SHA-256 sur url éditeur), aggregator='google_news', topic='technology'
  AND last_fetched_at de la source est mis à jour
```

### Scénario alternatif 1 — Ingestion des 3 sous-canaux en parallèle

```gherkin
Scenario: Les 3 sous-canaux Google News sont ingérés simultanément par les workers
  GIVEN 3 sources Google News sont en base avec status="active" (top_stories, technology, science)
  WHEN le Scheduler publie 3 FetchSourceMessage (un par source)
  THEN les 3 handlers s'exécutent en parallèle (workers distincts)
  AND les articles sont taggés respectivement topic='top_stories', topic='technology', topic='science'
  AND les 3 rate limiters Redis sont décrémentés indépendamment (1 token chacun)
  AND les articles communs à plusieurs topics sont dédupliqués par SHA-256 URL canonique
```

### Scénario alternatif 2 — Déduplication cross-source (TechCrunch direct + Google News)

```gherkin
Scenario: Un article TechCrunch déjà indexé via RSS direct est dédupliqué depuis Google News
  GIVEN l'article "Apple annonce macOS 16" de TechCrunch est déjà en base
  AND son content_hash=SHA-256("https://techcrunch.com/2026/07/28/apple-macos16/"), aggregator=NULL
  WHEN Google News retourne le même article avec url="https://techcrunch.com/2026/07/28/apple-macos16/"
  AND le FetchSourceHandler calcule SHA-256 sur cette URL canonique (identique)
  THEN la contrainte UNIQUE content_hash déclenche ON CONFLICT DO NOTHING
  AND l'article n'est PAS réinséré en doublon
  AND un log DEBUG est enregistré : action="dedup_hit", content_hash, source="google_news_technology"
  AND l'article original (aggregator=NULL) est conservé tel quel (pas de modification)
```

### Scénario erreur 1 — URL Google News RSS modifiée ou retourne HTTP 404

```gherkin
Scenario: Google News modifie l'URL d'un topic et le flux retourne 404
  GIVEN la source "Google News - Science" pointe vers une URL dont le topic_id est obsolète
  WHEN FeedIo effectue un GET et reçoit HTTP 404
  THEN une exception est catchée dans FetchSourceHandler
  AND CircuitBreakerService incrémente consecutive_errors pour cette source
  AND un log ERROR est enregistré : source_id, topic="science", http_code=404, url
  AND un message d'alerte est envoyé (log CRITICAL ou notification admin) signalant la nécessité de reconfigurer l'URL
  AND last_error_at est mis à jour sur la source
  AND les 2 autres sources Google News (top_stories, technology) continuent d'être ingérées normalement
```

### Scénario erreur 2 — Flux Google News retourne un contenu géo-bloqué (flux vide ou erreur HTTP 451)

```gherkin
Scenario: Google News retourne un flux vide ou HTTP 451 (restriction géographique)
  GIVEN le serveur d'ingestion est dans une région où Google News restreint le contenu
  WHEN FeedIo retourne HTTP 200 avec un flux RSS valide mais 0 article (<channel> vide) ou HTTP 451
  THEN le FetchSourceHandler détecte 0 articles parsés (ou HTTP 451)
  AND un log WARNING est enregistré : source_id, topic, articles_count=0 (ou http_code=451), hint="potential geo-block"
  AND le circuit breaker n'incrémente PAS consecutive_errors (ce n'est pas une erreur réseau)
  AND last_fetched_at est mis à jour (cycle considéré comme effectué)
  AND le Daily Brief exclut simplement les articles de cette source pour ce cycle sans lever d'exception
```
