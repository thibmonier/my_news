# Backlog Index

> Dernière mise à jour : 2026-08-09

---

## Résumé Global

| Type | 🔴 To Do | 🟢 Done | Total actif | Archivé (SPLIT) |
|------|----------|---------|-------------|-----------------|
| EPICs | — | — | 8 | — |
| User Stories | 39 | 17 | 56 | 3 |
| Tasks | — | 164 décomposées (S1+S2) | 164 | — |

**Vélocité réalisée** : 75 pts sur 234 (32 %) — Sprints 1 à 3 livrés.
_US actives : 56 (3 parents SPLIT US-015/016/034 archivés, remplacés par leurs enfants)._

---

## EPICs

| ID | Nom | US actives | Livrées | Progression |
|----|-----|-----------|---------|-------------|
| EPIC-001 | Daily Brief Core | 7 | 7 | 🟢 100 % |
| EPIC-002 | Moteur Synthèse IA | 10 | 3 | 🟡 30 % |
| EPIC-003 | Sources & Indexation | 6 | 3 | 🟡 50 % |
| EPIC-004 | Comptes & Premium | 8 | 4 | 🟡 50 % |
| EPIC-005 | Mobile Flutter | 6 | 0 | 🔴 0 % |
| EPIC-006 | API Publique | 6 | 0 | 🔴 0 % |
| EPIC-007 | Privacy On-Device | 6 | 0 | 🔴 0 % |
| EPIC-008 | Analytics & Personnalisation | 7 | 0 | 🔴 0 % |

---

## Sprints livrés

| Sprint | But | US | Points | Statut |
|--------|-----|-----|--------|--------|
| sprint-001 | Walking Skeleton | US-001, US-002, US-003, US-010, US-020, US-030, US-033 | 36 | 🟢 Done |
| sprint-002 | Enrichissement | US-004, US-005, US-011, US-021, US-031 | 23 | 🟢 Done |
| sprint-003 | Consolidation | US-006, US-007, US-012, US-022, US-032 | 16 | 🟢 Done |

_Total livré : 17 US / 75 pts._

> ⚠️ Le champ `Sprint` dans les en-têtes de certaines US (US-030, US-032, US-033) indique encore `backlog` alors qu'elles ont été livrées — les task-boards de sprint font foi. À réaligner (cf. recommandation R2).

---

## Backlog priorisé (hors sprints livrés — 39 US)

| EPIC | US restantes | Points |
|------|--------------|--------|
| EPIC-002 | US-013, US-014, US-015a, US-015b, US-016a, US-016b, US-016c | 24 |
| EPIC-003 | US-023, US-024, US-025 | 13 |
| EPIC-004 | US-034a, US-034b, US-035, US-036 | 14 |
| EPIC-005 | US-040, US-041, US-042, US-043, US-044, US-045 | 25 |
| EPIC-006 | US-050, US-051, US-052, US-053, US-054, US-055 | 23 |
| EPIC-007 | US-060, US-061, US-062, US-063, US-064, US-065 | n/d |
| EPIC-008 | US-070, US-071, US-072, US-073, US-074, US-075, US-076 | 33 |

_EPIC-007 : story points à confirmer (format non parsé dans les fichiers US-060→065)._

---

## Stories archivées (SPLIT)

| Parent | Remplacé par | Raison |
|--------|--------------|--------|
| US-015 | US-015a, US-015b | Synthèse on-device découpée (téléchargement modèle / inférence) |
| US-016 | US-016a, US-016b, US-016c | Clustering découpé (embeddings / HDBSCAN / classification) |
| US-034 | US-034a, US-034b | Stripe découpé (checkout / customer portal) |

---

## Légende Statuts

| Icône | Statut | Description |
|-------|--------|-------------|
| 🔴 | To Do | Pas encore commencé |
| 🟡 | In Progress / partiel | En cours |
| ⏸️ | Blocked | Bloqué |
| 🟢 | Done | Terminé |
