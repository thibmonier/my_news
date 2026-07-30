# US-007 : Indicateur de progression de lecture (ligne émeraude 2px)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-007 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 2 (Fibonacci) |
| **Priorité** | Could Have (MoSCoW) |
| **Sprint** | sprint-003-consolidation |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** voir une ligne de progression de lecture émeraude (#10B981, 2px de hauteur) en haut de la page du Daily Brief qui avance proportionnellement à mon scroll,
**Afin de** savoir en un coup d'œil où j'en suis dans ma lecture du brief et ressentir un sentiment d'accomplissement qui m'encourage à aller jusqu'au bout.

### Conversation

- La ligne de progression s'applique-t-elle uniquement à /brief ou à toutes les pages ? Décision : uniquement à la page /brief pour la v1 (scope minimal, YAGNI).
- Le composant est-il un Stimulus controller ou du JavaScript vanilla ? Décision : Stimulus controller (`progress-bar_controller.ts`) — cohérent avec l'architecture Symfony UX du projet.
- La ligne est-elle visible sur mobile ? Décision : oui, même comportement mobile et desktop (position: fixed, top: 0, z-index: 100).
- La couleur est-elle hard-codée ou via une CSS custom property ? Décision : CSS custom property `--color-emerald: #10B981` définie dans le design system (réutilisée pour tous les éléments IA).
- La progression doit-elle inclure uniquement le corps de l'article ou toute la page (header inclus) ? Décision : toute la page, de la position 0 à la position max (document.scrollHeight - window.innerHeight).
- Faut-il une animation CSS transition sur l'avancée ? Décision : oui, `transition: width 0.1s linear` pour un rendu fluide.
- Y a-t-il un risque de performance avec l'écouteur scroll ? Décision : throttle à 50ms via `requestAnimationFrame` dans le controller Stimulus.

### Validation INVEST

- [x] **I**ndependent : dépend de US-001 (page /brief existante) mais indépendant des US IA (US-003 à US-006)
- [x] **N**egotiable : couleur (émeraude #10B981), hauteur (2px), animation, scope (page /brief uniquement)
- [x] **V**aluable : améliore l'expérience de lecture et le taux de complétion du brief — indicateur de rétention produit
- [x] **E**stimable : Stimulus controller simple, CSS pur, estimé 2 pts
- [x] **S**ized : 2 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères mesurables (position du scroll → largeur de la ligne), test Stimulus unitaire

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Template Twig** | `layouts/base.html.twig` (ou `brief/index.html.twig`) | Ajout de `<div data-controller="progress-bar" class="progress-bar" style="width: 0%"></div>` |
| **Stimulus Controller** | `assets/controllers/progress-bar_controller.ts` | `connect()` → addEventListener('scroll') → throttle requestAnimationFrame → met à jour `style.width` |
| **CSS** | `assets/styles/components/_progress-bar.css` | `position: fixed; top: 0; left: 0; height: 2px; background: var(--color-emerald, #10B981); z-index: 100; transition: width 0.1s linear;` |
| **Design Token** | CSS custom property | `--color-emerald: #10B981` (partagé avec les badges IA, réservé aux éléments IA/progression) |
| **Build** | Symfony AssetMapper / Webpack Encore | Compilation TypeScript du controller, bundle CSS |
| **Backend** | Aucun (client-side only) | Pas de requête serveur, pas de persistance — composant 100% frontend. Exception documentée au principe de vertical slicing : indicateur purement visuel sans logique métier serveur ni donnée persistée. |
| **Accessibilité** | ARIA | `role="progressbar"` + `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax="100"` sur le `<div>` |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Progression de lecture au scroll

```gherkin
Scenario: La barre de progression avance proportionnellement au scroll
  GIVEN Thomas consulte la page /brief sur desktop (viewport ≥ 1024px)
  AND la page est scrollable (contenu > viewport)
  WHEN Thomas est au sommet de la page (scroll position = 0)
  THEN la ligne émeraude a une largeur de 0% (invisible)
  WHEN Thomas scrolle jusqu'à mi-page (position = 50% de (scrollHeight - innerHeight))
  THEN la ligne a une largeur d'environ 50% (± 2%)
  WHEN Thomas atteint le bas de la page (scroll position max)
  THEN la ligne a une largeur de 100%
  AND la couleur de la ligne est exactement #10B981 (vérifiée par test Panther `getComputedStyle`)
```

### Scénario alternatif 1 — Comportement identique sur mobile

```gherkin
Scenario: Progression de lecture fonctionnelle sur smartphone
  GIVEN Thomas consulte /brief depuis son smartphone (viewport = 375px)
  WHEN il scrolle jusqu'à 75% de la page
  THEN la ligne émeraude affiche une largeur d'environ 75% (± 2%)
  AND la ligne est visible en haut du viewport (position: fixed, top: 0)
  AND la ligne ne cache aucun contenu lisible (z-index correct, hauteur 2px uniquement)
```

### Scénario alternatif 2 — Réinitialisation via navigation Turbo

```gherkin
Scenario: Réinitialisation de la progression lors de la navigation Turbo
  GIVEN Thomas a scrollé à 80% de la page /brief
  WHEN il clique sur un lien Turbo Drive vers une autre page
  THEN la barre de progression se réinitialise à 0% lors du chargement de la nouvelle page
  AND le controller Stimulus est correctement déconnecté (disconnect() supprime l'écouteur scroll)
  AND aucune fuite mémoire (listener orphelin) n'est détectable
```

### Scénario d'erreur 1 — JavaScript désactivé (accessibilité progressive)

```gherkin
Scenario: Page /brief accessible sans JavaScript (progressive enhancement)
  GIVEN le navigateur de Thomas a JavaScript désactivé
  WHEN il accède à /brief
  THEN la page s'affiche normalement avec les 3 histoires (contenu côté serveur Twig)
  AND la barre de progression n'est pas visible (le controller Stimulus ne s'active pas)
  AND aucun message d'erreur JavaScript n'apparaît dans la page
  AND l'attribut ARIA role="progressbar" reste présent dans le HTML source (accessibilité structurelle)
```

### Scénario d'erreur 2 — Page non-scrollable (contenu court)

```gherkin
Scenario: Barre de progression sur une page dont le contenu tient dans le viewport
  GIVEN la page /brief affiche seulement 1 histoire (brief incomplet) tenant dans le viewport sans scroll
  WHEN Thomas charge la page (scrollHeight - innerHeight = 0)
  THEN le controller Stimulus détecte que la division par zéro est impossible
  AND la largeur de la barre est fixée à 100% (page entièrement "lue" car pas de scroll nécessaire)
  AND aucune erreur JavaScript (NaN, Infinity) n'est loggée dans la console
```
