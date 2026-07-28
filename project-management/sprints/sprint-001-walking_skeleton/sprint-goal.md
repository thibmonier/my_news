# Sprint 001 — Walking Skeleton

## Sprint Goal

> **Livrer le Walking Skeleton de Briefly AI : pipeline RSS réel → sélection algorithmique → Daily Brief public consultable sans authentification, enrichi d'une première synthèse Mistral à la demande, avec inscription sécurisée Argon2id et quota Redis — prouvant de bout en bout la promesse «fort signal, faible bruit».**

---

## Dates

| Evenement | Date |
|-----------|------|
| Debut Sprint | 2026-07-28 |
| Fin Sprint | 2026-08-10 |
| Review / Retro | 2026-08-10 |

---

## Perimetre (User Stories en scope)

| ID | Titre | EPIC | Points | Persona |
|----|-------|------|--------|---------|
| US-020 | Pipeline RSS Walking Skeleton (fetch + dedup SHA-256 + stockage) | EPIC-003 | 8 | P-001 |
| US-001 | Page web publique du Daily Brief (Walking Skeleton) | EPIC-001 | 5 | P-001 |
| US-002 | Selection algorithmique des 3 histoires majeures du Daily Brief | EPIC-001 | 5 | P-001 |
| US-010 | Synthese IA a la demande sur URL (Walking Skeleton web) | EPIC-002 | 5 | P-001 |
| US-030 | Inscription par email avec mot de passe securise | EPIC-004 | 5 | P-001/02/03 |
| US-033 | Quota quotidien de syntheses et paywall placeholder | EPIC-004 | 5 | P-001 |
| US-003 | Planification automatique du batch Daily Brief — 5h UTC | EPIC-001 | 3 | P-001 |

**Total Sprint 1 : 36 story points**

### Ordre de realisation recommande

```
US-020 (RSS) → US-002 (Selection) → US-003 (Scheduler)
             ↘                          ↓
US-030 (Auth) → US-033 (Quota) → US-001 (Page publique)
                                       ↓
                               US-010 (Synthese IA)
```

---

## Ceremonies

### Sprint Planning — Part 1 (QUOI) — 2h

**Animateur** : Tech Lead (Scrum Master)
**Participants** : PO + equipe dev

- Presentation et validation du Sprint Goal par le PO
- Revue de chaque US : criteres d'acceptance Gherkin, questions de clarification
- Confirmation que les US respectent la Definition of Ready (INVEST + 3C + 5 scenarios Gherkin)
- Engagement collectif sur le perimetre (36 pts)

**Questions a lever en Part 1 :**
- Credentials Mistral EU disponibles pour l'environnement de dev ?
- Flux RSS de seed valides (TechCrunch, TheVerge, ArsTechnica) accessibles depuis le Docker ?
- Domaine de dev pour les headers HSTS/CSP ?

### Sprint Planning — Part 2 (COMMENT) — 2h

**Animateur** : Tech Lead
**Participants** : equipe dev

- Decomposition de chaque US en taches techniques (`/project:decompose-tasks 001`)
- Estimation des taches en heures (0.5h — 8h max)
- Graphe de dependances inter-taches (Mermaid)
- Identification des risques techniques (voir section Risques)
- Construction du Task Board initial

### Daily Scrum — 15 min (chaque jour ouvre)

**Format** : Stand-up synchrone (ou asynchrone ecrit si equipe distribuee)

1. Qu'ai-je fait hier qui fait avancer le Sprint Goal ?
2. Que vais-je faire aujourd'hui ?
3. Quels obstacles m'en empechent ?

**Role Tech Lead** : observer, noter les blocages, supprimer les impediments dans l'heure.

### Sprint Review — 2h (2026-08-10)

**Participants** : equipe + PO + stakeholders

Demonstrations livrees :
1. Flux RSS ingere en temps reel depuis 3 sources publiques (US-020)
2. Daily Brief public `/brief` affichant `01 02 03` avec horodatage (US-001)
3. Selection algorithmique des 3 histoires (score composite) (US-002)
4. Batch Scheduler 5h UTC + Lock Redis (US-003)
5. Synthese Mistral `BRIEFLY AI:` generee sur une URL (US-010)
6. Inscription utilisateur et session securisee (US-030)
7. Quota Redis — HTTP 429 a la 4e synthese + modal paywall placeholder (US-033)

**Critere de succes** : toute la chaine fonctionne de bout en bout en live demo, sans scripts de contournement.

### Retrospective — 1h30 (2026-08-10, apres la Review)

**Animateur** : Tech Lead (Scrum Master)
**Technique** : Start / Stop / Continue

**Directive Fondamentale :**
> "Peu importe ce que nous decouvrons, nous comprenons et croyons sincerement
> que tout le monde a fait le meilleur travail possible, compte tenu de ce qu'il
> savait a ce moment-la, de ses competences et aptitudes, des ressources
> disponibles et de la situation du moment."
> — Norm Kerth, *Project Retrospectives*

