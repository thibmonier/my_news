# Sprint Retrospective — Sprint 001 Walking Skeleton

**Date** : 2026-08-10 (apres la Review)
**Duree** : 1h30
**Animateur** : Tech Lead / Scrum Master
**Technique** : Start / Stop / Continue

---

## Directive Fondamentale de Norm Kerth

> "Peu importe ce que nous decouvrons, nous comprenons et croyons sincerement
> que tout le monde a fait le meilleur travail possible, compte tenu de ce qu'il
> savait a ce moment-la, de ses competences et aptitudes, des ressources
> disponibles et de la situation du moment."
> — Norm Kerth, *Project Retrospectives*

*5 minutes sont consacrees en ouverture a la lecture de cette directive et a la creation d'un espace de securite psychologique. Toutes les observations qui suivent s'inscrivent dans cet esprit.*

---

## Deroulement

| Phase | Duree | Description |
|-------|-------|-------------|
| Ouverture — Directive Fondamentale | 5 min | Lecture collective, securite psychologique |
| Redaction individuelle (post-its) | 15 min | Chacun ecrit ses Start / Stop / Continue |
| Regroupement et lecture collective | 20 min | Affichage et vote par points (dot voting) |
| Themes prioritaires (2-3 retenus) | 30 min | Discussion approfondie sur les themes les plus votes |
| Plan d'action SMART Sprint 2 | 20 min | Formalisation des actions avec responsable + echeance |

---

## Observations — Start / Stop / Continue

### START — Ce que nous devrions commencer a faire

- **Verifier la compatibilite des dependances majeures avant le premier commit** : l'incompatibilite doctrine-bundle ^2 / Symfony 8 a necessite des bumps en cascade (^3.3 + migrations-bundle ^4 + api-platform ^4.3 + php-cs-fixer ^3.95). Un spike de 2h en amont aurait evite ce blocage.
- **Etablir une matrice de compatibilite des versions** pour le triplet Symfony / Doctrine / API Platform a chaque debut de sprint ou montee de version.
- **Ajouter un smoke-test automatise `docker compose up` + GET `/api/health`** dans le pipeline local (et CI des que le billing est resolu) pour detecter immediatement un probleme d'image ou de configuration Docker.
- **Utiliser les interfaces comme point de mock dans les tests** (DIP applique) : les classes `final` ne peuvent pas etre mockees directement — le reflex doit etre de mocker systematiquement l'interface.
- **Mettre en place un canal de communication asynchrone dedie** (fil ou document) pour les decisions d'architecture prises en solo, afin de les rendre visibles a l'equipe sans attendre le Daily.

### STOP — Ce que nous devrions arreter de faire

