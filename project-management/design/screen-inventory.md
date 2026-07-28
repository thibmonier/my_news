# Inventaire des écrans Stitch → EPICs / User Stories

> **Source** : projet Stitch `7076573032400883843` (cf ADR-011). Snapshot 2026-07-28.
> Chaque US UI référence son **écran Stitch exact** (colonne Screen ID). C'est un critère de la DoD (§5).
> Design systems : Insight Minimalist (clair) / Insight Dark (sombre).

## Écrans UI (22)

| Écran | Device | Thèmes présents | EPIC | US référentes | Screen ID (light / mobile) |
|-------|--------|-----------------|------|---------------|----------------------------|
| **Connexion** | Mobile + Desktop | clair + sombre | EPIC-004 | US-030 (inscription), US-031 (OAuth Google/GitHub) | `dd93a46a93c047899325463a7248f482` (mobile), `054ab2b42dde49ff9c2730a377cfe0f2` (desktop) |
| **Tableau de bord** (Daily Brief) | Mobile + Desktop | clair + sombre | EPIC-001 | US-001 (page publique), US-002 (3 histoires), US-005 (cartes typées), US-006 (Featured Summary desktop) | `d5733a8a469f40629b0bc1e64df064c8` (mobile), `9d5a083a4831457ab3f66c3564287b1f` (desktop) |
| **Lecture d'Article** | Mobile + Desktop | clair + sombre | EPIC-001 / EPIC-002 | US-004 (condensé IA), US-007 (progression lecture), US-010 (synthèse à la demande) | `f5d26330c5f24d6d8066a26fa11cae0b` (mobile), `443acbd38e884f55aadce3384075aed6` (desktop) |
| **Exploration par Sujets** | Mobile + Desktop | clair + sombre | EPIC-008 | US-071 (sujets chauds par catégorie), US-070 (thèmes d'intérêt) | `8a9528523f8b46b7ae563437a76b348d` (mobile), `daa67ee504bc4a59b0f57c626792777e` (desktop) |
| **Gestion des Sources** | Mobile + Desktop | clair + sombre | EPIC-003 | US-021 (CRUD sources admin), US-025 (Google News) | `bbdb824c74e74012a3f07c874c75dd19` (mobile), `0b6038c73b4e4da59248816ddb1aa388` (desktop) |
| **Articles Sauvegardés** | Mobile **seul** | clair + sombre | EPIC-008 | US-072 (bibliothèque), US-073 (filtres ALL/SUMMARIES) | `7f47a12d8f644bcb8675e84d265d0f2a` (mobile), **desktop MANQUANT** |
| **Gestion du Compte** | Mobile + Desktop | clair + sombre | EPIC-004 / EPIC-007 | US-032 (profil), US-035 (confidentialité), US-036 (suppression), US-060/063 (privacy) | `005c93dc9b5148b3b13782116321441a` (mobile), `e194fff1229145ae8967ba6d59990375` (desktop) |

## Screen IDs — variantes sombres & desktop

| Écran | Mobile sombre | Desktop clair | Desktop sombre |
|-------|---------------|---------------|----------------|
| Connexion | `605c78ff90aa436b976d919bbd2b68f0` | `054ab2b42dde49ff9c2730a377cfe0f2` | `f16991257b9d40a2821dcd7ea03b5c52` |
| Tableau de bord | `8de19dd1188c47249d6aa7a3feedc1b0` | `9d5a083a4831457ab3f66c3564287b1f` | — |
| Lecture d'Article | `58ce52b2971d400fb99bae4701d293ca` | `443acbd38e884f55aadce3384075aed6` | — |
| Exploration par Sujets | `5aa1cee3348645b993cf00ba4885fdf2` | `daa67ee504bc4a59b0f57c626792777e` | — |
| Gestion des Sources | `0a433dae0dcc4529816e611863d81e25` | `0b6038c73b4e4da59248816ddb1aa388` | — |
| Articles Sauvegardés | `15d1fcba2290470dacbae564f23e49f0` | **MANQUANT** | **MANQUANT** |
| Gestion du Compte | `f6c7a6a6dfe7445eb7ae11d9266c0a5e` | `e194fff1229145ae8967ba6d59990375` | `1f1c2d7a52574e9db6f281ae41725ea1` |

## Écran manquant (gap brief §9.9)

**« Articles Sauvegardés » desktop** — absent en clair ET sombre (n'existe qu'en mobile).

- **Action** : générer via Stitch `generate_screen_from_text` avec le design system **Insight Minimalist** (`assets/5fdfcb78a73d41beba1d359fb04284fd`), `deviceType: DESKTOP`, prompt fondé sur l'écran mobile + le layout barre latérale desktop (cf brief §4).
- **Bloquant pour** : EPIC-008 US-073 en version desktop. Non bloquant Sprint 1 (mobile hors WS).
- **Statut** : à produire (nécessite confirmation — action sortante sur le projet Stitch).

## Note

Les captures ne sont PAS la source. Pour l'implémentation, récupérer l'écran de référence via `get_screen`
(`projects/7076573032400883843/screens/<id>`) et appliquer les tokens de `design-tokens.md`.
