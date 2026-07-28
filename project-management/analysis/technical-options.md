# Options Techniques — Briefly AI

> Phase 1 (Analyse). Évaluation orientée décision. Pile imposée non négociable :
> Symfony 8, API Platform 4, FrankenPHP, PostgreSQL, Redis, Docker.

---

## 1. Frontend desktop — Headless vs Symfony/Twig+Turbo

| Critère | Headless (SPA + API) | Twig + Turbo (Hotwire) |
|---------|----------------------|------------------------|
| SEO/indexation | Complexe (SSR requis) | **Natif** |
| Auth | JWT (cohérent mobile) | Session HttpOnly (robuste vol token) |
| Partage code | API partagée, UI distincte | Stack unique |
| Complexité équipe | +1 stack JS | Backend seul |
| Temps réel | WebSocket/SSE natif | Turbo Streams (SSE) |
| Interactivité riche | Native | Limitée (OK listes/flux) |

**➡ Recommandation : Symfony/Twig + Turbo.** SEO critique pour plateforme news publique, stack unique, Turbo Streams couvre les MàJ temps réel. API Platform reste headless pour le mobile — coexistence sans contradiction. Migration vers frontend JS possible plus tard sans toucher le backend.

*Réserve* : basculer headless si l'équipe a des experts JS et vise une interactivité très riche (dashboards analytics temps réel).

---

## 2. Techno mobile

| Option | Force | Faiblesse |
|--------|-------|-----------|
| Natif (Swift/Kotlin) | Perf max, APIs directes | 2 codebases, maintenance ×2 |
| React Native | Base JS partagée, écosystème mature | Perf listes longues, dépendance Expo |
| **Flutter** | Codebase unique, perf (Impeller), APIs dispo | Moins de libs tierces (mais couvre tout) |
| KMP | Logique partagée, UI native | Maturité 2026 insuffisante, UI dupliquée |

Besoins couverts par Flutter : cache offline (Hive/Isar/drift), push (FCM/APNs), biométrie (`local_auth`), secure storage (`flutter_secure_storage`), notifications locales (Notifee).

