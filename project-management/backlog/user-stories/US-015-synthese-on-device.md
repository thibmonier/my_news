# US-015 : Synthèse on-device opt-in Flutter + Phi-3 Mini (P-003)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-015 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-003 Marc — Développeur indépendant, 44 ans, privacy-first |
| **Story Points** | 8 (Fibonacci) |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |

**Dépend de :** US-010 (couche synthèse), EPIC-005 (application Flutter mobile)

---

## User Story — Carte

**En tant que** P-003 Marc, développeur indépendant privacy-first,
**Je veux** activer le traitement on-device (Phi-3 Mini) pour les articles que je lis
**Afin d'** avoir la certitude absolue qu'aucune donnée de lecture ne quitte mon téléphone vers un serveur externe.

---

## Les 3 C

### Carte (résumé)

Mode opt-in dans l'application Flutter : Marc télécharge le modèle Phi-3 Mini (~1.8 GB) une seule fois depuis le CDN Briefly, stocké dans l'espace privé de l'app (`flutter_secure_storage` / Hive). L'inférence est 100% locale via `llm_ffi` ou `onnxruntime_flutter`. L'UI indique clairement "Traitement local — aucune donnée envoyée" avec une icône cadenas. Consentement RGPD explicite requis à l'activation. Fallback serveur optionnel si le modèle n'est pas téléchargé.

### Conversation (notes & questions ouvertes)

