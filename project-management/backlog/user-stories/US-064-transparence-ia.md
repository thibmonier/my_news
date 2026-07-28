# US-064 : Consulter la transparence du traitement IA (AI Act)

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-064 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-002 Priya (chercheuse stratégie), P-003 Marc (dev indépendant) |
| **Story points** | 3 (Fibonacci) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-002 : Priya, chercheuse en stratégie,
**Je veux** accéder à une page de transparence indiquant quels modèles IA traitent mes données, quelles informations sont envoyées et dans quel but,
**Afin de** comprendre précisément le rôle de l'IA dans Briefly, valider la conformité avec les exigences de mon organisation et documenter la traçabilité des synthèses que j'exploite dans mes travaux.

---

### Conversation

- **Conformité AI Act** : Briefly est classé "limited risk" (génération de contenu informatif, pas de décision critique) → obligation de transparence active envers les utilisateurs (Art. 52 AI Act).
- **Contenu statique de la page** :
  1. Modèles disponibles et utilisés (Mistral Large EU, Phi-3 Mini on-device, Gemma 2B on-device, OpenAI GPT-4o fallback)
  2. Données transmises : texte de l'article uniquement — jamais l'identité, jamais l'historique de l'utilisateur
  3. Finalité : classification de thème, regroupement sémantique (clustering), génération de synthèse
  4. Localisation du traitement : serveurs EU (Mistral AI SAS, France) ou sur l'appareil (on-device)
  5. Conservation : cache Redis 24 h par (article_id, niveau_synthèse) — aucun stockage long terme lié à l'utilisateur
  6. Liens vers : politique de confidentialité Briefly, CGU, politique IA de Mistral AI
  7. Badge "AI Act — Transparence assurée"
- **Section dynamique** (authentifiée) : modèle actuellement actif pour CE compte, nombre de synthèses générées dans les 30 derniers jours, mode on-device ou cloud.
- **Accès** : depuis le footer public (sans auth), depuis Paramètres > IA (auth), depuis l'icône "ⓘ" sur chaque synthèse générée.
- **Pas d'identifiant utilisateur dans la section publique** : seuls les paramètres généraux, aucune donnée personnelle.
- **Vue mobile** : écran `AiTransparencyScreen` accessible depuis le menu synthèse d'un article.

---

### Validation INVEST

- [x] **Independent** — peut être livrée indépendamment ; enrichit la compréhension de US-062 mais ne la bloque pas
- [x] **Negotiable** — contenu exact négociable avec le DPO et le juriste (conformité AI Act) ; section dynamique peut être simplifiée
- [x] **Valuable** — conformité réglementaire (AI Act) + confiance éditoriale + différenciateur pour P-002 et P-003
- [x] **Estimable** — 3 pts : page Twig statique + section Twig Live dynamique + endpoint API Platform + écran Flutter
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Vue `user_ai_usage_summary` | `SELECT user_id, on_device_model, on_device_ai_enabled, COUNT(*) FILTER (WHERE generated_at > NOW()-INTERVAL '30 days') AS syntheses_30j FROM synthesis_cache JOIN user_preferences USING (user_id) GROUP BY 1,2,3` |
| **API Platform** | `GET /api/me/ai-transparency` | Retourne `{ active_model, on_device, syntheses_last_30_days }` ; voter ownership ; cache Redis 1 h |
| **Symfony/Twig** | `/transparency/ai` | Page publique (sans auth) : section statique + section dynamique via Twig Live Component (`AiTransparencyComponent`) qui charge la section personnalisée si session active |
| **Symfony/Twig** | `AiTransparencyComponent` | Twig Live Component : charge `GET /api/me/ai-transparency` si user authentifié, affiche "Reconnectez-vous" sinon |
| **Symfony** | `FooterComponent` | Lien "Transparence IA" dans le footer global |
| **Flutter** | `AiTransparencyScreen` | Accessible depuis le menu "..." d'une synthèse et depuis Paramètres > IA ; affiche section statique + section dynamique de l'utilisateur courant |
| **Flutter** | `SynthesisBadgeWidget` | Icône "ⓘ" à côté du badge "BRIEFLY AI:" ouvre un bottom sheet `AiTransparencySheet` pour CETTE synthèse spécifique |
| **Sécurité** | Page publique `/transparency/ai` | Aucune donnée personnelle en section statique ; section dynamique protégée par session/JWT ; headers `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex` |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Consultation de la page de transparence IA (web, mode cloud)

