# Diagramme C4 — Niveau 1 : Contexte Système

**Produit :** Briefly AI
**Date :** 2026-07-28
**Niveau C4 :** 1 — Context (vue macroscopique : acteurs, système central, systèmes externes)
**Décisions reflétées :** Symfony 8 + API Platform 4 + FrankenPHP, Flutter, Mistral EU + fallback OpenAI, Stripe Billing, auth OAuth2 + JWT EdDSA + Argon2id, FCM/APNs, RGPD, OWASP 2025.

---

## Diagramme

```mermaid
C4Context
  title Briefly AI — Contexte Système (C4 Niveau 1)

  Person(p001, "Thomas — P-001", "Cadre VP Eng, 38 ans. Daily Brief matinal sur desktop en 10 min. Premium.")
  Person(p002, "Priya — P-002", "Chercheuse strategie, 31 ans. Syntheses detaillees et export Markdown. Premium.")
  Person(p003, "Marc — P-003", "Dev freelance, 44 ans. Mobile privacy-first et API REST pour son dashboard. Premium.")
  Person(admin, "Administrateur", "Equipe interne. Gere les sources RSS, monitore le pipeline et les KPIs produit.")

  System(briefly, "Briefly AI", "Agregateur editorial intelligent: ingere les flux RSS-Atom, deduplique (SHA-256 URL + SimHash), genere un Daily Brief quotidien de 3 histoires curatees par IA. Syntheses a la demande (Free: 3/j, Premium: illimitees). Conforme RGPD, AI Act et OWASP 2025.")

  System_Ext(rss_sources, "Sources RSS et Atom", "Flux d'actualite de medias tiers: tech, finance, science, geopolitique. Publics ou sous accord contractuel.")
  System_Ext(gnews, "Google News RSS", "Sous-canaux thematiques: A la une, Technologie, Science. RSS public consomme via FeedIo.")
  System_Ext(mistral, "Mistral EU", "LLM principal heberge dans l'UE. DPA en place. Synthese, classification et clustering. Aucun identifiant utilisateur transmis dans les prompts.")
  System_Ext(openai_fb, "OpenAI", "Fallback LLM. Circuit breaker: active automatiquement si Mistral depasse 3 erreurs consecutives en 5 min. RTO inferieur a 30 s.")
  System_Ext(stripe, "Stripe Billing", "Abonnements Premium 12 EUR/mois et 99 EUR/an. Customer Portal. Stripe Tax TVA EU. Webhooks subscription.updated et payment_failed.")
  System_Ext(fcm_apns, "FCM et APNs", "Livraison des notifications push Android via FCM et iOS via APNs. Limite stricte: 1 notification par jour par utilisateur.")
  System_Ext(email_svc, "Fournisseur Email", "Emails transactionnels via Postmark ou Sendgrid: confirmation inscription, facture, reinitialisation mot de passe.")
  System_Ext(oauth_idp, "Google et GitHub OAuth2", "Fournisseurs d'identite pour auth deleguee. Authorization Code Flow avec PKCE. Pas de mot de passe tiers stocke.")

  Rel(p001, briefly, "Lit le Daily Brief sur desktop", "HTTPS — Session HttpOnly SameSite=Strict")
  Rel(p002, briefly, "Syntheses detaillees et export Markdown", "HTTPS — Session HttpOnly")
  Rel(p003, briefly, "Daily Brief mobile et API REST", "HTTPS — JWT EdDSA Bearer + cle API Premium")
  Rel(admin, briefly, "Gere les sources et monitore les KPIs", "HTTPS — Session HttpOnly")

  Rel(briefly, rss_sources, "Ingere les flux via FeedIo avec ETag conditionnel", "HTTPS — Pull toutes les 15 min par source")
  Rel(briefly, gnews, "Ingere les sous-canaux thematiques", "HTTPS — RSS public")
  Rel(briefly, mistral, "Genere syntheses, classifications et clusters HDBSCAN", "HTTPS REST — Provider EU RGPD conforme")
  Rel(briefly, openai_fb, "Fallback syntheses si Mistral indisponible", "HTTPS REST — Active par circuit breaker")
  Rel(briefly, stripe, "Cree sessions Checkout et liens Customer Portal", "HTTPS REST")
  Rel(stripe, briefly, "Webhooks entrants: subscription.updated, payment_failed", "HTTPS POST — Signature HMAC SHA-256 verifiee")
  Rel(briefly, fcm_apns, "Envoie la notification quotidienne du Daily Brief", "HTTPS REST — FCM et APNs, 1 notif/jour max")
  Rel(briefly, email_svc, "Envoie les emails transactionnels", "HTTPS API — Postmark ou Sendgrid")
  Rel(briefly, oauth_idp, "Initie le flux d'auth deleguee", "HTTPS — Authorization Code PKCE")

  UpdateLayoutConfig($c4ShapeInRow="4", $c4BoundaryInRow="1")
```

---

## Légende et notes d'architecture

### Acteurs

