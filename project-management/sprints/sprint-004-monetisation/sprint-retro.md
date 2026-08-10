# Sprint Rétrospective — Sprint 004 Monétisation Premium

**Date** : 2026-09-21 (après la Review)
**Durée** : 1h30
**Animateur** : Tech Lead / Scrum Master
**Technique** : Start / Stop / Continue
**Mode d'exécution** : Auto-sprint (agent autonome)

---

## Directive Fondamentale de Norm Kerth

> "Peu importe ce que nous découvrons, nous comprenons et croyons sincèrement
> que tout le monde a fait le meilleur travail possible, compte tenu de ce qu'il
> savait à ce moment-là, de ses compétences et aptitudes, des ressources
> disponibles et de la situation du moment."
> — Norm Kerth, *Project Retrospectives*

*5 minutes sont consacrées en ouverture à la lecture de cette directive et à la création d'un espace de sécurité psychologique. Toutes les observations qui suivent s'inscrivent dans cet esprit.*

---

## Déroulement

| Phase | Durée | Description |
|-------|-------|-------------|
| Ouverture — Directive Fondamentale | 5 min | Lecture collective, sécurité psychologique |
| Rédaction individuelle (post-its) | 15 min | Chacun écrit ses Start / Stop / Continue |
| Regroupement et lecture collective | 20 min | Affichage et vote par points (dot voting) |
| Thèmes prioritaires (2-3 retenus) | 30 min | Discussion approfondie sur les thèmes les plus votés |
| Plan d'action SMART Sprint 5 | 20 min | Formalisation des actions avec responsable + échéance |

---

## Bilan quantitatif du Sprint 4

| Indicateur | Sprint 3 | Sprint 4 | Delta |
|------------|----------|----------|-------|
| US livrées | 5 US / 16 pts | 4 US / 16 pts | = pts, -1 US (sprint plus dense) |
| Tests | 554 | 660 | +106 (+19 %) |
| PHPStan erreurs | 0 | 0 | stable |
| Deptrac violations | 0 | 0 | stable |
| CS Fixer | 0 | 0 | stable |
| Régressions introduites | 0 | 0 | stable |
| CI GitHub Actions | KO (billing) | **Verte — 1er essai** | **Résolu Sprint 4** |

**US livrées** : US-034a (Stripe Checkout + activation webhook Premium), US-035 (réglages RGPD), US-013 (quota free réel + paywall HTTP 402 + bypass Premium), US-034b (Customer Portal + gestion cycle de vie downgrade/cancel/past_due).

**Progression tests par commit** : 605 (US-034a) → 622 (US-035) → 637 (US-013) → 660 (US-034b).

**Ordre d'exécution réel** : US-034a → US-035 → US-013 → US-034b. US-035 était planifiée en parallèle dès J1 mais a été exécutée en séquence (mode auto-sprint mono-agent).

**Sprint Goal atteint** : quota free réel (HTTP 402), Stripe Checkout + activation Premium, Customer Portal + lifecycle webhooks (cancelled/updated/payment_failed), réglages RGPD autonomes — tout en WEB-ONLY, 0 tâche [FE-MOB].

---

## Observations — Start / Stop / Continue

### START — Ce que nous devrions commencer à faire

- **Documenter une procédure de test E2E Stripe en mode test** : la totalité du comportement Stripe a été couverte via `StripeGatewayStub` (ports/adaptateurs) et les tests WebTestCase. C'est robuste pour la logique métier, mais l'intégration réelle — redirect vers `checkout.stripe.com`, réception de webhooks depuis la CLI Stripe, validation de la session Stripe en sandbox — n'est pas automatisée. Avant la mise en production réelle, un guide `docs/stripe-e2e-guide.md` avec les numéros de carte test, les événements `stripe trigger`, et le setup `stripe listen --forward-to` est nécessaire pour qu'un humain puisse valider le flux complet en < 30 min.

- **Désigner un "propriétaire de config" par sprint lors du Planning Part 2** : les fichiers `composer.json`, `services.yaml`, `security.yaml`, `messenger.yaml` ont été modifiés exclusivement dans US-034a (premier commit), ce qui a imposé une contrainte de séquencement stricte. Ce n'était pas documenté comme contrainte explicite dans les dépendances — seul le `SubscriptionRepository` était tracé comme point de synchronisation. Nommer un US "propriétaire des fichiers de config partagés" dans `sprint-dependencies.md` rend la contrainte visible dès le Planning et aide une vraie équipe multi-dev à planifier ses branches.

