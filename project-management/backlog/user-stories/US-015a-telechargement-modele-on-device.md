# US-015a : Téléchargement du modèle Phi-3 Mini on-device (Flutter)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-015a |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-003 Marc — Développeur indépendant, 44 ans, privacy-first |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-015 — Synthèse on-device opt-in Flutter + Phi-3 Mini |

**Dépend de :** EPIC-005 (squelette Flutter mobile disponible)
**Requis par :** US-015b (inférence locale)

---

## User Story — Carte

**En tant que** P-003 Marc, développeur indépendant privacy-first,
**Je veux** télécharger le modèle Phi-3 Mini sur mon téléphone après avoir donné mon consentement RGPD explicite
**Afin de** disposer du modèle localement et de pouvoir activer le traitement on-device à tout moment, sans dépendance réseau pour les synthèses futures.

---

## Les 3 C

### Carte (résumé)

Interface Flutter opt-in dans Paramètres > Confidentialité > Synthèse privée : bouton de téléchargement, barre de progression (Mo restants + %), annulation possible, reprise automatique sur interruption réseau via HTTP Range Requests. Vérification d'intégrité SHA-256 à la fin du téléchargement. Consentement RGPD explicite (modale avant lancement) obligatoire. Modèle téléchargé depuis `https://cdn.briefly.ai/models/phi3-mini-q4.onnx`. Métadonnées (taille, version, date, chemin) stockées dans Hive ; flag opt-in dans `flutter_secure_storage`. Si l'appareil dispose de moins de 4 GB de RAM, un avertissement non bloquant est affiché.

### Conversation (notes & questions ouvertes)

- Le consentement RGPD est-il enregistré en base backend ? Oui, via `PATCH /api/v1/me/preferences` avec `on_device_consent_at: TIMESTAMPTZ` et `on_device_enabled: true`.
- La reprise sur interruption est-elle implémentée via HTTP Range Requests ou un gestionnaire custom ? Décision : HTTP Range Requests via le package `dio` (supporte le resume natif).
- Le modèle est-il stocké dans l'espace privé de l'app (inaccessible aux autres apps) ? Oui, via `getApplicationDocumentsDirectory()` (sandbox iOS/Android).
- Que se passe-t-il si l'utilisateur désinstalle l'app ? Le fichier modèle est supprimé avec les données de l'app (comportement OS natif).
- Faut-il un mécanisme de mise à jour du modèle (nouvelle version Phi-3) ? Non en v1 — l'utilisateur devra désactiver/réactiver pour re-télécharger. Évolution US future.
- Espace requis affiché avant téléchargement ? Oui : message "Ce téléchargement nécessite 1.8 GB d'espace libre" avant la modale de consentement.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Flutter — UI** | `OnDeviceSettingsScreen` | Toggle opt-in, bouton "Télécharger le modèle (1.8 GB)", barre de progression (% + Mo restants), bouton "Annuler" |
| **Flutter — Service** | `ModelDownloadService` | Téléchargement CDN via `dio` ; HTTP Range Requests (reprise) ; vérification SHA-256 après complétion |
| **Flutter — Storage** | `flutter_secure_storage` | Stockage du flag `on_device_enabled` et du chemin du fichier modèle |
| **Flutter — Storage** | `Hive` | Métadonnées : taille totale, version, `downloaded_at`, hash attendu |
| **Flutter — Consent** | `RgpdConsentModal` | Modale pré-téléchargement : volume 1.8 GB, stockage 100% local, usage off-line exclusif, bouton Accepter / Refuser |
| **Backend — Symfony** | `UserPreference` entity + `PATCH /api/v1/me/preferences` | Sync `on_device_consent_at` (TIMESTAMPTZ) et `on_device_enabled` (bool) en base avec timestamp de consentement |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Téléchargement réussi avec consentement et vérification SHA-256

