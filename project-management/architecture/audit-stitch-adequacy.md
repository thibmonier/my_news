# Audit d'adéquation Stitch ↔ Implémentation — Briefly AI

> **Date** : 2026-07-31
> **Source de vérité UI** : projet Stitch `7076573032400883843` (INV-7 / ADR-011)
> **Design systems** : Insight Minimalist (`assets/5fdfcb78a73d41beba1d359fb04284fd`) · Insight Dark (`assets/f58f5b2282634093935ec7a53ba2f1c9`)
> **Stack analysée** : Docker up (`briefly-ai-app-1` healthy) · Symfony routes via `debug:router` · HTML rendu via `curl -sk --http2`

---

## 1. Table de correspondance Stitch ↔ Routes implémentées

| Écran Stitch | Screen IDs (light mob / dark mob / light desk / dark desk) | Route(s) impl. | Statut | Adéquation % | Divergences clés |
|---|---|---|---|---|---|
| **Connexion** | `dd93a46a` / `605c78ff` / `054ab2b4` / `f16991257` | `/login` | Partiel | **25 %** | Fonts `system-ui` (vs Source Serif 4 + Inter + Hanken Grotesk) ; bouton `#10B981` → **VIOLATION INV-2** (émeraude = IA only) ; layout 480 px centré vs Stitch two-column ; pas de tokens CSS ; pas de dark mode ; aucun logo BRIEFLY |
| **Tableau de bord** (Daily Brief) | `d5733a8a` / `8de19dd1` / `9d5a083a` / — | `/brief` (+ `/` → redirect) | Partiel | **65 %** | Onglets catégorie absents (For You / Markets / Tech) ; bottom nav absente (Explore / Sources / Saved) ; bookmark absent sur cartes ; Featured Summary (US-006) CSS présent mais section HTML non rendue (conditions non remplies) ; dark mode partiel (media query ok, `data-theme` non câblé) |
| **Lecture d'Article** | `f5d26330` / `58ce52b2` / `443acbd3` / — | — | **MANQUANT** | **0 %** | Aucune route `/article/{id}` ; articles ouverts en lien externe ; EPIC-001/002 (condensé IA, progression lecture) non accessibles dans un écran dédié |
| **Exploration par Sujets** | `8a952852` / `5aa1cee3` / `daa67ee5` / — | — | **MANQUANT** | **0 %** | Aucune route ; EPIC-008 US-070/071 non démarrées |
| **Gestion des Sources** | `bbdb824c` / `0a433dae` / `0b6038c7` / — | `/admin/sources` (CRUD admin) | Partiel (désalignement scope) | **20 %** | Stitch = écran user-facing ; implémenté = admin back-office ; couleurs `#4f46e5` / `#1a1a2e` (hors tokens) ; badges `border-radius 9999px` vs `4px` ; `system-ui` ; pas de tokens |
| **Articles Sauvegardés** | `7f47a12d` / `15d1fcba` / **desktop MANQUANT** / — | — | **MANQUANT** | **0 %** | Aucune route ; EPIC-008 US-072/073 non démarrées ; desktop Stitch également absent (gap §9.9 connu) |
| **Gestion du Compte** | `005c93dc` / `f6c7a6a6` / `e194fff1` / `1f1c2d7a` | `/profile/edit` | Partiel | **30 %** | `system-ui` ; `#10B981` ×4 sur brand, focus, avatar, bouton → **VIOLATION INV-2** ×4 ; `box-shadow` sur `.card` (règle : bordure seule) ; `border-radius 6px` vs token `4px` ; sections privacy / suppression compte (US-035/036) absentes ; pas de dark mode |

### Écrans implémentés sans maquette Stitch

| Route | Description | Remarque |
|---|---|---|
| `/register` | Inscription (form) | Même problèmes que `/login` : `system-ui`, INV-2, pas de tokens |
| `/quota/paywall-modal` | Modal paywall quota | Redirige vers `/login` (non authentifié) — contenu non évalué |