- Quel runtime d'inférence Flutter choisir ? `onnxruntime_flutter` (ONNX) vs `llm_ffi` (llama.cpp) ? À décider en refinement Tech Lead : llama.cpp offre plus de contrôle, ONNX est plus standardisé.
- Phi-3 Mini ou Gemma 2B selon les décisions techniques ? Les deux sont mentionnés ; Phi-3 Mini prioritaire (plus léger, ~1.8 GB vs ~2.5 GB pour Gemma 2B 4-bit).
- Minimum RAM requis côté device : 4 GB RAM recommandé (A14+ / Snapdragon 8 Gen 1+). Afficher un avertissement si le device ne correspond pas.
- Le consentement RGPD doit être enregistré localement (Hive) et synchronisé avec le backend (table `user_preferences`).
- Doit-on afficher la progression du téléchargement (1.8 GB) ? Oui, barre de progression avec taille restante et possibilité d'annulation.
- On-device = niveau Concise uniquement en v1 (contrainte de RAM et de vitesse d'inférence).
- Si Marc désactive l'opt-in : le modèle est supprimé du stockage local, le consentement est révoqué en base.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Flutter — UI** | `OnDeviceSettingsScreen` | Toggle opt-in "Synthèse privée (on-device)", bouton de téléchargement, barre de progression, badge cadenas |
| **Flutter — Service** | `OnDeviceInferenceService` | Interface identique à `ServerSynthesisService`, dispatche selon préférence utilisateur |
| **Flutter — ML** | `Phi3MiniEngine` | `onnxruntime_flutter` ou `llama_cpp_dart` ; chargement lazy du modèle en mémoire |
| **Flutter — Storage** | `flutter_secure_storage` | Stockage du flag opt-in + chemin modèle ; `Hive` pour les métadonnées (taille, version, date) |
| **Flutter — Download** | `ModelDownloadService` | Téléchargement CDN Briefly (`https://cdn.briefly.ai/models/phi3-mini-q4.onnx`), hash SHA-256 vérifié, reprise sur interruption |
| **Backend — Symfony** | `UserPreference` entity | `on_device_enabled: bool`, `on_device_consent_at: TIMESTAMPTZ`, synchronisé via `PATCH /api/v1/me/preferences` |
| **RGPD** | Consentement explicite | Modal de consentement RGPD avant téléchargement, enregistré localement ET en base avec timestamp |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Activation opt-in et première synthèse on-device

```gherkin
Scenario: Marc active la synthèse on-device et génère une synthèse locale
  GIVEN Marc est connecté sur l'application Flutter
  AND il accède aux Paramètres > Confidentialité > Synthèse privée
  WHEN il active le toggle "Synthèse on-device (Phi-3 Mini)"
  THEN une modale de consentement RGPD s'affiche expliquant le téléchargement du modèle (1.8 GB)
  AND après acceptation, le téléchargement démarre avec une barre de progression affichant les MB restants
  AND le modèle est vérifié par hash SHA-256 après téléchargement
  AND Marc peut générer une synthèse Concise d'un article sans aucune requête réseau vers Mistral ou OpenAI
  AND l'UI affiche le badge cadenas vert "Traitement local — aucune donnée envoyée"
```

### Scénario alternatif 1 — Interruption du téléchargement et reprise

```gherkin
Scenario: Téléchargement interrompu par perte de réseau, reprise automatique
  GIVEN Marc a démarré le téléchargement du modèle (40% complété)
  AND la connexion réseau est interrompue
  WHEN la connexion est rétablie dans les 30 minutes
  THEN le téléchargement reprend depuis la position interrompue (pas de recommencement intégral)
  AND la progression s'affiche correctement
  AND Marc n'est pas forcé de re-accepter le consentement RGPD
```

### Scénario alternatif 2 — Désactivation de l'opt-in et suppression du modèle

```gherkin
Scenario: Marc révoque son consentement on-device
  GIVEN Marc a le mode on-device activé et le modèle téléchargé
  WHEN il désactive le toggle "Synthèse on-device"
  THEN une modale de confirmation propose "Supprimer le modèle (libère 1.8 GB)" ou "Désactiver seulement"
  AND s'il choisit "Supprimer", le fichier modèle est effacé du stockage local
  AND le flag `on_device_enabled` est mis à false en base avec timestamp de révocation
  AND les synthèses suivantes utilisent à nouveau le serveur Mistral
```

### Scénario erreur 1 — Device insuffisant en RAM (< 4 GB)

```gherkin
Scenario: Marc tente d'activer on-device sur un appareil avec 2 GB de RAM
  GIVEN l'appareil de Marc dispose de 2 GB de RAM
  WHEN il tente d'activer le toggle "Synthèse on-device"
  THEN un message d'avertissement s'affiche : "Votre appareil (2 GB RAM) peut ne pas supporter ce mode — performances dégradées possibles"
  AND Marc peut néanmoins forcer l'activation après confirmation explicite
  AND si l'inférence échoue par OOM, l'UI propose de revenir au mode serveur
```

### Scénario erreur 2 — Hash SHA-256 du modèle invalide après téléchargement

```gherkin
Scenario: Le hash du modèle téléchargé ne correspond pas au hash attendu
  GIVEN le téléchargement du modèle Phi-3 Mini vient de se terminer
  AND le hash SHA-256 calculé diffère du hash publié par le CDN Briefly
  WHEN ModelDownloadService effectue la vérification d'intégrité
  THEN le fichier corrompu est immédiatement supprimé
  AND un message d'erreur "Téléchargement corrompu — réessayez" est affiché
  AND le mode on-device n'est pas activé
  AND l'incident est loggé (ERROR) sans aucune donnée personnelle
```

---

## Estimation & Références

- **Story Points** : 8
- **MoSCoW** : Should Have
- **Validation INVEST** :
  - [x] Independent — couche Flutter orthogonale au backend ; l'interface `SynthesisService` abstraite isole les deux implémentations
  - [x] Negotiable — choix du runtime (ONNX vs llama.cpp), modèle (Phi-3 vs Gemma), niveaux disponibles on-device
  - [x] Valuable — différenciateur fort pour P-003 Marc et segment privacy-conscious ; argument marketing
  - [x] Estimable — runtime mobile ML balisé, intégration Flutter documentée
  - [x] Sized — 8 pts maximum INVEST respecté (découpage possible : téléchargement seul = 3 pts + inférence = 5 pts si nécessaire)
  - [x] Testable — critères mesurables (absence de requête réseau, SHA-256, badge cadenas, RAM check)