- **Introduire un template de décomposition WEB-ONLY explicite** : le template standard du Tech Lead inclut systématiquement des tâches [FE-MOB]. Ce sprint était WEB-ONLY, et la décomposition a bien exclu le mobile, mais il faut une décision explicite à chaque tâche (marquée "N/A - WEB-ONLY" ou planifiée). Sprint 5 amorcera Flutter — le moment est bon pour formaliser deux variantes de template : `web-only` (Symfony seul) et `full-stack` (Symfony + Flutter + API Platform).

### STOP — Ce que nous devrions arrêter de faire

- **Laisser le `task-board.md` dériver par rapport aux fichiers US-xxx-tasks.md** : chaque commit de ce sprint a mis à jour le fichier de tâches de l'US concernée (26 à 30 lignes modifiées à chaque fois — statuts ✅), mais le `task-board.md` principal est resté figé à l'état initial « tout À faire ». En fin de sprint, la vue Kanban ne reflète pas la réalité. Soit le `task-board.md` devient l'unique source de vérité (mis à jour à chaque commit, fichiers US supprimés), soit les fichiers US-xxx-tasks.md sont la source et le task-board est généré. La duplication des statuts entre les deux est la source du drift.

- **Placer les messages Messenger dans le Domain sans vérification deptrac préalable** : `StripeWebhookMessage` a été initialement placé dans le Domain puis déplacé vers Application pour conformité deptrac (mentionné explicitement dans le commit US-034a). Deptrac l'a détecté lors de l'implémentation, pas avant. Un check deptrac rapide sur chaque nouveau fichier avant de committer (`make deptrac` ou alias rapide) éviterait ce type de correction en milieu de tâche.

### CONTINUE — Ce qui a bien fonctionné et doit être préservé

- **Architecture hexagonale comme fondation de la testabilité Stripe** : `StripeGatewayInterface` dans le Domain + `StripeCheckoutGateway` (adaptateur SDK) + `StripeGatewayStub` dans les tests — ce pattern a permis de couvrir l'intégralité du comportement Checkout/webhook/Portal sans aucune clé Stripe réelle. 10 tests unitaires + 10 feature tests pour US-034a, 9 tests lifecycle handlers US-034b, tous verts, aucune dépendance aux secrets. La valeur de l'architecture hexagonale n'a jamais été aussi visible que sur un composant d'infrastructure externe comme Stripe.

- **HMAC-SHA256 + idempotence webhooks** : la vérification HMAC via `Webhook::constructEvent` avant tout traitement, combinée à `ON CONFLICT (stripe_event_id) DO NOTHING` en base, donne une couche de sécurité double : authenticité du payload + protection contre les rejeux. Les tests couvrent les trois cas (HMAC valide / invalide / absent) ainsi que la double livraison d'un événement identique. Ce pattern est à reconduire systématiquement pour tout webhook de tiers en Sprint 5+.

- **429 → 402 : correction sémantique propre sans régression** : les processors `ArticleSynthesisProcessor` et `UrlSynthesisProcessor` retournaient HTTP 429 (Too Many Requests) pour le quota épuisé. Sprint 4 les a correctement migrés vers HTTP 402 (Payment Required), plus précis pour un paywall. Les tests existants ont été mis à jour en même temps que le code — aucun test n'a « glissé » en silence avec l'ancien status code. Ce type de correction sémantique est le genre de dette discrète qui s'accumule : la traiter à la source dans l'US concernée est la bonne approche.

- **0 régression sur la suite complète en 4 sprints** : 660 tests verts à la clôture du sprint, couvrant les 4 sprints accumulés. La progression constante (226 → 437 → 554 → 660) sans aucune régression introduite valide la rigueur TDD et le refus systématique de couper les tests en phase de livraison.