---

## 2. Score d'adéquation global

| Périmètre | Score |
|---|---|
| **Tous les groupes Stitch** (7 groupes, écrans manquants = 0 %) | **20 %** |
| **Écrans implémentés uniquement** (4 groupes avec route) | **35 %** |
| **Tokens / design system** (parmi les 4 écrans impl.) | **~40 %** (seul `/brief` utilise les tokens ; login/register/profile/admin = inline styles) |
| **Conformité INV-2** (émeraude = IA only) | **Critique** — violation dans 3 routes sur 4 |

---

## 3. Inventaire des écrans Stitch

| Titre | Screen ID | Type | Statut impl. |
|---|---|---|---|
| Connexion | `dd93a46a93c047899325463a7248f482` | Mobile clair | Partiel |
| Connexion (Sombre) | `605c78ff90aa436b976d919bbd2b68f0` | Mobile sombre | — (dark mode absent) |
| Connexion - Desktop | `054ab2b42dde49ff9c2730a377cfe0f2` | Desktop clair | Partiel |
| Connexion (Sombre) - Desktop | `f16991257b9d40a2821dcd7ea03b5c52` | Desktop sombre | — |
| Tableau de bord - Briefly AI | `d5733a8a469f40629b0bc1e64df064c8` | Mobile clair | Partiel |
| Tableau de bord (Sombre) | `8de19dd1188c47249d6aa7a3feedc1b0` | Mobile sombre | Partiel |
| Tableau de bord - Desktop | `9d5a083a4831457ab3f66c3564287b1f` | Desktop clair | Partiel |
| Lecture d'Article | `f5d26330c5f24d6d8066a26fa11cae0b` | Mobile clair | MANQUANT |
| Lecture d'Article (Sombre) | `58ce52b2971d400fb99bae4701d293ca` | Mobile sombre | MANQUANT |
| Lecture d'Article - Desktop | `443acbd38e884f55aadce3384075aed6` | Desktop clair | MANQUANT |
| Exploration par Sujets | `8a9528523f8b46b7ae563437a76b348d` | Mobile clair | MANQUANT |
| Exploration par Sujets (Sombre) | `5aa1cee3348645b993cf00ba4885fdf2` | Mobile sombre | MANQUANT |
| Exploration par Sujets - Desktop | `daa67ee504bc4a59b0f57c626792777e` | Desktop clair | MANQUANT |
| Gestion des Sources | `bbdb824c74e74012a3f07c874c75dd19` | Mobile clair | Partiel (désaligné) |
| Gestion des Sources (Sombre) | `0a433dae0dcc4529816e611863d81e25` | Mobile sombre | — |
| Gestion des Sources - Desktop | `0b6038c73b4e4da59248816ddb1aa388` | Desktop clair | Partiel (désaligné) |
| Articles Sauvegardés | `7f47a12d8f644bcb8675e84d265d0f2a` | Mobile clair | MANQUANT |
| Articles Sauvegardés (Sombre) | `15d1fcba2290470dacbae564f23e49f0` | Mobile sombre | MANQUANT |
| **Articles Sauvegardés - Desktop** | **MANQUANT en Stitch** | Desktop | MANQUANT dans Stitch ET impl. |
| Gestion du Compte | `005c93dc9b5148b3b13782116321441a` | Mobile clair | Partiel |
| Gestion du Compte (Sombre) | `f6c7a6a6dfe7445eb7ae11d9266c0a5e` | Mobile sombre | — |
| Gestion du Compte - Desktop | `e194fff1229145ae8967ba6d59990375` | Desktop clair | Partiel |
| Gestion du Compte (Sombre) - Desktop | `1f1c2d7a52574e9db6f281ae41725ea1` | Desktop sombre | — |

---

## 4. Écarts priorisés

