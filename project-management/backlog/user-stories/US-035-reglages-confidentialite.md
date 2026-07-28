# US-035 : Réglages de confidentialité et préférences RGPD

## En-tête

| Champ | Valeur |
|-------|--------|
| ID | US-035 |
| EPIC parent | EPIC-004 – Comptes Utilisateurs & Premium |
| Persona | P-003 Marc, développeur indépendant privacy-first |
| Story Points | 3 |
| Priorité MoSCoW | Must Have |
| Sprint | backlog |

---

## Carte (User Story)

**En tant que** P-003 Marc, développeur indépendant privacy-first
**Je veux** contrôler finement quelles données Briefly AI collecte et utilise à mon sujet via des interrupteurs clairs et persistés
**Afin de** utiliser la plateforme en accord avec mes valeurs de confidentialité et d'exercer mes droits RGPD de façon autonome, sans devoir contacter le support.

---

## Conversation (points à clarifier / décisions ouvertes)

- Désactiver "Analytique anonyme" désactive-t-il l'envoi côté serveur (Plausible/Matomo) ou uniquement le tag côté client ? (proposition : les deux)
- "Biométrie" s'applique uniquement au mobile (v1) — l'interrupteur est-il visible sur desktop ? (proposition : masqué sur desktop, n'existe que dans l'app Flutter US-05x)
- "Indexation moteurs" : exclut le profil public ET les listes de lecture publiques ?
- "Recommandations personnalisées" : désactiver cela signifie recevoir les recommandations génériques (non personnalisées par historique) ?
- Les préférences doivent-elles figurer dans l'export portabilité JSON ? (oui, obligatoire RGPD)
- Un changement de préférence prend-il effet immédiatement ou après un délai (proposition : immédiatement sauf indexation moteurs, propagée dans les 24h) ?

---

## Validation INVEST

- [x] **Independent** : ne dépend pas de US-034 (Premium) ; fonctionne pour tout compte authentifié
- [x] **Negotiable** : nombre d'interrupteurs réduit à 2 minimum en v1 si délai contraint
- [x] **Valuable** : différenciateur clé pour P-003 ; obligation légale RGPD Article 7 et 21
- [x] **Estimable** : CRUD préférences + UI interrupteurs + export JSON = 3 pts
- [x] **Sized** : ≤ 8 points
- [x] **Testable** : critères Gherkin ci-dessous

---

## Vertical Slicing

| Couche | Travail |
|--------|---------|
| **PostgreSQL** | Table `user_privacy_settings` : `user_id` (FK → users.id ON DELETE CASCADE, PK), `analytics_opt_in` (BOOL DEFAULT TRUE), `personalized_recs` (BOOL DEFAULT TRUE), `search_engine_indexing` (BOOL DEFAULT TRUE), `updated_at` (TIMESTAMPTZ) |
| **Backend Symfony** | `PrivacySettingsController::show()` (GET /settings/privacy) et `::update()` (PATCH /settings/privacy) ; `PrivacySettingsVoter::EDIT` ; `PrivacySettingsType` |
| **Middleware analytics** | `AnalyticsMiddleware` vérifie `analytics_opt_in` avant tout envoi à Plausible/Matomo ; `X-Robots-Tag: noindex, nofollow` injecté si `search_engine_indexing = false` |
| **Export RGPD** | `DataExportService::exportPrivacySettings()` inclut les préférences + horodatage dans le JSON portabilité |
| **Twig / Turbo** | Page `/settings/privacy` avec interrupteurs Stimulus (`toggle-controller`), sauvegarde auto via AJAX debounce 800ms (Turbo Stream PATCH) ; confirmation toast "Enregistré" |
| **Sécurité OWASP** | `PrivacySettingsVoter::EDIT` : propriétaire uniquement (HTTP 403 sinon) ; CSRF sur le formulaire |

---

## Critères d'Acceptance (Gherkin SMART)

### Scénario nominal — Désactivation de l'analytique anonyme

```gherkin
Scenario: Marc désactive la collecte analytique anonyme
  GIVEN Marc est authentifié et sur la page GET /settings/privacy
  AND l'interrupteur "Analytique anonyme" est en position "Activé" (analytics_opt_in = true)
  WHEN Marc bascule l'interrupteur vers "Désactivé"
  AND la sauvegarde automatique (debounce 800ms) s'exécute via PATCH /settings/privacy
  THEN `user_privacy_settings.analytics_opt_in = false` est persisté en base pour Marc
  AND `user_privacy_settings.updated_at` est mis à jour avec l'horodatage UTC courant
  AND un toast Turbo Stream "Préférences enregistrées" s'affiche pendant 3 secondes
  AND le script analytique (Plausible/Matomo) n'est plus inclus dans le HTML des pages suivantes visitées par Marc
```

### Scénario alternatif 1 — Désactivation de l'indexation par les moteurs de recherche

```gherkin
Scenario: Marc désactive l'indexation de son profil par les moteurs de recherche
  GIVEN Marc est sur /settings/privacy
  AND "Indexation par les moteurs de recherche" est "Activé" (search_engine_indexing = true)
  WHEN il bascule cet interrupteur vers "Désactivé" et la sauvegarde s'effectue
  THEN `search_engine_indexing = false` est persisté en base
  AND toutes les réponses HTTP sur /profile/{marc-uuid} incluent l'en-tête "X-Robots-Tag: noindex, nofollow"
  AND le Sitemap.xml exclut l'URL du profil de Marc lors du prochain build
  AND la modification est effective dès la requête suivante (< 1 s)
```

### Scénario alternatif 2 — Préférences de confidentialité incluses dans l'export RGPD

```gherkin
Scenario: Les préférences figurent dans l'export portabilité JSON
  GIVEN Marc a configuré analytics_opt_in=false, personalized_recs=true, search_engine_indexing=false
  WHEN il déclenche un export de ses données depuis /settings/privacy (bouton "Exporter mes données")
  THEN un fichier JSON est généré et proposé au téléchargement dans les 10 secondes
  AND le JSON contient un objet "privacy_settings" avec les champs :
    { "analytics_opt_in": false, "personalized_recs": true, "search_engine_indexing": false, "updated_at": "2026-07-28T14:30:00Z" }
  AND aucune donnée d'autres utilisateurs n'apparaît dans le fichier
```

### Scénario d'erreur 1 — Accès non authentifié à la page des réglages

```gherkin
Scenario: Visiteur non authentifié tente d'accéder aux réglages de confidentialité
  GIVEN un visiteur non authentifié
  WHEN il accède à GET /settings/privacy
  THEN le serveur retourne HTTP 302 vers /login
  AND le paramètre "redirect_to=/settings/privacy" est inclus dans l'URL de redirection
  WHEN Marc se connecte
  THEN il est redirigé automatiquement vers /settings/privacy
```

### Scénario d'erreur 2 — Tentative de modification des réglages d'un autre utilisateur

```gherkin
Scenario: P-001 Thomas tente de modifier les réglages de confidentialité de Marc
  GIVEN Thomas est authentifié (user_id = uuid-thomas)
  WHEN il envoie PATCH /settings/privacy avec un payload ciblant l'user_id de Marc
  THEN le `PrivacySettingsVoter` compare l'entité cible à l'utilisateur courant
  AND retourne DENY car `uuid-thomas != uuid-marc`
  AND le serveur retourne HTTP 403 Forbidden
  AND les réglages de Marc ne sont pas modifiés
  AND l'accès non autorisé est loggué en WARN (user_id demandeur, cible, timestamp)
```
