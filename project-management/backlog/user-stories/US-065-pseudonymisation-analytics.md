# US-065 : Pseudonymisation des analytics et données comportementales

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-065 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-003 Marc (dev indépendant, privacy-first) |
| **Story points** | 3 (Fibonacci) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-003 : Marc, développeur indépendant privacy-first,
**Je veux** que mes données comportementales soient automatiquement pseudonymisées dès leur collecte via un mécanisme à sel rotatif,
**Afin d'** être certain qu'aucun profil nominatif ne peut être reconstruit à partir de mon comportement de navigation, même en cas de fuite ou de compromission de la base de données analytics.

---

### Conversation

- **Mécanisme de pseudonymisation** : `pseudo_user_id = HMAC-SHA256(user_uuid | sel_courant)`. Le sel est stocké dans une table dédiée `analytics_salts`, chiffré (AES-256-GCM). Accès restreint au `PseudonymizationService` uniquement.
- **Rotation du sel** : tous les 90 jours. Après rotation, les événements antérieurs utilisent un pseudonyme différent → non reliables. Les anciens sels sont conservés pour permettre le droit à l'oubli analytics.
- **Isolation des tables** : `analytics_events` ne contient AUCUNE foreign key directe vers `users`. Séparation physique des données analytiques et personnelles.
- **Données couvertes** : clics sur articles, temps de lecture, recherches, synthèses consultées, catégories favorites. Pas les actions de compte (inscription, paiement).
- **Opt-out** (US-063) : si `analytics_enabled = false`, le `AnalyticsCollector` retourne immédiatement sans appel au `PseudonymizationService`.
- **Recommandations** : le moteur de reco reçoit uniquement le `pseudo_user_id` — jamais l'UUID réel.
- **Droit à l'oubli analytics** (endpoint dédié) : reconstruction du pseudonyme pour chaque sel connu → suppression des événements. Après expiration d'un sel sans conservation, les données sont techniquement anonymes.
- **Audit CNIL** : documenter dans le registre de traitement : finalité analytics, méthode de pseudonymisation, durée de conservation des sels, procédure de droit à l'oubli.

---

### Validation INVEST