```gherkin
Scenario: P-002 consulte la page de transparence IA depuis le footer
  GIVEN Priya est authentifiée avec le mode cloud actif (Mistral Large EU)
  WHEN elle clique sur "Transparence IA" dans le footer
  THEN la page /transparency/ai s'affiche et contient :
    Section statique :
    - "Modèles utilisés : Mistral Large (EU), Phi-3 Mini (on-device opt-in), OpenAI GPT-4o (fallback)"
    - "Données transmises : texte de l'article uniquement — jamais votre identité, jamais votre historique"
    - "Finalité : classification thème, clustering sémantique, génération synthèse"
    - "Localisation traitement cloud : serveurs EU (Mistral AI SAS, France)"
    - "Conservation : 24h en cache — aucun stockage long terme lié à votre compte"
    Section dynamique (authentifiée) :
    - "Votre modèle actif : Mistral Large (cloud)"
    - "Synthèses générées ces 30 derniers jours : X"
    Badge :
    - "AI Act — Transparence assurée"
    Et :
    - Un lien "Politique IA de Mistral AI" s'ouvre dans un nouvel onglet
```

### Scénario alternatif 02 — Section dynamique pour utilisateur en mode on-device

```gherkin
Scenario: P-003 avec Phi-3 Mini actif consulte la transparence IA
  GIVEN Marc est authentifié avec Phi-3 Mini activé en mode on-device
  WHEN il accède à /transparency/ai
  THEN la section dynamique affiche :
    - "Votre modèle actif : Phi-3 Mini (ON-DEVICE – aucune donnée envoyée à des serveurs externes)"
    - "Données transmises : AUCUNE – traitement 100% local sur votre appareil"
    - Badge "PRIVACY MAX" avec icône cadenas
  AND la section statique reste visible pour le contexte général
  AND aucune requête vers des API IA n'est émise pour afficher cette page
```

### Scénario alternatif 03 — Accès à la transparence depuis une synthèse d'article (Flutter)

```gherkin
Scenario: P-002 accède à la transparence depuis l'icône ⓘ d'une synthèse
  GIVEN Priya lit une synthèse d'article dans l'app Flutter, générée par Mistral Large
  WHEN elle tape sur l'icône "ⓘ" à côté du badge "BRIEFLY AI:"
  THEN un bottom sheet AiTransparencySheet s'affiche avec :
    - "Modèle : Mistral Large (EU)"
    - "Générée le : [date/heure de génération de cette synthèse]"
    - "Données utilisées pour cette synthèse : texte de l'article '[titre]' uniquement"
    - "Validité du cache : 24h"
  AND un bouton "En savoir plus" ouvre /transparency/ai dans le navigateur système
```

### Scénario d'erreur 04 — Utilisateur non authentifié sur la section dynamique

```gherkin
Scenario: Visiteur non authentifié accède à /transparency/ai
  GIVEN un visiteur non connecté accède à /transparency/ai
  WHEN Twig Live Component tente de charger la section dynamique
  THEN la section statique s'affiche normalement et complètement
  AND la section dynamique affiche "Connectez-vous pour voir votre configuration personnelle"
  AND aucune donnée personnelle n'est exposée dans la réponse HTML
  AND la page retourne HTTP 200 (pas de redirection vers /login)
```

### Scénario d'erreur 05 — Indisponibilité temporaire de la section dynamique

```gherkin
Scenario: L'API GET /api/me/ai-transparency retourne 503 pendant la consultation
  GIVEN Priya est authentifiée et accède à /transparency/ai
  WHEN le Twig Live Component appelle GET /api/me/ai-transparency et reçoit HTTP 503
  THEN la section statique s'affiche normalement sans dégradation
  AND la section dynamique affiche "Votre configuration personnelle est temporairement indisponible"
  AND aucune stack trace ou détail technique n'est exposé dans la page
  AND un lien "Actualiser" permet de réessayer sans rechargement complet de la page
```
