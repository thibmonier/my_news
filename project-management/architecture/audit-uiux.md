# Audit UI/UX — Briefly AI

**Date :** 2026-07-31
**Périmètre :** `/brief`, `/login`, `/register` (HTML rendu — stack Docker)
**Référentiel :** WCAG 2.2 AAA · Nielsen 10 heuristiques · Design System Insight Minimalist/Dark
**Réviseur :** Audit automatisé (analyse HTML réel + calculs de contraste WCAG)

---

## Scores globaux

| Dimension | Score |
|-----------|-------|
| **Accessibilité WCAG 2.2 AAA** | **52 / 100** |
| **UX (Nielsen 10 heuristiques)** | **57 / 100** |
| **UI Design (DS / états / responsive)** | **51 / 100** |
| **Score global** | **53 / 100** |

---

## Tableau Lighthouse (non mesuré — CLI indisponible)

> Lighthouse CLI non accessible dans l'environnement Docker actuel. Estimation par inference depuis le HTML réel.

| Audit Lighthouse | `/brief` (estimé) | `/login` (estimé) | `/register` (estimé) | Moyenne estimée |
|------------------|-------------------|-------------------|----------------------|-----------------|
| **Accessibility** | ~78 / 100 | ~52 / 100 | ~58 / 100 | **~63 / 100** |
| **Performance** | non mesuré | non mesuré | non mesuré | — |
| **Best Practices** | non mesuré | non mesuré | non mesuré | — |
| **SEO** | non mesuré | non mesuré | non mesuré | — |

> Détail Accessibility `/brief` : pénalités estimées sur contraste émeraude (−10), bouton CTA (−5), skip-nav absent (−5), `<time>` sans `datetime` (−2). Les bons points (ARIA, landmarks, labels, progressbar) remontent le score.
> Détail `/login` et `/register` : pénalités sur absence `<main>` (−15), contraste bouton submit (−10), bordure inputs #ccc (−5), pas de dark mode (−5), erreurs non annoncées (−5).

---

## Issues Critiques

*Bloquants — Level A ou AA, ou violation de l'invariant DS.*

| ID | Pages | Constat (HTML réel) | WCAG / Règle | Contraste calculé |
|----|-------|---------------------|--------------|-------------------|
| **C-01** | Toutes | Aucun lien « Passer au contenu » avant le `<header>` — `id="main-content"` existe sur `<main>` mais inutilisable sans le skip-link | 2.4.1 (A) — Bypass Blocks | — |
| **C-02** | `/login`, `/register` | Pas de `<main>`, `<header>`, `<footer>` — la page entière est dans `<body>` sans landmark | 4.1.2 (A), 1.3.6 (AAA) | — |
| **C-03** | `/brief`, `/login` | Texte émeraude `#10B981` sur fond blanc `#FFFFFF` : **2.53:1** — touche `.ai-summary__badge-text`, `.synthesis-badge`, `.synthesis-btn` label, `.ai-summary__open-link`, `.synthesis-original-link` | 1.4.3 (AA) exige 4.5:1 | 2.53:1 ❌ |
| **C-04** | `/login`, `/register` | Bouton submit `color: #FFFFFF` sur `background: #10B981` : **2.53:1** — aucune alternative textuelle | 1.4.3 (AA) exige 4.5:1 | 2.53:1 ❌ |
| **C-05** | `/brief` | Badge catégorie **orange `#EA580C`** sur `#FFFFFF` (10 px, uppercase) : **3.56:1** — badge `.badge--research` | 1.4.3 (AA) exige 4.5:1 pour texte normal ; 10 px n'est pas « grand texte » | 3.56:1 ❌ |
| **C-06** | `/login`, `/register` | Aucune déclaration `@media (prefers-color-scheme: dark)` — ces pages sont inutilisables visuellement en mode sombre système | Design system NFR, 1.4.3 dark mode | — |
| **C-07** | `/login`, `/register` | Erreurs formulaires non annoncées aux lecteurs d'écran : aucun `role="alert"` / `aria-live`, et les éléments `id="error-email"`, `id="error-fullName"` etc. référencés par `aria-describedby` **n'existent pas dans le DOM initial** — quand ils apparaissent côté serveur il faut les insérer | 4.1.3 (AA) — Status Messages | — |
| **C-08** | `/brief` | Bord bouton synthesis `border: 1px solid #10B981` sur blanc : **2.53:1** — échoue au contraste non-textuel des composants UI | 1.4.11 (AA) exige 3:1 | 2.53:1 ❌ |
| **C-09** | `/login`, `/register` | Violation INV-2 (constitution §2) : émeraude `#10B981` utilisé comme couleur du bouton submit (action non-IA), cassant l'invariant « émeraude = IA uniquement » | DS invariant INV-2 | — |