### P1 — Divergences majeures (bloquantes DoD / invariants)

| # | Écart | Scope | Invariant violé |
|---|---|---|---|
| P1-1 | **Violation INV-2 : émeraude utilisé hors IA** dans `/login`, `/register`, `/profile/edit` — bouton submit, avatar background, brand logo, input focus — couleur `#10B981` doit être réservée aux features IA (badge "BRIEFLY AI", condensés, synthèse) | login, register, profile | INV-2 |
| P1-2 | **3 groupes d'écrans complètement absents** : Lecture d'Article, Exploration par Sujets, Articles Sauvegardés (6 routes Symfony manquantes) | EPIC-001, EPIC-002, EPIC-008 | — |
| P1-3 | **Tokens CSS absents** dans 3/4 routes implémentées (`/login`, `/register`, `/profile/edit`, `/admin/sources`) — styles inline avec valeurs hexadécimales en dur, polices `system-ui` ; seul `/brief` utilise les CSS custom properties | Toutes routes sauf `/brief` | INV-7 (ADR-011) |
| P1-4 | **Désalignement Gestion des Sources** : Stitch définit un écran user-facing (US-021 user liste ses propres sources) ; implémenté en admin CRUD back-office (`/admin/sources`) — l'écran user-facing est manquant | EPIC-003 | — |

### P2 — Divergences mineures (qualité / expérience)

| # | Écart | Scope |
|---|---|---|
| P2-1 | `/brief` : navigation catégories (For You / Markets / Tech), bottom nav (Explore / Sources / Saved) et bookmarks absents vs Stitch Tableau de bord | `/brief` |
| P2-2 | `/brief` : Featured Summary (US-006) CSS présent mais aucun élément HTML `<div class="featured-summary">` dans la page rendue — section conditionnelle non activée | `/brief` |
| P2-3 | `/brief` : dark mode via `@media (prefers-color-scheme: dark)` uniquement ; attribut `data-theme` non câblé (toggle UI absent) | `/brief` |
| P2-4 | `/profile/edit` : `box-shadow` sur `.card` interdit par le design system (élévation tonale = bordure 1px uniquement) ; `border-radius: 6px` vs token `4px` | `/profile/edit` |
| P2-5 | `/admin/sources` : couleurs hors tokens (`#4f46e5`, `#1a1a2e`), pas de dark mode | `/admin/sources` |
| P2-6 | Aucun toggle thème (bouton clair/sombre) dans le header — présent dans Stitch Dashboard | Global |

### P3 — Écrans manquants en Stitch

| Écran | Besoin |
|---|---|
| `/register` | Maquette Stitch à créer (génération via `generate_screen_from_text`) |
| `/quota/paywall-modal` | Maquette Stitch à créer |
| Articles Sauvegardés - Desktop | Connu (§9.9) — à générer avec `generate_screen_from_text` + design system Insight Minimalist, `deviceType: DESKTOP` |

---

## 5. Actions concrètes pour réaligner sur Stitch

### 5.1 Actions immédiates (P1 — sprint en cours)

```bash
# Action 1 : Corriger les violations INV-2 dans login / register / profile
# Remplacer #10B981 par les tokens appropriés :
#   - Bouton submit primaire : var(--color-primary) #091426
#   - Avatar background : var(--color-primary) #091426  
#   - Input focus : border-color var(--color-deep-indigo) #1E1B4B avec indicateur émeraude 2px (Stitch spec)
#   - Brand logo : var(--color-on-primary) blanc sur fond primaire

# Action 2 : Migrer login / register / profile vers CSS custom properties
# Copier le bloc :root de /brief dans un layout.css partagé
# Supprimer tous les hex hardcodés dans login.html / register.html / profile/edit.html.twig
```

