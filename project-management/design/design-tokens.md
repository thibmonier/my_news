# Design Tokens — Briefly AI (source canonique)

> **Source de vérité** : projet Stitch « Intelligent News Digest » `7076573032400883843` (cf ADR-011).
> Extrait des design systems **Insight Minimalist** (clair, `assets/5fdfcb78a73d41beba1d359fb04284fd`) et
> **Insight Dark** (sombre, `assets/f58f5b2282634093935ec7a53ba2f1c9`).
> **DRY** : ce fichier est l'unique source des valeurs de design du repo. Ne PAS coder ces valeurs en dur ailleurs —
> web et mobile les consomment (CSS custom properties / Flutter ThemeData) sans les redéfinir.
> **Sync** : régénérer via `uiux:generate-design-md` / `uiux:design-tokens`, puis `upload_design_md` + `create_design_system_from_design_md`.

---

## 1. Couleurs

### Thème clair — Insight Minimalist
| Token | Valeur | Usage |
|-------|--------|-------|
| `emerald-accent` | `#10B981` | **IA exclusivement** (badges, synthèses, actions IA) |
| `deep-indigo` | `#1E1B4B` | texte principal, navigation haut niveau |
| `slate-gray` | `#64748B` | texte secondaire, métadonnées |
| `primary` | `#091426` | boutons primaires |
| `on-primary` | `#FFFFFF` | texte sur primaire |
| `primary-container` | `#1E293B` | conteneur primaire |
| `surface` / `background` | `#F7F9FB` | canevas « papier » |
| `surface-container-lowest` | `#FFFFFF` | cartes |
| `surface-border` | `#E2E8F0` | bordures 1px (séparation sans ombre) |
| `on-surface` | `#191C1E` | texte sur surface |
| `on-surface-variant` | `#45474C` | texte atténué |
| `outline` | `#75777D` | contours |
| `secondary` | `#006C49` | vert secondaire (états succès non-IA) |
| `error` | `#BA1A1A` | actions destructives |

### Thème sombre — Insight Dark
| Token | Valeur | Usage |
|-------|--------|-------|
| `emerald-accent` (primary) | `#4EDEA3` / container `#10B981` | IA, boutons primaires (texte noir `#003824`) |
| `surface` / `background` | `#051424` | canevas (bleu marine profond) |
| `surface-container-lowest` | `#010F1F` | niveau le plus bas |
| `surface-container-low` | `#0D1C2D` | |
| `surface-container` | `#122131` | cartes / navigation |
| `surface-container-high` | `#1C2B3C` | |
| `surface-container-highest` | `#273647` | modales / popovers, bordures |
| `on-surface` | `#D4E4FA` | texte principal |
| `on-surface-variant` | `#BBCABF` | texte atténué |
| `outline` | `#86948A` | contours |
| `secondary` | `#7BD0FF` | accent secondaire |
| `error` | `#FFB4AB` | erreurs |

> ⚠️ Le texte prose d'Insight Dark mentionne `#0f172a`/`#1e293b`/`#334155` ; les tokens `designMd` (autoritatifs)
> utilisent la famille `#051424`. **Les tokens ci-dessus font foi** ; la prose sera réalignée à la prochaine sync.

---

## 2. Typographie

| Rôle | Police | Taille / interligne | Poids | Interlettrage |
|------|--------|---------------------|-------|---------------|
| headline-xl (desktop) | **Source Serif 4** | 40 / 48 px | 700 | -0.02em |
| headline-xl-mobile | Source Serif 4 | 30 / 36 px | 700 | — |
| display-lg (dark) | Source Serif 4 | 48 / 56 px | 700 | -0.02em |
| headline-md | Source Serif 4 | 24 / 32 px | 600 | — |
| body-lg | **Inter** | 18 / 28 px | 400 | — |
| body-md | Inter | 16 / 24 px | 400 | — |
| label-caps (clair) | **Hanken Grotesk** | 12 / 16 px | 700 | +0.05em |
| ai-summary (clair) | Hanken Grotesk | 14 / 20 px | 500 | — |
| label-sm (sombre) | **JetBrains Mono** | 12 / 16 px | 500 | +0.05em |

> Police métadonnées **par thème** : Hanken Grotesk (clair) / JetBrains Mono (sombre).

---

## 3. Formes & espacement

| Rayon | Valeur | Usage |
|-------|--------|-------|
| `sm` | 0.125rem (2px) | **badges IA** (précision) |
| `DEFAULT` | 0.25rem (4px) | cartes, champs |
| `md` / `lg` / `xl` | 0.375 / 0.5 / 0.75rem | conteneurs (dark: lg/xl) |
| `full` | 9999px | avatars, logos de sources |

**Rythme vertical : 8px** (commun aux deux thèmes).

| Espacement | Clair (Minimalist) | Sombre (Dark) |
|------------|--------------------|--------------|
| base | (rythme 8px) | 8px |
| margin-mobile | 1rem (16px) | 20px |
| margin-desktop | 2.5rem (40px) | 64px |
| gutter | 1.5rem (24px) | 24px |
| stack-sm / md / lg | 0.5 / 1.5 / 3rem | — (macro-spacing 64px+) |
| container-max (lecture / navigation) | 768px / 1120px | 1280px |

> Les deux systèmes divergent sur les marges et container-max. **Convention repo** : le web (Twig+Turbo) implémente
> Insight Minimalist en primaire, Insight Dark en variante sombre ; le rythme 8px est invariant.

---

## 4. Web — CSS custom properties (généré)

