# Sprint Review — Sprint 002 Enrichissement

**Date** : 2026-08-24
**Durée** : 2h
**Animateur** : Tech Lead / Scrum Master

---

## Participants

| Rôle | Nom | Présence |
|------|-----|----------|
| Product Owner | — | Oui |
| Tech Lead / Scrum Master | — | Oui |
| Développeur(s) | — | Oui |
| Stakeholders | — | Placeholder |

---

## Sprint Goal — Verdict

> **Enrichir le Daily Brief avec des condensés IA par article et des cartes typées par catégorie, offrir plusieurs niveaux de synthèse, ouvrir l'authentification sociale OAuth et donner à l'admin le contrôle des sources RSS — passant du Walking Skeleton à une expérience produit complète.**

| Dimension | Statut | Détail |
|-----------|--------|--------|
| Sprint Goal technique (bout en bout) | **ATTEINT** | Les 5 US livrées, chaîne complète opérationnelle |
| Hypothèse produit (valeur perçue + rétention J+1) | **NON MESURÉE** | Nécessite un déploiement en production et des utilisateurs réels — reportée Sprint 3 |

---

## Démonstrations livrées

| # | User Story | Points | Demo | Statut |
|---|-----------|--------|------|--------|
| 1 | **US-004** — Condensé IA par article avec badge et traçabilité source | 5 pts | Page `/brief` affichant des condensés 3-4 puces (≤ 120 car.) préfixés du badge `BRIEFLY AI:` + icône `auto_awesome` (accent `#10B981`) et lien source `rel="noopener noreferrer"` ; fallback OpenAI automatique après 2 timeouts Mistral ; 0 PII dans les prompts (test bloquant CI) ; circuit breaker Redis 60s en démo | **DONE** |
| 2 | **US-011** — Niveaux de synthèse (concis / standard / détaillé) | 5 pts | Sélecteur radio Turbo Frame permettant de basculer entre les niveaux sans rechargement complet de la page ; cache Redis par `sha256(article_id + niveau)` TTL 24h ; timeout LLM dynamique adapté au niveau ; 422 retourné sur niveau invalide, 503 si LLM indisponible ; 0 PII quel que soit le niveau | **DONE** |
| 3 | **US-005** — Cartes typées par catégorie éditoriale | 3 pts | Pastilles catégorie (couleur + icône + libellé texte) sur chaque carte du Daily Brief ; taxonomie éditoriale portée par le VO `EditorialCategory` (whitelist) ; conforme WCAG 2.1 AA — la couleur n'est jamais le seul vecteur d'information ; échappement Twig strict (anti-XSS vérifié par test) | **DONE** |
| 4 | **US-021** — CRUD des sources RSS (back-office admin) | 5 pts | Back-office `/admin/sources` : liste paginée, création, édition, activation/désactivation et suppression douce ; `SourceVoter` (`ROLE_ADMIN`, deny-by-default sur chaque opération CRUD) ; CSRF actif sur tous les formulaires ; validation SSRF stricte (VO `SsrfSafeUrl` rejetant IP privées RFC-1918, loopback, link-local, `0.0.0.0`, schémas non http/https) ; Voter + CSRF + SSRF prouvés par tests PHPUnit | **DONE** |
| 5 | **US-031** — Authentification OAuth Google / GitHub | 5 pts | Boutons « Connexion avec Google » et « Connexion avec GitHub » sur la page de login ; email connu → liaison automatique au compte existant, email inconnu → création de compte sans mot de passe local ; consentement RGPD horodaté persisté ; session desktop (`httpOnly`, `secure`, `sameSite=strict`) ; 0 secret en dur (variables d'environnement uniquement) ; tests OAuth avec client mocké (aucun appel réseau réel) | **DONE** |

---

## Métriques Qualité — Cibles vs Atteintes

| Métrique | Cible (DoD) | Départ Sprint | Atteint |
|----------|-------------|---------------|---------|
| PHPStan niveau max | 0 erreur | 0 erreur | **0 erreur** |
| Deptrac — violations hexagonales | 0 violation | 0 violation | **0 violation** |
| PHP CS Fixer | 0 diff | 0 diff | **0 diff** |
| Tests PHPUnit (Unit + Feature) | CI verte | **226 tests** | **437/437** (0 échec) |
| Migrations appliquées (dev + test) | Oui | — | **Oui** (4 migrations Sprint 2) |
| 0 PII dans les prompts LLM | Test CI bloquant | — | **Confirmé** (test bloquant présent) |
| 0 secret en dur | Oui | Oui | **Confirmé** |
| Architecture hexagonale / DDD | Respectée | Confirmée Deptrac | **Confirmée** |

### Progression de la suite de tests

```
Sprint 1 (départ) : 226 tests
  +13  — US-004 condensé IA (dont test RGPD + XSS)
  +49  — US-011 niveaux de synthèse
  +49  — US-005 cartes typées par catégorie
  +48  — US-021 CRUD admin + Voter + CSRF + SSRF
  +22  — US-031 OAuth Google/GitHub
= 437 tests (Sprint 2)
```

### Points de sécurité OWASP vérifiés

- Validation SSRF stricte (VO `SsrfSafeUrl`, rejet RFC-1918/loopback/0.0.0.0)
- `SourceVoter` Symfony : deny-by-default, `ROLE_ADMIN` obligatoire sur chaque opération CRUD
- CSRF actif sur tous les formulaires Twig (back-office admin)
- Rate limiter Redis sur `/login` et `/oauth/callback`
- Consentement RGPD OAuth horodaté et persisté
- 0 PII (email, nom, IP) dans les prompts Mistral / OpenAI — test bloquant CI
- Cache Redis keyed sur `sha256(article_id + level)` uniquement — aucun identifiant utilisateur
- Données OAuth (access_token provider) non persistées en clair
- Suppression du compte admin hardcodé Sprint 1 (provider prod vide, users réels via Doctrine Argon2id)

---

## Incréments vs Sprint Goal

| Incrément | Lien Sprint Goal | Valeur livrée |
|-----------|-----------------|---------------|
| Condensés IA + badge (US-004) | Enrichissement brief | L'utilisateur lit 3-4 puces au lieu d'un titre brut |
| Niveaux de synthèse (US-011) | Contrôle utilisateur | L'utilisateur choisit sa profondeur de lecture |
| Cartes typées (US-005) | Lisibilité visuelle | La catégorie éditoriale est perceptible en un coup d'œil |
| CRUD sources admin (US-021) | Contrôle admin | Sophie peut gérer les sources RSS sans accès base de données |
| OAuth Google/GitHub (US-031) | Friction inscription ↓ | Inscription/connexion < 30 s via compte existant |

Le Daily Brief post-Sprint 2 est perceptiblement plus riche que le Walking Skeleton du Sprint 1 : les condensés IA, les cartes typées et le sélecteur de niveau répondent directement au positionnement « fort signal, faible bruit ».

---

## Hypothèse Produit — État de validation

> **Hypothèse** : Un utilisateur (P-001 Thomas) qui accède au Daily Brief enrichi — condensés IA, cartes typées, niveaux de synthèse — et peut s'inscrire via son compte Google ou GitHub en moins de 30 s, percevra une valeur nettement supérieure au Walking Skeleton et acceptera de revenir J+1 et J+7.

| Critère | Cible | Résultat |
|---------|-------|----------|
| Chaîne bout en bout fonctionnelle | Oui | **Oui** (démo live) |
| Condensé IA généré et affiché | Oui | **Validé en démo** |
| Sélecteur de niveau fonctionnel sans rechargement | Oui | **Validé (Turbo Frame)** |
| Login OAuth Google ou GitHub | < 30 s | **Validé en démo** (flow complet) |
| Admin ajoute une source RSS | < 2 min, sans erreur SSRF | **Validé en démo** |
| Temps de génération condensé (Mistral) P95 | < 3 s | À mesurer en production |
| Cache Redis sha256 — taux de hit | >= 60 % | À mesurer en production |
| Lighthouse Performance `/brief` | >= 90 | À mesurer en production |
| Rétention J+1 et J+7 | Mesurable | **NON MESURABLE** — nécessite déploiement prod |

> La rétention J+1 / J+7 reste l'hypothèse prioritaire du Sprint 3. Elle ne peut être mesurée qu'après déploiement en production avec de vrais utilisateurs.

---

## Éléments non terminés

Aucun. Les 5 User Stories du scope Sprint 2 sont marquées **DONE** (23/23 pts livrés).

---

## Incidents notables

| Incident | Impact | Résolution |
|----------|--------|------------|
| Docker / OrbStack saturé (worker stallé) | Arrêt temporaire du worker de test | Redémarrage OrbStack, reprise sans perte de données ni régression |
| Qualification erronée d'une régression (5 tests) | Risque de régression non détectée | Détectée lors de la vérification explicite des tests Sprint 1 avant commit ; corrigée avant merge |
| CI GitHub Actions bloquée (billing repo privé) | Gates non exécutés en cloud | Tous les gates (PHPStan, Deptrac, CS Fixer, PHPUnit) exécutés en local Docker — 0 dégradation de qualité |

---

## Risques résiduels

| Risque | Probabilité | Impact | Action Sprint 3 |
|--------|-------------|--------|-----------------|
| CI GitHub Actions toujours bloquée (billing) | Élevée | Moyen | Résoudre le billing ou migrer vers CI alternative (GitLab CI / self-hosted runner) |
| Provisioning admin production non réalisé | Certaine | Moyen | À inclure dans le Sprint 3 comme tâche ops (hors US) |
| Écran Stitch « Sauvegardés » desktop manquant (gap §9.9) | Certaine | Faible | À adresser lors de l'affinage backlog Sprint 3 (US-006 ou nouvelle US) |
| Quota Mistral en production non surveillé | Moyenne | Élevé | Ajouter monitoring quota + alerte avant Sprint 3 (fallback OpenAI déjà implémenté) |
| Mesures de performance prod (Lighthouse, P95 LLM) non collectées | Certaine | Moyen | Staging + instrumentation à préparer en début de Sprint 3 |

---

## Feedback Stakeholders

> *Placeholder — à compléter lors de la Review du 2026-08-24.*

---

## Décisions issues de la Review

| Décision | Responsable | Échéance |
|----------|-------------|----------|
| Porter la mesure de rétention J+1 / J+7 en objectif Sprint 3 | PO | Sprint 3 Planning |
| Résoudre le blocage CI GitHub Actions (billing) | Tech Lead | J+1 Sprint 3 |
| Provisionner le compte admin en production | Tech Lead / DevOps | J+2 Sprint 3 |
| Instrumenter staging pour mesurer Lighthouse, P95 LLM et taux de hit cache | Tech Lead | Semaine 1 Sprint 3 |
| Affiner US-006, US-007, US-012, US-022, US-032 pour le Sprint 3 | PO + Tech Lead | Backlog Refinement Sprint 3 |

---

## Métriques de vélocité

| Élément | Valeur |
|---------|--------|
| Story points engagés | 23 pts |
| Story points livrés | 23 pts (5/5 US) |
| Tests au départ du sprint | 226 |
| Tests en fin de sprint | 437 (+211) |
| Migrations livrées | 4 |
| Incidents bloquants | 0 (2 incidents mineurs résolus sans perte) |

---

## Prochaines étapes — Candidats Sprint 3

Les User Stories suivantes sont identifiées comme candidates prioritaires pour le Sprint 3 (affinage à confirmer en Backlog Refinement) :

| ID | Titre | EPIC | Points estimés | Dépend de |
|----|-------|------|----------------|-----------|
| US-006 | Featured Summary CTA (« Lire la synthèse complète ») | EPIC-001 | 3 | US-004, US-011 |
| US-007 | Indicateur de progression de lecture | EPIC-001 | 2 | US-005 |
| US-012 | Cache Redis des synthèses complètes | EPIC-002 | 3 | US-011 |
| US-022 | Déduplication SimHash (articles quasi-identiques) | EPIC-003 | 5 | US-021 |
| US-032 | Profil utilisateur (préférences, abonnement) | EPIC-004 | 5 | US-031 |

**Objectif Sprint 3 pressenti** : Mesurer la rétention réelle (J+1 / J+7) sur un environnement de staging accessible à de vrais utilisateurs, et enrichir le profil utilisateur pour personnaliser le Brief.
