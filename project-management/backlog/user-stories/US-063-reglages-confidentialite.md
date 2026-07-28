# US-063 : Configurer les réglages granulaires de confidentialité

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-063 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-001 Thomas (cadre dirigeant tech), P-002 Priya (chercheuse stratégie), P-003 Marc (dev indépendant) |
| **Story points** | 5 (Fibonacci) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-003 : Marc, développeur indépendant privacy-first,
**Je veux** configurer précisément quelles données Briefly collecte et utilise depuis un écran dédié (analytics anonymes, recommandations personnalisées, indexation profil par moteurs de recherche),
**Afin de** contrôler au grain fin mon empreinte sur la plateforme selon mes cas d'usage et d'exercer mes droits RGPD sans ambiguïté.

---

### Conversation

- **Réglages disponibles en v1** :
  1. Analytics anonymes (défaut : opt-in — base légale : intérêt légitime Briefly pour améliorer le produit, opt-out possible)
  2. Recommandations personnalisées (défaut : opt-in — base légale : exécution du contrat, opt-out dégrade l'expérience)
  3. Indexation du profil public par les moteurs de recherche (défaut : opt-out — base légale : consentement explicite requis)
- **Application immédiate ?** Oui — chaque toggle est sauvegardé en temps réel (PATCH individuel par champ ou payload complet — à décider en Sprint Planning).
- **Synchronisation cross-device** : préférence persistée en BD, rechargée à la connexion sur chaque appareil.
- **Opt-out analytics** : désactive la collecte côté Flutter (pas d'envoi au `AnalyticsCollector`) ET côté Symfony (flag vérifié dans les listeners avant insertion dans `analytics_events`).
- **Opt-out reco personnalisées** : le moteur de recommandation reçoit une liste vide de signaux comportementaux ; les articles sont servis par ordre de popularité éditorial.
- **Opt-out indexation** : `X-Robots-Tag: noindex, nofollow` injecté sur les pages profil publiques + exclusion du sitemap.xml dans les 5 min (invalidation cache Redis).
- **Log RGPD** : chaque modification de préférence est loguée (`audit_log_rgpd` : user_id_hash, champ modifié, ancienne valeur, nouvelle valeur, timestamp).
- **Consentement initial** : géré à l'onboarding (US séparée). Cette US couvre uniquement la modification post-inscription.

---

### Validation INVEST

- [x] **Independent** — indépendante de US-060 (l'historique existe sans ces réglages) ; US-065 (pseudonymisation) bénéficie de l'opt-out analytics mais peut fonctionner sans
- [x] **Negotiable** — liste des réglages ajustable avec le DPO ; granularité (par feature vs global) négociable
- [x] **Valuable** — conformité RGPD, confiance des 3 personas, différenciateur éditorial "privacy-first"
- [x] **Estimable** — 5 pts : schema BD + API endpoint + logique métier opt-out (Flutter + Symfony) + UI web + UI Flutter + sync cross-device
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Table `user_privacy_settings` | `user_id UUID FK UNIQUE, analytics_enabled BOOL DEFAULT TRUE, personalized_reco_enabled BOOL DEFAULT TRUE, search_engine_indexing BOOL DEFAULT FALSE, updated_at TIMESTAMPTZ` ; index sur `user_id` |
| **PostgreSQL** | Table `audit_log_rgpd` | Insertion à chaque modification : champ, ancienne/nouvelle valeur, timestamp, user_id_hash |
| **API Platform** | `GET /api/me/privacy-settings` | Retourne les 3 préférences ; voter ownership |
| **API Platform** | `PATCH /api/me/privacy-settings` | Validation DTOs (3 champs booléens) ; voter ownership ; invalidation cache Redis préférences ; log RGPD |
| **Symfony Listener** | `PrivacySettingsChangedListener` | Sur `PrivacySettingsUpdatedEvent` : si `analytics_enabled=false`, désactive le `AnalyticsCollector` pour ce user ; si `search_engine_indexing=false`, invalide cache page profil |
| **Symfony/Twig + Turbo** | `/account/privacy/settings` | 3 toggles Stimulus ; sauvegarde via Turbo Stream (sans rechargement) ; badge RGPD info-bulle base légale sur chaque toggle |
| **Symfony** | `ProfileController` | Injecte `X-Robots-Tag: noindex, nofollow` si `search_engine_indexing=false` ; exclusion sitemap (cache Redis 5 min) |
| **Flutter** | `PrivacySettingsScreen` | 3 `SwitchListTile` synchronisés via `PrivacySettingsRepository` ; toast "Réglages sauvegardés" ; rechargement à la connexion |
| **Sécurité OWASP** | `PrivacySettingsVoter` | Vérifie ownership ; UUID non séquentiel ; log tentative non autorisée |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Désactivation des analytics anonymes (web)

```gherkin
Scenario: P-003 désactive les analytics anonymes depuis le web
  GIVEN Marc est authentifié sur le web et les analytics sont activées (analytics_enabled = true)
  WHEN il accède à Paramètres > Confidentialité > Réglages et bascule le toggle "Analytics anonymes" sur OFF
  THEN PATCH /api/me/privacy-settings est appelé avec { analyticsEnabled: false }
  AND le toggle reste à OFF sans rechargement de page (Turbo Stream)
  AND analytics_enabled = false est persisté en base
  AND un log RGPD est créé (champ: analytics_enabled, ancienne: true, nouvelle: false, timestamp)
  AND à partir de cet instant, aucun événement n'est inséré dans analytics_events pour ce compte
  AND un toast "Réglages sauvegardés" s'affiche 3 secondes puis disparaît
```

### Scénario alternatif 02 — Synchronisation cross-device (web → mobile)

```gherkin
Scenario: Réglage modifié sur web et reflété sur l'app Flutter dans les 30 secondes
  GIVEN Marc désactive les recommandations personnalisées depuis le web
  WHEN il ouvre l'app Flutter dans les 30 secondes (reconnexion au lancement)
  THEN l'écran Paramètres > Confidentialité > Réglages affiche "Recommandations personnalisées" à OFF
  AND le moteur de recommandation sert des articles par popularité éditorial (non personnalisés) pour ce compte
```

### Scénario alternatif 03 — Activation de l'indexation moteurs de recherche

```gherkin
Scenario: P-001 active l'indexation de son profil par les moteurs de recherche
  GIVEN Thomas a le réglage "Indexation moteurs" à OFF (défaut)
  WHEN il active le toggle "Indexation par les moteurs de recherche" dans Paramètres
  THEN search_engine_indexing = true est persisté en base
  AND la page profil publique de Thomas ne contient plus X-Robots-Tag: noindex dans les 5 minutes (cache Redis invalidé)
  AND le sitemap.xml inclut l'URL du profil lors de sa prochaine génération
```

### Scénario d'erreur 04 — Modification des réglages hors connexion (Flutter)

```gherkin
Scenario: Marc tente de modifier un réglage en mode avion
  GIVEN Marc est hors connexion sur l'app mobile (mode avion actif)
  WHEN il bascule le toggle "Analytics anonymes" sur OFF
  THEN le toggle revient à sa position d'origine (ON) après 1,5 secondes
  AND un message toast s'affiche "Connexion requise pour modifier les réglages de confidentialité"
  AND aucune modification locale n'est enregistrée (pas de queue offline pour les réglages sensibles)
```

### Scénario d'erreur 05 — Tentative de modification non autorisée via API

```gherkin
Scenario: Tentative de PATCH sur les réglages d'un autre utilisateur
  GIVEN un attaquant envoie PATCH /api/me/privacy-settings avec un JWT valide mais un payload contenant l'UUID d'un autre compte
  WHEN le PrivacySettingsVoter évalue la requête
  THEN l'API retourne HTTP 403 Forbidden avec body { "error": "Access denied" }
  AND aucune modification n'est apportée en base
  AND un log de sécurité est créé avec l'IP, le user_id du JWT et l'UUID cible tenté
  AND l'incident est visible dans le tableau de bord sécurité (si EPIC-008 analytics actif)
```
