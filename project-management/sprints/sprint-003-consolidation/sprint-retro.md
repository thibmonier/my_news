# Sprint Rétrospective — Sprint 003 Consolidation

**Date** : 2026-09-07 (après la Review)
**Durée** : 1h30
**Animateur** : Tech Lead / Scrum Master
**Technique** : Start / Stop / Continue

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
| Plan d'action SMART Sprint 4 | 20 min | Formalisation des actions avec responsable + échéance |

---

## Bilan quantitatif du Sprint 3

| Indicateur | Sprint 2 | Sprint 3 | Delta |
|------------|----------|----------|-------|
| US livrées | 5 US / 23 pts | 5 US / 16 pts | = US, -7 pts (sprint volontairement resserré) |
| Tests | 437 | 554 | +117 (+27 %) |
| PHPStan erreurs | 0 | 0 | stable |
| Deptrac violations | 0 | 0 | stable |
| CS Fixer | 0 | 0 | stable |
| Régressions introduites | 0 | 0 | stable |
| CI GitHub Actions | KO (billing) | KO (billing) | non résolu — **3ème sprint consécutif** |

**US livrées** : déduplication SimHash (US-022), profil utilisateur (US-032), cache Redis synthèses (US-012), Featured Summary desktop + CTA (US-006), indicateur de progression de lecture (US-007).

**Objectif prioritaire non atteint** : mesure de la rétention J+1/J+7 — nécessite un environnement staging déployé et une instrumentation analytics, conditionnés à des credentials/ops humains non automatisables par les workers de code.

**Gaps reportés** : déploiement staging (environnement + secrets) ; provisioning compte admin prod (dette depuis Sprint 2).

---

## Observations — Start / Stop / Continue

### START — Ce que nous devrions commencer à faire

- **Prioriser le staging en tête de sprint, pas en fin** : l'objectif de mesurer la rétention J+1/J+7 figurait dans le Sprint Goal de Sprint 3, mais les tâches ops nécessaires (déploiement serveur, injection de secrets, instrumentation analytics) ont glissé dans `technical-tasks` non faits. Un objectif produit conditionné à une infra n'est valide que si l'infra est livrée en premier. Sprint 4 doit ouvrir par la mise en ligne du staging — aucune US applicative ne sera démarrée tant que l'environnement n'est pas opérationnel.
- **Distinguer explicitement « tâches code » et « tâches ops/credentials » dès la décomposition** : lors du Sprint Planning Part 2, chaque tâche doit être étiquetée `[OPS-HUMAIN]` si elle exige une intervention manuelle non automatisable (accès billing, secrets, provisioning). Ces tâches doivent être assignées à un responsable humain identifié avec une date d'engagement avant le lancement du sprint, et non planifiées comme des tâches ordinaires pour les workers IA.
- **Fixer un engagement de résolution CI en amont du Sprint Planning, pas en DoD** : la CI GitHub Actions apparaît dans la DoD Sprint 3 (`Tests passants en CI`) et reste bloquée. Inscrire une contrainte dans la DoD que personne n'est en mesure de lever pendant le sprint invalide la DoD. L'activation de la CI doit devenir un préconditionnel au Sprint Planning, pas une case cochée en Review.

### STOP — Ce que nous devrions arrêter de faire

- **Planifier des objectifs de mesure produit sans avoir confirmé l'infra de mesure** : l'hypothèse de rétention J+1/J+7 était centrale dans le Sprint Goal mais sa validation exige staging + instrumentation + accès humains. Formuler un Sprint Goal dépendant d'un prérequis infra non garanti expose le sprint à un échec structurel sur son objectif principal, indépendamment de la qualité du code livré.
- **Reporter la CI GitHub Actions d'un sprint à l'autre sans escalade** : l'action A1 Sprint 1, reconduite A2a Sprint 2, reconduite encore en Sprint 3. Trois sprints de reporting sans résolution signalent une action mal conçue (absence de responsable nommé, absence d'alternative documentée, absence de date dure). Ce pattern doit cesser.
- **Absorber le provisioning admin prod dans le backlog sans traçabilité** : le compte admin prod est une dette de Sprint 2 non soldée. Sans propriétaire ni ticket visible dans le backlog, il continuera de glisser sprint après sprint.

### CONTINUE — Ce qui a bien fonctionné et doit être préservé