Format :
- 5 min : rappel de la directive, creation de la securite psychologique
- 15 min : chacun ecrit ses post-its (Start / Stop / Continue)
- 20 min : regroupement et lecture collective
- 30 min : vote par points (dot voting) sur les 2-3 themes prioritaires
- 20 min : plan d'action SMART pour Sprint 2 (responsable + echeance)

**Actions minimales a definir en retro :**
- 1 action sur le processus
- 1 action sur l'outillage ou la technique
- 1 action sur la collaboration

### Affinage Backlog (Backlog Refinement) — en continu

**Duree** : max 10% de la capacite du sprint (environ 4h sur 2 semaines)
**Timing recommande** : deux sessions de 2h en milieu de sprint (J+4 et J+8)

Objectif pour Sprint 2 :
- Raffiner US-004 (Condense IA badge), US-005 (Cartes categories), US-011 (Niveaux synthese), US-021 (CRUD sources RSS), US-031 (OAuth Google/GitHub)
- Verifier INVEST + 5 scenarios Gherkin min sur chaque US
- Estimer les US non estimees

---

## Definition of Done — Sprint 1

> Pour etre marquee DONE, chaque US du Sprint 1 doit satisfaire TOUS les criteres suivants.

### Code
- [ ] Code ecrit et fonctionnel (pas de stub vide)
- [ ] PSR-12 respecte (PHP CS Fixer : 0 diff)
- [ ] PHPStan niveau max : 0 erreur
- [ ] Architecture hexagonale : pas de fuite d'infrastructure dans le domaine
- [ ] Pas de code mort, pas de TODO non tickete
- [ ] UUID v7 non sequentiels sur toutes les nouvelles entites

### Tests
- [ ] Couverture de code >= 80% (unitaires + integration)
- [ ] PHPUnit : Unit + Integration + ApiTestCase pour les endpoints
- [ ] Tests passants en CI (GitHub Actions)
- [ ] 0 test commente, 0 test skip non justifie

### Securite OWASP (obligatoire Sprint 1)
- [ ] Voters Symfony sur chaque operation protegee
- [ ] CSRF actif sur tous les formulaires Twig
- [ ] Rate limiting Redis sur /login et /register
- [ ] Headers securite : CSP, HSTS, X-Frame-Options, X-Content-Type-Options
- [ ] Argon2id (128 MiB, t=3, p=1) pour les mots de passe
- [ ] 0 donnee personnelle dans les prompts Mistral (assert CI bloquant)
- [ ] Validation SSRF sur toutes les URLs externes ingrees
- [ ] 0 secret en dur (variables d'environnement uniquement)

### Vertical Slice
- [ ] Symfony Controller → Service domaine → Repository Doctrine → PostgreSQL
- [ ] Turbo Frames/Streams fonctionnels sur les actions dynamiques
- [ ] API Platform endpoint(s) documente(s) (OpenAPI generee sans erreur)

### RGPD (minimal Sprint 1)
- [ ] Consentement explicite horodate collecte a l'inscription (US-030)
- [ ] Quota Redis sans FK utilisateur dans les cles (UUID uniquement)
- [ ] 0 identifiant utilisateur dans les messages Messenger

### Documentation
- [ ] PHPDoc sur les services et interfaces publics
- [ ] Criteres d'acceptance Gherkin passes en revue (US marquee DONE = scenarios validates)

### Review
- [ ] Code review approuvee par >= 1 pair
- [ ] Pas de commentaire bloquant ouvert

### CI/CD
- [ ] Pipeline CI verte (test + PHPStan + CS Fixer)
- [ ] Pas de regression sur les US precedemment DONE

---

## Hypothese Produit Validee par ce Sprint

> **Hypothese** : Un utilisateur (P-001 Thomas) expose a un Daily Brief de 3 histoires selectionees algorithmiquement et enrichies d'une synthese IA en moins de 60s, avec inscription en moins de 2 min, percevra la valeur «fort signal, faible bruit» et reviendra le lendemain (retention J+1).

**Metrique de validation (a mesurer en Review) :**
- Toute la chaine fonctionne de bout en bout sans intervention manuelle
- Temps de generation du Daily Brief (batch 5h UTC) < 30s
- Temps de reponse page `/brief` < 500ms (sans cache)
- Inscription complete en < 2 min (test chronometre en demo)
- Au moins 1 synthese Mistral generee avec succes prefixee `BRIEFLY AI:`

---

## Risques Identifies Sprint 1

| Risque | Probabilite | Impact | Mitigation |
|--------|-------------|--------|------------|
| Acces RSS bloque (rate limit / IP) | Moyen | Eleve | Seed de fixtures statiques pour les tests, User-Agent specifique Briefly |
| Quota Mistral EU depassee en dev | Faible | Moyen | Mock MistralApiClient dans les tests, cle API dediee dev |
| Scheduler Symfony non supporte sur l'host Docker | Faible | Eleve | Valider `php bin/console messenger:consume` en Docker compose au J+1 |
| Complexite Redis Lock distribue US-003 | Moyen | Moyen | TryLock simple TTL 600s, pas de RedLock en Sprint 1 |
| Capacite equipe (36 pts = plafond velosite) | Moyen | Eleve | US-003 (3 pts) retirable en dernier recours sans casser le Walking Skeleton |
