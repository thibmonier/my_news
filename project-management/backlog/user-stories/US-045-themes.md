# US-045 : Thèmes clair/sombre (Insight Minimalist / Insight Dark)

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-045 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-001 — Thomas, cadre dirigeant tech |
| **Story Points** | 2 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** basculer entre le thème clair (Insight Minimalist) et le thème sombre (Insight Dark) depuis la barre supérieure, ou laisser l'app suivre les préférences de mon système,
**Afin d'** adapter l'interface à mon contexte de lecture : fond clair au bureau en journée, fond sombre lors de mes déplacements en transport le soir, sans effort de navigation dans les paramètres.

---

## Les 3 C

### Carte (résumé)

`ThemeNotifier` (Riverpod) gérant 3 modes : Light (Insight Minimalist), Dark (Insight Dark), System. Icône `light_mode` / `dark_mode` / `brightness_auto` dans l'AppBar (tap cyclique : Light → Dark → System). Préférence persistée localement (`SharedPreferences`). Au démarrage, résolution : préférence stockée > System theme. L'accent émeraude `#10B981` (badges IA "BRIEFLY AI:") est présent dans les deux thèmes. Golden tests pour les deux palettes.

### Conversation

- Le toggle dans l'AppBar cycle-t-il entre 2 modes (Light/Dark) ou 3 (Light/Dark/System) ? (v1 : 3 modes, icône distincte pour chaque)
- La préférence thème est-elle synchronisée avec le compte utilisateur (multi-appareils) ? (v1 : locale uniquement, persistée SharedPreferences)
- Les couleurs exactes des palettes Insight Minimalist et Insight Dark sont-elles définies dans un design system (tokens) ? (oui, à définir avec UI/UX dans les design tokens — EPIC-005 consomme les tokens définis)
- L'accent émeraude #10B981 est-il identique en light et dark ? (oui, identique — couleur de marque réservée IA)
- Les composants tiers (WebView pour "OUVRIR L'ORIGINAL") respectent-ils le thème ? (v1 : WebView en CustomTabsActivity/SFSafariViewController — thème OS, hors contrôle Flutter)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `ThemeNotifier` (Riverpod StateNotifier) | État du thème (Light / Dark / System) |
| **Flutter** | `MaterialApp` + `ThemeData` | Application globale du thème |
| **Flutter** | `AppBar` icon `light_mode`/`dark_mode`/`brightness_auto` | Toggle dans la barre supérieure |
| **Flutter** | `SharedPreferences` | Persistance locale de la préférence |
| **Flutter** | Golden tests (`flutter_test` + `golden_toolkit`) | Validation visuelle des deux palettes |
| **Design System** | Tokens couleur (primaire, fond, texte, accent émeraude #10B981) | Cohérence visuelle inter-écrans |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Basculer vers le thème sombre depuis la barre supérieure

```gherkin
Scenario: Passer du thème clair au thème sombre via l'icône AppBar
  GIVEN l'application est ouverte en thème clair (Insight Minimalist)
    AND l'icône light_mode est affichée dans l'AppBar
  WHEN l'utilisateur tape une fois sur l'icône light_mode
  THEN l'application bascule instantanément (< 100 ms, sans rechargement d'écran) vers le thème sombre (Insight Dark)
    AND tous les écrans visibles (Flux, AppBar, lecteur) adoptent la palette sombre
    AND l'accent émeraude #10B981 reste identique sur les badges "BRIEFLY AI:"
    AND l'icône dans l'AppBar change pour dark_mode
    AND la préférence "dark" est persistée dans SharedPreferences
```

### Scénario alternatif 1 — Mode System (auto-détection des préférences OS)

```gherkin
Scenario: L'app suit automatiquement le thème du système d'exploitation
  GIVEN l'utilisateur a tapé une 2e fois sur l'icône thème (dark_mode → brightness_auto)
    AND la préférence "system" est stockée dans SharedPreferences
  WHEN l'OS bascule en mode sombre (coucher du soleil, réglage automatique)
  THEN l'app passe automatiquement en Insight Dark
  WHEN l'OS bascule en mode clair (lever du soleil)
  THEN l'app passe automatiquement en Insight Minimalist
    AND aucune action utilisateur n'est requise
    AND l'icône AppBar reste brightness_auto pour indiquer le mode automatique
```

### Scénario alternatif 2 — Persistance du thème après fermeture et réouverture

```gherkin
Scenario: Le thème choisi est conservé entre les sessions
  GIVEN l'utilisateur a sélectionné le thème sombre (préférence "dark" dans SharedPreferences)
  WHEN l'utilisateur ferme complètement l'application
    AND rouvre l'application 8 heures plus tard
  THEN l'application démarre directement en thème sombre (Insight Dark)
    AND aucune animation de basculement n'est visible au démarrage
    AND l'icône AppBar affiche dark_mode
```

### Scénario d'erreur 1 — Préférence de thème corrompue ou absente (première installation)

```gherkin
Scenario: Thème par défaut "System" si aucune préférence stockée
  GIVEN c'est le premier lancement de l'application (SharedPreferences vide)
    AND l'OS de l'utilisateur est en mode sombre
  WHEN l'application démarre
  THEN l'application applique le thème sombre (Insight Dark) par défaut (fallback System)
    AND l'icône AppBar affiche brightness_auto
    AND aucune erreur n'est levée si SharedPreferences est indisponible (try/catch gracieux)
```

### Scénario d'erreur 2 — Conflit entre préférence manuelle et changement de thème OS

```gherkin
Scenario: La préférence manuelle prime sur le changement de thème OS
  GIVEN l'utilisateur a explicitement choisi le thème clair (Insight Minimalist) — préférence "light"
    AND l'OS bascule en mode sombre (nuit)
  WHEN l'événement système de changement de thème est déclenché
  THEN l'application reste en thème clair (Insight Minimalist)
    AND la préférence "light" n'est pas écrasée par le changement OS
    AND seul un tap sur l'icône AppBar ou la sélection de "brightness_auto" permet de revenir au suivi OS
```

---

## Estimation

- **Story Points** : 2
- **MoSCoW** : Should Have
- **Complexité** : ThemeData Flutter + Riverpod + SharedPreferences + golden tests

## Validation INVEST

- [x] **Independent** : Dépend de US-040 (AppBar existante) mais verticalement indépendant et livrable seul
- [x] **Negotiable** : Nombre de modes (2 ou 3), synchronisation multi-appareils (v2), palette exacte (design tokens)
- [x] **Valuable** : Confort de lecture multi-contextes, cohérence avec l'expérience web (identité visuelle Briefly AI)
- [x] **Estimable** : 2 points, Flutter ThemeData + SharedPreferences bien maîtrisés
- [x] **Sized** : 2 points <= 8 pts
- [x] **Testable** : Golden tests 2 palettes, tests unitaires ThemeNotifier (Light → Dark → System → Light), tests widget icône AppBar
