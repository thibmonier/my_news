# ADR-006 — Datastores : PostgreSQL (relationnel + JSONB) + Redis (cache/sessions/files/quotas)

**Statut :** Accepté — 2026-07-28
**Auteur :** Tech Lead (CSM)
**Décideurs :** Tech Lead, Product Owner
**Références :** PRD §5.1 (NFR-001 à NFR-005), PRD §5.2 (NFR-006 à NFR-010), constraints.md (T3, T4), technical-options.md §§3,5, risks-opportunities.md (RIS-08)

---

## Contexte

Briefly AI nécessite deux catégories de persistance aux profils radicalement différents :

### Données durables (relationnelles + semi-structurées)

| Entité | Volume estimé (12 mois) | Caractéristiques |
|--------|------------------------|-----------------|
| `users` | 50 000 lignes | Relationnel strict, RGPD |
| `articles` | 3 M lignes | Texte long, rétention 90j, dédup SHA-256 |
| `sources` | 500 lignes | Config, métadonnées JSONB |
| `briefs` | 365 × N lignes | Généré quotidiennement par timezone |
| `summaries` | 1 M lignes | Cache DB 24h, lié article + niveau |
| `subscriptions` | 50 000 lignes | Stripe billing, audit trail |
| `clusters` | 100 000 lignes | HDBSCAN, référence inter-articles |

