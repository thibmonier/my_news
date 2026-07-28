# US-075 : Tableau de bord analytique respectueux RGPD

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-075 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-003 Marc — développeur indépendant privacy-first, 44 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 Marc, développeur indépendant privacy-first,
**Je veux** activer (opt-in) un suivi de mes habitudes de lecture et consulter les statistiques agrégées qui en résultent
**Afin de** comprendre mes patterns de consommation d'information sans que mes données soient transmises à des tiers ou utilisées à des fins publicitaires.

---

## Conversation (3C)

### Points à clarifier
- Consentement granulaire ou global ? (ex: "je consens au suivi de lecture" vs case par case)
- Quelles métriques personnelles afficher en v1 ? Propositions : nombre d'articles lus cette semaine, catégories les plus consultées, temps moyen de session estimé, synthèses IA ouvertes.
- Après combien de jours les événements bruts sont-ils anonymisés/agrégés ? (proposition : 90 jours)
- Le droit à la suppression déclenche-t-il la suppression en cascade des `reading_events` ou uniquement leur anonymisation ?
- Les données sont-elles exportables (portabilité RGPD) au format JSON via l'endpoint prévu en EPIC-006 ?

### Alternatives envisagées
- Utiliser un service analytics tiers (Plausible, Matomo self-hosted) plutôt qu'une implémentation maison — réduit le délai mais dépend d'un serveur tiers (incompatible P-003 privacy-first).
- Ne collecter que des métriques côté serveur (logs nginx) sans SDK côté client — plus simple mais moins précis (pas de temps de lecture réel).

### Validation INVEST
- [x] **Independent** — indépendant de US-072/073 ; opt-in déclenche la collecte pour US-070/071
- [x] **Negotiable** — granularité des métriques et durée de rétention à affiner
- [x] **Valuable** — différenciateur fort pour P-003 ; preuve de confiance pour tous les personas
- [x] **Estimable** — table `reading_events` + endpoint consent + dashboard Stimulus = 5 pts
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — consentement, collecte et affichage vérifiables end-to-end

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony / API Platform | `POST /api/consent` body `{"analytics": true\|false}` ; `GET /api/analytics/me` → métriques agrégées ; `DELETE /api/analytics/me` → suppression données |
| **Domaine** | Entity `ReadingEvent`, `UserConsent` | `reading_events(user_id, article_uuid, event_type ENUM[open,complete,save], created_at)` ; `user_consent(user_id, analytics_at, revoked_at)` |
| **Persistance** | PostgreSQL | Table `reading_events` (TTL 90 jours via pg_cron/Scheduler) ; anonymisation après 90 j (user_id → NULL, IP → NULL) ; index `(user_id, created_at)` |
| **Frontend web** | Twig + Turbo + Stimulus | Page `/profile/analytics` : bannière de consentement opt-in RGPD ; toggle activation/désactivation ; 4 métriques cartes (articles lus, catégories favorites, sessions, synthèses IA ouvertes) |
| **Frontend mobile** | Flutter | Écran "Mes statistiques" dans le profil ; toggle consentement avec dialog de confirmation explicite |
| **Sécurité** | Symfony Voter | `AnalyticsVoter::canRead/canDelete()` — propriétaire uniquement ; jamais d'identifiant utilisateur dans les prompts IA (EPIC-002) ; aucune donnée transmise à des tiers |
| **RGPD** | — | Consentement explicite enregistré avec timestamp AVANT toute collecte ; révocation possible à tout moment (désactivation immédiate) ; droit à la suppression : `DELETE /api/analytics/me` purge la table `reading_events` pour l'utilisateur concerné |
| **Portabilité** | — | Données exportables au format JSON via le futur endpoint EPIC-006 |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Activation du suivi et consultation des statistiques

