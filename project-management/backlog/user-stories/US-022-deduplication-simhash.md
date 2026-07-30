# US-022 : Déduplication avancée par SimHash de titre

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-022 |
| **EPIC parent** | EPIC-003 Gestion des Sources & Indexation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | sprint-003-consolidation |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** ne jamais voir le même article en doublon dans mon Daily Brief, même lorsqu'il est couvert par des sources différentes avec des titres légèrement reformulés
**Afin de** gagner du temps de lecture et ne recevoir que des signaux réellement nouveaux et distincts.

### Conversation

- Quel algorithme SimHash est retenu et quelle bibliothèque PHP ? Décision : SimHash à 64 bits sur les tokens normalisés du titre (minuscules, suppression stopwords FR/EN, tokenisation espace/ponctuation). Bibliothèque : `serafins/simhash` ou implémentation interne légère (< 50 lignes).
- Quel seuil de distance de Hamming pour considérer deux articles comme doublons ? Décision : distance ≤ 3 bits sur 64. Paramètre configurable via `services.yaml` (`briefly.simhash.threshold: 3`).
- Quelle fenêtre temporelle pour la comparaison ? Décision : articles publiés dans une fenêtre ±2 heures autour de `published_at` de l'article entrant. Au-delà, un article similaire est considéré comme une reprise éditoriale distincte.
- Comment stocker le SimHash pour une recherche efficace ? Décision : colonne `title_simhash BIGINT NULLABLE` sur la table `articles` ; pas d'index GiST pour v1 (volume modeste < 100K articles) — requête SQL `BIT_COUNT(title_simhash XOR ?)` avec filtre temporel sur index `published_at`.
- Que fait-on d'un article détecté comme doublon ? Décision : il est inséré en base avec `is_duplicate=TRUE` et `duplicate_of=<uuid>` (FK sur l'article original) — jamais supprimé, pour conserver la traçabilité. Le Daily Brief filtre `is_duplicate=FALSE`.
- La déduplication SimHash remplace-t-elle SHA-256 ? Décision : non, les deux coexistent. SHA-256 sur URL canonique reste la barrière primaire (même article, même URL). SimHash est la barrière secondaire (même article, URL et source différentes).

### Validation INVEST

- [x] **I**ndependent : se greffe sur le pipeline US-020 (colonne supplémentaire + enrichissement du handler), livrable séparément
- [x] **N**egotiable : seuil de distance Hamming, fenêtre temporelle, bibliothèque SimHash
- [x] **V**aluable : réduit directement la redondance perçue par Thomas, améliore la qualité du Daily Brief
- [x] **E**stimable : calcul SimHash + requête SQL BIT_COUNT + deux colonnes Doctrine, estimé 3 pts
- [x] **S**ized : 3 pts < 8 pts
- [x] **T**estable : tests unitaires sur `SimHashService`, tests d'intégration sur `FetchSourceHandler` avec fixtures pré-chargées

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Migration** | Doctrine Migration | Ajout colonnes `title_simhash BIGINT NULL`, `is_duplicate BOOLEAN NOT NULL DEFAULT FALSE`, `duplicate_of UUID NULL FK articles(id)` ; index sur `published_at` |
| **Domain Service** | `SimHashService` | `compute(string $title): int` — normalisation (minuscules, suppression stopwords, tokenisation), calcul SimHash 64 bits |
| **Domain Service** | `SimHashService::distance(int $a, int $b): int` | `BIT_COUNT($a XOR $b)` en PHP (ou via SQL selon volume) |
| **Messenger Handler** | `FetchSourceHandler` | Enrichissement du handler US-020 : après insertion article, calcul SimHash + recherche doublon potentiel dans la fenêtre ±2h |
| **Repository** | `ArticleRepository::findPotentialDuplicates(int $simhash, DateTimeImmutable $publishedAt, int $threshold)` | Requête SQL : `WHERE BIT_COUNT(title_simhash XOR :simhash) <= :threshold AND ABS(EXTRACT(EPOCH FROM published_at - :pub)) <= 7200` |
| **Domain** | `Article` entity | Ajout champs : `title_simhash`, `is_duplicate`, `duplicate_of` (nullable ManyToOne self-referential) |
| **Base de données** | PostgreSQL | Fonction `BIT_COUNT()` disponible nativement (PostgreSQL 15+) ; index B-tree sur `published_at` pour filter temporel |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Détection d'un doublon par SimHash de titre proche

