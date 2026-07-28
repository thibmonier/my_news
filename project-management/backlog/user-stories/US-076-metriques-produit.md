# US-076 : Métriques produit — rétention et engagement

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-076 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-004 (à créer — Product Owner / équipe produit, usage interne admin) |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-004 (Product Owner de Briefly AI — profil admin interne, à définir dans personas.md),
**Je veux** accéder à un tableau de bord admin affichant les métriques d'engagement clés (taux de rétention J7/J30, taux de deep-dive sur les synthèses, temps gagné estimé par cohorte)
**Afin de** prendre des décisions produit basées sur des données mesurables et piloter l'amélioration continue du produit.

---

## Conversation (3C)

### Points à clarifier
- Ces métriques sont-elles basées sur les `reading_events` opt-in (US-075) uniquement ou aussi sur des métriques serveur anonymes (logs) ?
- Granularité temporelle : données journalières, hebdomadaires, en cohortes d'inscription ?
- Qui a accès au dashboard admin ? Uniquement `ROLE_ADMIN` ou aussi `ROLE_ANALYST` ?
- Les métriques sont-elles affichées uniquement en agrégé (jamais de donnée individuelle) ?
- Le "temps gagné estimé" est-il calculé côté serveur (ex: 3 min par synthèse lue vs 15 min article complet) ou fourni par l'utilisateur ?

### Alternatives envisagées
- Déléguer à un outil BI externe (Metabase, Grafana) pointant sur la base de données en lecture seule — plus puissant mais introduction d'une dépendance externe (hors scope v1).
- Exposer les métriques via l'API publique (EPIC-006) pour que l'équipe data les consomme dans ses propres outils — phase 2.

### Validation INVEST
- [x] **Independent** — dépend de US-075 pour les données mais peut être livré avec des métriques partielles (logs serveur)
- [x] **Negotiable** — métriques affichées, granularité et rôles d'accès négociables
- [x] **Valuable** — permet de prouver la valeur de EPIC-008 et d'orienter la roadmap
- [x] **Estimable** — vues matérialisées PostgreSQL + endpoint admin + dashboard Twig = 5 pts
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — calculs de rétention vérifiables avec fixtures ; accès restreint testable

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `GET /api/admin/metrics?period=7d\|30d` — accès ROLE_ADMIN uniquement |
| **Domaine** | `ProductMetricsQuery` | Calcul : rétention J7 = users actifs J7 / users inscrits J0 par cohorte ; deep-dive = (synthèses ouvertes / articles vus) * 100 ; temps gagné = nb_synthèses * 12 min |
| **Persistance** | PostgreSQL | Vues matérialisées `mv_retention_cohorts`, `mv_engagement_scores` rafraîchies quotidiennement via Symfony Scheduler ; agrégation uniquement (pas de données individuelles) |
| **Frontend web** | Twig + Stimulus | Page `/admin/metrics` : 4 cartes KPI (rétention J7, rétention J30, taux deep-dive, temps gagné) ; graphique en courbes par cohorte (Chart.js via Stimulus) |
| **Sécurité** | Symfony Voter | `AdminMetricsVoter::canRead()` : ROLE_ADMIN uniquement ; logs d'accès au dashboard (qui consulte quand) ; données 100 % agrégées — aucune PII exposée |
| **Console** | Symfony Command | `app:metrics:refresh` : recalcul manuel des vues matérialisées (utile en dev/staging) |
| **RGPD** | — | Données agrégées uniquement (aucune donnée individuelle) ; basées sur `reading_events` anonymisés après 90 j (US-075) |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Consultation des métriques de rétention J7

```gherkin
Scenario: Le Product Owner consulte les métriques de rétention J7
  GIVEN l'utilisateur admin est authentifié avec ROLE_ADMIN
    AND les vues matérialisées ont été rafraîchies dans les dernières 24 heures
  WHEN il accède à /admin/metrics?period=7d
  THEN l'API GET /api/admin/metrics?period=7d retourne HTTP 200 avec :
       {"retention_j7": 0.42, "retention_j30": 0.28, "deepdive_rate": 0.35, "time_saved_hours_total": 1240, "active_users_7d": 847}
    AND le dashboard affiche 4 cartes KPI avec les valeurs ci-dessus
    AND un graphique de rétention par cohorte hebdomadaire est affiché (8 dernières semaines)
    AND aucune donnée individuelle (email, nom, lectures spécifiques) n'est visible
```

### Scénario alternatif 1 — Comparaison J7 vs J30

```gherkin
Scenario: Le Product Owner compare rétention J7 et J30
  GIVEN l'admin est authentifié et sur /admin/metrics
  WHEN il bascule le sélecteur de période sur "30 jours"
  THEN l'API est rappelée avec period=30d
    AND les KPI sont mis à jour pour refléter la période de 30 jours
    AND le graphique s'étend sur les 30 derniers jours
    AND un indicateur de tendance (flèche haut/bas) compare J7 et J30 pour chaque métrique
```

### Scénario alternatif 2 — Refresh manuel des vues matérialisées

```gherkin
Scenario: Un développeur rafraîchit manuellement les métriques en staging
  GIVEN un accès terminal au container Symfony en staging
  WHEN la commande "php bin/console app:metrics:refresh" est exécutée
  THEN les vues matérialisées mv_retention_cohorts et mv_engagement_scores sont recalculées
    AND la console affiche "Métriques rafraîchies en {durée}ms — {N} cohortes calculées"
    AND le dashboard admin reflète immédiatement les nouvelles valeurs
```

### Scénario d'erreur 1 — Accès non autorisé au dashboard admin

```gherkin
Scenario: Un utilisateur sans ROLE_ADMIN tente d'accéder aux métriques
  GIVEN Priya est authentifiée avec uniquement ROLE_USER
  WHEN elle accède à /admin/metrics (via URL directe)
  THEN l'API GET /api/admin/metrics retourne HTTP 403 Forbidden avec {"error": "Accès réservé aux administrateurs"}
    AND la page web retourne une réponse 403 sans exposer de données
    AND l'accès non autorisé est loggé (user_id, timestamp, IP, ressource ciblée)
```

### Scénario d'erreur 2 — Valeur de période invalide

```gherkin
Scenario: Appel API avec une valeur de période non supportée
  GIVEN l'admin est authentifié
  WHEN il appelle GET /api/admin/metrics?period=365d
  THEN l'API retourne HTTP 422 avec {"error": "Période non supportée. Valeurs acceptées : 7d, 30d"}
    AND aucune donnée n'est retournée
    AND aucune stack trace n'est exposée dans la réponse
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — preuve de valeur de l'EPIC-008 ; guide la roadmap |
| **Risque technique** | Moyen — calcul de cohortes de rétention (agrégation complexe) |
| **Dépendances** | US-075 (données reading_events), EPIC-001 (users.created_at pour cohortes) |
