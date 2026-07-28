# Constitution du Projet — Briefly AI

> **Nature :** décisions **immuables**. Toute modification exige une procédure d'amendement (§9).
> **Version :** 1.0.0 · **Ratifiée :** 2026-07-28 · **Autorité :** Product Owner + Tech Lead.
> **Sources :** `brief.md`, `prd.md`, `analysis/constraints.md`, `analysis/technical-options.md`, `.claude/rules/`.

---

## 1. Vision & Mission

**Vision (une phrase) :** transformer un flux d'actualités chaotique en un **Daily Brief quotidien à fort signal** — 3 histoires majeures synthétisées par IA, à angle éditorial défendable.

**Mission :** rendre à des professionnels de l'information (cadres, chercheurs, développeurs) le **temps** qu'ils perdent à trier le bruit, en étant un **éditeur algorithmique** (pull de valeur), pas un agrégateur (push de volume).

**Devise produit :** *fort signal, faible bruit, ton éditorial.*

### Objectifs non négociables
1. Le Daily Brief est le **point d'entrée identitaire** — pas une simple fonctionnalité.
2. Toute production IA est **traçable et rattachée à sa source**.
3. La **confidentialité** est crédible (option de traitement on-device, RGPD, sans tracker).
4. La qualité éditoriale prime sur le volume.

### Critères d'échec (ce qui invaliderait le projet)
- Le brief devient indiscernable de « Google News mis en forme ».
- La rétention J+1 du Walking Skeleton reste < 40 % → pivot obligatoire.
- Sur-notification / dérive vers les apps d'engagement qu'on veut remplacer.
- Fuite de données personnelles vers des LLM tiers.

---

## 2. Invariants produit (immuables)

| # | Invariant |
|---|-----------|
| INV-1 | Le Daily Brief comporte **exactement 3 histoires majeures** numérotées (01/02/03), horodatées. |
| INV-2 | L'accent **émeraude `#10B981` est réservé exclusivement à l'IA** (badges, synthèses, actions IA). |
| INV-3 | Toute synthèse IA est préfixée **« BRIEFLY AI: »** et porte un lien **« OUVRIR L'ORIGINAL »** vers la source. |
| INV-4 | La traçabilité IA ne repose **jamais uniquement sur la couleur** (texte + icône toujours présents — WCAG 1.4.1). |
| INV-5 | **Maximum 1 notification par jour** (Daily Brief). Pas de breaking news en v1. |
| INV-6 | **Aucun identifiant utilisateur** ni donnée personnelle transmis aux LLM (serveur ou on-device). |
| INV-7 | Le **projet Stitch `7076573032400883843`** (« Intelligent News Digest ») est la **source de vérité UI**. Toute évolution visuelle passe par lui ; les tokens versionnés (`design/design-tokens.md`) sont l'unique source des valeurs de design (cf ADR-011). |

---

## 3. Contraintes techniques (VERROUILLÉES)

> Non négociables sans amendement + ADR.

### Stack imposée
| Couche | Techno verrouillée |
|--------|--------------------|
| Backend | **Symfony 8** exposé via **API Platform 4** |
| Serveur applicatif | **FrankenPHP** (worker mode) |
| Base de données | **PostgreSQL** |
| Cache / sessions / files / quotas | **Redis** |
| Conteneurisation (dev + prod) | **Docker** |
| Frontend desktop | **Symfony/Twig + Turbo** (SSR, session HttpOnly, SEO natif) — cf ADR-001 |
| Mobile | **Flutter** (Android + iOS) — cf ADR-002 |
| Ingestion | **FeedIo + Symfony Scheduler + Messenger** — cf ADR-003 |
| Couche IA | **Hybride** : Mistral (EU) serveur + Phi-3 Mini/Gemma 2B on-device opt-in — cf ADR-004 |
| Fournisseur LLM par défaut | **Mistral (hébergement UE, RGPD)** ; fallback OpenAI |
| Authentification | OAuth2 (Google/GitHub) + **JWT mobile** + **session desktop** + biométrie — cf ADR-005 |
| Facturation | **Stripe Billing** + Customer Portal + webhooks Messenger |

### Architecture imposée
- **Hexagonale + DDD**, découpage `Domain / Application / Infrastructure / Presentation`.
- Règles de dépendances (deptrac) : `Domain: []`, `Application: [Domain]`, `Infrastructure & Presentation: [Domain, Application]`.
- Bounded contexts : Ingestion, Synthèse IA, Brief, Comptes/Billing, Sources, Notifications.

---

## 4. Principes de conception

### Obligatoires
- **SOLID** intégral (cf `rules/04`), **KISS / DRY / YAGNI** (cf `rules/05`).
- **TDD / BDD** — Red/Green/Refactor, Gherkin SMART. Couverture **≥ 80 %**.
- **PSR-12**, **PHPStan niveau max**, Dart analyzer strict (mobile).
- Value Objects immuables pour les concepts métier (`Email`, `Fingerprint`, `SummaryLevel`, `Money`).
- Interfaces de repository **dans le Domain**, implémentations dans Infrastructure (DIP).
- Injection de dépendances par constructeur. Immutabilité par défaut.