---

## Issues Majeures

*Non bloquants Sprint 1 mais impactants sur la qualité AA/AAA.*

| ID | Pages | Constat | WCAG / Règle | Valeur mesurée |
|----|-------|---------|--------------|----------------|
| **M-01** | `/brief` | Texte amber `#f59e0b` (`.synthesis-partial`) sur `#FFFFFF` : **2.05:1** — avertissement partial illisible | 1.4.3 (AA) exige 4.5:1 | 2.05:1 ❌ |
| **M-02** | Toutes | Aucun style `:focus-visible` explicite — les éléments interactifs héritent seulement de l'outline navigateur par défaut (souvent invisible sur Chrome) | 2.4.11 (AA) — Focus Appearance | — |
| **M-03** | `/brief` | `input[type="radio"]` dans `.level-option` : zone de clic effective estimée à ~20×20 px (padding minimal) | 2.5.5 (AA) — 44×44 px minimum | ~20 px ❌ |
| **M-04** | `/brief` | Animation `.synthesis-skeleton { animation: pulse 1.5s infinite }` sans `@media (prefers-reduced-motion: no-preference)` | 2.3.3 (AAA) — Animation from Interactions | — |
| **M-05** | `/brief` | Police Material Symbols Rounded chargée depuis `fonts.gstatic.com` (Google CDN externe) — viole la CSP `default-src 'self'` et crée une dépendance RGPD | CSP § 16.4 tech-spec, privacy | — |
| **M-06** | `/brief` | `--color-emerald-accent` non surchargé dans le bloc `@media (prefers-color-scheme: dark)` inline du template — bug : en dark mode le template utilise `#10B981` (clair) au lieu de `#4EDEA3` (foncé, token officiel) | DS dark mode, 1.4.3 (AA) dark mode | — |
| **M-07** | `/register` | Hint du mot de passe (`<small>Minimum 12 caractères…`) non relié à l'input via `aria-describedby` — les AT n'annoncent pas les exigences au focus | 1.3.1 (A) — Info and Relationships | — |
| **M-08** | `/brief` | `<time>LAST UPDATED 29 Jul 2026 19:06 UTC</time>` sans attribut `datetime` machine-readable ; contenu en anglais dans une page `lang="fr"` | 1.3.1 (A), 3.1.2 (AA) — Language of Parts | — |
| **M-09** | `/login`, `/register` | Design tokens non appliqués — couleurs hardcodées (`#10B981`, `#ccc`, `#6b7280`), police `system-ui` au lieu de Inter/Hanken Grotesk, `border-radius: 4px` sans variable — rupture totale du DS | DS (DRY) | — |
| **M-10** | `/login`, `/register` | Bordures inputs `#ccc` (`#CCCCCC`) sur `#FFFFFF` : **1.55:1** — composant UI invisible à faible contraste | 1.4.11 (AA) exige 3:1 pour UI | 1.55:1 ❌ |
| **M-11** | `/brief` | Outline `#75777D` sur blanc : **4.48:1** — juste sous le seuil AA 4.5:1 pour du texte normal (`story-source`, 12 px) | 1.4.3 (AA) | 4.48:1 ❌ |
| **M-12** | Toutes | Polices Source Serif 4, Inter, Hanken Grotesk déclarées dans les tokens CSS mais aucune `@font-face` ni `<link>` Google Fonts visible dans le HTML rendu — les pages tombent en fallback Georgia/system-ui | Performance/DS | — |
| **M-13** | Toutes | Liens `target="_blank"` sans indication visuelle ou AT de l'ouverture nouvel onglet (les `aria-label` ne le mentionnent pas pour les liens article) | 3.2.5 (AAA) — Change on Request | — |

---

## Issues Suggérées

