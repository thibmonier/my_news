# US-015b : Inférence locale Phi-3 Mini pour synthèse concise (Flutter on-device)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-015b |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-003 Marc — Développeur indépendant, 44 ans, privacy-first |
| **Story Points** | 5 (Fibonacci) |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Parent (SPLIT)** | US-015 — Synthèse on-device opt-in Flutter + Phi-3 Mini |

**Dépend de :** US-015a (modèle Phi-3 Mini téléchargé et validé), US-010 (interface `SynthesisService` définie au Walking Skeleton)

---

## User Story — Carte

**En tant que** P-003 Marc, développeur indépendant privacy-first,
**Je veux** générer une synthèse concise d'un article directement sur mon téléphone via le modèle Phi-3 Mini local
**Afin d'** avoir la certitude absolue qu'aucune donnée de lecture ne quitte mon appareil vers un serveur externe lors du traitement IA.

---

## Les 3 C

### Carte (résumé)

Lorsque le modèle Phi-3 Mini est téléchargé (US-015a) et l'opt-in activé, le bouton "Synthèse concise" utilise le moteur local (`onnxruntime_flutter`) au lieu du serveur Mistral. L'interface `SynthesisService` est abstraite : `ServerSynthesisService` et `OnDeviceInferenceService` implémentent le même contrat ; le dispatch s'effectue selon `UserPreferences.on_device_enabled`. L'UI affiche le badge cadenas vert "Traitement local — aucune donnée envoyée". Niveau Concise uniquement en v1 (contrainte RAM/vitesse). Si l'inférence échoue par OOM, une modale propose le fallback serveur pour cet article. Le modèle est chargé paresseusement (lazy) et maintenu en mémoire pour la session.

### Conversation (notes & questions ouvertes)

- Quel runtime d'inférence Flutter ? Décision Tech Lead : `onnxruntime_flutter` (ONNX standardisé, multiplateforme iOS/Android, maintenance active).
- Prompt template on-device : même prompt que serveur, tronqué à 4096 tokens si l'article dépasse la fenêtre de contexte de Phi-3 Mini.
- Temps d'inférence acceptable : cible < 20 secondes sur A14 Bionic / Snapdragon 8 Gen 1+. Au-delà de 5 secondes, afficher une barre de progression.
- Faut-il vérifier l'absence de trafic réseau vers Mistral/OpenAI ? Oui : indicateur "Aucune connexion utilisée" dans la synthèse générée ; vérifiable par monitoring réseau en test.
- `Phi3MiniEngine` charge-t-il le modèle une seule fois par session ? Oui, chargement lazy et persistant en mémoire ; déchargement au passage en arrière-plan prolongé (> 10 min) pour libérer RAM.

### Confirmation (critères d'acceptance)

Voir section Critères d'acceptance Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Flutter — Interface** | `SynthesisService` (abstract) | Interface commune à `ServerSynthesisService` et `OnDeviceInferenceService` ; dispatch selon `UserPreferences.on_device_enabled` |
| **Flutter — ML Engine** | `Phi3MiniEngine` | `onnxruntime_flutter` ; chargement lazy du modèle ; inférence avec prompt concise ; fenêtre de contexte 4096 tokens |
| **Flutter — UI** | Badge "Traitement local" | Icône cadenas vert + texte "Aucune donnée envoyée" affiché sur chaque synthèse générée on-device |
| **Flutter — UI** | Barre de progression inférence | Affichée si le délai d'inférence dépasse 5 secondes (indicateur de traitement local en cours) |
| **Flutter — UI** | Modale fallback OOM | Si `OOMException` → "Impossible d'effectuer la synthèse on-device — utiliser le serveur pour cet article ?" |
| **Flutter — UI** | `ArticleSynthesisWidget` | Résultat préfixé "BRIEFLY AI:" format identique au mode serveur (3 points clés + source) |
| **Backend** | Aucune requête émise | Zéro appel réseau vers Mistral, OpenAI ou tout endpoint IA lors d'une synthèse on-device |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Synthèse concise générée 100% en local sans trafic réseau