```gherkin
Scenario: Marc active le suivi de lecture et consulte ses statistiques
  GIVEN Marc est authentifié sur Briefly AI
    AND il n'a pas encore donné son consentement analytics (analytics_consent = NULL)
  WHEN il accède à /profile/analytics
  THEN une bannière de consentement RGPD est affichée avec le texte exact :
       "Activé uniquement avec votre accord explicite. Vos données ne sont pas revendues.
        Elles sont agrégées localement et supprimées après 90 jours."
    AND un bouton "Activer le suivi" est présent
  WHEN Marc clique sur "Activer le suivi"
  THEN l'API POST /api/consent body {"analytics": true} retourne HTTP 200
    AND `user_consent.analytics_at` est enregistré avec le timestamp courant
    AND les événements de lecture sont désormais collectés (ReadingEvent dispatché sur chaque ouverture d'article)
    AND les métriques s'affichent (potentiellement vides si aucun événement encore collecté)
```

### Scénario alternatif 1 — Consultation des métriques après 7 jours de suivi

```gherkin
Scenario: Marc consulte ses statistiques après une semaine de suivi
  GIVEN Marc a consenti au suivi analytics il y a 7 jours
    AND 43 événements de lecture ont été collectés
  WHEN il accède à /profile/analytics
  THEN l'API GET /api/analytics/me retourne les métriques agrégées :
       {"articles_read_7d": 43, "top_categories": ["Technologie","Crypto","Espace"],
        "ai_summaries_opened": 12, "estimated_time_saved_minutes": 38}
    AND les données sont affichées sous forme de 4 cartes métriques
    AND aucune donnée personnelle brute (titres d'articles, heures précises) n'est exposée dans l'API
    AND un message "Données agrégées — vos lectures individuelles ne sont pas stockées" est affiché
```

### Scénario alternatif 2 — Révocation du consentement

```gherkin
Scenario: Marc révoque son consentement analytics
  GIVEN Marc a consenti au suivi et des événements ont été collectés
  WHEN il désactive le toggle "Suivi de lecture" dans /profile/analytics
  THEN l'API POST /api/consent body {"analytics": false} retourne HTTP 200
    AND `user_consent.revoked_at` est enregistré
    AND aucun nouvel événement `ReadingEvent` n'est dispatché à partir de ce moment
    AND les données agrégées déjà calculées restent visibles (elles ne contiennent pas de PII)
    AND un message "Suivi désactivé. Les nouvelles lectures ne sont plus enregistrées." est affiché
```

### Scénario d'erreur 1 — Droit à la suppression (RGPD)

```gherkin
Scenario: Marc exerce son droit à la suppression de ses données analytics
  GIVEN Marc est authentifié et a des données analytics collectées
  WHEN il clique sur "Supprimer toutes mes données de suivi" et confirme le dialog
  THEN l'API DELETE /api/analytics/me retourne HTTP 204
    AND tous les enregistrements `reading_events` liés à Marc sont supprimés de la base de données
    AND `user_consent` est réinitialisé (analytics_at = NULL, revoked_at = NULL)
    AND la page affiche "Vos données de suivi ont été supprimées. Le suivi a été désactivé."
    AND la suppression est effective en moins de 72 heures (confirmation RGPD)
```

### Scénario d'erreur 2 — Collecte bloquée sans consentement préalable

```gherkin
Scenario: Tentative de collecte sans consentement explicite
  GIVEN Marc est authentifié mais n'a pas consenti au suivi analytics
  WHEN il ouvre un article (événement qui déclencherait normalement un ReadingEvent)
  THEN aucun enregistrement n'est créé dans la table reading_events
    AND l'API GET /api/analytics/me retourne HTTP 403 avec {"error": "Consentement analytics requis"}
    AND la page /profile/analytics affiche uniquement la bannière de consentement (pas de métriques)
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 5 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — trust signal clé pour P-003 et différenciateur vs concurrents |
| **Risque technique** | Moyen — gestion du consentement RGPD + anonymisation périodique (pg_cron) |
| **Dépendances** | EPIC-001 (auth), US-070/071 (événements à collecter si consentement), US-076 (métriques produit) |