> Fichier exécutable : `project-management/design/design-tokens.css`. Voir aussi ce même bloc ci-dessous.
> Le web consomme ces variables — aucune valeur hex en dur dans les templates Twig.

```css
:root {
  /* couleur */
  --color-emerald-accent: #10B981;   /* IA uniquement */
  --color-deep-indigo:    #1E1B4B;
  --color-slate-gray:     #64748B;
  --color-primary:        #091426;
  --color-on-primary:     #FFFFFF;
  --color-surface:        #F7F9FB;
  --color-surface-card:   #FFFFFF;
  --color-surface-border: #E2E8F0;
  --color-on-surface:     #191C1E;
  --color-on-surface-variant: #45474C;
  --color-error:          #BA1A1A;
  /* typo */
  --font-headline: "Source Serif 4", Georgia, serif;
  --font-body:     "Inter", system-ui, sans-serif;
  --font-meta:     "Hanken Grotesk", ui-sans-serif, sans-serif;
  /* formes */
  --radius-ai: 0.125rem;    /* badges IA */
  --radius:    0.25rem;     /* cartes, champs */
  --radius-full: 9999px;
  /* espacement (rythme 8px) */
  --space-stack-sm: 0.5rem;
  --space-stack-md: 1.5rem;
  --space-stack-lg: 3rem;
  --space-gutter:   1.5rem;
  --margin-mobile:  1rem;
  --margin-desktop: 2.5rem;
  --read-max: 768px;
  --browse-max: 1120px;
}

:root[data-theme="dark"], .theme-dark {
  --color-emerald-accent: #4EDEA3;
  --color-primary:        #4EDEA3;
  --color-on-primary:     #003824;
  --color-surface:        #051424;
  --color-surface-card:   #122131;
  --color-surface-border: #273647;
  --color-on-surface:     #D4E4FA;
  --color-on-surface-variant: #BBCABF;
  --color-error:          #FFB4AB;
  --font-meta:            "JetBrains Mono", ui-monospace, monospace;
  --margin-mobile:  20px;
  --margin-desktop: 64px;
  --browse-max: 1280px;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-emerald-accent: #4EDEA3; --color-primary: #4EDEA3; --color-on-primary: #003824;
    --color-surface: #051424; --color-surface-card: #122131; --color-surface-border: #273647;
    --color-on-surface: #D4E4FA; --color-on-surface-variant: #BBCABF; --color-error: #FFB4AB;
    --font-meta: "JetBrains Mono", ui-monospace, monospace;
  }
}
```

---

## 5. Mobile — Flutter Material 3 ThemeData (généré)

> Le thème Flutter est dérivé des mêmes tokens — pas de duplication de valeurs.

```dart
// lib/design/briefly_theme.dart — généré depuis design-tokens.md (ne pas éditer à la main)
import 'package:flutter/material.dart';

class BrieflyTokens {
  static const emerald = Color(0xFF10B981);   // IA uniquement
  static const deepIndigo = Color(0xFF1E1B4B);
  static const slateGray = Color(0xFF64748B);
  static const surfaceLight = Color(0xFFF7F9FB);
  static const surfaceDark = Color(0xFF051424);
  static const errorLight = Color(0xFFBA1A1A);
}

ThemeData brieflyLight() => ThemeData(
  useMaterial3: true,
  colorScheme: const ColorScheme.light(
    primary: Color(0xFF091426), onPrimary: Colors.white,
    secondary: BrieflyTokens.emerald,           // accent IA
    surface: BrieflyTokens.surfaceLight, onSurface: Color(0xFF191C1E),
    error: BrieflyTokens.errorLight, outline: Color(0xFFE2E8F0),
  ),
  textTheme: const TextTheme(
    displayLarge: TextStyle(fontFamily: 'Source Serif 4', fontSize: 40, height: 48/40, fontWeight: FontWeight.w700, letterSpacing: -0.8),
    headlineMedium: TextStyle(fontFamily: 'Source Serif 4', fontSize: 24, height: 32/24, fontWeight: FontWeight.w600),
    bodyLarge: TextStyle(fontFamily: 'Inter', fontSize: 18, height: 28/18),
    bodyMedium: TextStyle(fontFamily: 'Inter', fontSize: 16, height: 24/16),
    labelSmall: TextStyle(fontFamily: 'Hanken Grotesk', fontSize: 12, height: 16/12, fontWeight: FontWeight.w700, letterSpacing: 0.6),
  ),
);

ThemeData brieflyDark() => ThemeData(
  useMaterial3: true,
  colorScheme: const ColorScheme.dark(
    primary: Color(0xFF4EDEA3), onPrimary: Color(0xFF003824),
    secondary: Color(0xFF7BD0FF),
    surface: BrieflyTokens.surfaceDark, onSurface: Color(0xFFD4E4FA),
    error: Color(0xFFFFB4AB), outline: Color(0xFF273647),
  ),
  // Métadonnées en JetBrains Mono en thème sombre.
);
```

---

## 6. Règles d'usage (invariants — cf constitution §2)

- **Émeraude = IA uniquement** (INV-2). Jamais pour de la décoration non-IA.
- Traçabilité IA jamais uniquement par la couleur : texte « BRIEFLY AI: » + icône (INV-4, WCAG 1.4.1).
- Rayon **2px** réservé aux badges IA (précision) ; **4px** cartes/champs.
- Indicateur de progression de lecture : ligne **émeraude 2px** en haut du viewport.
- Cartes **bordure 1px**, pas d'ombre (sauf hover 4px).

---

**Dernière sync Stitch** : 2026-07-28 · **À resynchroniser** à chaque évolution des design systems Stitch.