**Contraintes :** intégrité référentielle forte (abonnement → utilisateur → articles), transactions ACID (paiement Stripe, droit à l'oubli en cascade), requêtes analytiques (KPIs dashboard), dédup sur index UNIQUE (URL SHA-256), JSONB pour flex sans migration (config source, métriques per-source).

### Données éphémères à haute fréquence

| Usage Redis | TTL | Volume |
|-------------|-----|--------|
| Sessions web | 30 min (sliding) | 5 000 sessions concurrentes |
| Rate limit login | 15 min | Par IP + compte |
| Rate limit API | 1h fenêtre glissante | Par clé API |
| Cache synthèses IA | 24h | ~100 000 entrées actives |
| Quotas Free (3 synthèses/jour) | EOD UTC | 1 par utilisateur actif |
| File Messenger (jobs ingestion) | Traité < 30 min | Pics : 10 000 messages/heure |
| ETag RSS par source | 15 min | 500 entrées |
| Circuit breaker état | 5 min fenêtre | 500 entrées |

**Contraintes :** sub-milliseconde, non-persistant acceptable (sessions et rate limits recréables), pub/sub pour workers Messenger, pas de ACID.

La tension principale : doit-on utiliser une seule base polyvalente (PostgreSQL seul, ou MongoDB seul) plutôt que deux datastores à opérer ?

---

## Décision

**PostgreSQL 16+ pour toutes les données durables + Redis 7+ pour toutes les données éphémères.**

### PostgreSQL — configuration et rôles

**Rôle :** source de vérité unique pour toutes les données persistantes.

#### Schéma — principes directeurs

- **UUIDs v4** (non séquentiels) pour toutes les clés primaires exposées (NFR-011) — générés côté application via `symfony/uid`.
- **Index UNIQUE** sur `articles.url_hash` (SHA-256 de l'URL canonique sans UTM) — déduplication O(1) (FR-022).
- **JSONB** pour les champs de configuration flexibles : `sources.config` (headers custom, sélecteurs CSS, intervalles), `articles.metadata` (tags sources, scores clustering), `users.preferences` (thématiques, langue, timezone) — évite les migrations à chaque évolution de config sans sacrifier les capacités d'indexation (index GIN sur JSONB).
- **Full-text search** via `tsvector` + index GIN sur `articles.title` et `articles.content_excerpt` — recherche interne sans Elasticsearch pour le périmètre v1.
- **Partitionnement** de la table `articles` par plage de `published_at` (partitions mensuelles) — facilite les suppressions RGPD (DROP PARTITION) et réduit la taille des index sur les requêtes récentes.
- **PITR** (Point-In-Time Recovery) activé — RPO < 1 h (NFR-030). Backups daily snapshots, rétention 30 jours.

#### Doctrine ORM — règles d'usage

- **Zéro SQL natif** sauf requêtes analytiques lourdes encapsulées dans des `NativeQuery` documentées et testées.
- **Lazy loading** désactivé par défaut sur les associations — préférer `EAGER` explicite ou `JOIN FETCH` dans les repositories pour éviter les N+1.
- **Migrations Doctrine** versionnées (jamais de modification rétroactive, jamais de `DROP` sans migration dédiée et revue).
- **Connection pooling** : PgBouncer en mode transaction pour les workers Messenger (jusqu'à 10 workers, chacun avec 5 connexions max → 50 connexions PgBouncer → 10-15 connexions PostgreSQL effectives).

#### Extensions PostgreSQL utilisées

| Extension | Usage |
|-----------|-------|
| `uuid-ossp` | Génération UUID v4 natif |
| `pg_trgm` | Similarité titre (SimHash complement) |
| `unaccent` | Recherche full-text insensible aux accents |
| `pgcrypto` | Hashage côté DB si besoin (non par défaut) |

---

### Redis — configuration et rôles

**Rôle :** couche éphémère haute performance, séparée logiquement en bases numérotées (ou namespaces par clé).

#### Bases logiques Redis

| Base (DB) | Contenu | TTL |
|-----------|---------|-----|
| 0 | Sessions PHP (Symfony) | 30 min sliding |
| 1 | Rate limiting (login + API) | 15 min / 1h |
| 2 | Cache synthèses IA | 24h |
| 3 | Quotas Free (compteurs synthèses) | EOD UTC (TTL calculé au moment du set) |
| 4 | File Messenger (streams Redis) | N/A (consommé par workers) |
| 5 | ETag RSS + circuit breaker | 15 min / 5 min |

La séparation en bases permet un `FLUSHDB` ciblé en cas de besoin de reset sans affecter les autres usages, et facilite le monitoring (métriques par base).

#### Clés Redis — conventions de nommage

```
session:{session_id}
rl:login:{ip}:{account_uuid}
rl:api:{api_key}
summary:{article_uuid}:{level}
quota:free:{user_uuid}:{YYYY-MM-DD}
rss:etag:{source_uuid}
cb:{source_uuid}:failures
```

#### Redis Streams (Symfony Messenger transport)

- Transport Messenger configuré sur Redis Streams (`symfony/messenger` + `redis` transport).
- Consumer groups par type de worker : `fetch_source`, `parse_article`, `dedup_article`, `generate_brief`.
- Dead Letter Queue : messages échoués après 3 tentatives → DLQ stream `messenger.failed` + alerte.
- Persistance Redis AOF activée pour les streams Messenger uniquement (les autres usages tolèrent la perte).

#### Éviction mémoire

- Politique : `allkeys-lru` — en cas de pression mémoire, les clés les moins récemment utilisées sont évincées.
- Exceptions : les streams Messenger sont en base Redis dédiée avec `maxmemory-policy noeviction` (les jobs ne doivent pas être perdus silencieusement).
- Mémoire cible : 2 Go Redis (suffisant pour 100 000 synthèses en cache + sessions + rate limits). À réviser à 6 mois selon le hit rate réel.

---

## Alternatives considérées

### A1 — PostgreSQL seul (sans Redis)

**Pour :**
- Un seul datastore à opérer, monitorer, sauvegarder.
- PostgreSQL peut gérer les sessions (table `symfony_session`), le cache (table `cache_items`), les files de messages (`SKIP LOCKED` sur une table `jobs`).
- Transactions ACID même pour les données éphémères.

**Contre :**
- Les sessions en base PostgreSQL génèrent des écritures synchrones à chaque requête web — sous 5 000 utilisateurs concurrents (NFR-008), cela représente environ 5 000 lectures/écritures/min sur une table `symfony_session` non partitionnée : dégradation mesurable de la latence (NFR-002 : < 200 ms P95).
- Le rate limiting avec `SKIP LOCKED` + `UPDATE` en PostgreSQL est fonctionnel mais 50× plus lent que `INCR`/`EXPIRE` Redis pour des fenêtres glissantes.
- PostgreSQL `pg_notify` peut remplacer Redis Pub/Sub pour les workers Messenger, mais les Streams Redis offrent la persistance, le consumer group, et la DLQ natifs sans développement custom.
- Le cache des synthèses IA (TTL 24h, ~100 000 entrées) en PostgreSQL crée une table à rotation rapide (DELETE + INSERT permanents) avec vacuum agressif — pression sur les performances générales.
- OWASP recommande de ne pas stocker les sessions dans la même base que les données applicatives (séparation des surfaces d'attaque).

**Rejetée :** PostgreSQL seul crée des goulets d'étranglement mesurables sur les workloads à haute fréquence et courte durée de vie.

---

### A2 — MongoDB (remplacement de PostgreSQL)

**Pour :**
- JSONB natif partout — pas besoin de jongler entre relationnel et semi-structuré.
- Horizontal scaling natif (sharding).
- Flexibilité de schéma pour les métadonnées d'articles (pas de migration à chaque nouveau champ source).

**Contre :**
- Doctrine ODM (MongoDB) vs Doctrine ORM (PostgreSQL) — la stack Symfony recommandée est ORM. Changer impose une réécriture de la couche persistance.
- Les transactions ACID multi-collections sont disponibles depuis MongoDB 4.0 mais moins matures que PostgreSQL en termes de tooling et de comportement sous contention.
- L'intégrité référentielle (abonnement → utilisateur, article → source) n'est pas enforcée nativement — à gérer applicativement.
- Les requêtes analytiques (KPI dashboard : DAU, MRR, taux de conversion) sont plus complexes et moins performantes qu'en SQL.
- API Platform est optimisé pour Doctrine ORM — API Platform + Doctrine ODM est supporté mais moins documenté.
- La contrainte T3 (PostgreSQL imposé) est non négociable.

**Rejetée :** Contrainte T3 non négociable. PostgreSQL couvre tous les besoins y compris le semi-structuré via JSONB avec indexation GIN.

---

### A3 — Memcached (remplacement de Redis)

**Pour :**
- Plus simple que Redis pour du cache pur.
- Légèrement plus performant que Redis pour des opérations GET/SET simples (moindre overhead du protocole).

**Contre :**
- Pas de Streams — impossible de remplacer Redis comme transport Symfony Messenger.
- Pas de structures de données avancées (`INCR`, `EXPIRE` atomique, sets, sorted sets) nécessaires pour le rate limiting et les quotas.
- Pas de persistance (même optionnelle) — les queues Messenger seraient perdues en cas de redémarrage.
- Pas de pub/sub natif.
- Symfony `symfony/cache` supporte Memcached, mais `symfony/messenger` ne supporte pas Memcached comme transport.

**Rejetée :** Memcached ne couvre pas les besoins Streams + structures avancées. Redis est la seule solution couvrant tous les usages éphémères identifiés.

---

### A4 — PostgreSQL + Redis + Elasticsearch (ajout pour la recherche full-text)

**Pour :**
- Elasticsearch offre des capacités de recherche full-text supérieures (stemming multilingue, fuzzy search, scoring BM25 avancé).
- Utile si la recherche par mot-clé dans 3 M articles devient critique.

**Contre :**
- Troisième datastore à opérer — complexité opérationnelle disproportionnée pour le périmètre v1 (contrainte Res2 : pas de DevOps dédié).
- PostgreSQL `tsvector` + index GIN couvre 95 % des besoins de recherche v1 avec les 3 M articles prévus.
- Elasticsearch consomme 2-4 Go RAM en configuration minimale — ressource à allouer au parsing et au LLM.
- Duplication des données articles PostgreSQL ↔ Elasticsearch à maintenir (pipeline d'indexation).

**Rejetée :** Le volume v1 (3 M articles) est parfaitement géré par PostgreSQL `tsvector`. Elasticsearch est candidat pour v2 si le DAU dépasse 50 000 et que la recherche devient une feature critique.

---

### A5 — PostgreSQL + Redis + stockage objet S3 (archivage articles 90j+)

**Pour :**
- Réduit la taille de la table `articles` à 90 jours de données chaudes.
- Coût de stockage inférieur (S3 vs disque PostgreSQL).
- Exigence FR-027 (archivage ou suppression à 90j).

**Contre :**
- Ajoute un troisième composant d'infrastructure (S3/MinIO) dès v1.
- La table `articles` à 3 M lignes en 12 mois est largement dans les capacités PostgreSQL avec le partitionnement mensuel (DROP PARTITION pour la suppression RGPD, archive via `pg_dump` partiel pour l'archivage froid).
- Les articles archivés ne sont pas exposés aux utilisateurs v1 — l'accès S3 est interne uniquement.
- MinIO self-hosted ajoute de la maintenance.

**Décision différée :** La stratégie d'archivage froid (S3 ou suppression pure) est définie en Sprint 3 lors de l'implémentation de FR-027. En v1, la suppression à 90j suffit. L'ADR sera mis à jour à ce moment.

---

## Conséquences

### Positives

- **Performance** : Redis absorbe toutes les opérations à haute fréquence (sessions, rate limit, cache) sans charger PostgreSQL — P95 API < 200 ms tenable (NFR-002) même sous 5 000 utilisateurs concurrents.
- **Scalabilité ingestion** : Redis Streams + consumer groups permettent de scaler les workers Messenger horizontalement sans coordination complexe (NFR-006 : 500 sources/heure, NFR-007 : 10 000 articles/heure).
- **Cache hit rate IA** : la clé `summary:{article_uuid}:{level}` garantit une déduplication parfaite des appels LLM — objectif ≥ 80 % (NFR-010) avec un corpus actif de 100 000 articles.
- **RGPD** : le partitionnement de `articles` par mois simplifie la suppression des données à 90j (DROP PARTITION) sans impacter les données récentes. La suppression en cascade sur `users` est une transaction PostgreSQL unique.
- **Déduplication** : index UNIQUE PostgreSQL sur `url_hash` + rate limit Redis par source = deux barrières indépendantes contre la duplication (RIS-08).
- **Flexibilité sans migration** : JSONB sur `sources.config` permet d'ajouter des paramètres de source sans `ALTER TABLE`.

### Négatives / Points d'attention

- **Deux services à opérer** : PostgreSQL + Redis — deux points de défaillance, deux configurations de sécurité, deux systèmes de sauvegarde. Redis n'a pas besoin de backup complet (données éphémères), mais les streams Messenger en AOF nécessitent une politique de sauvegarde.
- **Cohérence cache/base** : le cache Redis de synthèses IA (24h) peut désynchroniser si un article est modifié en base dans la fenêtre de cache. Mitigation : TTL court + invalidation sur mise à jour via `EventSubscriber` Doctrine.
- **Pression mémoire Redis** : 100 000 synthèses en cache (moyenne 2 Ko/synthèse JSON) = 200 Mo base. Avec les sessions, rate limits, ETag : cible 512 Mo à 1 Go. À surveiller avec les métriques Redis `used_memory`.
- **PgBouncer** : composant supplémentaire à gérer (connection pooler) — nécessaire dès que les workers Messenger sont en parallèle (sinon épuisement des connexions PostgreSQL).
- **Partitionnement articles** : à configurer dès la migration initiale (Doctrine ne gère pas le partitionnement natif — SQL natif dans la migration).

---

## Implémentation — points d'architecture

- ORM : `doctrine/orm` + `doctrine/dbal` + driver `pdo_pgsql`.
- Redis : `predis/predis` ou extension `phpredis` (plus performant). Symfony Cache Adapter Redis + Messenger transport Redis.
- PgBouncer : image Docker `bitnami/pgbouncer` dans `docker-compose.yml`.
- Migrations : `doctrine/migrations` — convention `Version{YYYYMMDDHHMMSS}.php`.
- Monitoring : `pg_stat_statements` (PostgreSQL), `redis-cli info` via exporter Prometheus dans le dashboard admin.

---

*ADR validé en Sprint Planning Sprint 1 — 2026-07-28*
