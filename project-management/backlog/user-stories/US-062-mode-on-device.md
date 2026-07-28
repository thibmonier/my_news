# US-062 : Activer le mode vie privée avec synthèse on-device

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-062 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-003 Marc (dev indépendant, privacy-first) |
| **Story points** | 8 (Fibonacci — maximum, ne pas découper) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-003 : Marc, développeur indépendant privacy-first,
**Je veux** activer un mode vie privée qui exécute la synthèse IA entièrement sur mon appareil (opt-in, avec téléchargement d'un modèle local),
**Afin de** lire et synthétiser des articles sensibles sans qu'aucun texte ne quitte mon appareil, même vers les serveurs Briefly.

---

### Conversation

- **Modèles supportés v1** : Phi-3 Mini (~1,5 Go GGUF Q4_K_M) et Gemma 2B (~1,8 Go GGUF Q4_K_M). Choix laissé à l'utilisateur.
- **Moteur d'inférence** : llama.cpp via FFI Flutter (paquet `llama_cpp_dart`) ou `mediapipe_tasks_genai` selon la plateforme. À arbitrer en Sprint Planning technique.
- **Téléchargement** : chunked download avec progress stream, reprise sur interruption (HTTP Range), vérification intégrité SHA-256 après téléchargement.
- **Avertissement qualité** : la synthèse on-device est inférieure à celle de Mistral (cloud) — badge distinct et message informatif affiché à la première utilisation.
- **Portée v1** : uniquement les synthèses (pas la classification/clustering côté serveur — ne traite pas le texte brut utilisateur).
- **Espace disque** : avertissement si < 2 Go libres avant téléchargement. Affichage de la taille requise.
- **Sécurité du modèle** : fichier stocké dans `getApplicationSupportDirectory()` (répertoire privé app, non accessible à d'autres apps). Clé AES-256 dans `flutter_secure_storage`. Vérification SHA-256 à chaque démarrage du moteur.
- **Désactivation automatique** : si mode on-device actif, aucune requête de synthèse n'est envoyée aux API externes — garde implémentée côté Flutter ET vérifiée côté Symfony (`/api/me/preferences` flag).
- **Disponibilité** : mobile Flutter (Android + iOS) en v1 ; web en v2.
- **Badge visuel** : "ON-DEVICE" en gris anthracite (#374151) distinct de l'émeraude #10B981 réservée à "BRIEFLY AI:" (cloud).

---

### Validation INVEST

- [x] **Independent** — indépendante de US-060, US-061, US-063 ; enrichit US-064 mais ne la bloque pas
- [x] **Negotiable** — moteur d'inférence (llama.cpp vs MediaPipe) et modèles (Phi-3 vs Gemma) négociables
- [x] **Valuable** — différenciateur fort pour P-003, unique sur le marché des news apps ; acquisition niche privacy-first
- [x] **Estimable** — 8 pts : download manager + vérification SHA-256 + moteur inférence + UI choix modèle + badge + persistance préférence API
- [x] **Sized** — exactement 8 pts (maximum autorisé — ne pas découper : la valeur est indivisible sans le téléchargement ET l'inférence)
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Colonne `user_preferences` | `on_device_ai_enabled BOOLEAN DEFAULT FALSE`, `on_device_model VARCHAR(50) CHECK (IN ('phi3-mini','gemma-2b', NULL))` |
| **API Platform** | `GET /api/models` | Liste des modèles disponibles (id, nom, taille_go, url_cdn, sha256, description) — public, non authentifié |
| **API Platform** | `PATCH /api/me/preferences` | `{ onDeviceAiEnabled: bool, onDeviceModel: string }` ; voter ownership |
| **Flutter — Download** | `ModelDownloadService` | Chunked HTTP download (dio), progress StreamController, reprise HTTP Range, vérification SHA-256 post-download |
| **Flutter — Inference** | `OnDeviceAiService` | Charge le modèle GGUF via llama.cpp FFI ; méthode `synthesize(articleText, level) → String` ; timeout 60 s |
| **Flutter — Routing** | `SynthesisRepository` | Switch cloud/on-device selon `UserPreferences.onDeviceAiEnabled` ; aucune requête réseau IA si on-device actif |
| **Flutter — Sécurité** | `SecureModelStorage` | Stockage dans `getApplicationSupportDirectory()` ; clé AES-256 chiffrée dans `flutter_secure_storage` ; vérification intégrité au boot |
| **Flutter — UI** | `OnDeviceAiScreen` | Choix modèle (radio), progress bar téléchargement, toggle activation, statut (non installé / téléchargement / actif) |
| **Flutter — Badge** | `SynthesisBadge` | `ON-DEVICE` (#374151 gris) vs `BRIEFLY AI:` (#10B981 émeraude) |
| **Sécurité OWASP** | Guard Symfony | `ArticleSynthesisController` vérifie `user.onDeviceAiEnabled` avant tout appel API IA externe ; log audit activation/désactivation |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Activation et téléchargement réussi du modèle

```gherkin
Scenario: P-003 active le mode on-device et télécharge Phi-3 Mini
  GIVEN Marc est authentifié sur l'app Flutter avec Wi-Fi actif et 3 Go d'espace libre
  WHEN il accède à Paramètres > Confidentialité > Mode Vie Privée et active le toggle
  THEN l'app affiche "Choisissez un modèle :" avec deux options :
    - "Phi-3 Mini — 1,5 Go — Recommandé"
    - "Gemma 2B — 1,8 Go — Alternative"
  WHEN Marc sélectionne Phi-3 Mini et clique "Télécharger"
  THEN une barre de progression s'affiche avec le pourcentage et l'estimation de temps restant
  AND après téléchargement complet, la vérification SHA-256 est exécutée automatiquement
  AND si SHA-256 valide, le toggle affiche "Mode Vie Privée — ACTIF" avec badge "ON-DEVICE"
  AND PATCH /api/me/preferences est appelé avec { onDeviceAiEnabled: true, onDeviceModel: "phi3-mini" }
```

### Scénario alternatif 02 — Inférence on-device sans réseau

```gherkin
Scenario: Génération d'une synthèse en mode on-device hors connexion
  GIVEN Marc a activé le mode on-device avec Phi-3 Mini installé et l'appareil est en mode avion
  WHEN il demande la synthèse d'un article chargé en cache
  THEN la synthèse est générée localement en moins de 30 secondes (appareil mid-range : Snapdragon 8 Gen 1 ou Apple A15)
  AND la synthèse est préfixée "BRIEFLY AI (ON-DEVICE):" avec badge gris anthracite #374151
  AND un message "Qualité réduite vs synthèse cloud" s'affiche lors de la première utilisation uniquement
  AND aucune requête réseau n'est émise vers les domaines IA de Briefly (vérifiable via proxy Charles/mitmproxy)
```

### Scénario alternatif 03 — Reprise de téléchargement après interruption réseau

```gherkin
Scenario: Interruption réseau à 60% du téléchargement de Phi-3 Mini
  GIVEN Marc télécharge Phi-3 Mini et la progression est à 60%
  WHEN la connexion Wi-Fi est perdue
  THEN le téléchargement se met en pause avec le message "Connexion perdue – reprise automatique dès que possible"
  AND la progression reste affichée à 60% (aucune réinitialisation)
  WHEN la connexion Wi-Fi est rétablie dans les 5 minutes
  THEN le téléchargement reprend depuis le dernier chunk (HTTP Range) sans redémarrer depuis 0%
  AND la progression continue à partir de 60%
```

### Scénario d'erreur 04 — Espace disque insuffisant

```gherkin
Scenario: Tentative de téléchargement sans espace suffisant
  GIVEN Marc tente de télécharger Phi-3 Mini (1,5 Go) avec seulement 900 Mo libres sur l'appareil
  WHEN il clique "Télécharger"
  THEN l'app affiche immédiatement sans lancer le téléchargement :
    "Espace insuffisant : 1,5 Go requis, 900 Mo disponibles"
  AND un bouton "Libérer de l'espace" ouvre les paramètres de stockage de l'OS
  AND le mode on-device reste désactivé (toggle à OFF)
```

### Scénario d'erreur 05 — Corruption du fichier modèle détectée au démarrage

```gherkin
Scenario: Vérification SHA-256 échoue après un téléchargement corrompu
  GIVEN le fichier phi3-mini.gguf a été téléchargé mais son SHA-256 ne correspond pas au hash attendu
  WHEN OnDeviceAiService tente de charger le modèle au démarrage de l'inférence
  THEN le fichier corrompu est supprimé automatiquement
  AND un message s'affiche "Modèle corrompu détecté – Un nouveau téléchargement est requis"
  AND le mode on-device est désactivé automatiquement (toggle OFF, PATCH /api/me/preferences envoyé)
  AND l'app continue à fonctionner en mode cloud (aucune interruption du service de synthèse)
  AND aucune données de l'article n'est envoyée via le moteur on-device défaillant
```
