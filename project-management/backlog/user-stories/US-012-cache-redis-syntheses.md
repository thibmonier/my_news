# US-012 : Cache Redis 24h des synthèses générées

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-012 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-001 Thomas — Cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Must Have |
| **Sprint** | backlog |

**Dépend de :** US-010 (SynthesisService), US-011 (clé de cache par niveau)

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** que les synthèses déjà générées soient servies instantanément lors d'une deuxième consultation
**Afin de** ne pas attendre 5+ secondes à chaque ouverture d'un article déjà analysé, et réduire les coûts d'appels API Mistral.

---

## Les 3 C

### Carte (résumé)

Layer de cache Redis intercalé dans `SynthesisService` entre la demande et l'appel Mistral. Clé = `synthesis:{sha256(url + level)}`, TTL = 86400s (24h). Sur cache hit, retour < 100ms. Sur cache miss, appel Mistral puis écriture en cache. Pas d'invalidation manuelle en v1 (TTL suffit). Le cache ne stocke jamais d'identifiant utilisateur.

### Conversation (notes & questions ouvertes)

- La clé de cache doit inclure le niveau pour éviter les collisions entre Concise et Detailed sur la même URL.
- Redis est déjà dans la stack (voir décisions techniques) : utiliser Symfony Cache avec l'adaptateur Redis.
- Faut-il exposer un header `X-Cache: HIT|MISS` dans la réponse API ? Oui, utile pour les tests et le monitoring.
- La synthèse persistée en PostgreSQL (US-010) reste la source de vérité ; Redis est une couche de rapidité uniquement.
- Cas de rotation IP/CDN : l'URL canonique est normalisée (lowercase, paramètres triés) avant le hash pour maximiser les hits.
- TTL 24h aligné sur la fraîcheur éditoriale de Briefly (actualités du jour).

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain** | `SynthesisService` | Cache-aside pattern : `get(key) ?? callMistral() + set(key, result, TTL=86400)` |
| **Infrastructure** | `RedisSynthesisCache` | `Symfony\Contracts\Cache\CacheInterface`, clé `synthesis:` + SHA-256(url + level) |
| **API Platform** | Header de réponse | `X-Cache: HIT` ou `X-Cache: MISS` selon la provenance |
| **PostgreSQL** | Aucune modification | La table `synthesis_results` reste inchangée (source de vérité) |
| **Frontend Web** | Aucune modification visible | Transparence totale ; l'UI reçoit le même payload JSON |
| **Monitoring** | Log structuré | Cache hit/miss loggé avec `url_hash` et `level` pour analytics de coût |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Cache hit sur une synthèse déjà générée

```gherkin
Scenario: Deuxième demande de synthèse pour la même URL et le même niveau
  GIVEN Thomas a déjà généré une synthèse Concise pour l'URL "https://techcrunch.com/article-xyz"
  AND cette synthèse est présente dans Redis avec une TTL > 0
  WHEN il clique à nouveau sur "GENERATE AI SUMMARY" pour la même URL au niveau Concise
  THEN la synthèse est retournée en moins de 200ms
  AND le header de réponse contient "X-Cache: HIT"
  AND aucun appel HTTP vers le service Mistral n'est effectué
  AND le contenu retourné est identique à la première synthèse
```

### Scénario alternatif 1 — Cache miss (premier appel ou TTL expiré)

```gherkin
Scenario: Première demande ou TTL Redis expiré pour une URL donnée
  GIVEN aucune synthèse n'existe dans Redis pour l'URL "https://wired.com/article-abc" au niveau Detailed
  WHEN Priya clique sur "GENERATE AI SUMMARY"
  THEN un appel HTTP vers Mistral est effectué
  AND la synthèse résultante est écrite dans Redis avec TTL = 86400 secondes
  AND le header de réponse contient "X-Cache: MISS"
  AND la synthèse est également persistée dans synthesis_results (PostgreSQL)
```

### Scénario alternatif 2 — Niveaux différents = entrées cache distinctes

```gherkin
Scenario: Deux niveaux différents sur la même URL génèrent des entrées cache indépendantes
  GIVEN Thomas génère une synthèse Concise pour "https://hbr.org/article-def"
  WHEN Priya demande une synthèse Detailed pour la même URL
  THEN un appel Mistral est effectué (cache miss sur la clé Detailed)
  AND Redis contient deux entrées distinctes pour cette URL :
    | clé                                        | level    |
    | synthesis:sha256("https://hbr.org/...concise")   | concise  |
    | synthesis:sha256("https://hbr.org/...detailed")  | detailed |
```

### Scénario erreur 1 — Redis indisponible (fallback gracieux)

```gherkin
Scenario: Redis est inaccessible au moment de la demande
  GIVEN le service Redis est temporairement hors ligne
  WHEN Thomas clique sur "GENERATE AI SUMMARY"
  THEN SynthesisService bypasse le cache et appelle Mistral directement
  AND la synthèse est retournée normalement à l'utilisateur
  AND une erreur "cache_unavailable" est loguée (niveau WARNING) sans interrompre le flux
  AND le header de réponse contient "X-Cache: BYPASS"
```

### Scénario erreur 2 — Tentative d'injection de clé cache malveillante

```gherkin
Scenario: URL contenant des caractères de contrôle Redis dans le paramètre
  GIVEN un client API envoie une URL contenant "\r\n" ou des caractères nuls
  WHEN la requête atteint SynthesisService
  THEN l'URL est normalisée et assainie avant génération du SHA-256
  AND aucune erreur Redis de type "Wrong number of arguments" n'est levée
  AND le code HTTP 422 est retourné si l'URL reste invalide après normalisation
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Must Have
- **Validation INVEST** :
  - [x] Independent — couche technique orthogonale aux autres US
  - [x] Negotiable — TTL, stratégie d'invalidation et structure de clé ajustables
  - [x] Valuable — réduit les coûts Mistral et améliore le ressenti utilisateur
  - [x] Estimable — pattern cache-aside standard, Symfony Cache bien documenté
  - [x] Sized — 3 pts, périmètre technique clairement borné
  - [x] Testable — header X-Cache, temps de réponse, absence d'appel Mistral vérifiables