```gherkin
Scenario: Marc génère une synthèse concise sans aucune requête réseau vers un LLM externe
  GIVEN Marc a le modèle Phi-3 Mini téléchargé et validé (US-015a complétée)
  AND l'opt-in "Synthèse on-device" est activé dans Paramètres > Confidentialité
  AND Marc ouvre un article dans l'application Flutter
  WHEN il appuie sur "Synthèse concise"
  THEN aucune requête réseau n'est émise vers les endpoints Mistral ou OpenAI (0 appel réseau sortant vers les LLM)
  AND la synthèse est générée en moins de 20 secondes sur un appareil A14+ ou Snapdragon 8 Gen 1+
  AND la synthèse est préfixée "BRIEFLY AI:" et contient 3 points clés (format identique au mode serveur)
  AND le badge cadenas vert "Traitement local — aucune donnée envoyée" est affiché sous la synthèse
```

### Scénario alternatif 1 — Désactivation de l'opt-in, retour au mode serveur

```gherkin
Scenario: Marc désactive l'opt-in — les synthèses reprennent via le serveur Mistral
  GIVEN Marc a l'opt-in on-device activé et a généré des synthèses localement
  WHEN il désactive le toggle "Synthèse on-device" dans Paramètres > Confidentialité
  THEN les synthèses suivantes sont envoyées vers le serveur Mistral (comportement par défaut)
  AND le badge cadenas n'apparaît plus sur les nouvelles synthèses
  AND aucune synthèse en cours n'est interrompue
```

### Scénario alternatif 2 — Suppression du modèle pour libérer de l'espace

```gherkin
Scenario: Marc supprime le modèle pour récupérer 1.8 GB de stockage
  GIVEN Marc a le mode on-device activé et le modèle Phi-3 Mini téléchargé
  WHEN il désactive le toggle "Synthèse on-device" et choisit "Supprimer le modèle (libère 1.8 GB)"
  THEN le fichier modèle est supprimé du stockage local de l'application
  AND le flag on_device_enabled est mis à false en base (PATCH /api/v1/me/preferences) avec timestamp de révocation
  AND les synthèses suivantes utilisent à nouveau le serveur Mistral
  AND le bouton redevient "Télécharger le modèle" dans les paramètres
```

### Scénario erreur 1 — Inférence échoue par manque de mémoire vive (OOM)

```gherkin
Scenario: L'inférence locale échoue par OOM sur un appareil à mémoire limitée
  GIVEN Marc a le mode on-device activé sur un appareil avec 2 GB de RAM
  AND Phi3MiniEngine déclenche une inférence et reçoit une OOMException
  WHEN le moteur d'inférence signale l'erreur OOM
  THEN l'inférence est annulée proprement sans crash de l'application
  AND une modale propose "Impossible d'effectuer la synthèse on-device — utiliser le serveur pour cet article ?"
  AND si Marc confirme, la synthèse est effectuée via le serveur sans badge cadenas
  AND l'incident OOM est loggé localement (ERROR) sans aucune donnée personnelle
```

### Scénario erreur 2 — Modèle absent du stockage (US-015a non complétée ou fichier supprimé)

```gherkin
Scenario: Marc tente une synthèse on-device mais le fichier modèle est absent
  GIVEN le flag on_device_enabled est true mais le fichier modèle est absent du stockage local
  WHEN Marc appuie sur "Synthèse concise"
  THEN Phi3MiniEngine détecte l'absence du fichier modèle
  AND affiche le message "Modèle introuvable — téléchargez-le dans Paramètres > Confidentialité"
  AND aucune synthèse n'est effectuée (ni locale ni serveur) jusqu'à résolution
  AND le flag on_device_enabled est automatiquement remis à false pour éviter un état incohérent
```

---

## Estimation & Références

- **Story Points** : 5
- **MoSCoW** : Should Have
- **Parent SPLIT** : US-015

### Validation INVEST

- [x] **I**ndependent : couche Flutter orthogonale au backend ; l'interface `SynthesisService` abstraite permet le développement en isolation complète du serveur
- [x] **N**egotiable : runtime (ONNX vs llama.cpp), niveaux disponibles on-device (Concise uniquement v1), comportement OOM (fallback vs blocage strict)
- [x] **V**aluable : fonctionnalité différenciante livrable et démontrable à la Review Sprint ; Marc peut vérifier l'absence de trafic réseau lui-même avec ses outils (mitmproxy, NextDNS)
- [x] **E**stimable : `onnxruntime_flutter` + abstraction `SynthesisService` + badge cadenas + OOM handler balisés, 5 pts calibré
- [x] **S**ized : 5 pts ≤ 8 pts ✓
- [x] **T**estable : absence de requête réseau vérifiable (mock réseau Flutter + assert 0 appels vers LLM), badge cadenas visible en test widget, OOM simulable, état incohérent (modèle absent) reproduisible
