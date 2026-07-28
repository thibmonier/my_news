# Résumé de Recherche — Briefly AI

> Phase 1 (Analyse) — Track Enterprise. Consolide recherche technique + business.
> Date : 2026-07-28 · Source : brief.md + recherche research-assistant + product-owner.

---

## 1. Problème & proposition de valeur

Le problème n'est pas l'accès à l'information mais le **coût cognitif** de traverser le bruit. Briefly AI repositionne l'agrégateur (push de volume) en **éditeur algorithmique** (pull de valeur) : le *Daily Brief* — 3 histoires majeures synthétisées, angle éditorial défendable — est un produit, pas une fonctionnalité.

Promesse : **temps récupéré**, pas contenu supplémentaire. Différenciation = qualité des sources + ton éditorial + privacy crédible.

---

## 2. Personas (détail dans backlog phase Planification)

| ID | Profil | Besoin cœur | Critère succès |
|----|--------|-------------|----------------|
| **P-001 Thomas** | Cadre dirigeant tech, 38 ans | Couvrir son secteur en <15 min/jour | ≥5j/7, conversion premium S3 |
| **P-002 Priya** | Chercheuse stratégie, 31 ans | Synthèse transverse sur sources premium | Rétention J90, export/indexation |
| **P-003 Marc** | Dev indépendant, 44 ans, privacy | Info qualité sans tracking, traitement local, API | Activation API J+7 |

---

## 3. Marché & concurrence

| Acteur | Menace | Note |
|--------|--------|------|
| Google News | Faible | Distribution massive, aucune synthèse, biais engagement |
| Feedly | Moyenne | Même audience pro, RSS pro, synthèse IA timide (Leo) |
| Perplexity | **Forte** | Monte sur l'actualité (Perplexity Daily), sources citées |
| Ground News | Faible | Complémentaire (biais/sourcing) |
| Particle | Nulle | Absorbé par Yahoo (2024) — valide le segment |
| Artifact † | Nulle | Fermé fév. 2024 — signal : standalone difficile |

**Différenciateurs** : (1) Daily Brief comme point d'entrée identitaire ; (2) ton éditorial assumé ; (3) sourcing premium verrouillé (Economist, HBR, MIT TR) ; (4) privacy-first crédible (DMA/AI Act).

**Tendances** : synthèse IA généralisée sous 18 mois → l'avantage sera la qualité sources + ton, pas la synthèse. Fatigue de l'IA générique. Agrégation & droits (jurisprudence NYT vs OpenAI → indexation contractuelle, pas scraping). On-device AI viable (Apple Intelligence, Gemini Nano, Mistral Small).

---

## 4. Recherche technique — synthèse

Pile imposée : **Symfony 8 + API Platform 4 + FrankenPHP + PostgreSQL + Redis + Docker**. Détail des arbitrages dans `technical-options.md`. Recommandations clés :

| Sujet | Reco |
|-------|------|
| Frontend desktop | Symfony/Twig + Turbo (SEO natif, stack unique, session HttpOnly) |
| Mobile | Flutter (codebase unique, perfs suffisantes, APIs natives dispo) |
| Ingestion | FeedIo + Symfony Scheduler + Messenger |
| Déduplication | SHA-256 URL canonique + SimHash titre + fenêtre temporelle |
| IA synthèse | **Hybride** : Mistral serveur (batch/demande) + Phi-3 Mini on-device (opt-in) |
| LLM défaut | Mistral (RGPD, hébergement EU) + fallback OpenAI |
| Auth | KnpU OAuth2 + JWT mobile + session desktop + biométrie (protège token local) |
| Push | FCM/APNs via Notifee (Flutter) |
| Billing | Stripe Billing + webhooks → Messenger |

---

## 5. Métriques de succès (cibles 12 mois)

- Rétention J30 ≥ 40 % (moyenne news apps ~20 %)
- Conversion premium ≥ 8 % des actifs mensuels
- DAU/MAU ≥ 35 % (rituel quotidien installé)
- NPS premium ≥ 45 (signal PMF)

KPIs : brief open rate ≥60 %, taux de deep-dive, streak, MRR/ARPU/LTV, « temps gagné » déclaré, share rate du brief.

---

## 6. Résultats clés

- 3 personas alignés sur 3 cibles distinctes, tensions produit différentes (rapidité / profondeur / privacy).
- Positionnement viable mais **risqué** : si le brief v1 est indiscernable de « Google News mis en forme », le positionnement ne tient pas → au moins 1 source non grand public dès le MVP.
- Pile technique cohérente ; 2 arbitrages structurants (frontend desktop, mobile) tranchables immédiatement.
- La couche IA on-device est le pari de différenciation le plus fort (P-003) mais réaliste uniquement en **hybride**.
- 9 décisions du brief §9 tranchées — voir `constraints.md` §Décisions.