| Acteur | Profil | Canal principal | Plan cible | KPI de rétention |
|--------|--------|-----------------|------------|-----------------|
| **P-001 Thomas** | Cadre VP Engineering, 38 ans | Web desktop (7h30–8h, quotidien) | Premium | DAU/WAU ≥ 71 % (5j/7) |
| **P-002 Priya** | Chercheuse stratégie, 31 ans | Web desktop (sessions profondes, vendredi) | Premium | Feature export ≥ 1/semaine |
| **P-003 Marc** | Développeur freelance, 44 ans | Mobile Android (GrapheneOS) + API REST | Premium | Appels API ≥ 5/semaine |
| **Administrateur** | Équipe interne | Interface admin web | — | — |

### Briefly AI — périmètre du système central

La boîte "Briefly AI" encapsule l'intégralité de la plateforme à ce niveau. Elle recouvre :

- **Pipeline d'ingestion** : collecte RSS/Atom toutes les 15 min, déduplication SHA-256 URL + SimHash titre (fenêtre ±2h).
- **Moteur IA** : Mistral EU (primaire) avec fallback OpenAI, cache Redis 24 h, quotas Free/Premium (3 synthèses/jour Free).
- **Daily Brief** : sélection algorithmique de 3 histoires majeures, clustering HDBSCAN, génération batch 5h UTC, rendu SSR Twig.
- **Comptes et facturation** : OAuth2 délégué, JWT EdDSA mobile, session HttpOnly desktop, Stripe Billing, RGPD complet.
- **API REST** : API Platform 4, documentée OpenAPI 3.1, réservée aux utilisateurs Premium (Bearer token, 100 req/h).

Le détail des conteneurs internes est développé dans le diagramme Niveau 2 (c4-container.md).

### Systèmes externes

| Système | Rôle dans Briefly AI | Contrainte clé |
|---------|---------------------|----------------|
| **Sources RSS / Atom** | Alimentation principale du pipeline d'ingestion | CGU RSS à respecter ; contractualisation sources premium obligatoire (jurisprudence NYT vs OpenAI) |
| **Google News RSS** | Sous-canaux thématiques supplémentaires | Pas d'API officielle — flux RSS public uniquement |
| **Mistral EU** | LLM primaire : synthèse, classification, embeddings clustering | DPA en place, hébergement UE, aucun identifiant utilisateur dans les prompts (RGPD + AI Act) |
| **OpenAI** | LLM fallback automatique | Activé uniquement par circuit breaker (3 erreurs / 5 min), transparent pour l'utilisateur |
| **Stripe Billing** | Monétisation : abonnements, Customer Portal, TVA EU | PCI DSS entièrement délégué — pas de stockage carte en propre |
| **FCM / APNs** | Notifications push mobiles | Règle stricte B5 : 1 notification/jour max par utilisateur |
| **Fournisseur Email** | Emails transactionnels (inscription, facture, reset) | DPA requise, hébergement EU préféré |
| **Google / GitHub OAuth2** | Authentification déléguée (alternative à email/mdp) | PKCE obligatoire, aucun mot de passe tiers stocké |

### Principes architecturaux visibles à ce niveau

| Principe | Manifestation dans le diagramme |
|----------|--------------------------------|
| **Confiance zéro LLM** | Aucun identifiant utilisateur transmis à Mistral ou OpenAI — prompts anonymisés |
| **Résilience LLM** | Circuit breaker Mistral → OpenAI transparent, RTO < 30 s |
| **Monétisation déléguée** | Stripe gère le cycle de vie abonnement complet — PCI DSS délégué |
| **Signal vs bruit** | 1 notification push/jour max (contrainte B5 — positionnement Briefly AI) |
| **SEO natif** | Daily Brief en page publique SSR (Twig) — indexable sans JavaScript |
| **Privacy by design** | Mistral EU + prompts sans données personnelles + mode on-device opt-in (P-003) |
| **Webhooks entrants Stripe** | Relation bidirectionnelle : Briefly envoie vers Stripe (REST) ET reçoit de Stripe (webhooks HMAC) |

### Flux principaux résumés

```
P-001/P-002 (desktop)
  → HTTPS Session HttpOnly
  → Briefly AI (SSR Twig + API Platform)
  → PostgreSQL + Redis

P-003 (mobile)
  → HTTPS JWT EdDSA
  → Briefly AI (API Platform REST)
  → [on-device Phi-3 Mini opt-in, aucun flux externe]

Sources RSS/Atom
  → FeedIo ETag conditionnel (15 min)
  → Briefly AI (Workers Messenger)
  → Dedup SHA-256 + SimHash
  → PostgreSQL

Daily Brief (batch 5h UTC)
  → Briefly AI (Scheduler + Workers)
  → Clustering HDBSCAN (Mistral EU)
  → Sélection 3 histoires
  → Notification push FCM/APNs (1/jour max)

Synthèse à la demande
  → Briefly AI
  → Vérif quota Redis (Free: 3/j)
  → Mistral EU [ou OpenAI fallback]
  → Cache Redis 24h
```