- **CI GitHub Actions verte au premier essai** : après 3 sprints consécutifs de CI bloquée (billing), le pipeline a passé au vert sur PR #14 sans itération. PHPStan max, CS Fixer, Deptrac, PHPUnit — tous les gates verts. La discipline des gates locaux appliquée depuis Sprint 2 a rendu la CI quasi-redondante côté qualité, mais son rôle de filet de sécurité officiel est désormais opérationnel.

- **`QuotaService` comme point d'extension maîtrisé** : `QuotaService` existait depuis Sprint 3 (US-033). Sprint 4 l'a étendu avec `isPremium()`, `refund()`, et l'injection de `SubscriptionRepositoryInterface` — sans modifier son interface publique existante. Le `bypass quota Premium` s'est inséré proprement dans le flux existant. OCP respecté en pratique.

---

## Thèmes prioritaires retenus (dot voting)

| Rang | Thème | Votes | Origine |
|------|-------|-------|---------|
| 1 | Absence de test E2E Stripe réel — le stub couvre le domaine mais pas le tunnel/sandbox | — | Observation START |
| 2 | Drift task-board.md / US-xxx-tasks.md — deux sources de vérité qui divergent | — | Observation STOP |
| 3 | Config files partagés non documentés comme contrainte de séquencement | — | Observation START |

---

## Plan d'Action SMART — Sprint 5

### Action 1 — DOCUMENTATION : Guide de test E2E Stripe (mode test)

| Champ | Détail |
|-------|--------|
| **Quoi** | Créer `docs/stripe-e2e-guide.md` documentant la procédure complète de test manuel en mode sandbox : (1) setup `stripe listen --forward-to localhost:8000/stripe/webhook`, (2) souscription mensuelle avec carte `4242 4242 4242 4242`, (3) événement `stripe trigger customer.subscription.deleted` pour tester le downgrade, (4) cas `past_due` avec `invoice.payment_failed`. Inclure le checklist de validation du flux complet Free → Checkout → Premium → Portal → Cancel en < 30 min. |
| **Pourquoi** | Le `StripeGatewayStub` couvre tous les contrats métier mais ne valide pas le tunnel réseau, le redirect Stripe, ni la réception réelle de webhooks signés. Ce gap ne sera visible qu'en staging ou production — trop tard. |
| **Responsable** | Tech Lead |
| **Échéance** | **2026-10-01 (J+2 Sprint 5)** |
| **Critère de succès** | Fichier `docs/stripe-e2e-guide.md` présent, checklist exécutable en autonomie par un développeur sans connaissance préalable de Stripe |
| **Mesurable** | Fichier présent dans le repo avant la première US Stripe de Sprint 5 (si applicable) ; checklist validé par un pair en < 30 min lors d'une session de revue |

---

### Action 2 — PROCESSUS : Single source of vérité pour le suivi des tâches

| Champ | Détail |
|-------|--------|
| **Quoi** | Choisir et documenter une règle ferme pour Sprint 5 : soit (a) le `task-board.md` est l'unique source de vérité — les fichiers `US-xxx-tasks.md` détaillent uniquement la décomposition initiale et ne sont plus mis à jour après le Sprint Planning, soit (b) les fichiers `US-xxx-tasks.md` sont la source de vérité et le `task-board.md` est généré manuellement en fin de sprint uniquement pour la Review. Quel que soit le choix, l'appliquer sans exception dès le premier commit Sprint 5. Documenter la règle retenue dans `.claude/rules/03-task-tracking.md`. |
| **Pourquoi** | En Sprint 4, les fichiers `US-xxx-tasks.md` ont été mis à jour commit par commit (source de vérité réelle), mais le `task-board.md` n'a jamais bougé. La vue Kanban ne reflète pas la réalité en fin de sprint — ce qui nuit à la transparence lors de la Review et du reporting. |
| **Responsable** | Tech Lead |
| **Échéance** | **Sprint 5 Planning Part 2 — 2026-09-29** |
| **Critère de succès** | Fichier `.claude/rules/03-task-tracking.md` créé ; 0 incohérence de statut entre sources en milieu et fin de Sprint 5 |
| **Mesurable** | À la Review Sprint 5, le Kanban reflète fidèlement l'état réel des tâches sans mise à jour de rattrapage |

---

### Action 3 — TECHNIQUE : Check deptrac avant commit, pas en CI uniquement

