# Backlog Index — Briefly AI

> Derniere mise a jour : 2026-07-28

---

## Resume Global

| Type | En Sprint | Backlog | Done | Total |
|------|-----------|---------|------|-------|
| EPICs | 4 | 4 | 0 | 8 |
| User Stories | 7 | 45 | 0 | 52 |
| Story Points | 36 | 198 | 0 | 234 |

**Velocite cible** : 20–40 pts/sprint | **Sprint actuel** : sprint-001-walking_skeleton

---

## Vue d'Ensemble par EPIC

| ID | Nom | Priorite (MoSCoW) | US | Points | Statut |
|----|-----|-------------------|----|--------|--------|
| EPIC-001 | Daily Brief Core | Must Have | 7 | 28 | En Sprint (3 US) |
| EPIC-002 | Moteur de Synthese IA | Must Have | 7 | 37 | En Sprint (1 US) |
| EPIC-003 | Gestion des Sources & Indexation | Must Have | 6 | 29 | En Sprint (1 US) |
| EPIC-004 | Comptes Utilisateurs & Premium | Must Have | 7 | 32 | En Sprint (2 US) |
| EPIC-005 | Experience Mobile Native | Should Have | 6 | 25 | Backlog |
| EPIC-006 | API Publique | Could Have | 6 | 23 | Backlog |
| EPIC-007 | Privacy & On-Device AI | Should Have | 6 | 27 | Backlog |
| EPIC-008 | Analytics & Personnalisation | Could Have | 7 | 33 | Backlog |
| | **TOTAL** | | **52** | **234** | |

---

## Sprint Actuel : sprint-001-walking_skeleton

**Sprint Goal** : Livrer le Walking Skeleton de Briefly AI — pipeline RSS reel, selection algorithmique, Daily Brief public, synthese Mistral a la demande, inscription securisee, quota Redis.

**Dates** : 2026-07-28 → 2026-08-10 | **Points** : 36

| ID | Titre | EPIC | Points | Statut |
|----|-------|------|--------|--------|
| US-020 | Pipeline RSS Walking Skeleton (fetch + dedup SHA-256 + stockage) | EPIC-003 | 8 | 🔲 A faire |
| US-001 | Page web publique du Daily Brief (Walking Skeleton) | EPIC-001 | 5 | 🔲 A faire |
| US-002 | Selection algorithmique des 3 histoires majeures du Daily Brief | EPIC-001 | 5 | 🔲 A faire |
| US-010 | Synthese IA a la demande sur URL (Walking Skeleton web) | EPIC-002 | 5 | 🔲 A faire |
| US-030 | Inscription par email avec mot de passe securise | EPIC-004 | 5 | 🔲 A faire |
| US-033 | Quota quotidien de syntheses et paywall placeholder | EPIC-004 | 5 | 🔲 A faire |
| US-003 | Planification automatique du batch Daily Brief — 5h UTC | EPIC-001 | 3 | 🔲 A faire |

**Lien** : `sprints/sprint-001-walking_skeleton/`

---

## Backlog Priorise (Hors Sprint)

### EPIC-001 — Daily Brief Core (28 pts / 7 US)

> MMF : Une page web publique affichant automatiquement chaque matin les 3 histoires les plus importantes du jour, sans authentification.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-001 | Page web publique du Daily Brief (Walking Skeleton) | 5 | 🔲 En Sprint |
| US-002 | Selection algorithmique des 3 histoires majeures | 5 | 🔲 En Sprint |
| US-003 | Planification automatique du batch Daily Brief — 5h UTC | 3 | 🔲 En Sprint |
| US-004 | Condense IA par article avec badge et tracabilite source | 5 | 🔲 Backlog |
| US-005 | Cartes typees par categorie editoriale | 3 | 🔲 Backlog |
| US-006 | Featured Summary desktop + CTA Lire le brief complet | 5 | 🔲 Backlog |
| US-007 | Indicateur de progression de lecture (ligne emeraude 2px) | 2 | 🔲 Backlog |

### EPIC-002 — Moteur de Synthese IA (37 pts / 7 US)

> MMF : En un clic depuis n'importe quel article ou URL, Briefly genere un condense structure (~200 mots) prefixe "BRIEFLY AI:" avec 3 points cles et sources citees.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-010 | Synthese IA a la demande sur URL (Walking Skeleton web) | 5 | 🔲 En Sprint |
| US-011 | Niveaux de synthese multi-niveaux (Concise / Detailed / Narrative) | 5 | 🔲 Backlog |
| US-012 | Cache Redis 24h des syntheses generees | 3 | 🔲 Backlog |
| US-013 | Quota gratuit (3 syntheses/jour) et paywall progressif | 5 | 🔲 Backlog |
| US-014 | Fallback provider OpenAI en cas d'indisponibilite Mistral | 3 | 🔲 Backlog |
| US-015 | Synthese on-device opt-in Flutter + Phi-3 Mini (P-003) | 8 | 🔲 Backlog |
| US-016 | Clustering semantique et classification par sujets | 8 | 🔲 Backlog |

### EPIC-003 — Gestion des Sources & Indexation (29 pts / 6 US)

> MMF : Un pipeline RSS operationnel sur 3 sources publiques — fetch automatique via FeedIo, deduplication SHA-256 et stockage PostgreSQL — declenche par Symfony Scheduler.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-020 | Pipeline RSS Walking Skeleton (fetch + dedup SHA-256 + stockage) | 8 | 🔲 En Sprint |
| US-021 | Gestion CRUD sources RSS/Atom par l'administrateur | 5 | 🔲 Backlog |
| US-022 | Deduplication avancee par SimHash de titre | 3 | 🔲 Backlog |
| US-023 | Rate limiting Redis + circuit breaker par source | 5 | 🔲 Backlog |
| US-024 | File de priorite Messenger (premium avant gratuit) | 3 | 🔲 Backlog |
| US-025 | Connecteur Google News (sous-canaux A la une / Technologie / Science) | 5 | 🔲 Backlog |