- [x] **Independent** — couche technique transverse ; s'active avec ou sans US-063 (le flag opt-out est vérifié avant l'appel au service)
- [x] **Negotiable** — algorithme négociable (HMAC-SHA256 vs UUID v5 salt) ; fréquence de rotation 90 j ajustable
- [x] **Valuable** — réduction du risque RGPD en cas de fuite (données analytics non exploitables sans le sel) ; confiance P-003 ; conformité CNIL
- [x] **Estimable** — 3 pts : schema BD (2 tables) + PseudonymizationService + RotateSaltCommand + endpoint droit à l'oubli analytics
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Table `analytics_events` | `id UUID, pseudo_user_id VARCHAR(64), event_type VARCHAR(50), article_id UUID, occurred_at TIMESTAMPTZ, metadata JSONB` — AUCUNE FK vers `users` |
| **PostgreSQL** | Table `analytics_salts` | `id UUID, salt_encrypted BYTEA, valid_from TIMESTAMPTZ, valid_until TIMESTAMPTZ, is_current BOOLEAN DEFAULT TRUE, created_at TIMESTAMPTZ` ; index sur `is_current` |
| **Symfony** | `PseudonymizationService` | `computePseudo(User $user): string` → `hash_hmac('sha256', $user->getId() . '|' . $currentSalt, $appSecret)` ; cache Redis 1 h du pseudo par user_id (évite recalcul fréquent) |
| **Symfony** | `AnalyticsCollector` | Vérifie `$user->getPrivacySettings()->isAnalyticsEnabled()` ; si false → return ; si true → appelle `PseudonymizationService::computePseudo()` puis insère dans `analytics_events` |
| **Symfony Scheduler** | `RotateSaltCommand` | Déclenché toutes les nuits du 1er du mois (90 j en production) ; génère un nouveau sel AES-256-GCM ; marque l'ancien `is_current = false` ; log RGPD "salt_rotated" |
| **API Platform** | `DELETE /api/me/analytics-data` | Reconstruit tous les pseudonymes de l'utilisateur (un par sel connu non expiré) ; supprime les `analytics_events` correspondants ; retourne `{ deleted_count: N, periods_covered: M, anonymous_periods: P }` |
| **Symfony/Twig + Turbo** | `/account/privacy` section analytics | Bouton "Supprimer mes données analytiques" (distinct de l'historique lecture) ; confirmation modale avec explication technique simplifiée |
| **Flutter** | Aucun identifiant utilisateur | Les events Flutter (ex: `firebase_analytics`) n'utilisent que le `pseudo_user_id` reçu du serveur après authentification ; jamais l'UUID réel |
| **Sécurité** | Chiffrement des sels | `analytics_salts.salt_encrypted` : AES-256-GCM, clé dans vault/env `ANALYTICS_SALT_ENCRYPTION_KEY` ; pas accessible via API |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Collecte d'un événement analytique pseudonymisé

```gherkin
Scenario: Marc lit un article et l'event est inséré pseudonymisé
  GIVEN Marc est authentifié avec analytics_enabled = true
  AND le sel courant en base est S1 (is_current = true)
  WHEN Marc ouvre un article et le lit pendant 2 minutes (événement "article_read" déclenché)
  THEN un seul enregistrement est inséré dans analytics_events avec :
    - pseudo_user_id = HMAC-SHA256(marc_uuid | S1) — NON l'UUID réel de Marc
    - event_type = "article_read"
    - article_id = UUID de l'article
    - occurred_at = timestamp UTC actuel
  AND la table analytics_events ne contient aucune colonne "user_id" ou FK directe vers la table users
  AND une requête SELECT * FROM analytics_events WHERE pseudo_user_id = marc_uuid ne retourne aucun résultat
```

### Scénario alternatif 02 — Rotation automatique du sel tous les 90 jours

```gherkin
Scenario: Le Scheduler déclenche la rotation du sel analytique
  GIVEN le sel courant S1 a été créé il y a 90 jours (valid_until = NOW())
  WHEN RotateSaltCommand est exécuté par Symfony Scheduler à minuit UTC
  THEN un nouveau sel S2 est généré (AES-256-GCM) et inséré avec is_current = true
  AND l'ancien sel S1 est mis à jour avec is_current = false (mais conservé pour le droit à l'oubli)
  AND les nouveaux événements utilisent HMAC-SHA256(user_uuid | S2) — pseudo différent de celui avec S1
  AND une entrée "salt_rotated" est créée dans audit_log_rgpd avec timestamp
  AND aucune interruption de la collecte analytics pendant la rotation (transaction atomique)
```

### Scénario alternatif 03 — Opt-out analytics stoppe la collecte sans erreur

```gherkin
Scenario: Marc désactive les analytics (US-063) et aucun event n'est collecté
  GIVEN Marc désactive "Analytics anonymes" dans Paramètres > Confidentialité (analytics_enabled = false)
  WHEN Marc ouvre 5 articles dans les 10 minutes suivantes
  THEN aucun enregistrement n'est inséré dans analytics_events avec son pseudo_user_id
  AND AnalyticsCollector retourne immédiatement sans appeler PseudonymizationService
  AND aucun pseudo_user_id n'est calculé ni mis en cache Redis pour Marc pendant cette période
```

### Scénario d'erreur 04 — Sel courant introuvable (incident infrastructure)

```gherkin
Scenario: Aucun sel courant disponible (table analytics_salts corrompue ou vide)
  GIVEN la table analytics_salts ne contient aucune ligne avec is_current = true
  WHEN PseudonymizationService::computePseudo() est appelé lors d'un événement analytics
  THEN une exception SaltNotFoundException est levée
  AND l'événement analytique N'est PAS inséré dans analytics_events (fail safe : pas de collecte avec identifiant réel)
  AND une alerte CRITICAL est loguée (sans données personnelles)
  AND les fonctionnalités core de l'app (lecture d'articles, synthèses) ne sont PAS impactées (exception capturée silencieusement dans AnalyticsCollector)
```

### Scénario d'erreur 05 — Droit à l'oubli analytics avec plusieurs sels dont un expiré

```gherkin
Scenario: Marc demande la suppression de ses données analytiques après 200 jours (2 rotations de sel)
  GIVEN Marc a des événements analytics sur 3 périodes :
    - Jours 0-90 avec sel S1 (conservé)
    - Jours 90-180 avec sel S2 (conservé)
    - Jours 180-200 avec sel S3 courant
  WHEN il déclenche DELETE /api/me/analytics-data
  THEN le service reconstruit ses 3 pseudonymes (S1, S2, S3) et supprime tous les events correspondants
  AND la réponse indique { deleted_count: N, periods_covered: 3, anonymous_periods: 0 }
  AND un log RGPD "analytics_deleted" est créé avec timestamp, user_id_hash, nombre d'événements supprimés
  AND si un sel antérieur a été purgé (non conservé), les events de cette période sont mentionnés comme "techniquement anonymes — non supprimables" dans la réponse
  AND la réponse explique en langage clair : "Ces données ne peuvent plus être associées à votre compte"
```