**➡ Recommandation : Flutter.** Codebase unique, perfs proches natif pour app de lecture/flux, toutes les APIs requises disponibles. KMP écarté (coût d'entrée trop élevé pour petite équipe).

---

## 3. Chaîne d'ingestion news

```
Sources RSS/Atom + Google News RSS
  → Symfony Scheduler (déclenchement récurrent)
  → Symfony Messenger (workers async)
  → fetch · parse · dedup · store
  → PostgreSQL + Redis
```

- **Fetch/Parse** : `debril/feed-io` (RSS/Atom, encodages, dates malformées, ETag/Last-Modified → requêtes conditionnelles). Google News = pas d'API officielle, mais flux RSS par topic/requête consommables via FeedIo.
- **Planif** : `symfony/scheduler`, message récurrent par source (intervalle 5-15 min).
- **Async** : `symfony/messenger`, transport Redis/AMQP/Doctrine selon charge. Workers séparés : `fetch_source`, `parse_article`, `dedup_article`, `generate_brief`.
- **Déduplication** (2 niveaux) :
  1. URL canonique → hash SHA-256 (sans UTM), index UNIQUE.
  2. Similarité titre → SimHash/MinHash + fenêtre ±2h ; fallback Levenshtein tokens >85 % sous 4h → doublon pointant l'article canonique.
  3. Fingerprint en colonne indexée `articles`.
- **Brief quotidien 6h locale** : génération batch à 5h UTC, envoi décalé par timezone profil via messages Messenger différés.

---

## 4. Couche IA synthèse

Niveaux : **concis** (2-3 phrases, push), **détaillé** (150-300 mots), **narratif** (500+, premium). Plus regroupement (clustering d'événements) et classification (topics).

| Traitement | Trade-off |
|------------|-----------|
| Full serveur (LLM API) | Coût maîtrisé en batch, latence OK pré-généré. **Confidentialité : données chez un tiers → contraire à la cible privacy** |
| On-device | Phi-3 Mini / Gemma 2B / LLaMA 3.2 quantisés 4-bit. Concis réalisable sur iPhone 15+/Pixel 8+. **Synthèse longue + clustering hors de portée** (latence/mémoire, modèle 1-2 Go) |
| **Hybride (reco)** | Meilleur des deux |

**Répartition hybride :**

| Niveau | Où | Modèle |
|--------|----|--------|
| Classification topics | Serveur batch | Embedding + classifieur léger |
| Clustering stories | Serveur batch | HDBSCAN + embeddings |
| Concis (push) | Pré-généré serveur | Mistral Small / GPT-4o-mini |
| Concis à la demande | On-device (opt-in) | Phi-3 Mini / Gemma 2B |
| Détaillé | Serveur | Claude Haiku / Mistral Medium |
| Narratif | Serveur (premium) | GPT-4o / Claude Sonnet |

**Privacy** : mode « vie privée » = synthèse on-device only (modèle téléchargeable optionnel). Défaut : transmet articles à synthétiser, jamais l'historique de lecture. Provider défaut **Mistral** (RGPD/EU). Cache Redis TTL 24h par (article, niveau).

---

## 5. Intégrations transverses

- **Stripe Billing** : Customer Portal (auto-gestion), webhooks → Messenger (`subscription.updated`, `payment_failed`), quotas Redis (compteur TTL mensuel) validés via `StateProcessor`/listener API Platform. Stripe Tax (TVA EU) dès le départ.
- **Push** : FCM (Android) + APNs (iOS), Notifee côté Flutter. Digest email via `symfony/mailer` + Postmark/Sendgrid. Fenêtre silence configurable.
- **Auth déléguée** : KnpU OAuth2 Client Bundle (Google/GitHub). Flux OAuth2 code → compte local → JWT mobile / session desktop. Biométrie = déverrouille le refresh token en Keychain/Keystore (`flutter_secure_storage` + `local_auth`), ne remplace pas l'auth serveur.

---

## 6. Non-fonctionnel

- **Scalabilité ingestion** : workers Messenger parallèles (scale horizontal Docker/K8s), rate limiter Redis par source, file de priorité (premium > gratuit), circuit breaker par source.
- **Cache Redis** : sessions (30 min), rate limiting (fenêtre 1h), synthèses IA (24h), quotas (fin mois), file Messenger (streams), ETag RSS (15 min).
- **i18n** : `symfony/translation` (ICU, fr+en), mobile `flutter_localizations`+`intl` (ARB). Contenu généré taggé `lang`, modèles de synthèse par langue.
- **RGPD** : consentement explicite, droit à l'oubli (commande cascade), portabilité (export JSON), pas d'identifiant user dans les prompts LLM, rétention logs ≤12 mois.
- **Sécurité OWASP 2025** : Doctrine paramétré, Argon2id, rate limit `/login` (5/15 min Redis), voters + UUID non séquentiels, `composer audit`+Dependabot+SBOM en CI, CSP/HSTS/COOP/COEP via FrankenPHP, secrets en Docker Secrets/Vault, API Platform (opérations minimales, pagination obligatoire).

---

## Synthèse décisions techniques

| Décision | Recommandation |
|----------|----------------|
| Frontend desktop | Symfony/Twig + Turbo |
| Mobile | Flutter |
| Feed parsing | FeedIo + Scheduler + Messenger |
| Déduplication | SHA-256 URL + SimHash titre |
| IA synthèse | Hybride Mistral serveur + Phi-3 Mini on-device (opt-in) |
| Auth | KnpU OAuth2 + JWT mobile + session desktop |
| Push | FCM/APNs via Notifee |
| Billing | Stripe Billing + webhooks Messenger |
| LLM provider défaut | Mistral (EU) + fallback OpenAI |

*Ces recommandations alimenteront les ADR (phase Conception) et le Tech Spec.*