### EPIC-004 — Comptes Utilisateurs & Premium (32 pts / 7 US)

> MMF : Un visiteur peut creer un compte en moins de 2 minutes, consulter son quota de syntheses restant et etre invite a passer Briefly Premium des le depassement de la limite quotidienne gratuite.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-030 | Inscription par email avec mot de passe securise | 5 | 🔲 En Sprint |
| US-033 | Quota quotidien de syntheses et paywall placeholder | 5 | 🔲 En Sprint |
| US-031 | Authentification deleguee Google / GitHub (OAuth2) | 5 | 🔲 Backlog |
| US-032 | Gestion du profil utilisateur | 3 | 🔲 Backlog |
| US-034 | Abonnement Briefly Premium via Stripe Billing | 8 | 🔲 Backlog |
| US-035 | Reglages de confidentialite et preferences RGPD | 3 | 🔲 Backlog |
| US-036 | Suppression de compte conforme RGPD | 3 | 🔲 Backlog |

### EPIC-005 — Experience Mobile Native (25 pts / 6 US)

> MMF : Application Flutter iOS/Android permettant a un utilisateur authentifie de consulter son Daily Brief, sauvegarder des articles en lecture differee et recevoir sa notification push quotidienne.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-040 | Squelette Flutter + Navigation 4 onglets | 5 | 🔲 Backlog |
| US-041 | Consultation du Daily Brief sur mobile | 5 | 🔲 Backlog |
| US-042 | Sauvegarde d'articles et cache offline | 5 | 🔲 Backlog |
| US-043 | Notifications push Daily Brief (FCM/APNs) | 5 | 🔲 Backlog |
| US-044 | Authentification biometrique (Face ID / Touch ID) | 3 | 🔲 Backlog |
| US-045 | Themes clair/sombre (Insight Minimalist / Insight Dark) | 2 | 🔲 Backlog |

### EPIC-006 — API Publique (23 pts / 6 US)

> MMF : Un developpeur disposant d'un token API peut recuperer le Daily Brief du jour en JSON via GET /v1/daily-brief et consulter la documentation interactive OpenAPI.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-050 | Gestion des tokens API | 5 | 🔲 Backlog |
| US-051 | Endpoint GET /v1/daily-brief | 5 | 🔲 Backlog |
| US-052 | Endpoint POST /v1/synthesize | 5 | 🔲 Backlog |
| US-053 | Rate limit headers et quotas par plan | 3 | 🔲 Backlog |
| US-054 | Documentation developpeur OpenAPI / Swagger UI | 3 | 🔲 Backlog |
| US-055 | Lien 'API ACCESS' pied de page desktop | 2 | 🔲 Backlog |

### EPIC-007 — Privacy & On-Device AI (27 pts / 6 US)

> MMF : Un utilisateur peut consulter et supprimer son historique de lecture, telecharger ses donnees en JSON et activer la synthese on-device, sans qu'aucune donnee personnelle ne soit transmise a un tiers IA.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-060 | Consulter et supprimer l'historique de lecture | 5 | 🔲 Backlog |
| US-061 | Exporter ses donnees personnelles en JSON | 3 | 🔲 Backlog |
| US-062 | Activer le mode vie privee avec synthese on-device | 8 | 🔲 Backlog |
| US-063 | Configurer les reglages granulaires de confidentialite | 5 | 🔲 Backlog |
| US-064 | Consulter la transparence du traitement IA (AI Act) | 3 | 🔲 Backlog |
| US-065 | Pseudonymisation des analytics et donnees comportementales | 3 | 🔲 Backlog |

### EPIC-008 — Analytics & Personnalisation (33 pts / 7 US)

> MMF : Permettre a l'utilisateur de configurer 3 themes d'interet qui ponderent son Daily Brief des le lendemain, d'explorer les sujets chauds par categorie et de sauvegarder des articles dans une bibliotheque personnelle filtrable.

| ID | Titre | Points | Statut |
|----|-------|--------|--------|
| US-070 | Configuration de 3 themes d'interet pour le Daily Brief | 5 | 🔲 Backlog |
| US-071 | Exploration des sujets chauds par categorie | 5 | 🔲 Backlog |
| US-072 | Sauvegarde d'articles dans la bibliotheque personnelle | 5 | 🔲 Backlog |
| US-073 | Filtrage et organisation de la bibliotheque personnelle | 5 | 🔲 Backlog |
| US-074 | Partage d'article et acces a la source originale | 3 | 🔲 Backlog |
| US-075 | Tableau de bord analytique respectueux RGPD | 5 | 🔲 Backlog |
| US-076 | Metriques produit — retention et engagement | 5 | 🔲 Backlog |

---

## Legende Statuts

| Icone | Statut | Description |
|-------|--------|-------------|
| 🔲 | A faire | Pas encore commence |
| 🔄 | En cours | Developpement en cours |
| 👀 | Review | Code review / QA |
| ✅ | Done | Criteres DoD valides |
| 🚫 | Bloque | Impediment identifie |

---

## Liens Utiles

| Ressource | Chemin |
|-----------|--------|
| Sprint actuel | `sprints/sprint-001-walking_skeleton/` |
| Sprint Goal | `sprints/sprint-001-walking_skeleton/sprint-goal.md` |
| Task Board | `sprints/sprint-001-walking_skeleton/task-board.md` |
| Definition of Done | `definition-of-done.md` |
| Dependances inter-EPIC | `dependencies-matrix.md` |
| Personas | `personas.md` |
| PRD | `prd.md` |
