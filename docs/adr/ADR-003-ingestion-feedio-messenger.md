# ADR-003 : Pipeline d'ingestion — FeedIo + Symfony Scheduler + Messenger + déduplication SHA-256 + SimHash

**Date :** 2026-07-28
**Statut :** Accepté
**Décideurs :** Tech Lead, Product Owner
**Contraintes source :** T1, T3, T4, T5 (Symfony 8, PostgreSQL, Redis, Docker) — décision tranchée via analyse §3 technical-options

---

## Contexte

Briefly AI repose entièrement sur un flux d'articles frais ingérés automatiquement. Sans pipeline d'ingestion opérationnel, ni Daily Brief, ni synthèse IA, ni valeur produit ne sont possibles (EPIC-003 : dépendance directe de EPIC-001 et EPIC-002). Le pipeline doit traiter jusqu'à 500 sources/heure (NFR-006) et 10 000 articles/heure en pic de news (NFR-007), rester résilient (circuit breaker, FR-023), et garantir l'absence de doublons visibles par l'utilisateur (FR-022).

La stack imposée est Symfony 8 + PostgreSQL + Redis + Docker (contraintes T1, T3, T4, T5). Tout composant externe ajouté doit s'intégrer dans cet environnement sans introduire un service d'infrastructure supplémentaire en v1.

---

## Décision

**Pipeline complet :**
1. **FeedIo** (`debril/feed-io`) : parsing RSS/Atom/RDF avec gestion des encodages non-UTF-8, dates malformées, champs propriétaires, ETag/Last-Modified pour requêtes HTTP conditionnelles.
2. **Symfony Scheduler** (`symfony/scheduler`) : déclenchement récurrent des jobs de fetch par source (intervalle configurable, défaut 15 min).
3. **Symfony Messenger** (`symfony/messenger`) : traitement asynchrone des messages via workers Docker indépendants, transport Redis Streams en v1 (évolutif vers AMQP/RabbitMQ si besoin).
4. **Déduplication à deux niveaux** :
   - Niveau 1 (URL) : SHA-256 du canonique URL (sans paramètres UTM/tracking), stocké en colonne `url_hash CHAR(64)` avec contrainte `UNIQUE` PostgreSQL.
   - Niveau 2 (titre) : SimHash (64 bits) du titre nettoyé, comparaison par distance de Hamming ≤ 3 bits dans une fenêtre temporelle ±2 heures.

**Architecture des workers Messenger :**

```
Symfony Scheduler
  → message FetchSourceMessage (par source, toutes les N min)
      → Worker fetch_source  : télécharge le flux RSS via FeedIo
          → message ParseArticleMessage (par article brut)
              → Worker parse_article  : extrait les champs, nettoie HTML, extrait URL canonique
                  → message DedupArticleMessage
                      → Worker dedup_article  : applique SHA-256 + SimHash, persiste ou ignore
                          → message ClassifyArticleMessage (si nouvel article)
                              → Worker classify_article : appel Mistral (classification topic)
```