| Champ | Détail |
|-------|--------|
| **Quoi** | Ajouter `make deptrac` (ou alias rapide `make check-layers`) dans la procédure de validation pré-commit aux côtés de PHPStan et CS Fixer. L'objectif est de détecter les violations de couche (ex. un message Messenger placé dans Domain) au moment de la création du fichier, pas en fin d'implémentation. Le check doit s'exécuter en < 5 s sur le seul fichier nouvellement créé si possible, ou sur le périmètre de l'US en cours. |
| **Pourquoi** | `StripeWebhookMessage` a été déplacé de Domain vers Application pour conformité deptrac après avoir déjà été écrit et connecté. Ce type de correction en cours d'implémentation est coûteux : il requiert de renommer les imports dans plusieurs fichiers. Un check préventif à la création du fichier coupe ce coût à zéro. |
| **Responsable** | Tech Lead |
| **Échéance** | **Sprint 5 J+1 — 2026-09-30** |
| **Critère de succès** | `make pre-commit` (ou équivalent dans `.claude/rules/`) inclut `deptrac` ; 0 déplacement de fichier pour raison de couche en cours de sprint |
| **Mesurable** | Aucune violation deptrac dans les messages de commit Sprint 5 du type « moved to X for deptrac compliance » |

---

### Action 4 — PLANIFICATION : Documenter les fichiers de config partagés comme contrainte dans sprint-dependencies.md

| Champ | Détail |
|-------|--------|
| **Quoi** | Lors du Sprint Planning Part 2 de Sprint 5, identifier explicitement les fichiers partagés entre US (typiquement `composer.json`, `services.yaml`, `security.yaml`, `messenger.yaml`) et désigner une US comme "propriétaire de config" pour chacun. Ajouter une section `## Fichiers de config partagés` dans `sprint-dependencies.md` avec la matrice `fichier → US propriétaire → US consommatrices`. Les US consommatrices doivent attendre le merge de l'US propriétaire avant de toucher ces fichiers, ou allouer du temps de rebase explicitement. |
| **Pourquoi** | En Sprint 4, US-034a a modifié 6 fichiers de config partagés. US-035 (planifiée en parallèle) a dû ajouter une entrée `security.yaml` dans son propre commit, mais n'a pu le faire qu'en séquence après US-034a. La contrainte existait mais n'était pas documentée — ce qui aurait causé des conflits de merge dans une vraie équipe multi-dev. |
| **Responsable** | Tech Lead (animation Planning) + PO (validation périmètre) |
| **Échéance** | **Sprint 5 Planning Part 2 — 2026-09-29** |
| **Critère de succès** | `sprint-005-xxx/sprint-dependencies.md` contient une section "Fichiers de config partagés" ; les dépendances de config sont visibles dans le graphe Mermaid des dépendances inter-US |
| **Mesurable** | 0 conflit de merge sur les fichiers de config en Sprint 5 lié à une dépendance non documentée |

---

### Action 5 — PROCESSUS : Template de décomposition WEB-ONLY vs FULL-STACK (préparation Flutter Sprint 5+)

| Champ | Détail |
|-------|--------|
| **Quoi** | Avant le Sprint Planning Part 2 de Sprint 5 (premier sprint potentiellement avec US Flutter), formaliser deux variantes de template de décomposition de tâches : (1) **WEB-ONLY** : colonnes [DB] / [BE] / [FE-WEB] / [TEST] / [DOC] / [REV], sans [FE-MOB], avec mention explicite "N/A — WEB-ONLY" ; (2) **FULL-STACK** : colonnes [DB] / [BE] / [FE-WEB] / [FE-MOB] / [TEST] / [DOC] / [REV] avec temps Flutter estimés. Documenter dans `.claude/skills/project-decompose-tasks.md` ou dans le template existant. Chaque US en Sprint 5 doit déclarer explicitement son type dès la décomposition. |
| **Pourquoi** | Sprint 4 était WEB-ONLY mais le template standard du Tech Lead inclut [FE-MOB] par défaut. La décomposition a correctement exclu le mobile (0 tâche [FE-MOB]), mais la décision a été implicite, non tracée. Sprint 5 marque potentiellement l'entrée de Flutter dans le backlog (EPIC-003, EPIC-002 niveau mobile). La distinction explicite dès la décomposition évite d'oublier des tâches Flutter ou d'en ajouter par inadvertance. |
| **Responsable** | Tech Lead |
| **Échéance** | **Sprint 5 Planning Part 2 — 2026-09-29** |
| **Critère de succès** | Chaque US dans `tasks/US-xxx-tasks.md` Sprint 5 porte un label `[WEB-ONLY]` ou `[FULL-STACK]` en en-tête ; 0 ambiguïté sur la présence ou l'absence de tâches [FE-MOB] |
| **Mesurable** | `tasks/README.md` Sprint 5 mentionne le type (WEB-ONLY / FULL-STACK) pour chaque US avant le démarrage du sprint |