- **Utiliser des dependances abandonnees sans verification prealable** : `debril/feed-io` etait abandonnee ; son fork `php-feed-io/feed-io` etait disponible. Un audit rapide sur Packagist/GitHub avant de choisir une librairie aurait evite ce changement en cours de sprint.
- **Poser l'opcache preload au niveau build Docker** : l'instruction `opcache.preload` dans le Dockerfile cassait `composer install` lors du build (le fichier de preload n'existait pas encore). Le preload doit etre active uniquement au runtime (prod) via une variable d'environnement, pas au build.
- **Utiliser des cles de configuration Doctrine ORM 3.x obsoletes** (`auto_generate_proxy_classes`) : les cles deprecation-bloquantes doivent etre identifiees en amont via le UPGRADE.md de chaque composant majeur.
- **Travailler avec des bindings DI non verifies** : les interfaces sans implementation declaree bloquent la compilation du conteneur Symfony. Ajouter une etape `php bin/console debug:container` en smoke-test de build.

### CONTINUE — Ce qui a bien fonctionne et doit etre preserve

- **TDD et Pest** : 226 tests verts (178 Unit + 48 Feature contre PostgreSQL+Redis reels) sont le filet de securite qui a permis de corriger les obstacles techniques sans regression. A conserver comme pratique non-negociable.
- **Architecture hexagonale + Deptrac** : 0 violation sur 94 fichiers src. Le garde-fou outille (Deptrac) a rendu les violations visibles immediatement plutot qu'en fin de sprint.
- **PHPStan niveau max + PHP CS Fixer** : la qualite statique imposee des le debut a evite les dettes techniques silencieuses. Les 0 erreurs finales refletent une rigueur maintenue tout au long du sprint.
- **Securite integree des la conception** : Argon2id, anti-enumeration, SSRF interne, UUID v4, RFC 7807 — ces choix pris en Sprint 1 coutent peu a mettre en place maintenant et beaucoup a retrofitter plus tard.
- **Verification locale via Docker en l'absence de CI cloud** : la decision pragmatique d'effectuer les verifications en local via Docker a permis de livrer le sprint sans dependre d'une infrastructure externe bloquee.
- **Decomposition fine des taches** : 84 taches / 140,5h ont rendu le travail visible et les impediments localisables.

---

## Themes prioritaires retenus (dot voting)

| Rang | Theme | Votes | Origine |
|------|-------|-------|---------|
| 1 | CI GitHub Actions bloquee (billing) | — | Obstacle #6 |
| 2 | Audit des dependances avant adoption | — | Obstacles #7 + #1 |
| 3 | Docker build fiabilite (opcache, conteneur DI) | — | Obstacles #2 + #4 |

---

## Plan d'Action SMART — Sprint 2

### Action 1 — PROCESSUS : Regler la CI GitHub Actions (billing)

| Champ | Detail |
|-------|--------|
| **Quoi** | Activer le billing du repo prive sur GitHub pour debloquer GitHub Actions ; valider qu'un premier pipeline (PHPStan + Pest + CS Fixer + build Docker) passe au vert |
| **Pourquoi** | La CI n'a pas pu etre verifiee en cloud pendant le Sprint 1. La verification locale via Docker est un filet de secours, pas un substitut a une CI verte automatisee |
| **Responsable** | Tech Lead |
| **Echeance** | Jour 1 du Sprint 2 (avant le premier commit) |
| **Critere de succes** | Pipeline GitHub Actions vert sur `main` avec les 4 jobs : test, phpstan, cs-fixer, docker-build |
| **Mesurable** | Badge CI vert visible dans le README |

---

### Action 2 — OUTILLAGE / TECHNIQUE : Audit et migration des dependances risquees

| Champ | Detail |
|-------|--------|
| **Quoi** (a) | Migrer `debril/feed-io` → `php-feed-io/feed-io` (fork actif) dans `composer.json` et adapter les usages |
| **Quoi** (b) | Creer une `deps-compatibility-matrix.md` dans `.claude/references/` listant les versions epingles et verifiees pour le triplet Symfony 8 / Doctrine ORM 3 / API Platform 4 |
| **Quoi** (c) | Ajouter dans le pipeline CI un job `composer audit` (vulnerabilites) + verification que toutes les dependances ont un `abandoned: false` sur Packagist |
| **Pourquoi** | `debril/feed-io` abandonnee + doctrine-bundle incompatible ont genere du retrabalho en milieu de sprint |
| **Responsable** | Dev (migration) + Tech Lead (matrice + CI job) |
| **Echeance** | Fin de la semaine 1 du Sprint 2 (J+5) |
| **Critere de succes** | `composer.json` utilise `php-feed-io/feed-io` ; `composer audit` = 0 alerte critique ; matrice de compatibilite a jour |
| **Mesurable** | `composer audit` dans la CI, 0 warning abandonnement |

---

### Action 3 — COLLABORATION : Smoke-test Docker obligatoire + checklist d'integration en fin de tache

| Champ | Detail |
|-------|--------|
| **Quoi** (a) | Ajouter un script `make smoke` local qui execute : `docker compose up -d --build && curl -f http://localhost/api/health && docker compose down` — bloquant si echec |
| **Quoi** (b) | Ajouter dans la Definition of Done du Sprint 2 : "smoke-test `make smoke` passe sur la machine du developpeur avant de marquer une tache DONE" |
| **Quoi** (c) | Creer un fil d'equipe (ou section Daily) "decisions archi en solo" pour rendre visibles les choix de configuration (ex : opcache, bindings DI) pris en autonomie |
| **Pourquoi** | Le bug opcache.preload et les bindings DI manquants ont ete detectes tardivement car il n'existait pas de verification de build automatique accessible a tous. La visibilite des decisions en solo aurait permis une detection collective plus rapide |
| **Responsable** | Tech Lead (script + DoD) + toute l'equipe (fil archi) |
| **Echeance** | Sprint Planning Part 2 du Sprint 2 (le script `make smoke` doit exister avant que la premiere tache soit prise) |
| **Critere de succes** | `make smoke` executable ; DoD Sprint 2 amendee ; au moins 1 decision d'archi partagee via le fil dans la premiere semaine |
| **Mesurable** | Script present dans `Makefile` ; DoD mise a jour dans `sprint-002-*/sprint-goal.md` |

---

## Tableau de suivi des actions

| # | Action | Type | Responsable | Echeance | Statut |
|---|--------|------|-------------|----------|--------|
| A1 | Billing GitHub Actions + pipeline CI vert | Processus | Tech Lead | Jour 1 Sprint 2 | A faire |
| A2a | Migration `debril/feed-io` → `php-feed-io/feed-io` | Technique | Dev | J+5 Sprint 2 | A faire |
| A2b | Matrice compatibilite Symfony 8 / Doctrine 3 / API Platform 4 | Technique | Tech Lead | J+5 Sprint 2 | A faire |
| A2c | Job `composer audit` dans CI | Technique | Tech Lead | J+5 Sprint 2 | A faire |
| A3a | Script `make smoke` (docker build + healthcheck) | Collaboration | Tech Lead | Sprint Planning Part 2 | A faire |
| A3b | DoD Sprint 2 : smoke-test obligatoire | Collaboration | Tech Lead | Sprint Planning Part 2 | A faire |
| A3c | Fil equipe "decisions archi en solo" | Collaboration | Toute l'equipe | Debut Sprint 2 | A faire |

---

## Rappel — Hypothese produit a valider en Sprint 2

> **Retention J+1** : Un utilisateur (P-001 Thomas) expose a un Daily Brief selectionne algorithmiquement avec synthese IA percevra la valeur «fort signal, faible bruit» et reviendra le lendemain.

Cette hypothese n'a pas pu etre mesuree en Sprint 1 (absence de deploiement prod + utilisateurs reels). Elle devient l'objectif de validation prioritaire du Sprint 2.

**Prerequis** : deploiement en staging accessible + recrutement de 5 utilisateurs beta minimum.
