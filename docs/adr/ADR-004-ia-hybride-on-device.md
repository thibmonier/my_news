# ADR-004 — IA hybride : Mistral EU (serveur) + Phi-3 Mini on-device (opt-in)

**Statut :** Accepté — 2026-07-28
**Auteur :** Tech Lead (CSM)
**Décideurs :** Tech Lead, Product Owner
**Références :** PRD §4.2 (FR-011 à FR-020), PRD §5.3 (NFR-018/019), constraints.md §9.4, risks-opportunities.md (RIS-02, RIS-04, RIS-07, OPP-01)

---

## Contexte

Briefly AI repose sur la génération de synthèses IA comme feature cœur (EPIC-002). Trois niveaux de synthèse sont requis :

- **Concis** (2-3 phrases) — push quotidien et lecture rapide (P-001 Thomas)
- **Détaillé** (150-300 mots) — session de travail (P-002 Priya)
- **Narratif** (500+ mots, Premium) — veille structurée + tendances 7 jours

À ces niveaux s'ajoutent deux traitements batch :

- **Classification thématique** des articles ingérés (tech, finance, science…)
- **Clustering sémantique** (HDBSCAN) pour regrouper les histoires par événement

Les tensions à résoudre sont les suivantes :

1. **Coût LLM** : la synthèse à la demande sur un plan Free (3/jour, potentiellement 10 000 utilisateurs) peut engendrer des coûts incontrôlés (RIS-02).
2. **Conformité RGPD / AI Act** : tout envoi d'article à un LLM tiers doit respecter l'hébergement EU, l'absence de données personnelles dans les prompts, et la transparence (badge "BRIEFLY AI:").
3. **Privacy utilisateur** : P-003 (Marc) exige un traitement souverain pour des lectures sensibles — sans donnée sortante vers un tiers (RIS-07, OPP-01).
4. **Qualité et latence** : le résumé concis doit arriver en moins de 8 s (NFR-003). Le mode on-device tolère jusqu'à 15 s (NFR-004) car l'utilisateur a activé ce choix délibérément.
5. **Capacité on-device** : les modèles quantisés 4-bit (Phi-3 Mini ~2 Go, Gemma 2B ~1,5 Go) tiennent sur iPhone 15+/Pixel 8+, mais ne peuvent pas traiter le clustering (besoin d'embeddings cross-articles) ni la synthèse narrative (contexte long, qualité insuffisante).

---

## Décision

**Architecture hybride à deux modes, opt-in strict pour le traitement local :**

### Mode par défaut — Serveur (Mistral EU)

| Usage | Modèle | Déclenchement |
|-------|--------|---------------|
| Classification thématique batch | Embedding léger + classifieur | Après ingestion article |
| Clustering stories (HDBSCAN) | Embeddings Mistral | Batch quotidien 5h UTC |
| Synthèse concise pré-générée | Mistral Small | Batch quotidien (top clusters) |
| Synthèse concise à la demande | Mistral Small | Requête utilisateur |
| Synthèse détaillée | Mistral Medium | Requête utilisateur |
| Synthèse narrative (Premium) | Mistral Large | Requête utilisateur |

- Provider principal : **Mistral AI** (infrastructure EU, DPA signé, conforme RGPD — NFR-018).
- Fallback automatique vers **OpenAI** si Mistral déclenche 3 erreurs consécutives en 5 minutes (circuit breaker — FR-017, NFR-029). Le basculement est transparent pour l'utilisateur.
- Cache Redis 24h par couple `(article_id, niveau)` — évite les doubles appels LLM sur le même contenu (FR-013, NFR-010 hit rate ≥ 80 %).
- **Aucun identifiant utilisateur ni donnée personnelle** n'est inclus dans les prompts : seul le contenu éditorial anonymisé est transmis (FR-018, NFR-018).

### Mode on-device — Phi-3 Mini (opt-in explicite)

- Disponible uniquement pour la **synthèse concise** (2-3 phrases), sur mobile Flutter.
- Modèle : **Phi-3 Mini 4-bit quantisé** (Microsoft, licence MIT) ou Gemma 2B selon disponibilité Play/App Store.
- Téléchargement du modèle déclenché par l'utilisateur après consentement explicite ("Activer le traitement sur votre appareil — votre contenu ne quittera pas votre téléphone").
- Indicateur visuel "On-device" en vert affiché pendant le traitement (P-003, AI Act transparence).
- Ce mode est **jamais le défaut** — il ne se substitue au serveur que sur action utilisateur délibérée.
- Désactivation du mode : la synthèse revient automatiquement au serveur sans friction UX.

### Règles transversales

- Toute synthèse IA est identifiée par : badge émeraude `#10B981`, préfixe "BRIEFLY AI:", lien "OUVRIR L'ORIGINAL" vers la source primaire (FR-012, NFR-019, AI Act).
- La génération proactive (batch) des synthèses des top-clusters avant la demande utilisateur réduit la latence perçue à moins de 1 s (cache chaud).

---

## Alternatives considérées

### A1 — Full serveur uniquement (OpenAI ou Mistral, sans on-device)

**Pour :**
- Architecture uniforme, une seule couche LLM à maintenir.
- Qualité de synthèse supérieure (modèles plus larges).
- Pas de gestion du cycle de vie des modèles on-device (téléchargement, mises à jour, compatibilité matérielle).

**Contre :**
- Incompatible avec le besoin privacy de P-003 (Marc) — perd un segment stratégique haute-LTV (OPP-01).
- Tout traitement = données transmises à un tiers même pour du contenu sensible client.
- Fragilité face au durcissement réglementaire (AI Act, transferts hors UE potentiels si fallback OpenAI US sans DPA).

**Rejetée :** L'opportunité "Premier mover on-device crédible" (OPP-01) est un différenciateur durable. P-003 en est le validateur critique.

---

### A2 — Full on-device (toutes synthèses locales, aucun LLM serveur)

**Pour :**
- Privacy maximale, zéro donnée sortante.
- Coût LLM nul en régime de croisière.
- Argument marketing fort pour le segment privacy-first.

**Contre :**
- Les synthèses détaillées et narratives (500+ mots) dépassent les capacités des modèles quantisés 4-bit disponibles en 2026 en termes de qualité et de fenêtre de contexte.
- Le clustering sémantique multi-articles (HDBSCAN) est impossible on-device (besoin d'embeddings cross-documents, mémoire insuffisante).
- La pré-génération batch (Daily Brief 5h UTC pour 10 000 utilisateurs) nécessiterait que tous les appareils soient allumés et en réseau à la même heure — impraticable.
- Latence sur appareils d'entrée de gamme (Pixel 6a, iPhone 13 mini) : jusqu'à 45 s pour un résumé détaillé — inacceptable (NFR-004 : ≤ 15 s).
- Impossible pour P-002 (Priya, ThinkPad sans GPU dédié pour inférence rapide).

**Rejetée :** Techniquement hors de portée pour les cas d'usage Détaillé et Narratif, et opérationnellement irréaliste pour le Daily Brief batch.

---

### A3 — Hybride avec modèle on-device par défaut (opt-out)

**Pour :**
- Privacy "by default" conforme à la doctrine RGPD.
- Meilleur message marketing pour P-003.

**Contre :**
- Téléchargement forcé du modèle (~1,5-2 Go) à l'installation : taux de désinstallation élevé (expérience App Store / Play Store négative).
- Incompatibilité sur appareils anciens (< iPhone 13, < Pixel 6) — fragmente l'audience.
- P-001 (Thomas) et P-002 (Priya) utilisent le web desktop : on-device n'y est pas pertinent, l'opt-out serait confus.
- Complexité UX : l'utilisateur doit comprendre pourquoi son appli télécharge 2 Go sans l'avoir demandé.

**Rejetée :** L'opt-in explicite est supérieur UX et conforme à l'esprit RGPD ("Privacy by design" = choix éclairé, pas contrainte cachée). La contrainte matérielle rend le mode par défaut risqué.

---

### A4 — Hybride avec provider US (OpenAI GPT-4o) en principal

**Pour :**
- Qualité de synthèse GPT-4o supérieure à Mistral Medium (notamment pour le narratif).
- SDK mature, large communauté.

**Contre :**
- Hébergement US → problème RGPD Art. 46 (transferts hors UE sans clause standard ou décision d'adéquation pleinement stabilisée).
- DPA avec OpenAI disponible mais hébergement EU non garanti sur tous les tiers.
- Dépendance à un fournisseur non-EU pour la feature cœur — risque réglementaire et géopolitique.
- NFR-018 impose explicitement Mistral EU comme provider par défaut.

**Rejetée :** Contrainte réglementaire R3 et NFR-018 imposent un provider EU par défaut. OpenAI reste acceptable en fallback (DPA + clause standard suffit en position secondaire, temps de traitement limité).

---

## Conséquences

### Positives

- **Différenciation privacy** crédible et vérifiable (OPP-01) : Briefly AI est le seul agrégateur offrant un traitement souverain optionnel, auditables par P-003.
- **Conformité RGPD et AI Act** native : provider EU principal, aucun ID utilisateur dans les prompts, transparence obligatoire du badge (NFR-018, NFR-019).
- **Maîtrise des coûts LLM** : cache Redis 24h + batch pré-généré + quota Free → hit rate cible 80 % réduit les appels facturés de 80 % (RIS-02).
- **Résilience** : le circuit breaker Mistral → OpenAI garantit une continuité de service (NFR-029, RTO < 30 s).
- **Flexibilité** : les niveaux de synthèse (concis/détaillé/narratif) peuvent utiliser des modèles différents et être optimisés indépendamment.

### Négatives / Points d'attention

- **Complexité opérationnelle** : deux pipelines LLM (serveur + on-device) à maintenir, avec des cycles de mise à jour de modèles différents (Mistral versioning vs Phi-3 Mini releases).
- **Cycle de vie on-device** : les mises à jour du modèle Phi-3 Mini nécessitent une distribution via un CDN ou les stores — processus plus lent que le serveur.
- **Fragmentation appareils** : Phi-3 Mini exige iOS 16+/Android 12+ avec 4 Go RAM minimum — environ 15 % des appareils Android actuels sont exclus (à documenter dans les prérequis app store).
- **Latence on-device variable** : 8-15 s selon la génération de SoC — l'indicateur de progression est obligatoire pour ne pas perdre l'utilisateur (NFR-004).
- **Qualité on-device inférieure** : Phi-3 Mini 4-bit produit des synthèses concises correctes mais ne rival pas Mistral Medium sur la densité sémantique. L'UX doit gérer les attentes (label "Synthèse rapide" vs "Synthèse approfondie").
- **Fallback OpenAI** : en cas d'activation prolongée du circuit breaker, le coût augmente (tarif OpenAI > Mistral pour volume équivalent). Monitoring coût/jour obligatoire.

---

## Implémentation — points d'architecture

- Couche d'abstraction `SynthesisProvider` (interface hexagonale) : `MistralProvider`, `OpenAIProvider`, `OnDeviceProvider`. Le service applicatif ne connaît pas le provider concret.
- Circuit breaker implémenté avec `symfony/circuit-breaker` ou via Messenger middleware.
- Métriques par provider dans le dashboard admin (NFR : latence, coût/requête, taux de cache hit).
- Modèle Phi-3 Mini distribué via CDN privé ou assets Flutter — décision infrastructure Sprint 4.

---

*ADR validé en Sprint Planning Sprint 1 — 2026-07-28*