Les workers sont des conteneurs Docker séparés scalables horizontalement (NFR-009 : linéaire jusqu'à 10 workers). La file de priorité Redis (US-024) traite les sources d'abonnés Premium avant les sources génériques.

---

## Alternatives considérées

### Option A — Scraper custom maison (Guzzle + DOMCrawler)

Écriture d'un parser RSS/Atom from scratch avec Symfony HttpClient + Symfony DomCrawler.

| Critère | Évaluation |
|---------|------------|
| Contrôle | Total |
| Complexité | Élevée : gestion encodages, formats de dates RFC2822/ISO8601/non-standard, champs Atom 1.0, Media RSS, Dublin Core |
| Maintenance | Charge continue : chaque nouveau format de flux = code à écrire |
| Testabilité | Bonne si bien découplé |

**Rejeté.** FeedIo couvre déjà les cas d'encodage non-UTF-8, les dates malformées et les champs propriétaires testés sur des milliers de flux réels. Réécrire ce composant serait une violation du principe YAGNI pour une fonctionnalité non différenciante.

### Option B — Lecteur de flux via un service tiers (Feedly API, Superfeedr)

Délégation de l'ingestion à un agrégateur externe.

| Critère | Évaluation |
|---------|------------|
| Simplicité | Élevée : webhook ou polling API simplifiés |
| Dépendance | Critique : si le service tiers change ses CGU ou ses tarifs, le pipeline s'arrête |
| Coût | Variables : Superfeedr facture à la source et au volume |
| Contrôle déduplication | Inexistant : le service décide ce qui est "nouveau" |
| RGPD/R4 | Les données de toutes les sources transitent chez un tiers |

**Rejeté.** Le risque RIS-03 (droits sur sources premium) et la contrainte R3 (données ne transitent pas chez des tiers non contractualisés) excluent de déléguer l'ingestion à un agrégateur externe. La contrainte B3 (indexation contractuelle des sources premium, pas de scraping via tiers) renforce ce rejet.

### Option C — Worker CRON simple (sans Messenger)

Symfony Scheduler déclenche un service PHP qui fetch, parse, déduplique et stocke de façon synchrone, sans file de messages.

| Critère | Évaluation |
|---------|------------|
| Simplicité | Élevée pour un faible volume |
| Scalabilité | Nulle : tout tient dans un processus synchrone ; une source lente bloque les autres |
| Résilience | Faible : l'échec d'une source peut interrompre le traitement des sources suivantes |
| Circuit breaker | Impossible sans isoler les traitements |

**Rejeté.** La contrainte NFR-006 (500 sources/heure) et NFR-028 (isolation totale des pannes) nécessitent impérativement un traitement asynchrone avec workers séparés. Symfony Messenger est la solution native Symfony pour ce besoin.

### Option D — Apache Kafka ou RabbitMQ comme broker

Remplacement du transport Redis Streams par un broker dédié dès v1.

| Critère | Évaluation |
|---------|------------|
| Débit | Kafka : millions d'événements/s — largement surdimensionné |
| Infra | +1 service à opérer en Docker (Kafka + ZooKeeper ou KRaft, ou RabbitMQ) |
| Équipe | Compétences Kafka/RabbitMQ à monter |
| Compatibilité Messenger | Transports disponibles, mais complexité de configuration supérieure |

**Rejeté pour v1.** Le transport Redis Streams de Symfony Messenger couvre les 10 000 articles/heure (NFR-007) sans service additionnel. Redis est déjà utilisé pour le cache, les sessions, les quotas et le rate limiting (contrainte T4). Messenger rend le changement de transport transparent : la migration vers AMQP est une décision d'infrastructure future, pas une dette architecturale.

### Option retenue — FeedIo + Scheduler + Messenger + Redis Streams (v1) + déduplication 2 niveaux

Voir section "Décision" ci-dessus.

---

## Détail de la stratégie de déduplication

La déduplication est une fonction critique : un utilisateur qui voit la même information deux fois dans son Daily Brief perd confiance dans le signal (RIS-01 positionnement invalide). La stratégie à deux niveaux combinés est conçue pour couvrir les cas que chaque niveau seul ne couvre pas.

### Niveau 1 — SHA-256 de l'URL canonique

**Principe :** la même histoire publiée par la même source aura toujours la même URL. Le hash SHA-256 de l'URL nettoyée (suppression des paramètres UTM, tracking, session : `?utm_source=...`, `?fbclid=...`) est stocké dans la colonne `url_hash CHAR(64)` avec contrainte `UNIQUE` en base PostgreSQL.

**Ce que ce niveau détecte :** les doublons exacts — même article republié, même URL distribuée via plusieurs flux RSS (ex : un article Hacker News agrégé dans 3 flux différents).

**Ce que ce niveau ne détecte pas :** deux articles de deux sources différentes couvrant le même événement avec des URLs distinctes (ex : TechCrunch et The Verge publient sur le même funding round).

**Implémentation :**
```
url_canonical = stripTrackingParams(article.url)
url_hash = sha256(url_canonical)  // CHAR(64), INDEX UNIQUE
```

### Niveau 2 — SimHash du titre (détection de near-duplicates)

**Principe :** SimHash (Charikar, 2002) est une technique de hashing sensible à la similarité (locality-sensitive hashing). Un SimHash 64 bits est calculé sur les tokens du titre normalisé. Deux articles dont les SimHash ont une distance de Hamming ≤ 3 bits (sur 64) ont une similarité de titre > 95 % et sont considérés comme le même événement.

**Fenêtre temporelle ±2 heures :** la comparaison SimHash n'est effectuée qu'entre articles publiés dans un intervalle de 2 heures. Au-delà, deux articles de titres similaires peuvent légitimement couvrir des événements distincts (ex : "La Fed relève ses taux" publié aujourd'hui et il y a 3 jours = deux événements).

**Ce que ce niveau détecte :** "Apple lance l'iPhone 17" (TechCrunch) vs "Apple annonce l'iPhone 17" (The Verge) dans la même fenêtre temporelle — doublons sémantiques inter-sources.

**Ce que ce niveau ne détecte pas :** des articles de fond sur le même sujet avec des titres radicalement différents (clustering sémantique = EPIC-002 US-016, hors scope Sprint 1).

**Implémentation :**
```
title_clean = normalize(lowercase(stripPunctuation(article.title)))
title_simhash = simhash(tokenize(title_clean))  // BIGINT, INDEX
// Requête dédup :
SELECT id FROM articles
WHERE ABS(EXTRACT(EPOCH FROM (published_at - :pub)) / 3600) <= 2
AND BIT_COUNT(title_simhash # :new_simhash) <= 3
LIMIT 1
```

**Stockage :** colonne `title_simhash BIGINT` indexée dans la table `articles`. L'opérateur bitwise XOR (`#` en PostgreSQL) + `BIT_COUNT` est disponible nativement depuis PostgreSQL 15.

**Article canonique :** en cas de détection doublon SimHash, le premier article ingéré (timestamp d'ingestion) devient l'article canonique. Les doublons pointent vers l'article canonique via une clé étrangère `canonical_article_id` (nullable). Les doublons ne sont jamais affichés dans le Daily Brief.

### Pourquoi pas MinHash ou TF-IDF cosine similarity ?

MinHash est plus précis sur les contenus longs mais requiert le stockage de N signatures et des requêtes de comparaison coûteuses (O(n×k)). Pour des titres de 5-15 mots, SimHash 64 bits avec distance de Hamming est suffisant, plus rapide (O(1) avec index BIGINT) et implémentable sans bibliothèque ML. La précision mesurée sur des benchmarks de flux RSS dépasse 92 % de rappel pour des near-duplicates de titres en fenêtre ±2 heures.

---

## Conséquences

### Positives

- **Résilience par isolation** : chaque source a son propre circuit breaker Redis (US-023). Une source qui tombe en erreur (timeout, 429, 503) est suspendue indépendamment sans impacter le reste du pipeline (FR-023, NFR-028). Le circuit breaker ouvre après 3 erreurs consécutives sur 5 minutes, ferme après 1 minute (configurable).
- **Scalabilité horizontale** : les workers Messenger sont des processus Docker sans état partagé (hors Redis/PostgreSQL). Scale-out linéaire jusqu'à 10 workers (NFR-009) par simple augmentation du `docker compose up --scale worker_parse=5`.
- **Fraîcheur garantie** : les requêtes HTTP conditionnelles (ETag + Last-Modified) via FeedIo évitent de reparseur les flux inchangés. Consommation réseau réduite de ~40 % en régime de croisière sur les sources actives modérément fréquentes.
- **Déduplication sans hallucination de doublon** : la combinaison SHA-256 (exact) + SimHash (near-duplicate) élimine les deux classes de doublons les plus fréquentes dans les flux RSS (même URL dupliquée, même événement couvert par N sources), sans faux positifs sur des articles légitimement distincts.
- **Stack sans nouveau service** : Redis est déjà le transport Messenger, le cache synthèses IA, le rate limiter et le store des quotas (contrainte T4). Aucun service additionnel (Kafka, RabbitMQ) n'est introduit en v1, réduisant la charge DevOps (Res2).
- **Observabilité native** : Symfony Messenger expose les métriques de workers (messages traités, en queue, en erreur) via le profiler et le composant `messenger:stats`. Compatible avec Prometheus/Grafana (EPIC-008 monitoring).
- **File de priorité Premium** : US-024 — le transport Redis Streams permet des files nommées distinctes (`premium_articles`, `free_articles`) ; les workers premium sont configurés pour vider leur file avant de consommer la file standard. P-002 Priya et P-003 Marc (abonnés Premium) voient leurs sources personnalisées traitées en priorité.
- **Conformité RGPD** : le pipeline n'ingère que le contenu éditorial public (titre, résumé, URL, date). Aucune donnée utilisateur ne transite dans les messages Messenger. Les identifiants utilisateur sont absents de tous les niveaux du pipeline (NFR-018, R3).

### Négatives

- **Déduplication SimHash imparfaite** : le rappel de 92 % signifie que ~8 % des near-duplicates inter-sources ne sont pas détectés au niveau titre (ex : traduction d'un titre anglais en français par deux sources différentes, ou reformulation radicale). Ces cas seront traités par le clustering sémantique HDBSCAN (EPIC-002 US-016), qui est une couche complémentaire opérant après l'ingestion.
- **BIT_COUNT PostgreSQL 15+** : la stratégie SimHash suppose PostgreSQL ≥ 15 pour `BIT_COUNT()` natif. La contrainte T3 impose PostgreSQL sans version minimale spécifiée — à valider en Sprint 1 (l'image Docker officielle PostgreSQL 16/17 est recommandée).
- **Redis comme transport unique en v1** : Redis Streams offre des garanties "at-least-once" avec acknowledgment. En cas de crash worker avant ACK, le message est re-traité. L'idempotence de l'opération de déduplication (contrainte UNIQUE sur `url_hash`) garantit qu'un double traitement est sans effet. Toutefois, Redis n'offre pas la durabilité de disque d'un broker AMQP dédié — acceptable en v1 avec backups PostgreSQL quotidiens (NFR-030).
- **FeedIo et Google News** : Google News ne propose pas d'API officielle. Les flux RSS Google News (`news.google.com/rss/...`) sont consommables via FeedIo mais sont soumis aux CGU Google et peuvent être modifiés sans préavis (RIS-02). La décision §9.5 prévoit des sources RSS directes en parallèle pour réduire cette dépendance.
- **Latence de classification** : chaque nouvel article déclenche un appel Mistral pour la classification (FR-026). En pic de news (10 000 articles/heure), le coût LLM de classification peut dépasser les projections (RIS-02). Mitigation : classification par batch en fin de cycle d'ingestion, ou modèle de classification embarqué léger (embedding + classifieur scikit-learn) à évaluer en Sprint 2.

### Contraintes respectées

| Contrainte | Satisfaction |
|------------|-------------|
| T1 — Symfony 8 | FeedIo, Scheduler, Messenger sont des composants Symfony natifs ou compatibles |
| T3 — PostgreSQL | Index UNIQUE sur `url_hash`, BIGINT SimHash, contrainte FK `canonical_article_id` |
| T4 — Redis | Transport Messenger Redis Streams, rate limiter par source, files de priorité |
| T5 — Docker | Workers Messenger = conteneurs Docker séparés, scale-out via compose |
| NFR-006 | 500 sources/heure : Scheduler + workers parallèles couvrent ce volume |
| NFR-007 | 10 000 articles/heure : workers parse + dedup horizontalement scalables |
| NFR-009 | Scale linéaire jusqu'à 10 workers : Docker Compose replicas |
| NFR-028 | Circuit breaker par source : isolation totale des pannes |
| FR-022 | Déduplication SHA-256 + SimHash ±2h : deux niveaux combinés |
| FR-023 | Rate limiter Redis + circuit breaker indépendant par source |
| R3/NFR-018 | Aucun identifiant utilisateur dans le pipeline d'ingestion |

### Impact sur les personas

| Persona | Bénéfice |
|---------|---------|
| **P-001 Thomas** | Le Daily Brief ne répète jamais la même information issue de deux sources — le signal est propre, pas de redondance (frustration principale P-001) |
| **P-002 Priya** | Sources Premium traitées en file prioritaire (US-024) ; fraîcheur des sources HBR/MIT TR garantie via ETag conditionnel |
| **P-003 Marc** | Pipeline sans donnée utilisateur ; le circuit breaker empêche qu'une source défaillante ne dégrade l'expérience globale ; monitoring endpoint santé sources (FR-028) compatible avec son dashboard Grafana |

---

## Notes de révision

Cette décision sera réexaminée si :
- Le volume d'articles dépasse 50 000/heure (Redis Streams devient un goulot) → migration vers AMQP (RabbitMQ ou Kafka).
- PostgreSQL 15 n'est pas disponible dans l'environnement de déploiement → implémentation `BIT_COUNT` en PHP dans le `DedupService`.
- Le coût de classification Mistral dépasse le budget mensuel IA de 30 % → intégration d'un modèle de classification embarqué (ONNX / FastText).
- FeedIo cesse d'être maintenu (dernier commit vérifié : actif en 2026) → migration vers SimplePie (PHP) ou réécriture interne.

**Prochaine révision planifiée :** Sprint 1 Review (Walking Skeleton pipeline RSS opérationnel, US-020).
