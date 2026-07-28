# ADR-011 — Design system & source de vérité UI = projet Stitch « Intelligent News Digest »

## Statut

**Accepté** — 2026-07-28. Gravé dans la constitution (§2 INV-7, §4). Amendement via la procédure §9 de la constitution.

## Contexte

Les maquettes de Briefly AI vivent dans le projet **Google Stitch** « Intelligent News Digest » :

- **Project ID** : `7076573032400883843`
- **23 écrans** (22 UI mobile+desktop, thèmes clair et sombre) + 1 note « Project Brief ».
- **2 design systems** publiés :
  - **Insight Minimalist** (clair) — asset `assets/5fdfcb78a73d41beba1d359fb04284fd`
  - **Insight Dark** (sombre) — asset `assets/f58f5b2282634093935ec7a53ba2f1c9`

Sans gouvernance, deux dérives menacent : (1) les valeurs de design se dupliquent (captures d'écran, CSS, thème Flutter, PRD) et divergent ; (2) l'implémentation invente des écrans/variantes non validés. Il faut UNE source de vérité et un pont machine-lisible vers les deux stacks (Twig+Turbo, Flutter).

## Décision

**Le projet Stitch `7076573032400883843` est la source de vérité UI de Briefly AI.**

1. **Référence visuelle** : tout écran et toute évolution UI passe d'abord par Stitch. Les captures ne sont PAS la source — le projet Stitch l'est.
2. **Tokens versionnés (DRY)** : un artefact canonique `project-management/design/design-tokens.md` (+ `design-tokens.css`) extrait les tokens des deux design systems Stitch. C'est la **source unique** des valeurs de design côté repo. Aucune valeur de couleur/typo/espacement n'est codée en dur ailleurs.
3. **Machine-lisible, implémentation native par stack** :
   - Web (Twig+Turbo) : CSS custom properties générées depuis les tokens.
   - Mobile (Flutter) : `ThemeData` Material 3 générée depuis les mêmes tokens.
   - Stitch reste la référence visuelle ; chaque stack implémente nativement — **zéro duplication** de valeurs.
4. **Synchronisation** : `design-tokens.md` reste aligné avec Stitch via `upload_design_md` + `create_design_system_from_design_md`. Les commandes projet `uiux:generate-design-md` / `uiux:design-tokens` peuvent produire/régénérer l'artefact.
5. **Écrans manquants** : produits via Stitch (`generate_screen_from_text` / `generate_variants`) avant implémentation — jamais inventés côté code. Gap connu : **« Articles Sauvegardés » desktop absent** (existe mobile clair+sombre uniquement) — cf brief §9.9.
6. **Definition of Done** : une US UI n'est *done* que si elle correspond à l'écran Stitch référencé **et** respecte l'accessibilité (WCAG 2.1 AA, Lighthouse ≥ 90).

## Alternatives considérées

| Option | Rejet |
|--------|-------|
| Figma / autre outil | Les maquettes existent déjà dans Stitch ; migrer = coût + perte de la génération IA d'écrans. |
| Captures comme référence | Non versionnable finement, diverge, pas de tokens machine-lisibles. |
| Tokens dupliqués par stack | Viole DRY (constitution §4) — dérive garantie entre web et mobile. |
| Design system codé en dur dans chaque stack sans source | Pas de source de vérité, impossible à auditer en revue. |

## Conséquences

**Positives**
- Source de vérité unique et auditable ; cohérence web/mobile garantie par construction.
- Accessibilité gravée dans la DoD (corrige le gap A11y relevé par la design-review).
- Écrans manquants tracés et générés proprement (§9.9).

**Négatives / coûts**
- Dépendance à la disponibilité du MCP Stitch (les tokens versionnés dans le repo servent de repli hors-ligne).
- Discipline requise : toute évolution UI commence par Stitch, pas par le code.
- Deux design systems (clair/sombre) ont des échelles d'espacement et une police métadonnées différentes (Hanken Grotesk clair vs JetBrains Mono sombre) — à réconcilier dans les tokens.

## Références

- `project-management/design/design-tokens.md` (artefact canonique)
- `project-management/design/screen-inventory.md` (23 écrans → EPICs/US)
- brief.md §7 (identité visuelle), §9.9 (écran manquant)
- Constitution §2 (INV-7), §4 (design)
