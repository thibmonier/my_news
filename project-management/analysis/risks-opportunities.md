# Risques & Opportunités — Briefly AI

> Phase 1 (Analyse). Registre des risques avec atténuations + opportunités.

---

## Registre des risques

| ID | Risque | Impact | Prob. | Atténuation |
|----|--------|--------|-------|-------------|
| RIS-01 | **Positionnement invalide** : brief v1 indiscernable de « Google News mis en forme » | Élevé | Moyen | ≥1 source non grand public + ton éditorial dès le MVP ; test rétention J+1 en Walking Skeleton |
| RIS-02 | **Coûts LLM incontrôlés** (synthèse à la demande, API publique) | Élevé | Moyen | Batch nocturne, cache Redis 24h, quotas free (3/jour), rate limit API strict |
| RIS-03 | **Droits sur sources premium** (jurisprudence NYT vs OpenAI) | Élevé | Moyen | Indexation contractuelle uniquement, jamais de scraping paywall ; démarrer partenariats tôt (cycle long) |
| RIS-04 | **On-device AI décevant** (latence/qualité concis local) | Moyen | Moyen | Positionner on-device en opt-in, jamais défaut ; fallback serveur transparent |
| RIS-05 | **Sur-notification** tue le positionnement « signal fort » | Élevé | Faible | Règle stricte 1 notif/jour ; pas de breaking news v1 |
| RIS-06 | **Concurrence Perplexity** monte sur l'actualité (Perplexity Daily) | Moyen | Élevé | Gagner sur le rituel + ton éditorial + privacy ; ne pas concurrencer sur le conversationnel |
| RIS-07 | **Fuite RGPD / données aux LLM tiers** | Élevé | Faible | Provider EU (Mistral), pas d'ID user dans prompts, mode privacy on-device |
| RIS-08 | **Complexité ingestion** (dédup, sources instables, bans) | Moyen | Moyen | Circuit breaker par source, rate limiter Redis, dédup 2 niveaux, ETag conditionnel |
| RIS-09 | **Dérive de périmètre** (8 EPICs, freemium+API+mobile en v1) | Élevé | Élevé | Walking Skeleton strict (web only, pas de Stripe réel/mobile/API en Sprint 1) |
| RIS-10 | **Ressources équipe sous-dimensionnées** (hypothèse petite équipe) | Moyen | Inconnu | Confirmer taille équipe ; stack unique (Twig+Turbo, Flutter) pour limiter la charge |
| RIS-11 | **Qualité synthèse IA** (hallucination, sourcing non vérifiable) | Élevé | Moyen | Traçabilité obligatoire (lien source), condensé sourcé + vérifiable, pas de synthèse sans article rattaché |

---

## Opportunités

| ID | Opportunité | Levier |
|----|-------------|--------|
| OPP-01 | **Premier mover on-device crédible** | Aucun acteur mainstream ne peut promettre le privacy-first crédiblement (P-003, DMA/AI Act) |
| OPP-02 | **Fossé sourcing premium** | Indexer Economist/HBR/MIT TR là où Feedly échoue = avantage durable pour P-002 |
| OPP-03 | **Rituel matinal** = rétention forte | Le Daily Brief comme point d'entrée identitaire crée l'habitude et justifie le premium |
| OPP-04 | **API B2B** comme revenu récurrent différencié | Canal P-003 + intégrateurs, LTV élevée |
| OPP-05 | **Segment validé** | Fermeture d'Artifact + rachat de Particle = marché prouvé, place à prendre |
| OPP-06 | **Stack unique = time-to-market** | Twig+Turbo + Flutter + API Platform → petite équipe peut livrer vite |
| OPP-07 | **Ton éditorial** différenciant en français | Concurrence IA plus faible sur le marché francophone |

---

## Hypothèse centrale à valider (Walking Skeleton)

> **Les utilisateurs reviennent lire le Brief le lendemain sans qu'on les pousse.**

Si cette hypothèse tient → débloquer mobile, Stripe réel, notifications, API, on-device, personnalisation.
Si elle ne tient pas → le positionnement doit être revu avant tout investissement lourd.

Métrique de validation : **rétention J+1 sur le Daily Brief** en accès web non authentifié.

---

## Synthèse

- **11 risques** dont 6 à impact élevé — les plus critiques : dérive de périmètre (RIS-09), positionnement (RIS-01), coûts LLM (RIS-02), droits sources (RIS-03).
- **7 opportunités** — la différenciation défendable = privacy on-device (OPP-01) + sourcing premium (OPP-02) + rituel (OPP-03).
- Mitigation transverse dominante : **discipline de scope** (Walking Skeleton) + **traçabilité IA** + **provider EU**.
