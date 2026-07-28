# US-061 : Exporter ses données personnelles en JSON

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-061 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-002 Priya (chercheuse stratégie), P-003 Marc (dev indépendant) |
| **Story points** | 3 (Fibonacci) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-002 : Priya, chercheuse en stratégie,
**Je veux** exporter l'intégralité de mes données personnelles au format JSON depuis les paramètres,
**Afin de** exercer mon droit à la portabilité (RGPD Art. 20), réutiliser mon historique de veille dans mes propres outils d'analyse et conserver une copie locale indépendante de Briefly.

---

### Conversation

- **Contenu de l'export** : profil (email, nom d'affichage, date d'inscription), historique de lecture, articles favoris, historique de recherches, paramètres de confidentialité, synthèses générées pour l'utilisateur (texte + niveau + date).
- **Format** : JSON structuré (pas JSON-LD) pour faciliter la réutilisation dans des scripts Python/R/Node. Encodage UTF-8.
- **Mode de génération** : asynchrone (Symfony Messenger) car le volume peut être conséquent. Notification par email + lien de téléchargement (URL signée TTL 24 h).
- **Fréquence** : 1 export par 24 h par utilisateur (rate limit) pour éviter une charge excessive.
- **Taille** : pas de limite en v1 ; monitoring si > 100 Mo (alerting ops).
- **Les synthèses IA générées sont-elles incluses ?** Oui — contenu produit pour l'utilisateur, lui appartient (RGPD Art. 20).
- **Sécurité du lien** : URL signée HMAC-SHA256 avec TTL 24 h. Lien à usage unique (invalidé après premier téléchargement ? Non en v1 — TTL suffisant).
- **Stockage temporaire** : fichier généré stocké en local (volume Docker) ou S3 selon l'environnement ; supprimé après TTL.

---

### Validation INVEST

- [x] **Independent** — dépend de l'existence d'un compte, pas de US-060 (l'historique existera indépendamment)
- [x] **Negotiable** — contenu exact de l'export ajustable avec le DPO ; format JSON vs CSV reporté en v2
- [x] **Valuable** — droit RGPD fondamental + différenciateur confiance pour P-002 et P-003
- [x] **Estimable** — 3 pts : endpoint POST/GET + Messenger handler + email notification + URL signée
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Table `data_exports` | `id UUID, user_id UUID FK, status ENUM(pending/processing/ready/failed/expired), file_path VARCHAR, expires_at TIMESTAMPTZ, requested_at TIMESTAMPTZ, downloaded_at TIMESTAMPTZ` |
| **Symfony Messenger** | `GenerateUserDataExportMessage` | Message contenant `userId` ; handler `GenerateUserDataExportHandler` (agrégation multi-tables + sérialisation JSON + écriture fichier) |
| **API Platform** | `POST /api/me/exports` | Déclenche le message Messenger ; rate limit Redis 1/24h/user ; retourne `{ id, status: "pending" }` |
| **API Platform** | `GET /api/me/exports/{id}` | Statut de l'export ; voter ownership |
| **API Platform** | `GET /api/me/exports/{id}/download` | URL signée ou stream direct ; vérifie TTL et ownership ; marque `downloaded_at` |
| **Symfony Mailer** | Email "Votre export est prêt" | Template Twig avec lien signé ; envoi après handler success |
| **Symfony/Twig + Turbo** | `/account/privacy` section export | Bouton "Exporter mes données" ; Turbo Stream polling statut toutes les 10 s ; toast "Email envoyé" |
| **Flutter** | `PrivacySettingsScreen` | Bouton export → deep link vers navigateur externe (téléchargement géré par le navigateur) |
| **Sécurité OWASP** | Lien signé | `HMAC-SHA256(userId + exportId + expires_at, APP_SECRET)` ; vérification à chaque accès download |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Demande et réception de l'export complet

```gherkin
Scenario: P-002 demande l'export de ses données et le télécharge
  GIVEN Priya est authentifiée et possède 6 mois d'historique (120 articles, 45 favoris, 30 synthèses)
  WHEN elle clique sur "Exporter mes données" dans Paramètres > Confidentialité
  THEN un job est dispatchée via Symfony Messenger et la réponse indique status: "pending"
  AND Priya reçoit un email "Votre export Briefly est prêt" dans les 5 minutes avec un lien de téléchargement
  WHEN elle clique sur le lien de téléchargement
  THEN un fichier briefly_export_YYYY-MM-DD.json est téléchargé
  AND le fichier contient les sections : profile, reading_history, favorites, search_history, privacy_settings, generated_syntheses
  AND le fichier est encodé UTF-8 et valide JSON (parseable sans erreur)
  AND le lien expire après 24 heures
```

### Scénario alternatif 02 — Limitation à 1 export par 24 heures

```gherkin
Scenario: P-003 tente un deuxième export dans la même journée
  GIVEN Marc a demandé un export il y a 3 heures (statut: ready)
  WHEN il clique à nouveau sur "Exporter mes données"
  THEN un message s'affiche "Export déjà demandé. Prochain export disponible dans 21h"
  AND aucun nouveau job Messenger n'est créé
  AND un lien vers l'export précédent (encore valide, TTL non expiré) est proposé
```

### Scénario alternatif 03 — Export pour compte sans historique

```gherkin
Scenario: Nouvel utilisateur exporte ses données vides
  GIVEN un utilisateur inscrit depuis 1 jour sans historique ni favoris
  WHEN il demande l'export
  THEN le fichier JSON est généré avec les sections vides : reading_history: [], favorites: [], generated_syntheses: []
  AND la section profile et privacy_settings sont renseignées
  AND l'email de notification est envoyé dans les 5 minutes
```

### Scénario d'erreur 04 — Échec du job après 3 tentatives

```gherkin
Scenario: Erreur lors de la génération de l'export (worker indisponible)
  GIVEN Priya a demandé son export et le handler Messenger échoue 3 fois consécutives (retry exhausted)
  WHEN le message passe en failed_messages
  THEN l'entrée data_exports passe au statut "failed"
  AND Priya reçoit un email "Votre export a échoué — veuillez réessayer"
  AND la rate limit (1/24h) est réinitialisée pour permettre une nouvelle tentative immédiate
  AND l'incident est loggué au niveau ERROR sans données personnelles dans les métadonnées
```

### Scénario d'erreur 05 — Tentative de téléchargement avec lien expiré

```gherkin
Scenario: Lien de téléchargement expiré après 24 heures
  GIVEN Priya tente de télécharger son export 26 heures après l'avoir demandé
  WHEN elle accède à GET /api/me/exports/{id}/download
  THEN l'API retourne HTTP 410 Gone avec message "Lien expiré. Vous pouvez demander un nouvel export."
  AND le fichier est supprimé du stockage (nettoyage planifié)
  AND l'entrée data_exports passe au statut "expired"
  AND aucune donnée personnelle n'est renvoyée dans la réponse d'erreur
```
