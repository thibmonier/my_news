# PRD — Briefly AI

**Version:** 1.0.0
**Date:** 2026-07-28
**Statut:** Draft — En attente de validation stakeholders
**Product Owner:** CSPO

---

## Table des matières

1. [Résumé exécutif](#1-résumé-exécutif)
2. [Problème résolu et moteurs business](#2-problème-résolu-et-moteurs-business)
3. [Personas (résumés)](#3-personas-résumés)
4. [Exigences fonctionnelles](#4-exigences-fonctionnelles)
5. [Exigences non fonctionnelles](#5-exigences-non-fonctionnelles)
6. [Modèle économique](#6-modèle-économique)
7. [Métriques de succès et KPIs](#7-métriques-de-succès-et-kpis)
8. [Périmètre v1 et hors-scope](#8-périmètre-v1-et-hors-scope)
9. [Hypothèse centrale](#9-hypothèse-centrale)
10. [Risques majeurs](#10-risques-majeurs)

---

## 1. Résumé exécutif

### Vision

Briefly AI transforme un flux d'actualités chaotique en un **Daily Brief quotidien à fort signal** : 3 histoires majeures numérotées (01/02/03), horodatées "LAST UPDATED", accompagnées d'un CTA "Lire le brief complet". La proposition de valeur repose sur un postulat simple : **fort signal, faible bruit, ton éditorial**.

L'accent émeraude `#10B981` est réservé exclusivement aux contenus produits par l'IA. Toute production IA est préfixée **"BRIEFLY AI:"** et rattachée à sa source originale via un lien "OUVRIR L'ORIGINAL".

### Positionnement

| Dimension | Briefly AI | Agrégateurs classiques |
|-----------|------------|------------------------|
| Signal | 3 histoires/jour (curatées par IA) | Flux illimité non filtré |
| Ton | Éditorial, synthétique | Neutre ou absent |
| Traçabilité IA | Badge vert + lien source obligatoire | Opaque |
| Privacy | On-device opt-in, RGPD, sans tracker | Variable |
| Audience cible | Cadres, chercheurs, développeurs | Grand public |

Briefly AI ne concurrence pas les médias d'origine. Il **amplifie leur découvrabilité** tout en restant la destination quotidienne de synthèse.

---

## 2. Problème résolu et moteurs business

### Le problème: surcharge attentionnelle

Les professionnels de l'information (cadres, chercheurs, développeurs) font face à trois défis structurels :

1. **Volume**: un cadre reçoit en moyenne 120 newsletters, flux RSS et alertes Slack par jour. La sélection du signal pertinent prend entre 30 et 60 minutes.
2. **Redondance**: les mêmes événements sont couverts 8 à 12 fois par des sources différentes avec des angles quasi-identiques.
3. **Décontextualisation**: les alertes breaking news arrivent sans mise en perspective, forçant une deuxième lecture de fond systématique.

### Jobs-To-Be-Done

- "Quand je démarre ma journée, je veux comprendre ce qui compte en 10 minutes, pas en 1 heure."
- "Quand je prépare une réunion stratégique, je veux une synthèse vérifiable avec sources, pas une opinion."
- "Quand je consulte l'actualité sur mon téléphone, je veux ma vie privée protégée sans compromis UX."

### Moteurs business

| Moteur | Mécanisme |
|--------|-----------|
| **Conversion Free→Premium** | Paywall à la 4e synthèse IA/jour (quota Free: 3) |
| **Rétention** | Daily Brief quotidien crée une habitude de retour (J+1, J+7, J+30) |
| **B2B latent** | Accès API (Premium) ouvre un segment équipes/intégrations en v2 |
| **Network effect** | Partage de briefs via lien public génère des inscriptions organiques |

---

## 3. Personas (résumés)

> Fiches détaillées: `project-management/personas.md`

### P-001 — Thomas, Cadre dirigeant tech, 38 ans

Couvrir son secteur en moins de 15 min/jour, capturer des signaux faibles avant ses concurrents, partager des insights crédibles avec son réseau. Frustré par la redondance et la superficialité des flux actuels.

**Usage principal:** Daily Brief matinal (web desktop, 7h30-8h00) + partage ponctuel de synthèses dans Slack.

### P-002 — Priya, Chercheuse stratégie, 31 ans

Synthèse transversale sur sources premium (The Economist, HBR, MIT Tech Review), traçabilité sourcée impeccable, export Markdown, indexation personnalisée, veille hebdomadaire structurée.

**Usage principal:** Session de travail profonde (web desktop) + export vers Notion/Obsidian.

### P-003 — Marc, Développeur indépendant, 44 ans, privacy-first

Information sans tracker, traitement on-device optionnel pour lectures sensibles, accès API pour intégrer les briefs à son dashboard personnel.

**Usage principal:** Application mobile (Android) + API REST pour automatisation.

---

## 4. Exigences fonctionnelles

### 4.1 Daily Brief

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-001 | Le système génère automatiquement un Daily Brief quotidien composé de 3 histoires majeures numérotées (01/02/03), sélectionnées par l'IA parmi les articles ingérés dans les 24 dernières heures. | Must |
| FR-002 | Chaque histoire du Daily Brief affiche un horodatage "LAST UPDATED" indiquant la dernière mise à jour de la sélection. | Must |
| FR-003 | Le Daily Brief inclut un CTA "Lire le brief complet" redirigeant vers la page détaillée de chaque histoire. | Must |
| FR-004 | Le Daily Brief est accessible sans authentification (page publique, indexée SEO) pour les utilisateurs anonymes. | Must |
| FR-005 | Le système regenere le Daily Brief toutes les heures entre 5h00 et 22h00 (heure Paris, Europe/Paris) si de nouveaux articles ont été ingérés. | Must |
| FR-006 | L'utilisateur authentifié peut personnaliser les thématiques du Daily Brief (sélection de domaines: tech, finance, science, politique, etc.) parmi un catalogue prédéfini. | Should |
| FR-007 | Le Daily Brief est partageable via un lien public unique et permanent (slug de date: `/brief/2026-07-28`). | Should |
| FR-008 | L'utilisateur peut marquer une histoire du Daily Brief comme "Lu" pour ne plus la voir dans les briefs suivants. | Could |
| FR-009 | Le Daily Brief envoie une notification push (mobile, opt-in) une fois par jour maximum, à l'heure choisie par l'utilisateur (défaut: 7h30). | Should |
| FR-010 | La page du Daily Brief est rendue côté serveur (SSR via Symfony/Twig + Turbo) pour garantir le SEO et le temps d'affichage initial. | Must |

### 4.2 Synthèse IA

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-011 | L'utilisateur peut demander une synthèse IA pour tout article ou groupe d'articles (cluster). La synthèse est générée par Mistral (serveur EU, RGPD-conforme). | Must |
| FR-012 | Toute synthèse IA est visuellement identifiée par l'accent émeraude `#10B981`, le préfixe "BRIEFLY AI:" et un lien "OUVRIR L'ORIGINAL" vers la source. | Must |
| FR-013 | La synthèse IA est mise en cache Redis pendant 24 heures par couple (article, niveau). Un même article ne déclenche pas deux appels LLM dans la fenêtre de cache. | Must |
| FR-014 | L'utilisateur Free dispose de 3 synthèses IA gratuites par jour (compteur remis à zéro à minuit UTC). À la 4e demande, un paywall contextuel est affiché. | Must |
| FR-015 | L'utilisateur Premium dispose de synthèses IA illimitées par jour. | Must |
| FR-016 | L'utilisateur peut choisir le niveau de synthèse: "Concis" (3 phrases, on-device opt-in Phi-3 Mini / Gemma 2B) ou "Détaillé" (narratif complet, serveur Mistral). | Should |
| FR-017 | En cas d'indisponibilité de Mistral, le système bascule automatiquement vers le fallback OpenAI sans que l'utilisateur ne soit impacté (circuit breaker transparent). | Must |
| FR-018 | Aucun identifiant utilisateur ou donnée personnelle n'est transmis aux LLMs (serveur ou on-device). Les prompts ne contiennent que le contenu éditorial anonymisé. | Must |
| FR-019 | Le clustering d'articles (HDBSCAN) regroupe les articles similaires en "histoires" avant classification. L'utilisateur voit le nombre d'articles dans un cluster. | Should |
| FR-020 | La synthèse narrative (Premium) propose une analyse de tendance sur 7 jours pour les clusters récurrents. | Could |

### 4.3 Sources et ingestion

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-021 | Le système ingère automatiquement les flux RSS/Atom des sources configurées via Symfony Scheduler (toutes les 15 minutes par source, configurable). | Must |
| FR-022 | La déduplication des articles repose sur deux mécanismes combinés: hash SHA-256 de l'URL canonique (index UNIQUE en base) + SimHash du titre avec fenêtre temporelle ±2 heures. | Must |
| FR-023 | Chaque source dispose d'un rate limiter Redis et d'un circuit breaker indépendant. Une source en erreur est suspendue temporairement sans impacter les autres. | Must |
| FR-024 | L'administrateur peut ajouter, modifier ou désactiver une source via une interface d'administration (Symfony EasyAdmin ou interface dédiée). | Must |
| FR-025 | L'utilisateur Premium peut ajouter des sources RSS personnalisées (jusqu'à 10 sources custom). | Should |
| FR-026 | Le système catégorise automatiquement chaque article ingéré par domaine (tech, finance, science, géopolitique, etc.) via classification Mistral. | Must |
| FR-027 | Les articles sont conservés en base 90 jours, puis archivés (blob storage froid) ou supprimés selon la politique RGPD. | Must |
| FR-028 | Le système expose un endpoint de santé des sources permettant à l'administrateur de monitorer les taux d'erreur par source. | Should |

### 4.4 Comptes et Premium

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-029 | L'utilisateur peut s'inscrire et se connecter via OAuth2 (Google, GitHub) ou email/mot de passe (Argon2id, 128 MiB, t=3, p=1). | Must |
| FR-030 | L'authentification mobile repose sur JWT (access token 15 min, refresh token 7 jours) stocké dans le secure storage natif (flutter_secure_storage). | Must |
| FR-031 | L'authentification desktop repose sur une session HttpOnly (cookie sécurisé, SameSite=Strict) sans token JWT exposé au JavaScript. | Must |
| FR-032 | La biométrie mobile (local_auth) déverrouille le refresh token local sans ré-authentification serveur. | Should |
| FR-033 | Le paywall Premium est proposé en deux formules: mensuel (12 €/mois) et annuel (99 €/an, soit 17 % d'économie). | Must |
| FR-034 | Le paiement et la gestion d'abonnement sont assurés par Stripe Billing + Customer Portal. Les webhooks Stripe sont traités via Symfony Messenger (workers async). | Must |
| FR-035 | L'utilisateur Premium accède au Customer Portal Stripe directement depuis son espace compte (lien "Gérer mon abonnement"). | Must |
| FR-036 | Le rate limit de connexion est de 5 tentatives par 15 minutes par IP et par compte (Redis). Au-delà, un CAPTCHA est requis. | Must |
| FR-037 | L'utilisateur peut supprimer son compte (droit à l'oubli RGPD): suppression en cascade de toutes les données personnelles dans un délai de 30 jours. | Must |
| FR-038 | L'utilisateur peut exporter l'intégralité de ses données personnelles (format JSON structuré, portabilité RGPD) depuis son espace compte. | Must |

### 4.5 Mobile (Flutter)

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-039 | L'application mobile (Android + iOS) repose sur Flutter et partage la logique métier (state management BLoC/Riverpod). | Must |
| FR-040 | L'application mobile supporte le mode hors-ligne: les briefs et articles lus récemment sont cachés localement (Hive ou Isar). | Must |
| FR-041 | Les notifications push quotidiennes (Daily Brief) sont envoyées via FCM (Android) et APNs (iOS), gérées par Notifee côté Flutter. | Should |
| FR-042 | L'utilisateur peut activer le traitement on-device (Phi-3 Mini ou Gemma 2B) pour la synthèse concise. Cette option est clairement présentée comme opt-in avec explication de ses implications. | Should |
| FR-043 | L'interface mobile respecte les guidelines Material Design 3 (Android) et Cupertino (iOS) avec un système de design unifié. | Must |
| FR-044 | L'application mobile est distribuée sur Google Play Store et Apple App Store. | Must |

### 4.6 API publique (Premium)

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-045 | L'accès à l'API REST (API Platform 4) est réservé aux utilisateurs Premium via clé API (Bearer token). | Must |
| FR-046 | L'API expose les endpoints: `GET /api/briefs` (liste des briefs), `GET /api/briefs/{date}` (brief du jour), `GET /api/articles` (flux paginé), `GET /api/articles/{id}` (article + synthèse IA). | Must |
| FR-047 | L'API est documentée OpenAPI 3.1 (auto-générée par API Platform), accessible sur `/api/docs`. | Must |
| FR-048 | Le rate limit API est de 100 requêtes/heure par clé API (Premium). | Should |
| FR-049 | L'API supporte la pagination par curseur (cursor-based pagination) pour les endpoints de liste. | Should |
| FR-050 | L'API expose un webhook optionnel (Premium): l'utilisateur configure une URL de callback appelée à chaque nouveau Daily Brief généré. | Could |

### 4.7 Privacy et RGPD

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-051 | Le consentement explicite (RGPD) est recueilli à l'inscription avec granularité: analytique, marketing, notifications. Il est modifiable à tout moment. | Must |
| FR-052 | Aucun cookie tiers de tracking n'est déposé sans consentement (CMP conforme ePrivacy). | Must |
| FR-053 | Les données personnelles (email, préférences, historique de lecture) ne sont jamais partagées avec des tiers hors sous-traitants listés dans la politique de confidentialité. | Must |
| FR-054 | La politique de confidentialité et les CGU sont accessibles depuis toutes les pages (footer web, écran "À propos" mobile). | Must |
| FR-055 | Les logs applicatifs ne contiennent aucun identifiant utilisateur direct (pseudonymisation, UUIDs non séquentiels). | Must |
| FR-056 | La DPA (Data Processing Agreement) avec Mistral (EU) et Stripe est maintenue à jour. | Must |

### 4.8 Analytics produit

| ID | Exigence | Priorité MoSCoW |
|----|----------|-----------------|
| FR-057 | Le système collecte des métriques produit anonymisées: DAU/MAU, taux de conversion Free→Premium, synthèses IA consommées par niveau, taux d'ouverture des briefs. | Must |
| FR-058 | Un dashboard interne (administrateur uniquement) affiche les KPIs clés en temps réel: DAU, conversions du jour, revenu MRR/ARR. | Should |
| FR-059 | Les événements de conversion (inscription, passage Premium, annulation) sont tracés pour mesurer le funnel. | Must |
| FR-060 | L'analytics respecte le RGPD: données agrégées, pas de profil individuel, pas de fingerprinting, hébergement EU. | Must |

---

## 5. Exigences non fonctionnelles

### 5.1 Performance

| ID | Exigence | Cible |
|----|----------|-------|
| NFR-001 | Temps de chargement initial du Daily Brief (TTI) en conditions réseau standard (4G). | < 1,5 s (P95) |
| NFR-002 | Temps de réponse API (endpoints lecture) sous charge nominale. | < 200 ms (P95) |
| NFR-003 | Temps de génération d'une synthèse IA (Mistral, serveur). | < 8 s (P95) |
| NFR-004 | Temps de génération d'une synthèse on-device (Phi-3 Mini). | < 15 s (P95) |
| NFR-005 | Core Web Vitals: LCP < 2,5 s, CLS < 0,1, FID < 100 ms sur la page Daily Brief. | Objectif "Good" Google |

### 5.2 Scalabilité et ingestion

| ID | Exigence | Cible |
|----|----------|-------|
| NFR-006 | Capacité d'ingestion du pipeline RSS simultané (sources actives, workers Messenger). | 500 sources / heure |
| NFR-007 | Capacité de traitement des articles dédupliqués et classifiés par heure (pics de news). | 10 000 articles / heure |
| NFR-008 | Nombre d'utilisateurs concurrents supportés sur la plateforme web sans dégradation. | 5 000 concurrents |
| NFR-009 | Scalabilité horizontale des workers Symfony Messenger (scale-out containers). | Linéaire jusqu'à 10 workers |
| NFR-010 | Le cache Redis (synthèses IA) supporte un TTL de 24 h et un hit rate cible de ≥ 80 % en régime de croisière. | Hit rate ≥ 80 % |

### 5.3 Sécurité (OWASP Top 10:2025)

| ID | Exigence | Référence OWASP |
|----|----------|-----------------|
| NFR-011 | Contrôle d'accès vérifié à chaque requête via Symfony Security Voters (deny by default). UUIDs v4 non séquentiels pour toutes les ressources. | #1 Broken Access Control |
| NFR-012 | Mots de passe hashés Argon2id (128 MiB RAM, t=3, p=1). JWT mobiles signés EdDSA (Ed25519). Jamais de secrets en code source (vault ou variables d'environnement). | #2 Cryptographic Failures |
| NFR-013 | Requêtes SQL via Doctrine ORM uniquement (préparées). Validation des entrées côté serveur via Symfony Validator (whitelist). Pas de concaténation SQL. | #3 Injection |
| NFR-014 | SBOM généré automatiquement à chaque build CI (CycloneDX). Scan CVE (Trivy/Grype). Dépendances Composer pinées sur version exacte. | #6 Supply Chain Failures |
| NFR-015 | Headers de sécurité obligatoires: CSP Level 3, HSTS (max-age=31536000), COOP, COEP, CORP, X-Frame-Options: DENY, Permissions-Policy. | #5 Security Misconfiguration |

### 5.4 RGPD et AI Act

| ID | Exigence |
|----|----------|
| NFR-016 | Toutes les données personnelles sont hébergées dans l'UE (Hetzner/OVH ou équivalent certifié). |
| NFR-017 | Le droit à l'oubli est implémenté en cascade: suppression de l'email, des préférences, de l'historique, de la clé API, du refresh token dans 30 jours maximum. |
| NFR-018 | Les synthèses IA sont produites par Mistral (EU, accord DPA en place). Aucun contenu utilisateur identifiable dans les prompts LLM. |
| NFR-019 | Conformité AI Act: les synthèses IA sont clairement identifiées comme telles (badge émeraude, préfixe "BRIEFLY AI:") — obligation de transparence systèmes IA à risque limité. |
| NFR-020 | Une DPA est maintenue avec chaque sous-traitant traitant des données personnelles (Mistral, Stripe, hébergeur, service email). |

### 5.5 Internationalisation (i18n)

| ID | Exigence |
|----|----------|
| NFR-021 | L'interface est disponible en anglais (langue de référence v1) et en français (locale fr_FR, complète dès le lancement). |
| NFR-022 | Les traductions sont gérées via symfony/translation (format ICU) avec clés externalisées (pas de chaînes en dur). |
| NFR-023 | Les dates, heures, nombres et devises sont formatés selon la locale de l'utilisateur (détection Accept-Language + préférence explicite). |

### 5.6 Accessibilité

| ID | Exigence | Niveau |
|----|----------|--------|
| NFR-024 | Le Daily Brief et les pages de lecture respectent le niveau WCAG 2.1 AA minimum. | WCAG 2.1 AA |
| NFR-025 | Les badges IA (accent émeraude) ne reposent pas uniquement sur la couleur: un texte "BRIEFLY AI:" et une icône (robot ou étoile) sont toujours présents. | WCAG 1.4.1 |
| NFR-026 | Le score Lighthouse Accessibility des pages principales est ≥ 90. | Lighthouse ≥ 90 |

### 5.7 Disponibilité et résilience

| ID | Exigence | Cible |
|----|----------|-------|
| NFR-027 | Disponibilité de la plateforme web (hors maintenance planifiée). | 99,5 % / mois |
| NFR-028 | Le pipeline d'ingestion est résilient: une panne d'une source ou d'un worker n'interrompt pas les autres pipelines (circuit breaker par source). | Isolation totale |
| NFR-029 | Le fallback LLM (OpenAI) est activé automatiquement si Mistral dépasse 3 erreurs consécutives dans une fenêtre de 5 minutes. | RTO < 30 s |
| NFR-030 | Les sauvegardes de la base PostgreSQL sont effectuées quotidiennement (snapshot) avec rétention de 30 jours. Point-In-Time Recovery (PITR) activé. | RPO < 1 h |

---

## 6. Modèle économique

### Plans tarifaires

| Feature | Free | Premium Mensuel | Premium Annuel |
|---------|------|-----------------|----------------|
| Prix | 0 € | 12 €/mois | 99 €/an (≈8,25 €/mois) |
| Daily Brief complet | Oui | Oui | Oui |
| Synthèses IA / jour | 3 | Illimitées | Illimitées |
| Synthèse "Concis" on-device | Non | Oui (opt-in) | Oui (opt-in) |
| Sources RSS personnalisées | Non | Jusqu'à 10 | Jusqu'à 10 |
| Export Markdown | Non | Oui | Oui |
| Accès API REST | Non | Oui (100 req/h) | Oui (100 req/h) |
| Notifications push | Oui (1/jour max) | Oui (1/jour max) | Oui (1/jour max) |
| Support | Communautaire | Email (48 h) | Email prioritaire (24 h) |

### Mécanique de conversion

1. **Découverte** — Daily Brief public (SEO, partage social, bouche-à-oreille)
2. **Inscription Free** — accès aux 3 premières synthèses IA
3. **Friction douce** — paywall contextuel à la 4e synthèse ("Vous avez utilisé vos 3 analyses gratuites aujourd'hui")
4. **Conversion** — CTA Premium avec mise en avant du bénéfice spécifique déclenché (ex: "Accédez à des synthèses illimitées")
5. **Rétention** — habitude quotidienne via Daily Brief + personnalisation croissante (valeur perçue qui augmente avec l'usage)

### Projections de revenus (hypothèses v1 — 18 mois)

| Mois | Utilisateurs Free | Premium (8 %) | MRR (estimé) |
|------|-------------------|---------------|--------------|
| M3 | 1 000 | 80 | 960 € |
| M6 | 5 000 | 400 | 4 800 € |
| M12 | 20 000 | 1 600 | 19 200 € |
| M18 | 50 000 | 4 000 | 48 000 € |

> Ces projections sont des hypothèses de travail, non des engagements. Elles seront révisées à chaque trimestre.

---

## 7. Métriques de succès et KPIs

### KPIs Produit (objectifs 6 mois post-lancement)

| KPI | Définition | Cible | Fréquence de mesure |
|-----|------------|-------|---------------------|
| **Rétention J30** | % utilisateurs inscrits revenant à J+30 | ≥ 40 % | Mensuelle |
| **Rétention J7** | % utilisateurs inscrits revenant à J+7 | ≥ 60 % | Hebdomadaire |
| **DAU/MAU** | Ratio utilisateurs quotidiens / mensuels | ≥ 35 % | Quotidienne |
| **Conversion Premium** | % utilisateurs Free passant Premium dans 60 jours | ≥ 8 % | Mensuelle |
| **NPS** | Net Promoter Score (survey in-app, trimestriel) | ≥ 45 | Trimestrielle |
| **Taux d'ouverture Brief** | % d'utilisateurs quotidiens qui ouvrent le Daily Brief | ≥ 70 % | Quotidienne |
| **Churn mensuel Premium** | % d'abonnés Premium annulant chaque mois | ≤ 5 % | Mensuelle |

### KPIs Techniques (SLO)

| KPI | Cible |
|-----|-------|
| Disponibilité web | ≥ 99,5 % / mois |
| Cache hit rate synthèses IA | ≥ 80 % |
| Erreurs 5xx (taux) | < 0,1 % des requêtes |
| P95 latence API | < 200 ms |
| Ingestion pipeline lag | < 30 min entre publication source et disponibilité dans le brief |

### Hypothesis Tracking

L'hypothèse centrale (voir section 9) est mesurée via le KPI "Rétention J+1 du Walking Skeleton" activé dès le Sprint 1 beta interne.

---

## 8. Périmètre v1 et hors-scope

### Dans le périmètre v1

- Daily Brief quotidien (3 histoires, automatisé, SSR)
- Authentification complète (OAuth2, email/mot de passe, JWT mobile, biométrie)
- Pipeline d'ingestion RSS/Atom avec déduplication et classification
- Synthèse IA serveur (Mistral, 3 niveaux: Bref / Détaillé / Narratif)
- Paywall (Free: 3 synthèses/jour, Premium: illimitées)
- Paiement Stripe (abonnement mensuel et annuel)
- Application mobile Flutter (Android + iOS, cache offline)
- Notifications push quotidiennes (opt-in)
- API REST Premium documentée OpenAPI
- Internationalisation (EN + FR)
- Conformité RGPD complète (consentement, droit à l'oubli, export)
- Dashboard administrateur (sources, santé pipeline, KPIs basiques)

### Hors périmètre v1 (backlog v2+)

- Synthèse on-device (Phi-3 Mini / Gemma 2B) — listed as Should, validée en Sprint 4+
- Sources RSS personnalisées utilisateur — validée après rétention J30 prouvée
- Webhooks utilisateur API
- Brief audio (Text-to-Speech)
- Application web progressive (PWA offline)
- Plan Enterprise (équipes, SSO SAML)
- Intégration native Notion/Obsidian
- Alertes Breaking News temps réel
- Analyse de sentiment des articles
- Support multilingue au-delà EN/FR (v3+)
- Recommandation collaborative (item-based)

---

## 9. Hypothèse centrale

**"Un Daily Brief quotidien de 3 histoires curatées par IA, accessible en moins de 10 secondes depuis l'ouverture de l'application, génère un taux de retour J+1 supérieur à 50 % dès la semaine bêta interne."**

### Méthode de validation

| Étape | Action | Indicateur |
|-------|--------|------------|
| Sprint 1 | Walking Skeleton: pipeline ingestion + Daily Brief minimal + authentification | Taux de retour J+1 (beta interne, 10-20 utilisateurs) |
| Sprint 2 | Ajout synthèses IA + paywall | Taux de clic "Lire la synthèse" (objectif > 30 %) |
| Sprint 3 | Application mobile + notifications | DAU/MAU > 35 % sur cohorte mobile |
| Sprint 4 | Stripe + Premium complet | Premier conversion Free→Premium, taux ≥ 8 % |

Si le taux de retour J+1 est inférieur à 40 % à l'issue du Sprint 2, une session de pivot est déclenchée avant de continuer le développement.

---

## 10. Risques majeurs

> Analyse complète: `project-management/analysis/risks-opportunities.md`

| ID Risque | Description | Impact | Probabilité | Mitigation |
|-----------|-------------|--------|-------------|------------|
| R-001 | Coûts LLM (Mistral) supérieurs aux revenus Premium en phase de croissance | Élevé | Moyen | Cache Redis 24h (hit rate ≥ 80 %), quota Free strict (3/jour), monitoring coût/requête |
| R-002 | Changements de conditions d'utilisation RSS par les grands éditeurs | Élevé | Moyen | Diversification sources, accords éditoriaux directs en v2, contenu original en valeur ajoutée |
| R-003 | Non-conformité AI Act (transparence insuffisante des synthèses) | Élevé | Faible | Badge émeraude obligatoire, préfixe "BRIEFLY AI:", lien source systématique |
| R-004 | Latence synthèse IA supérieure aux attentes utilisateurs (> 8 s) | Moyen | Moyen | Cache warm-up, génération proactive des synthèses top-clusters, on-device fallback |
| R-005 | Taux de rétention J30 inférieur à 40 % (hypothèse non validée) | Élevé | Moyen | Pivot rapide dès Sprint 2 si signal faible, entretiens utilisateurs hebdomadaires |
| R-006 | Attaque bot / scraping abusif de l'API | Moyen | Élevé | Rate limiting Redis, clé API obligatoire (Premium), CAPTCHA anti-bot |

---

*PRD maintenu par le Product Owner. Toute modification doit être tracée en commentaire de commit git et discutée en Sprint Review ou Backlog Refinement.*

*Prochaine révision planifiée: Sprint 2 Review.*
