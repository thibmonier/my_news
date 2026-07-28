# ADR-001 : Frontend desktop — Symfony/Twig + Turbo vs SPA headless

**Date :** 2026-07-28
**Statut :** Accepté
**Décideurs :** Tech Lead, Product Owner
**Contraintes source :** T1 (Symfony 8 + API Platform 4), §9.1 (décision tranchée)

---

## Contexte

Briefly AI est une plateforme de lecture d'actualités dont la page principale — le Daily Brief — est publique, indexée par les moteurs de recherche et consommée massivement le matin entre 7h00 et 9h00 (pic de trafic P-001 Thomas). Le produit repose sur un backend Symfony 8 + API Platform 4 imposé. La décision porte sur la couche de présentation desktop : soit une SPA headless (React / Vue / Next.js) qui consomme l'API, soit un rendu serveur natif Symfony via Twig + Turbo (Hotwire).

L'équipe est de petite taille (hypothèse Res1) et ne dispose pas d'un frontend JS expert dédié. Le backend Symfony 8 est non négociable (contrainte T1).

---

## Décision

**Symfony/Twig + Turbo (Hotwire) pour le frontend desktop.**

Les mises à jour dynamiques (flux d'articles, indicateurs de lecture, actualisation du brief) sont gérées par Turbo Frames et Turbo Streams. Stimulus est utilisé pour les comportements JavaScript ciblés (menus, toasts, quota bar). API Platform reste le point d'entrée unique pour le client mobile Flutter et l'API publique Premium — les deux cohabitent sans contradiction.

---

## Alternatives considérées

### Option A — SPA headless (Next.js / Nuxt.js) + API Platform

| Critère | Évaluation |
|---------|------------|
| SEO | Requis SSR ou SSG côté framework JS (complexité config, coût de build) |
| Auth | JWT partagé mobile+desktop ; exposition du token au JavaScript → surface d'attaque XSS |
| Équipe | +1 stack complète (TypeScript, build pipeline, tests E2E JS) à maintenir |
| Temps de mise en marché | Plus long : deux pipelines de déploiement, deux sets de tests |
| Interactivité | Native JS, adapté aux dashboards riches en temps réel |
| Cache HTTP | Niveau API (Redis) suffit, mais pas de cache HTML page entière |

**Rejeté.** L'interactivité cible (liste d'articles, lecture, quota bar) ne justifie pas le surcoût d'une SPA complète pour une petite équipe. Le SEO natif Twig est préférable pour une plateforme news publique où le trafic organique est un levier de croissance primaire (FR-004, FR-007, FR-010).

### Option B — Hybride partiel (Twig statique + îlots React)

Twig pour le rendu initial, React pour des composants interactifs isolés (quota bar, synthèse AI streaming).

**Rejeté.** Complexité de build (bundler + Webpack Encore), deux paradigmes de composants à maintenir, gains limités par rapport à Stimulus pour les besoins identifiés.

### Option C (retenue) — Symfony/Twig + Turbo + Stimulus

Rendu serveur complet. Turbo Drive pour la navigation sans rechargement. Turbo Frames pour les mises à jour partielles (condensé IA chargé à la demande via lazy frame). Turbo Streams pour les mises à jour push (nouveau brief disponible, compteur quota). Stimulus pour les comportements JS localisés.

---

## Conséquences

### Positives

- **SEO natif** : la page Daily Brief est rendue HTML complet côté serveur dès le premier octet, indexée par Google sans configuration SSR additionnelle — critique pour la découvrabilité organique (contrainte B2, NFR-005, OPP-03 rituel matinal).
- **Temps d'affichage initial (TTI < 1,5 s P95)** : FrankenPHP en worker mode maintient le processus PHP en mémoire ; le HTML est envoyé en une seule passe, sans round-trip client API.
- **Auth desktop sécurisée** : session HttpOnly + SameSite=Strict (FR-031) — aucun token JWT exposé au JavaScript, protection XSS/CSRF native (NFR-012, OWASP #1 et #2).
- **Stack unique** : l'équipe backend Symfony maîtrise Twig + Turbo sans recruter de profil JS frontend. Le coût de maintenance est concentré sur une seule pile (OPP-06).
- **Partage de liens publics** : les URLs `/brief/2026-07-28` (FR-007) sont crawlables nativement, favorisant le partage organique (P-001 Thomas partage via Slack, P-002 Priya cite dans ses rapports).
- **WCAG 2.1 AA** : le rendu HTML sémantique de Twig facilite l'accessibilité structurelle (NFR-024, NFR-026).
- **Internationalisation** : `symfony/translation` (ICU) est la même couche utilisée dans les services backend — pas de duplication de la gestion i18n (contrainte T8, NFR-022).

### Négatives

- **Interactivité temps réel limitée** : les dashboards analytics complexes ou le streaming de tokens LLM mot par mot nécessiteront Turbo Streams + SSE côté serveur, ou un îlot Stimulus dédié. La latence perçue reste supérieure à une SPA pour des mises à jour très fréquentes (< 500 ms).
- **Pas de code Twig réutilisable sur mobile** : le code Flutter est entièrement distinct. Seule l'API Platform est partagée. Ce n'est pas une régression — c'est l'architecture cible retenue (Flutter pour mobile, Twig pour desktop, API Platform comme pivot).
- **Migration headless future** : si l'équipe croît et veut une SPA riche, la migration est possible sans toucher le backend (API Platform est déjà headless). Le couplage est faible. La migration est un choix futur, pas une dette actuelle.

### Contraintes respectées

| Contrainte | Satisfaction |
|------------|-------------|
| T1 — Symfony 8 + API Platform 4 | Twig est natif Symfony ; API Platform coexiste pour mobile + API publique |
| T2 — FrankenPHP worker mode | Twig bénéficie du mode worker sans modification |
| T7 — API unique mobile + frontend | API Platform reste le contrat unique ; Twig ne duplique pas les routes API |
| T8 — i18n dès Sprint 1 | `symfony/translation` partagé entre couche service et templates Twig |
| R1 — RGPD | Pas de cookie JS tiers, session HttpOnly conforme |
| NFR-005 — Core Web Vitals | LCP < 2,5 s atteignable en SSR avec FrankenPHP |

### Impact sur les personas

| Persona | Bénéfice |
|---------|---------|
| **P-001 Thomas** | Page Daily Brief chargée en < 1,5 s sur son MacBook au bureau avant le premier meeting ; partage de liens Slack indexés et prévisualisables |
| **P-002 Priya** | Navigation fluide entre clusters d'articles via Turbo Drive (pas de rechargement complet) ; Export Markdown déclenché sans JS complexe |
| **P-003 Marc** | Pas de tracker JavaScript tiers — l'audit réseau qu'il réalisera (cf. son critère de succès) ne renverra que les requêtes first-party |

---

## Notes de révision

Cette décision sera réexaminée si :
- L'équipe atteint 3+ développeurs JS front expérimentés.
- Les besoins analytics (EPIC-008) dépassent ce que Turbo Streams peut couvrir.
- La latence Turbo Stream dépasse 800 ms sur les mises à jour de quota en pic de charge.

**Prochaine révision planifiée :** Sprint 4 Review (post-validation hypothèse centrale rétention J+1).