---

## Tableau de suivi des actions

| # | Action | Type | Responsable | Échéance | Statut |
|---|--------|------|-------------|----------|--------|
| A1 | Guide `docs/stripe-e2e-guide.md` — procédure test E2E Stripe sandbox (Checkout + webhooks + Portal) | Documentation | Tech Lead | **2026-10-01** | À faire |
| A2 | Règle single source of vérité tâches — `.claude/rules/03-task-tracking.md` + appliqué dès Sprint 5 J1 | Processus | Tech Lead | **2026-09-29** | À faire |
| A3 | `make deptrac` en check pré-commit — 0 déplacement de fichier pour couche en cours de sprint | Technique | Tech Lead | **2026-09-30** | À faire |
| A4 | Section "Fichiers de config partagés" dans `sprint-dependencies.md` Sprint 5 | Planification | Tech Lead + PO | **2026-09-29** | À faire |
| A5 | Template décomposition WEB-ONLY vs FULL-STACK avant Sprint Planning Sprint 5 | Processus | Tech Lead | **2026-09-29** | À faire |

---

## Suivi des actions Sprint 3 (bilan)

| # Sprint 3 | Action | Statut |
|------------|--------|--------|
| A1 | Sprint Goal Sprint 4 : staging en prérequis bloquant J+2 | **Remplacé** — Sprint 4 a pivoté vers la monétisation (décision PO) ; l'objectif staging est reporté à post-Sprint 4 |
| A2a | CI GitHub Actions : billing résolu + pipeline vert sur `main` | **Résolu** — CI verte sur PR #14 au premier essai (Sprint 4) |
| A2b | Provisioning admin prod : compte P-004 Sophie ROLE_ADMIN actif | **Non résolu** — non scopé Sprint 4 ; à inclure dans les prérequis Sprint 5 |
| A3 | Protocole `[OPS-HUMAIN]` dans `.claude/rules/02-ops-human-tasks.md` | **Non résolu** — Sprint 4 sans tâches ops humaines bloquantes (pas de staging, clés Stripe disponibles) ; reporter avec l'action A5 Sprint 5 |

---

## Rappel — Hypothèse produit Sprint 4

> **Hypothèse** : En activant un quota free réel (3 synthèses/jour) avec paywall HTTP 402 vers Stripe Checkout, Briefly AI génère ses premiers revenus récurrents. P-001 Thomas, bloqué par le paywall lors de sa 4e synthèse quotidienne, souscrit à Premium (12€/mois) et peut immédiatement gérer son abonnement en autonomie (Customer Portal). P-003 Marc, sensible à la vie privée, configure ses préférences RGPD sans contacter le support.

**Chaîne technique livrée** :
- Quota Redis réel (INCR + DECR Lua atomique + plancher 0) avec bypass Premium — opérationnel.
- HTTP 402 + `X-Quota-Remaining` + `X-Resets-At` dans les processors — opérationnel.
- Stripe Checkout (HMAC-SHA256 + idempotence `stripe_event_id`) — opérationnel en mode test.
- Customer Portal + 3 handlers lifecycle idempotents — opérationnel.
- Grace period `past_due` + `cancel_at_period_end` — opérationnel.
- Réglages RGPD (3 toggles + export JSON Article 20 + Voter UUID-only) — opérationnel.

**Validation restante (hors code)** : test E2E Stripe avec vraies clés test + staging déployé. La chaîne technique est livrée ; la validation produit exige l'environnement (action A1).