- **Vérification junit indépendante systématique avant chaque commit** : appliquée comme gate bloquant dès le premier commit de Sprint 3 (action A1 de la rétro Sprint 2 soldée). Zéro fausse qualification de « régression pré-existante » ce sprint — à comparer avec l'incident #2 du Sprint 2. Le diff junit orchestrateur/worker est à conserver comme invariant de la DoD.
- **Workers séquentiels 1-à-la-fois pour les opérations Docker lourdes** : aucune saturation OrbStack ce sprint, aucun stall de watchdog. La règle `--max-parallel=1` sur les builds FrankenPHP (action A2b Sprint 2) a éliminé la classe d'incidents qui avait coûté une session entière en Sprint 2.
- **Réutilisation forte de l'existant** : SynthesisService, MistralApiClient, domaine Feed/User ont été étendus sans rupture de contrat. L'architecture hexagonale a absorbé SimHashService, CacheService Redis et ProfileService sans aucune violation Deptrac. Le couplage reste nul entre domaine et infrastructure.
- **TDD strict et couverture croissante** : 554 tests (+117 par rapport au Sprint 2). La suite complète passe sans régression sur l'ensemble du code des trois sprints. Les tests SimHash (fnv1a, stopwords, CJK, seuil configurable) et les tests Redis (HIT/MISS, TTL, namespace) ont été écrits avant l'implémentation.
- **PHPStan niveau max + CS Fixer + Deptrac** : gates verts à chaque US livrée. La progression de 3 sprints sans dette qualité accumulée valide l'investissement en gates locaux, même en l'absence de CI cloud.
- **Capacité maîtrisée pour un sprint de consolidation** : 16 pts assumés comme périmètre délibérément resserré ont permis de soigner la qualité (cache Redis, a11y WCAG progressbar, déduplication configurable) sans pression calendaire excessive.

---

## Thèmes prioritaires retenus (dot voting)

| Rang | Thème | Votes | Origine |
|------|-------|-------|---------|
| 1 | CI GitHub Actions non résolue depuis 3 sprints — dette technique de gouvernance | — | Bilan quantitatif |
| 2 | Objectif rétention J+1/J+7 non atteint : infra staging et tâches ops non différenciées du code | — | Sprint Goal non soldé |
| 3 | Provisioning admin prod : dette Sprint 2 invisible, jamais escaladée | — | Gaps reportés |

---

## Plan d'Action SMART — Sprint 4 (à planifier)

### Action 1 — PROCESSUS : Mettre le staging en condition préalable au Sprint 4, pas en scope concurrent

| Champ | Détail |
|-------|--------|
| **Quoi** | Inscrire dans le Sprint Planning Sprint 4 Part 1 que le déploiement de l'environnement staging (serveur, variables d'environnement DSN/Redis/Mistral, URL dédiée) et l'instrumentation analytics rétention (events `brief_opened` / `brief_completed`, 0 PII) constituent une **condition d'entrée au sprint**, non une US parmi d'autres. Si le staging n'est pas opérationnel à J+2 Sprint 4, le Sprint Goal « mesurer la rétention J+1/J+7 » doit être retiré et le périmètre US revu en conséquence lors d'une réunion d'urgence. |
| **Pourquoi** | L'objectif de rétention était le marqueur de valeur de Sprint 3. Son échec ne tient pas à la qualité du code (5 US / 554 tests livrés) mais à la dépendance non gérée à une infra humaine. Reproduire le même schéma en Sprint 4 produirait le même résultat. |
| **Responsable** | Tech Lead (mise à jour du Sprint Goal Sprint 4) + responsable ops désigné (provisioning staging) |
| **Échéance** | Sprint Planning Sprint 4 Part 1 — **2026-09-08** (J+1 après la rétro) |
| **Critère de succès** | Le Sprint Goal Sprint 4 mentionne explicitement : « staging opérationnel à J+2 — prérequis bloquant » ; un responsable ops est nommé dans le Sprint Planning avec une date d'engagement individuelle |
| **Mesurable** | `sprint-goal.md` Sprint 4 contient la mention du prérequis staging ; aucune US applicative ouverte avant confirmation staging opérationnel |

---

### Action 2 — OUTILLAGE / TECHNIQUE : Débloquer la CI GitHub Actions (ÉCHÉANCE FERME) + provisioning admin prod

| Champ | Détail |
|-------|--------|
| **Quoi (a) — CI** | Résoudre le problème de billing GitHub Actions sur le dépôt privé et valider un pipeline CI complet (PHPUnit + PHPStan + CS Fixer + Deptrac) passant au vert sur `main`. Si le billing reste bloqué par une contrainte externe, activer une alternative documentée avant J+3 Sprint 4 : runner self-hosted OrbStack, GitHub Free avec dépôt rendu public temporairement, ou Forgejo CI. La non-résolution doit être traitée comme un **impediment majeur** à remonter immédiatement au Product Owner. |
| **Quoi (b) — Admin prod** | Créer le compte administrateur de production pour P-004 Sophie avec `ROLE_ADMIN` via la commande `make admin:provision` (ou équivalent) et valider l'accès à l'interface d'administration Symfony. Tracer l'action dans `sprint-gaps.md`. |
| **Pourquoi** | La CI est une dette de gouvernance depuis le Sprint 1. Trois sprints de gates locaux uniquement exposent le projet à un risque de régression non détectée lors d'une contribution externe ou d'une revue de branche. Le provisioning admin bloque les opérations éditoriales de production. |
| **Responsable** | Tech Lead |
| **Échéance — CI** | **2026-09-09 (J+2 Sprint 4) — ÉCHÉANCE FERME. Si non résolu à cette date, escalade immédiate + choix d'alternative documentée avant fin de journée.** |
| **Échéance — Admin prod** | **2026-09-09 (J+2 Sprint 4)** |
| **Critère de succès** | Badge CI vert dans le README sur `main` ; compte admin prod actif avec connexion vérifiée |
| **Mesurable** | Badge GitHub Actions visible dans le README ; log de connexion admin en base de production |