```gherkin
Scenario: Un article au titre reformulé est marqué doublon
  GIVEN l'article A "Apple annonce son nouvel iPhone 17 Pro" (source: TechCrunch) est en base
  AND son title_simhash=X, published_at=14h00 UTC, is_duplicate=FALSE
  AND un article B "Apple dévoile l'iPhone 17 Pro" provenant d'ArsTechnica arrive dans le pipeline
  AND la distance Hamming entre SimHash(A) et SimHash(B) est égale à 2 (≤ seuil=3)
  AND la différence published_at entre A et B est 25 minutes (≤ 2h)
  WHEN le FetchSourceHandler traite l'article B et appelle SimHashService puis ArticleRepository::findPotentialDuplicates
  THEN l'article B est inséré en base avec is_duplicate=TRUE et duplicate_of=A.id
  AND B est exclu du Daily Brief (filtre is_duplicate=FALSE dans BriefSelectionService)
  AND un log DEBUG est enregistré : title_a, title_b, distance=2, window=25min, source_ids
```

### Scénario alternatif 1 — Articles similaires mais hors fenêtre temporelle (reprise éditoriale légitime)

```gherkin
Scenario: Deux articles similaires publiés à plus de 2h d'intervalle ne sont pas dédupliqués
  GIVEN l'article A "Apple annonce son nouvel iPhone 17 Pro" est en base, published_at=14h00 UTC J-1
  AND l'article B "Apple dévoile l'iPhone 17 Pro" arrive dans le pipeline, published_at=11h00 UTC J
  AND la différence published_at est 21 heures (> seuil 2h)
  WHEN le FetchSourceHandler calcule la fenêtre temporelle
  THEN la condition temporelle ±2h est FALSE
  AND l'article B est inséré avec is_duplicate=FALSE (article distinct)
  AND B est éligible au Daily Brief
```

### Scénario alternatif 2 — Titres similaires mais sémantiquement distincts (distance Hamming > seuil)

```gherkin
Scenario: Deux articles tech aux titres proches mais distincts ne sont pas fusionnés
  GIVEN l'article A "Apple investit massivement dans l'IA" (SimHash=X) est en base
  AND l'article B "Apple investit massivement dans la santé numérique" (distance Hamming(X, SimHash(B))=6)
  WHEN la comparaison SimHash est effectuée
  THEN la distance 6 > seuil 3
  AND l'article B est inséré avec is_duplicate=FALSE
  AND les deux articles restent distincts dans le flux
```

### Scénario erreur 1 — Titre d'article absent ou vide dans le flux RSS

```gherkin
Scenario: Un article RSS sans titre ne bloque pas l'ingestion
  GIVEN un article dont le champ <title> du flux RSS est absent ou composé uniquement d'espaces
  WHEN SimHashService::compute("") est appelé
  THEN le service retourne NULL (pas de calcul possible)
  AND title_simhash est stocké à NULL en base pour cet article
  AND aucune comparaison SimHash n'est tentée (fallback sur SHA-256 URL uniquement)
  AND l'ingestion se poursuit sans exception
  AND un log WARNING est enregistré : source_id, article_guid, "SimHash skipped: empty title"
```

### Scénario erreur 2 — Exception runtime lors du calcul SimHash (titre exotique)

```gherkin
Scenario: Une exception dans SimHashService est catchée sans bloquer le pipeline
  GIVEN un article dont le titre contient uniquement des caractères non-BMP (emojis composites, caractères CJK rares) provoquant une exception dans la normalisation
  WHEN SimHashService::compute() lève une RuntimeException
  THEN l'exception est catchée dans FetchSourceHandler (try/catch autour du calcul SimHash)
  AND l'article est inséré avec title_simhash=NULL et is_duplicate=FALSE (pas de perte de donnée)
  AND un log ERROR est enregistré : source_id, exception_class, exception_message, title_excerpt (50 chars, UTF-8 safe)
  AND le reste du batch continue normalement
```