### Interdits (anti-patterns bannis)
- ❌ Logique métier dans les controllers / State Processors.
- ❌ Annotations/attributs `#[ORM]` dans le `Domain`.
- ❌ Accès direct à Doctrine/ORM depuis controllers ou Domain.
- ❌ User Story **frontend-only** (vertical slicing obligatoire).
- ❌ Secrets dans le code source.
- ❌ Hash **MD5/SHA1/bcrypt** en nouveau code ; JWT **HS256**.
- ❌ Concaténation SQL (requêtes paramétrées uniquement).
- ❌ Valeurs de design (couleur/typo/espacement) codées en dur hors des tokens ; écrans UI inventés hors Stitch.

### Design system (source de vérité UI) — cf ADR-011

- **Référence** : projet Stitch `7076573032400883843`, design systems *Insight Minimalist* (clair) + *Insight Dark* (sombre).
- **Tokens DRY** : `project-management/design/design-tokens.md` (+ `.css`) = source unique ; web (CSS custom properties) et Flutter (ThemeData Material 3) dérivent des mêmes tokens, sans duplication.
- **Émeraude `#10B981` = IA exclusivement** ; badges IA rayon 2px, cartes/champs 4px ; rythme 8px.
- **Écrans manquants** produits via Stitch (jamais côté code). Gap connu : « Articles Sauvegardés » desktop (§9.9).
- **DoD UI** : une US UI n'est *done* que si elle correspond à l'écran Stitch référencé + WCAG 2.1 AA + Lighthouse ≥ 90.

---

## 5. Exigences non fonctionnelles (cibles immuables)

| Domaine | Cible |
|---------|-------|
| TTI Daily Brief (4G, P95) | < 1,5 s |
| Latence API lecture (P95) | < 200 ms |
| Synthèse IA serveur (P95) | < 8 s |
| Disponibilité web | ≥ 99,5 % / mois |
| Cache hit rate synthèses | ≥ 80 % |
| Scalabilité workers Messenger | horizontale, linéaire jusqu'à 10 workers |
| Ingestion | 500 sources/h, 10 000 articles/h en pic |
| Accessibilité | WCAG 2.1 AA minimum (badges IA WCAG 1.4.1) |

---

## 6. Sécurité & Conformité (socle immuable)

- **OWASP Top 10:2025** — mapping complet (cf `architecture/security.md`), deny by default, voters Symfony, UUID non séquentiels.
- **SSRF** : whitelist/validation stricte des URLs RSS **et** des URLs de `/synthesize` (input utilisateur).
- **Cryptographie** : Argon2id (128 MiB, t=3, p=1) ; JWT **EdDSA (Ed25519)** ; TLS 1.3 ; headers 2026 (CSP L3, HSTS, COOP/COEP/CORP, Permissions-Policy).
- **RGPD** : consentement explicite granulaire, droit à l'oubli en cascade (≤ 30 j), portabilité JSON, hébergement UE, DPA par sous-traitant.
- **AI Act** : synthèses IA identifiées (risque limité — obligation de transparence).
- **Supply chain** : `composer audit` + Dependabot + SBOM (CycloneDX) + scan CVE en CI ; dépendances pinées.

---

## 7. Modèle économique (verrouillé)

- **Free** : Daily Brief complet + **3 synthèses IA/jour**. Paywall à la 4e.
- **Premium** : **12 €/mois** ou **99 €/an** — synthèses illimitées, sources premium, historique 90 j, export, accès API.
- Paiement délégué à **Stripe** (PCI DSS délégué). TVA UE via Stripe Tax.

---

## 8. Frontières (boundaries)

### Hors périmètre v1
Breaking news temps réel · synthèse on-device au-delà du concis · webhooks utilisateur API · brief audio (TTS) · PWA offline · plan Enterprise/SSO SAML · intégrations natives Notion/Obsidian · langues au-delà EN/FR · recommandation collaborative.

### Intégrations externes (contractuelles)
Google News (RSS) · sources RSS/Atom · **Mistral** (défaut) / OpenAI (fallback) · **Stripe** · FCM/APNs · fournisseur email · OAuth Google/GitHub. Sources premium sous paywall = **indexation contractuelle uniquement** (jamais de scraping — jurisprudence NYT vs OpenAI).

### Frontières d'équipe
Backend (Symfony/API) · Web (Twig/Turbo) · Mobile (Flutter) — contrat API stable et versionné comme frontière.

---

## 9. Procédure d'amendement

1. Toute proposition de changement d'un élément **verrouillé** (§3) ou d'un **invariant** (§2) requiert un **ADR** dédié (`docs/adr/`).
2. Validation conjointe **Product Owner + Tech Lead**.
3. Discussion en **Sprint Review** ou **Backlog Refinement**, tracée en commit git.
4. Incrément de version de cette constitution (SemVer) + entrée au CHANGELOG.
5. Les NFR (§5) et cibles peuvent être **révisées trimestriellement** ; les invariants (§2) et la stack (§3) ne changent que par amendement formel.

---

*Constitution maintenue par le Product Owner et le Tech Lead. Prochaine revue : Sprint 2 Review.*