---

### Action 3 — COLLABORATION : Séparer code et ops dès la décomposition — étiquette `[OPS-HUMAIN]` obligatoire

| Champ | Détail |
|-------|--------|
| **Quoi** | Lors du Sprint Planning Part 2 de chaque sprint, toute tâche exigeant une action non automatisable par les workers IA (provisioning de secrets, accès billing, déploiement de serveur, création de comptes, configuration de DNS) est étiquetée `[OPS-HUMAIN]` dans le task board. Cette tâche doit (1) avoir un responsable humain nommé dans le Sprint Planning, (2) avoir une date d'engagement individuelle (pas « fin de sprint »), (3) être traitée en priorité absolue avant toute US qui en dépend. Si le responsable n'est pas disponible avant J+2, la tâche dépendante est retirée du périmètre sprint. Documenter le protocole dans `.claude/rules/02-ops-human-tasks.md`. |
| **Pourquoi** | Les tâches ops humaines ont glissé silencieusement dans `technical-tasks` non faits à Sprint 3 (staging, secrets, provisioning admin), rendant le Sprint Goal partiellement inatteignable. Les workers IA ne peuvent pas escalader un manque de credentials — seule la décomposition explicite rend le blocage visible au moment du Planning. |
| **Responsable** | Tech Lead (rédaction protocole + animation Sprint Planning) + Product Owner (validation que les OPS-HUMAIN sont bien assignées avant le démarrage) |
| **Échéance** | Sprint Planning Sprint 4 Part 2 — **2026-09-08** |
| **Critère de succès** | Fichier `.claude/rules/02-ops-human-tasks.md` créé avec le protocole ; toutes les tâches `[OPS-HUMAIN]` de Sprint 4 ont un responsable nommé dès le task board initial ; 0 tâche ops bloquante découverte en cours de sprint |
| **Mesurable** | Fichier règle présent avant le Planning ; colonne `Responsable` renseignée pour 100 % des tâches `[OPS-HUMAIN]` dans `task-board.md` Sprint 4 |

---

## Tableau de suivi des actions

| # | Action | Type | Responsable | Échéance | Statut |
|---|--------|------|-------------|----------|--------|
| A1 | Sprint Goal Sprint 4 : staging en prérequis bloquant J+2 — mention explicite dans sprint-goal.md | Processus | Tech Lead + Resp. ops | **2026-09-08** | À faire |
| A2a | CI GitHub Actions : billing résolu + pipeline vert sur `main` (ou alternative activée) | Technique | Tech Lead | **2026-09-09 — FERME** | À faire |
| A2b | Provisioning admin prod : compte P-004 Sophie ROLE_ADMIN actif + connexion vérifiée | Technique | Tech Lead | **2026-09-09** | À faire |
| A3 | Protocole `[OPS-HUMAIN]` documenté dans `.claude/rules/` + appliqué dès le Planning Sprint 4 | Collaboration | Tech Lead + PO | **2026-09-08** | À faire |

---

## Suivi des actions Sprint 2 (bilan)

| # Sprint 2 | Action | Statut |
|------------|--------|--------|
| A1 | DoD Sprint 3 — vérification junit indépendante obligatoire | **Résolu** — appliqué dès le premier commit Sprint 3, 0 faux négatif |
| A2a | CI GitHub Actions : résoudre billing + pipeline vert sur `main` | **Non résolu** → reconduit A2a Sprint 4 (FERME 2026-09-09) |
| A2b | Garde-fou Docker : `make agent-worker` séquentiel + règle `.claude/rules/` | **Résolu** — 0 saturation OrbStack Sprint 3 |
| A3a | `sprint-gaps.md` : provisioning admin + écran desktop répertoriés | **Partiellement résolu** — staging et admin prod toujours ouverts |
| A3b | Protocole inter-agents qualification junit documenté dans `.claude/rules/` | **Résolu** — diff junit avant commit intégré comme invariant |

---

## Rappel — Hypothèse produit à valider en Sprint 4

> **Hypothèse** : Un utilisateur (P-001 Thomas) qui accède au Daily Brief consolidé — featured summary narratif, CTA visible en permanence, barre de progression émeraude, articles dédupliqués, synthèses servies depuis le cache Redis — et qui peut gérer son profil (P-002 Priya), percevra une expérience significativement plus fluide, mesurable par un taux de rétention J+1 >= 40 % et J+7 >= 20 % observé sur l'environnement de staging instrumenté.

La chaîne technique est entièrement livrée côté code (US-006, US-007, US-012, US-022, US-032). Le seul prérequis restant est l'environnement staging avec instrumentation anonymisée. Sprint 4 doit livrer ce prérequis en priorité absolue pour permettre la validation de cette hypothèse fondatrice du produit.

**Prérequis Sprint 4 (non négociables)** : CI verte (A2a — FERME 2026-09-09) + staging déployé (A1 — prérequis J+2) + provisioning admin prod (A2b — 2026-09-09).