| Action | Fichiers | Effort |
|---|---|---|
| **A1** — Corriger INV-2 (`#10B981` → tokens) | `templates/security/login.html.twig`, `templates/registration/register.html.twig`, `templates/profile/edit.html.twig` | 2h |
| **A2** — Migrer vers tokens CSS dans les 4 templates | Idem + `templates/admin/layout.html.twig` | 4h |
| **A3** — Créer route `/article/{id}` (Lecture d'Article) | Nouveau controller + template calqué sur Stitch `f5d26330` | 1 sprint |
| **A4** — Créer route `/explore` (Exploration par Sujets) | Nouveau controller + template calqué sur Stitch `8a952852` | 1 sprint |
| **A5** — Créer route `/saved` (Articles Sauvegardés) | Nouveau controller + template calqué sur Stitch `7f47a12d` | 1 sprint |

### 5.2 Actions Stitch (maquettes manquantes à générer)

```
# Générer Articles Sauvegardés desktop (gap §9.9)
mcp__stitch__generate_screen_from_text({
  projectId: "7076573032400883843",
  prompt: "Articles Sauvegardés desktop — layout sidebar gauche fixe (nav principale Briefly AI), 
           contenu centré 1120px max, liste articles sauvegardés avec filtres ALL/SUMMARIES en haut, 
           cartes avec titre Source Serif 4, source Hanken Grotesk, badge BRIEFLY AI émeraude, 
           bouton désépingler. Même design system que mobile screen 7f47a12d8f644bcb8675e84d265d0f2a.",
  deviceType: "DESKTOP",
  designSystemId: "assets/5fdfcb78a73d41beba1d359fb04284fd"
})

# Générer Register (inscription)
mcp__stitch__generate_screen_from_text({
  projectId: "7076573032400883843",
  prompt: "Écran d'inscription Briefly AI, mobile 390px. 
           Formulaire : email, fullName, password (avec toggle afficher/masquer), checkbox CGU. 
           Header BRIEFLY logo. Bouton primaire Deep Indigo #091426 (PAS émeraude — INV-2). 
           Lien 'Déjà un compte ? Se connecter'. Même style que écran Connexion dd93a46a.",
  deviceType: "MOBILE",
  designSystemId: "assets/5fdfcb78a73d41beba1d359fb04284fd"
})
```

### 5.3 Actions sur `/brief` (P2)

| Action | Description | Effort |
|---|---|---|
| **B1** — Ajouter navigation catégories | Tabs "Pour vous / Marché / Tech" dans `site-header` | 2h |
| **B2** — Activer Featured Summary | Conditionner l'affichage HTML de `.featured-summary` quand `articles[0].aiSummary` existe | 1h |
| **B3** — Câbler toggle thème | Bouton `data-action="theme-toggle#toggle"` → `document.documentElement.dataset.theme` | 1h |
| **B4** — Ajouter bottom nav mobile | Section `<nav class="bottom-nav">` avec icônes Explore / Sources / Saved | 2h |

---

## 6. Récapitulatif

```
Écrans Stitch : 22 (7 groupes × variantes)
├─ Implémentés (partiellement) : 4 groupes — Connexion, Daily Brief, Sources (désaligné), Profil
│  └─ Adéquation moyenne des 4 groupes impl. : 35 %
├─ Non implémentés : 3 groupes — Lecture d'Article, Exploration, Articles Sauvegardés
│  └─ 6 variantes (mobile + desktop) non couvertes
└─ Desktop manquant en Stitch : Articles Sauvegardés (gap §9.9 connu)

Score global (7 groupes, 0 % pour manquants) : 20 %

Violations critiques :
  - INV-2 (émeraude = IA only) : 3 templates en infraction
  - Tokens CSS absents : 3/4 templates implémentés (hors /brief)
  - 3 groupes d'écrans EPIC-001/002/008 sans route Symfony
```

---

**Révisé par** : audit-stitch-adequacy — Claude Code agent
**Prochaine sync Stitch recommandée** : après correction A1/A2 (INV-2 + tokens)