```gherkin
Scenario: Marc télécharge le modèle Phi-3 Mini avec succès
  GIVEN Marc est connecté sur l'application Flutter
  AND il accède à Paramètres > Confidentialité > Synthèse privée
  WHEN il clique sur "Activer la synthèse on-device"
  THEN une modale de consentement RGPD s'affiche expliquant le téléchargement de 1.8 GB stocké localement
  AND après acceptation, le téléchargement démarre depuis cdn.briefly.ai avec une barre de progression (% + Mo restants)
  AND à la complétion, le hash SHA-256 du fichier est calculé et comparé au hash publié par le CDN
  AND si le hash est valide, le modèle est stocké dans l'espace privé de l'app (getApplicationDocumentsDirectory)
  AND le flag on_device_consent_at est enregistré en base via PATCH /api/v1/me/preferences avec timestamp UTC
  AND le bouton bascule en "Modèle téléchargé — Prêt à l'emploi" affiché en vert
```

### Scénario alternatif 1 — Interruption réseau avec reprise automatique

```gherkin
Scenario: Téléchargement interrompu par perte de réseau, reprise automatique sans re-consentement
  GIVEN Marc a démarré le téléchargement du modèle (40% complété, 720 Mo téléchargés)
  AND la connexion réseau est interrompue
  WHEN la connexion est rétablie dans les 30 minutes
  THEN le téléchargement reprend depuis la position interrompue via HTTP Range Requests (pas de recommencement intégral)
  AND la barre de progression affiche correctement 40% à la reprise
  AND Marc n'est pas invité à re-accepter le consentement RGPD
```

### Scénario alternatif 2 — Avertissement RAM insuffisante (non bloquant)

```gherkin
Scenario: Marc active l'opt-in sur un appareil avec 2 GB de RAM
  GIVEN l'appareil de Marc dispose de 2 GB de RAM (en dessous du seuil recommandé de 4 GB)
  WHEN il tente d'activer le toggle "Synthèse on-device"
  THEN un avertissement s'affiche : "Votre appareil (2 GB RAM) est en dessous de la recommandation (4 GB) — performances dégradées possibles"
  AND Marc peut néanmoins confirmer "Continuer quand même" pour démarrer le téléchargement
  AND le téléchargement démarre normalement après confirmation explicite
```

### Scénario erreur 1 — Hash SHA-256 invalide après téléchargement (fichier corrompu)

```gherkin
Scenario: Le modèle téléchargé est corrompu — hash ne correspond pas
  GIVEN le téléchargement du modèle Phi-3 Mini vient de se terminer
  AND le hash SHA-256 calculé diffère du hash publié par le CDN Briefly
  WHEN ModelDownloadService effectue la vérification d'intégrité
  THEN le fichier corrompu est immédiatement supprimé du stockage local
  AND le message "Téléchargement corrompu — réessayez" est affiché
  AND le flag on_device_enabled reste à false (modèle non disponible)
  AND l'incident est loggé (ERROR) sans aucune donnée personnelle
```

### Scénario erreur 2 — Espace de stockage insuffisant sur le device

```gherkin
Scenario: L'espace disque disponible est insuffisant pour stocker le modèle
  GIVEN l'appareil de Marc dispose de moins de 2 GB d'espace de stockage libre
  WHEN il tente de démarrer le téléchargement du modèle (1.8 GB)
  THEN le téléchargement est refusé avant d'avoir démarré
  AND le message "Espace insuffisant — libérez au moins 2 GB pour continuer" est affiché
  AND aucun téléchargement partiel n'est initié
  AND le flag on_device_enabled reste à false
```

---

## Estimation & Références

- **Story Points** : 3
- **MoSCoW** : Should Have
- **Parent SPLIT** : US-015

### Validation INVEST

- [x] **I**ndependent : téléchargement isolé de l'inférence ; livrable autonome (Marc dispose du modèle même si US-015b n'est pas encore livrée)
- [x] **N**egotiable : protocole de reprise (Range Requests vs download manager natif), format modèle (ONNX vs GGUF), CDN provider
- [x] **V**aluable : prépare l'expérience on-device sans bloquer le livrable ; Marc peut vérifier que son consentement RGPD est pris en compte dès cette US
- [x] **E**stimable : téléchargement Flutter avec reprise = package `dio` + `Hive` + validation SHA-256 balisés, 3 pts calibré
- [x] **S**ized : 3 pts ≤ 8 pts ✓
- [x] **T**estable : hash SHA-256 valide/invalide vérifiable, reprise réseau simulable (mock `dio`), avertissement RAM testable en test widget, espace insuffisant simulable