*Heuristiques Nielsen, bonnes pratiques UX et AAA non critiques.*

| ID | Heuristique | Pages | Constat |
|----|-------------|-------|---------|
| **S-01** | H9 — Aide récupération erreur | `/login` | Aucun lien « Mot de passe oublié » — friction inutile |
| **S-02** | H1 — Visibilité état système | `/login`, `/register` | Bouton submit sans état loading (pas de `aria-busy`, pas de texte « Connexion… ») |
| **S-03** | H3 — Contrôle utilisateur | `/brief` | Une fois la synthèse générée, `btn.textContent = '✓ SYNTHÈSE GÉNÉRÉE'` reste désactivé — impossible de regénérer ou changer le niveau sans recharger la page |
| **S-04** | H4 — Cohérence | `/brief` | Labels boutons et niveaux en anglais (`GENERATE AI SUMMARY`, `Concise`, `Detailed`, `Narrative`) sur une interface déclarée `lang="fr"` |
| **S-05** | H4 — Cohérence | Toutes | Les pages auth ont un rendu complètement différent de `/brief` (font, couleurs, layout, tone) — absence totale de continuité de marque |
| **S-06** | H8 — Design minimaliste | `/brief` | `.featured-summary { display: none }` masque un contenu riche sur mobile sans alternative ; la `<section>` n'existe pas dans le DOM mobile |
| **S-07** | WCAG 2.4.8 (AAA) | Toutes | Aucun fil d'Ariane ni indicateur de position dans le site |
| **S-08** | H6 — Reconnaissance | `/register` | Les champs marqués `*` obligatoires n'ont pas de légende explicative `<p>* Champ obligatoire</p>` |
| **S-09** | H1 — Visibilité | `/brief` | `aria-busy="true"` présent sur le skeleton ✓ mais `.synthesis-skeleton` en animation perpétuelle sans seuil d'échec visible côté UX (uniquement un message d'erreur après timeout JS) |
| **S-10** | H7 — Flexibilité | `/brief` | Sélecteur de niveau (radio) avec un seul choix présélectionné, mais pas de persistance du choix utilisateur entre les cartes — chaque ouverture revient à « Concis » |

---

## Points positifs

| # | Constat positif |
|---|----------------|
| 1 | **Structure ARIA exemplaire sur `/brief`** : `role="progressbar"` avec `aria-valuenow/min/max` mis à jour dynamiquement via JS RAF throttlé, `aria-live="polite"` sur les zones de synthèse, `role="region" aria-label` sur les résumés IA. |
| 2 | **Landmarks corrects sur `/brief`** : `<header role="banner">`, `<nav aria-label="Navigation principale">`, `<main id="main-content">`, `<footer role="contentinfo">`. |
| 3 | **Labels formulaires 100% associés** : tous les `<input>` ont un `<label for="...">` sur les 3 pages. |
| 4 | **Hiérarchie de titres stricte** : `h1` unique → `h2` (titres stories) sur `/brief` ; `h1` seul sur auth. |
| 5 | **Invariant INV-2 respecté sur `/brief`** : émeraude réservé IA, badge dégradé sans couleur verte, `aria-label="Résumé automatique indisponible"` sur le state dégradé. |
| 6 | **Gestion états JS robuste** : skeleton loading avec `aria-busy="true"`, 5 états d'erreur (401, 403, 429, 422, réseau/timeout), messages clairs et contextuels. |
| 7 | **Touch target radio élargi** : `<label class="level-option">` enveloppe le `<input>` — la zone cliquable s'étend à tout le label. |
| 8 | **Sécurité liens externes** : `rel="noopener noreferrer"` présent sur tous les `target="_blank"`. |
| 9 | **CSRF tokens** présents sur les deux formulaires auth. |
| 10 | **OAuth accessible** : SVG icônes avec `aria-hidden="true"` et `focusable="false"`, texte visible suffit à identifier l'action. |
| 11 | **Dark mode partiel fonctionnel sur `/brief`** : 6 tokens critiques surchargés dans la media query. |
| 12 | **Contraste corps de texte correct** : `#45474C` (on-surface-variant) sur `#F7F9FB` (surface) = **7.62:1** — passe AAA. `#191C1E` sur blanc = **~16:1** — passe AAA. |
| 13 | **Badges catégories : 4/5 couleurs passent AA** : violet 5.70:1, rouge 4.83:1, bleu 5.17:1, vert 5.01:1. |

