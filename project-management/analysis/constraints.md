# Contraintes Identifiées — Briefly AI

> Phase 1 (Analyse). Contraintes techniques, business, réglementaires + décisions tranchées (brief §9).

---

## Contraintes techniques (imposées)

| # | Contrainte | Impact |
|---|-----------|--------|
| T1 | Backend **Symfony 8 + API Platform 4** | Non négociable — architecture hexagonale + DDD |
| T2 | Serveur applicatif **FrankenPHP** | Worker mode, config CSP/HSTS au niveau serveur |
| T3 | **PostgreSQL** | Base relationnelle, index dédup, JSONB pour flex |
| T4 | **Redis** | Cache + sessions + files Messenger + quotas + rate limit |
| T5 | **Docker** dev + déploiement | Conteneurisation obligatoire, compose multi-services |
| T6 | Mobile **Android + iOS** obligatoires | Cache offline, push natif, biométrie |
| T7 | API unique consommée par mobile + (éventuel) frontend headless | Contrat API stable, versionné |
| T8 | **i18n dès le départ** (maquettes FR+EN) | Aucun texte en dur, architecture i18n Sprint 1 |
| T9 | Traçabilité IA : toute production IA identifiable + rattachée à sa source | Lien « OUVRIR L'ORIGINAL » systématique, badge émeraude |

---

## Contraintes business

| # | Contrainte | Impact |
|---|-----------|--------|
| B1 | Modèle **freemium/premium** dès la v1 (maquetté) | Stripe + gestion quotas dans le MVP élargi |
| B2 | Positionnement « fort signal, faible bruit », ton éditorial | ≥1 source non grand public dès le MVP, sinon positionnement invalide |
| B3 | Sources premium cibles sous paywall/droits (Economist, HBR, MIT TR) | Indexation **contractuelle** (jurisprudence NYT vs OpenAI), pas de scraping |
| B4 | API publique évoquée (pied de page desktop) | Vecteur B2B + P-003, mais rate limit obligatoire (coûts GPU) |
| B5 | Notification = ennemi du positionnement | 1 notif/jour max (Daily Brief), pas de breaking news en v1 |

---

## Contraintes réglementaires

| # | Contrainte | Impact |
|---|-----------|--------|
| R1 | **RGPD** (utilisateurs EU) | Consentement explicite, droit à l'oubli, portabilité, DPO si grande échelle |
| R2 | **AI Act / DMA** | Transparence traitement IA, mode privacy crédible |
| R3 | Données transmises aux LLM | Pas d'identifiant utilisateur dans les prompts, provider EU (Mistral) par défaut |
| R4 | Droits d'auteur sur contenus indexés | Contractualisation des sources premium, respect des CGU RSS |
| R5 | TVA EU sur abonnements | Stripe Tax dès le départ |
| R6 | Paiement | Stripe = PCI DSS délégué (pas de stockage carte en propre) |

---

## Contraintes de ressources

> **À confirmer par le porteur** — non précisé dans le brief. Hypothèses de travail :

| # | Hypothèse | À valider |
|---|-----------|-----------|
| Res1 | Petite équipe (justifie stack unique Twig+Turbo + Flutter) | Taille réelle équipe ? |
| Res2 | Pas de DevOps dédié → Docker Compose avant K8s | Compétence infra dispo ? |
| Res3 | Budget LLM à cadrer (batch nocturne pour maîtriser le coût) | Enveloppe mensuelle IA ? |
| Res4 | Délai MVP non fixé | Date cible v1 ? |

---

## Décisions tranchées — brief §9

| # | Décision | Arbitrage retenu | Statut |
|---|----------|------------------|--------|
| §9.1 | Frontend desktop | **Symfony/Twig + Turbo** (SEO natif, stack unique) | ✅ Reco — à confirmer porteur |
| §9.2 | Langue de référence + i18n | **Anglais v1 + français localisé simultané**, archi i18n Sprint 1 | ✅ Reco |
| §9.3 | Techno mobile | **Flutter** (codebase unique) | ✅ Reco |
| §9.4 | Étendue traitement local | **Hybride** : concis on-device opt-in, reste serveur (Mistral EU) | ✅ Reco |
| §9.5 | Sources v1 | **RSS public + Google News**, +2-3 sources premium Sprint 2 (≥1 non grand public dès MVP) | ✅ Reco |
| §9.6 | Modèle tarifaire | Free = brief complet + 3 synthèses/jour ; **Premium 12€/mois, 99€/an** (illimité, sources premium, historique 90j, export, API) | ✅ Tranché porteur (2026-07-28) |
| §9.7 | API publique | **v1 limitée** : `/daily-brief` (lecture) + `/synthesize` (rate limité), token auth, payante | ✅ Reco |
| §9.8 | Notifications temps réel | **1 notif/jour max** (Daily Brief). Breaking news hors scope v1 | ✅ Reco |
| §9.9 | Écran « sauvegardés » desktop absent | **À produire** en phase Conception (design) | 🔲 Todo design |

> ✅ = recommandation d'analyse, confirmation légère attendue. ⚠️ = décision business exclusive au porteur (prix). 🔲 = production requise phase suivante.

---

## Points ouverts nécessitant le porteur

1. **Prix premium & limites free** (§9.6) — seule décision purement business.
2. **Ressources équipe & budget IA** (Res1-4) — dimensionne la vélocité et l'arbitrage batch/temps réel.
3. **Stratégie sources premium** (B3/§9.5) — partenariats à initier tôt (cycle long).
