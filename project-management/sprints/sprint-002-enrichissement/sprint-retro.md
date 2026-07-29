# Sprint Rétrospective — Sprint 002 Enrichissement

**Date** : 2026-08-24 (après la Review)
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
| Plan d'action SMART Sprint 3 | 20 min | Formalisation des actions avec responsable + échéance |

---

## Bilan quantitatif du Sprint 2

| Indicateur | Sprint 1 | Sprint 2 | Delta |
|------------|----------|----------|-------|
| US livrées | 3 US / 15 pts | 5 US / 23 pts | +2 US / +8 pts |
| Tests | 226 | 437 | +211 (+93 %) |
| PHPStan erreurs | 0 | 0 | stable |
| Deptrac violations | 0 | 0 | stable |
| CS Fixer | 0 | 0 | stable |
| CI GitHub Actions | KO (billing) | KO (billing) | non résolu |

**US livrées** : chaîne d'enrichissement Brief (US-004 → US-011 → US-005), admin (US-021), OAuth (US-031).

**Gaps produits reportés** : provisioning admin en production (§ non scopé) ; écran « Sauvegardés » desktop Stitch (§9.9) manquant.

---

## Observations — Start / Stop / Continue

### START — Ce que nous devrions commencer à faire

- **Imposer la vérification junit indépendante comme gate bloquante avant tout commit** : la pratique a été appliquée informellement en fin de sprint après l'incident #2, mais n'est pas encore inscrite dans la DoD. Elle doit devenir un réflexe systématique (`make test --log-junit var/test-results.xml`) porté par l'orchestrateur, pas délégué au worker qui vient d'implémenter.
- **Appliquer un garde-fou de parallélisme Docker** : limiter à 1 opération Docker lourde à la fois (build FrankenPHP + workers d'implémentation). L'incident OrbStack (saturation CPU/RAM, watchdog 600 s) a stalé un worker complet ; la fondation sur disque a sauvé le travail, mais l'interruption reste coûteuse.
- **Documenter les gaps produits reportés dans un fichier `sprint-gaps.md` dédié** : le provisioning admin prod et l'écran Stitch desktop ont glissé silencieusement. Un fichier de suivi explicite les rend visibles pour le Product Owner et le Sprint Planning suivant.
- **Fixer une échéance ferme et non négociable pour la CI GitHub Actions** : l'action A1 de la rétro Sprint 1 (billing GitHub) n'a pas été soldée. Une troisième itération sans CI cloud n'est pas acceptable.

### STOP — Ce que nous devrions arrêter de faire

- **Laisser un worker qualifier des régressions comme « pré-existantes » sans preuve junit** : dans l'incident #2, 5 tests en échec ont été mal classifiés, masquant une vraie régression (override `test/security.yaml` cassant `loginUser()`). La qualification d'une régression pré-existante doit obligatoirement s'appuyer sur un diff de rapport junit entre la branche et `main`, jamais sur une appréciation subjective.
- **Lancer plusieurs sous-agents Docker lourds en parallèle sans limite de concurrence** : build d'image + worker FrankenPHP + sous-agent d'implémentation = saturation OrbStack. Les opérations Docker coûteuses doivent être séquentielles ou soumises à un sémaphore explicite.
- **Reporter d'un sprint à l'autre une action technique sans échéance contractuelle** : la CI GitHub Actions attend depuis le Jour 1 du Sprint 2. Sans date dure, l'action sera encore ouverte en Sprint 4.

### CONTINUE — Ce qui a bien fonctionné et doit être préservé

- **Sous-agents isolés pour la décomposition et les gates** : le contexte de l'orchestrateur est resté léger tout au long du sprint. Les workers de décomposition, gates PHPStan, Deptrac et CS Fixer ont chacun opéré dans un contexte propre — aucune pollution croisée, résultats fiables.
- **TDD strict** : 437 tests verts (+211 par rapport au Sprint 1). La suite a absorbé l'incident de régression et fourni le filet de sécurité nécessaire à sa correction rapide.
- **Fondation sur disque entre sessions** : après le stall du watchdog (600 s), le worker a pu reprendre sans perte de code. La discipline d'écriture sur disque avant validation est à conserver comme invariant.
- **Architecture hexagonale + Deptrac** : 0 violation sur l'ensemble du code Sprint 2. La chaîne d'enrichissement (SourceFetcher → ArticleRepository → BriefBuilder) respecte les couches sans couplage accidentel.
- **PHPStan niveau max + CS Fixer** : gates verts en local à chaque US livrée. Aucune dette qualité accumulée malgré le volume (US-004, US-005, US-011 représentent la partie la plus complexe de la chaîne IA).
- **Vertical slicing complet** : chaque US traverse API Platform → Domain → Infrastructure → tests Feature. Aucune US « backend only » ou « DB only ».
- **Récupération gracieuse des incidents** : les deux incidents (OrbStack saturé, faux négatif tests) ont été détectés, qualifiés et corrigés dans le sprint sans débordement. La traçabilité des fondations a été déterminante.

---

## Thèmes prioritaires retenus (dot voting)

| Rang | Thème | Votes | Origine |
|------|-------|-------|---------|
| 1 | CI GitHub Actions toujours KO — reportée sans échéance ferme depuis Sprint 1 | — | Incident #3 |
| 2 | Faux négatifs tests : qualification non étayée de régressions comme « pré-existantes » | — | Incident #2 |
| 3 | Saturation Docker sous charge parallèle — risque de perte de session | — | Incident #1 |

---

## Plan d'Action SMART — Sprint 3 (2026-08-25 → 2026-09-07)

### Action 1 — PROCESSUS : Formaliser la vérification junit indépendante avant tout commit

| Champ | Détail |
|-------|--------|
| **Quoi** | Inscrire dans la Definition of Done du Sprint 3 : « Avant tout commit, l'orchestrateur exécute `make test --log-junit var/test-results.xml` et compare le rapport au dernier rapport junit de `main`. Toute régression nouvelle (même un test) bloque le commit. La qualification "pré-existante" exige un diff junit explicite. » |
| **Pourquoi** | L'incident #2 (5 régressions mal qualifiées, régression réelle masquée sur `loginUser()`) aurait été détecté immédiatement par une comparaison junit objective. La pratique a été appliquée correctivement en fin de Sprint 2 ; elle doit devenir systématique dès le premier commit de Sprint 3. |
| **Responsable** | Tech Lead (mise à jour DoD) + tous les workers/sous-agents (respect du gate) |
| **Échéance** | Sprint Planning Part 2 — 2026-08-25 (avant la première tâche prise en cours) |
| **Critère de succès** | DoD Sprint 3 amendée avec la règle junit ; `make test --log-junit` présent dans le `Makefile` ; 0 commit de Sprint 3 ne contient une régression non détectée |
| **Mesurable** | Rapport junit archivé dans `var/test-results/` à chaque session ; diff consultable sur demande |

---

### Action 2 — OUTILLAGE / TECHNIQUE : CI GitHub Actions (ÉCHÉANCE FERME) + garde-fou charge Docker

| Champ | Détail |
|-------|--------|
| **Quoi (a) — CI** | Résoudre le billing du dépôt privé GitHub et valider qu'un premier pipeline (Pest + PHPStan + CS Fixer + build Docker) passe au vert sur `main`. Si le billing est bloqué par une contrainte externe, ouvrir une alternative (Forgejo CI, GitHub Free avec dépôt public temporaire, ou runner self-hosted via OrbStack). |
| **Quoi (b) — Docker** | Ajouter dans le `Makefile` une cible `make agent-worker` qui exécute les sous-agents Docker en séquentiel (`--max-parallel=1` pour les builds FrankenPHP) et documente la règle dans `.claude/rules/` : « Pas plus d'un build Docker lourd simultané par session OrbStack. » |
| **Pourquoi** | La CI cloud est reportée depuis le Jour 1 du Sprint 2 (action A1, Sprint 1 rétro). Les gates locaux sont un filet de secours, pas une CI. La saturation OrbStack (incident #1, watchdog 600 s) est reproductible si le parallélisme n'est pas contraint. |
| **Responsable** | Tech Lead |
| **Échéance — CI** | **2026-08-25 (Jour 1 Sprint 3) — ÉCHÉANCE FERME. Si non résolu à cette date, bloquer le Sprint Planning et traiter comme impediment majeur.** |
| **Échéance — Docker** | 2026-08-27 (J+3 Sprint 3) |
| **Critère de succès** | Badge CI vert dans le README (`main`) ; aucun stall de worker en Sprint 3 lié à une saturation OrbStack |
| **Mesurable** | Badge GitHub Actions visible ; `make agent-worker` documenté et utilisé |

---

### Action 3 — COLLABORATION : Traçabilité des gaps produits et protocole de qualification inter-agents

| Champ | Détail |
|-------|--------|
| **Quoi (a) — Gaps produits** | Créer `project-management/sprints/sprint-gaps.md` listant chaque gap reporté avec sa description, son sprint d'origine, son impact produit et son statut. Mettre à jour ce fichier en fin de chaque sprint (Review ou Rétro) avant le Sprint Planning suivant. Entrées immédiates : provisioning admin prod, écran « Sauvegardés » desktop (§9.9). |
| **Quoi (b) — Protocole inter-agents** | Documenter dans `.claude/rules/` le protocole de qualification des résultats de tests par les workers : (1) le worker d'implémentation produit le rapport junit, (2) l'orchestrateur ou un worker indépendant valide le diff avec `main` avant de valider la tâche. Aucun worker ne peut auto-valider une qualification « pré-existante ». |
| **Pourquoi** | Les deux gaps produits (admin prod, écran desktop) ont glissé sans tracé visible pour le PO. Le faux négatif tests provient d'une auto-qualification non contre-vérifiée — une séparation orchestrateur / worker d'implémentation sur ce point l'aurait évité. |
| **Responsable** | Tech Lead (fichier gaps + règle protocole) + Product Owner (validation des gaps) |
| **Échéance** | Sprint Planning Sprint 3 — 2026-08-25 (gaps intégrés avant la réunion) |
| **Critère de succès** | `sprint-gaps.md` créé et partagé ; protocole de qualification documenté dans `.claude/rules/` ; 0 faux négatif de qualification tests en Sprint 3 |
| **Mesurable** | Fichier gaps présent avant le Sprint Planning ; règle référencée dans la DoD Sprint 3 |

---

## Tableau de suivi des actions

| # | Action | Type | Responsable | Échéance | Statut |
|---|--------|------|-------------|----------|--------|
| A1 | DoD Sprint 3 — vérification junit indépendante obligatoire | Processus | Tech Lead | 2026-08-25 | À faire |
| A2a | CI GitHub Actions : résoudre billing + pipeline vert sur `main` | Technique | Tech Lead | **2026-08-25 — FERME** | À faire |
| A2b | Garde-fou Docker : `make agent-worker` séquentiel + règle `.claude/rules/` | Technique | Tech Lead | 2026-08-27 | À faire |
| A3a | `sprint-gaps.md` : provisioning admin + écran desktop répertoriés | Collaboration | Tech Lead + PO | 2026-08-25 | À faire |
| A3b | Protocole inter-agents qualification junit documenté dans `.claude/rules/` | Collaboration | Tech Lead | 2026-08-25 | À faire |

---

## Suivi des actions Sprint 1 (bilan)

| # Sprint 1 | Action | Statut |
|------------|--------|--------|
| A1 | Billing GitHub Actions + pipeline CI vert | Non résolu → reporté A2a Sprint 3 (FERME) |
| A2a | Migration `debril/feed-io` → `php-feed-io/feed-io` | Résolu en Sprint 2 |
| A2b | Matrice compatibilité Symfony 8 / Doctrine 3 / API Platform 4 | Résolu en Sprint 2 |
| A2c | Job `composer audit` dans CI | En attente CI (dépend A2a Sprint 3) |
| A3a | Script `make smoke` (docker build + healthcheck) | Résolu en Sprint 2 |
| A3b | DoD Sprint 2 : smoke-test obligatoire | Résolu en Sprint 2 |
| A3c | Fil équipe « décisions archi en solo » | Partiellement appliqué via changelogs de sessions |

---

## Rappel — Hypothèses produit à valider en Sprint 3

> **Rétention J+1** : Un utilisateur (P-001 Thomas) exposé à un Daily Brief sélectionné algorithmiquement avec synthèse IA percevra la valeur « fort signal, faible bruit » et reviendra le lendemain.

La chaîne d'enrichissement (US-004 → US-011 → US-005) livrée en Sprint 2 constitue le socle technique pour tester cette hypothèse. Le Sprint 3 doit permettre un déploiement en staging accessible et le recrutement de 5 utilisateurs beta minimum.

**Prérequis Sprint 3** : CI verte (A2a) + environnement staging + provisioning admin (gap reporté).