---

## Plan d'action

### P1 — Critique (Sprint en cours, avant merge de toute page publique)

| Action | Fichier(s) concerné(s) | Effort |
|--------|------------------------|--------|
| **P1-01** Ajouter skip-link sur les 3 templates | `templates/brief/index.html.twig`, `templates/security/login.html.twig`, `templates/registration/register.html.twig` | 30 min |
| **P1-02** Remplacer texte émeraude en foreground par `--color-deep-indigo` (#1E1B4B, 14.1:1 vs blanc) sur : `.synthesis-btn` label, `.synthesis-badge`, `.ai-summary__badge-text` nominal, `.ai-summary__open-link` | `<style>` inline /brief | 1 h |
| **P1-03** Changer couleur bouton submit/CTA : fond `var(--color-primary)` (#091426) + texte `var(--color-on-primary)` (#FFFFFF) → 13.2:1 ✓ — respecte INV-2 (non-IA = non-émeraude) | templates auth + brief CSS | 1 h |
| **P1-04** Badge orange : passer `--color-badge-orange: #B45309` (4.71:1 vs blanc) | `<style>` inline /brief | 15 min |
| **P1-05** Ajouter landmarks `<main>`, `<header>`, `<footer>` + classes `site-header`/`site-footer` dans les templates login/register | templates auth | 45 min |
| **P1-06** Appliquer le design system sur /login et /register : importer le CSS tokens, remplacer couleurs hardcodées | templates auth | 3 h |
| **P1-07** Ajouter `@media (prefers-color-scheme: dark)` complet sur les templates auth (même bloc que /brief, + `--color-outline`, `--color-primary-container`, `--font-meta`) | templates auth | 1 h |
| **P1-08** Erreurs formulaire : ajouter `<div role="alert" aria-live="assertive" id="form-errors" class="sr-only"></div>` et rendre les `<span id="error-{field}">` en DOM même vides (hidden) dès le rendu initial | templates auth | 1 h |
| **P1-09** Corriger dark mode `/brief` : ajouter `--color-emerald-accent: #4EDEA3`, `--color-outline: #86948A`, `--color-primary-container: #10B981` dans le bloc dark inline | brief CSS | 15 min |
| **P1-10** Bordure synthesis-btn : remplacer `var(--color-emerald-accent)` → `var(--color-deep-indigo)` ou augmenter épaisseur + appliquer l'action P1-02 | brief CSS | 30 min |

**Durée P1 estimée : ~9 h**

---

### P2 — Majeur (Sprint suivant)

| Action | Fichier(s) | Effort |
|--------|-----------|--------|
| **P2-01** Focus visible global : ajouter `:focus-visible { outline: 2px solid var(--color-deep-indigo); outline-offset: 2px; }` dans le reset CSS | CSS global | 15 min |
| **P2-02** Amber → `#B45309` dans `.synthesis-partial` : 4.71:1 vs blanc, passe AA | brief CSS | 5 min |
| **P2-03** prefers-reduced-motion : wrapper `.synthesis-skeleton` dans `@media (prefers-reduced-motion: no-preference) { animation: pulse… }` | brief CSS | 10 min |
| **P2-04** Material Symbols : héberger localement la fonte woff2 dans `public/fonts/` ou remplacer l'icône par un SVG inline `aria-hidden` | brief + Dockerfile | 2 h |
| **P2-05** `aria-describedby` password : ajouter `id="hint-password"` sur la `<small>` et `aria-describedby="hint-password error-plainPassword"` sur l'input | register template | 10 min |
| **P2-06** `<time datetime="2026-07-29T19:06:00Z">` : rendre dynamique depuis le contrôleur PHP | brief template + controller | 30 min |
| **P2-07** Outline `#75777D` → `#6B7280` (4.60:1 vs blanc, passe AA) ou utiliser `var(--color-on-surface-variant)` pour `.story-source` | brief CSS | 5 min |
| **P2-08** Touch target radio : ajouter `padding: 0.5rem 0.25rem; min-height: 44px` sur `.level-option` | brief CSS | 10 min |
| **P2-09** Bordures input : passer `border: 1px solid #6B7280` (4.60:1) ou `var(--color-on-surface-variant)` sur auth | auth CSS | 10 min |
| **P2-10** Charger les polices web : ajouter `<link rel="preconnect" href="https://fonts.googleapis.com">` + `<link>` pour Source Serif 4/Inter/Hanken Grotesk dans le layout Twig | base.html.twig | 30 min |
| **P2-11** Liens external new tab : ajouter `(s'ouvre dans un nouvel onglet)` en visually-hidden dans les aria-labels des liens `target="_blank"` | brief template | 30 min |

**Durée P2 estimée : ~5 h**

---

### P3 — Amélioration UX (Sprint 2+)

| Action | Heuristique | Effort |
|--------|-------------|--------|
| **P3-01** Lien « Mot de passe oublié » sur `/login` | H9 | 2 h (route + template) |
| **P3-02** Loading state bouton submit : `aria-busy="true"` + texte « Connexion… » via JS léger | H1 | 1 h |
| **P3-03** Traduire labels EN→FR : `GENERATE AI SUMMARY → GÉNÉRER LA SYNTHÈSE IA`, `Concise/Detailed/Narrative → Concis/Détaillé/Narratif` | H4 | 30 min |
| **P3-04** Bouton reset synthèse : permettre régénération avec un autre niveau, ou réinitialiser le bouton après confirmation | H3 | 2 h |
| **P3-05** Featured summary mobile : créer variante `<summary-card-compact>` visible en mobile | H8 | 3 h |
| **P3-06** Légende `*` obligatoire : `<p class="form-legend">Les champs marqués * sont obligatoires</p>` | H6 | 10 min |
| **P3-07** Persistance niveau de synthèse : stocker en `localStorage` et pré-sélectionner entre les cartes | H7 | 1 h |
| **P3-08** Fil d'Ariane / localisation : ajouter un `<nav aria-label="Fil d'Ariane">` minimal | WCAG 2.4.8 | 1 h |

**Durée P3 estimée : ~11 h**

---

## Arbitrages (ordre de priorité)

```
A11y AAA > Lighthouse AA > UX > Mobile-first > DS cohérence
```

| Conflit | Décision |
|---------|----------|
| Émeraude sur fond blanc (INV-2 vs contraste) | **A11y gagne** : l'émeraude ne peut jamais être du texte en foreground sur fond clair. Utiliser deep-indigo (#1E1B4B) pour les labels IA ; l'émeraude reste pour les bordures d'encadrement IA (3:1 non-text OK). |
| Badge catégorie coloré (UX identitaire vs AAA) | **AA d'abord** : corriger orange uniquement (3.56:1 < 4.5:1 AA). Les autres couleurs (violet 5.70, rouge 4.83, bleu 5.17, vert 5.01) passent AA. AAA (7:1) non atteignable pour les badges colorés sur blanc — acceptable si les labels textuels sont présents (ils le sont via `aria-label`). |
| Pages auth rapidement fonctionnelles vs DS complet | **Priorité Level A** : landmarks + dark mode d'abord (P1). DS visuel complet en P2 (pas bloquant si fonctionnel). |
| Material Symbols CDN vs performance | **Privacy/CSP d'abord** : auto-héberger. Si délai Sprint 1, fallback texte-SVG inline accepté provisoirement. |
| Animation skeleton (UX chargement) vs prefers-reduced-motion | **AAA réduit-motion gagne** : un texte statique « Génération en cours… » est suffisant pour l'UX sans animation. |

---

## Synthèse des calculs de contraste (tableau de référence)

| Paire couleurs | Ratio calculé | Seuil AA | Seuil AAA | Verdict |
|----------------|---------------|----------|-----------|---------|
| `#10B981` texte sur `#FFFFFF` | 2.53:1 | 4.5:1 | 7:1 | ❌ Critique |
| `#FFFFFF` sur bouton `#10B981` | 2.53:1 | 4.5:1 | 7:1 | ❌ Critique |
| Bordure `#10B981` sur `#FFFFFF` (non-text) | 2.53:1 | 3:1 | — | ❌ Critique |
| `#EA580C` badge sur `#FFFFFF` | 3.56:1 | 4.5:1 | 7:1 | ❌ Critique |
| `#f59e0b` text sur `#FFFFFF` | 2.05:1 | 4.5:1 | 7:1 | ❌ Critique |
| `#75777D` text sur `#FFFFFF` | 4.48:1 | 4.5:1 | 7:1 | ❌ Majeur (1 centième) |
| `#64748B` text sur `#FFFFFF` | 4.76:1 | 4.5:1 | 7:1 | ⚠️ AA ✓, AAA ✗ |
| `#DC2626` texte sur `#FEE2E2` (error box) | 3.96:1 | 4.5:1 | 7:1 | ⚠️ Fail AA 14 px |
| `#CCCCCC` bordure sur `#FFFFFF` (inputs auth) | 1.55:1 | 3:1 (1.4.11) | — | ❌ Majeur |
| `#45474C` sur `#F7F9FB` | 7.62:1 | 4.5:1 | 7:1 | ✅ AAA |
| `#191C1E` sur `#FFFFFF` | ~16:1 | 4.5:1 | 7:1 | ✅ AAA |
| `#374151` OAuth text sur `#FFFFFF` | 8.84:1 | 4.5:1 | 7:1 | ✅ AAA |
| `#7C3AED` badge sur `#FFFFFF` | 5.70:1 | 4.5:1 | 7:1 | ⚠️ AA ✓, AAA ✗ |
| `#DC2626` badge sur `#FFFFFF` | 4.83:1 | 4.5:1 | 7:1 | ⚠️ AA ✓, AAA ✗ |
| `#FFFFFF` logo sur `#1E293B` (header) | 14.6:1 | 4.5:1 | 7:1 | ✅ AAA |
| `#10B981` sur `#1E293B` (dark header) | 5.77:1 | 4.5:1 | 7:1 | ⚠️ AA ✓, AAA ✗ |

---

## Détail UX — 10 heuristiques Nielsen

| # | Heuristique | Score | Observations clés |
|---|-------------|-------|-------------------|
| H1 | Visibilité de l'état système | 3.5 / 5 | `/brief` : skeleton loading, aria-busy, progress bar IA excellents. Auth : bouton submit sans état loading, aucun retour pendant la soumission. |
| H2 | Correspondance monde réel | 3 / 5 | Featured summary absent sur mobile (contenu inexistant), labels tech (`NARRATIVE`, `DETAILED`) peu naturels. |
| H3 | Contrôle et liberté | 2.5 / 5 | Pas de dismiss de la synthèse générée. Pas de mot de passe oublié. Niveau de synthèse non réinitialisable entre cartes. |
| H4 | Cohérence et standards | 2 / 5 | Rupture majeure entre `/brief` (DS complet) et pages auth (system-ui, couleurs hardcodées). Labels EN sur UI FR. |
| H5 | Prévention des erreurs | 3.5 / 5 | Exigences mot de passe documentées (`<small>`). `novalidate` + validation serveur. Mais `<small>` non lié au champ. |
| H6 | Reconnaissance > rappel | 3 / 5 | Niveaux de synthèse labellés en anglais. Badge catégories bien visuels. Pas de légende pour `*`. |
| H7 | Flexibilité et efficacité | 3.5 / 5 | OAuth fourni ✓. Niveau de synthèse sélectionnable ✓. Pas de persistance, pas de raccourcis. |
| H8 | Design minimaliste | 3.5 / 5 | `/brief` épuré et lisible. Auth fonctionnel mais sans esthétique. État dégradé IA bien géré. |
| H9 | Aide récupération d'erreur | 2.5 / 5 | Erreurs JS synthèse claires et contextuelles ✓. Erreurs formulaires non annoncées AT. Pas de guidance sur `/login`. |
| H10 | Aide et documentation | 3 / 5 | Hint mdp sur register ✓. Aucune page d'aide contextuelle. No tooltip sur les niveaux de synthèse. |
| **Moyenne** | | **2.85 / 5 → 57 / 100** | |

---

*Rapport généré par analyse statique du HTML rendu (curl HTTPS). Calculs de contraste WCAG 2.2 selon formule luminance relative sRGB. Aucune exécution de test automatisé (axe/pa11y/Lighthouse CLI) dans l'environnement Docker.*

**Prochaine revue recommandée :** après P1 (Sprint actuel), relancer audit sur les pages auth et vérifier le contraste des nouvelles couleurs.
