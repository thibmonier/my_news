# US-074 : Partage d'article et accès à la source originale

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-074 |
| **EPIC parent** | EPIC-008 — Analytics & Personnalisation |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 3 (Fibonacci) |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** partager un article en un clic ou ouvrir la source journalistique originale depuis ma bibliothèque ou le Daily Brief
**Afin de** diffuser des insights crédibles à mon réseau professionnel tout en garantissant la traçabilité éditoriale.

---

## Conversation (3C)

### Points à clarifier
- Le lien "OUVRIR L'ORIGINAL" redirige-t-il directement vers l'URL canonique de l'article ou via un proxy Briefly (tracking interne opt-in) ?
- Le partage génère-t-il un lien deeplink Briefly (`briefly.ai/article/{uuid}`) ou l'URL canonique directe de la source ?
- Le partage est-il disponible uniquement depuis la bibliothèque ou aussi depuis le Daily Brief et la page d'exploration ?
- Sur mobile, l'action de partage utilise-t-elle le système natif (Android Intent / iOS UIActivityViewController) ?

### Alternatives envisagées
- Générer un aperçu Open Graph riche (titre, image, source) pour l'URL de partage Briefly — meilleure expérience LinkedIn/Slack mais nécessite un rendu SSR supplémentaire (phase 2).
- Copier l'URL dans le presse-papier comme fallback si l'API Web Share n'est pas disponible.

### Validation INVEST
- [x] **Independent** — peut fonctionner sans US-075/076 ; dépend de US-072 pour la bibliothèque
- [x] **Negotiable** — destination du partage (deeplink vs URL directe) à définir
- [x] **Valuable** — P-001 partage des insights crédibles ; renforce l'image de marque Briefly
- [x] **Estimable** — endpoint redirect + Web Share API + Flutter share_plus = 3 pts
- [x] **Sized** — 3 pts, clairement sous 8
- [x] **Testable** — redirection vérifiable, partage natif testable sur device/simulateur

---

## Vertical Slicing

| Couche | Composant | Détail |
|--------|-----------|--------|
| **API** | Symfony | `GET /api/articles/{uuid}/original-url` → retourne `{"canonical_url": "https://..."}` ; validation que l'URL est whitelistée (protection SSRF) |
| **Domaine** | `Article.canonicalUrl` | Champ stocké à l'ingestion (EPIC-003) ; jamais reconstruit depuis input utilisateur |
| **Persistance** | PostgreSQL | Lecture `articles.canonical_url` ; pas d'écriture sur cette US |
| **Frontend web** | Twig + Stimulus | Bouton "OUVRIR L'ORIGINAL" : `<a href="{{ article.canonical_url }}" rel="noopener noreferrer" target="_blank">` ; bouton "Partager" : Web Share API (fallback : copie clipboard) |
| **Frontend mobile** | Flutter | Bouton "OUVRIR L'ORIGINAL" : `launchUrl(Uri.parse(canonicalUrl), mode: LaunchMode.externalApplication)` ; bouton "Partager" : `share_plus` natif |
| **Sécurité** | Symfony | URL canonique stockée à l'ingestion (jamais issue d'un input utilisateur) ; validation whitelist domaines autorisés (SSRF — OWASP A10) ; `rel="noopener noreferrer"` sur tous les liens externes ; authentification requise |
| **Traçabilité** | — | Le lien partagé et le lien "OUVRIR L'ORIGINAL" sont identiques à l'URL canonique — transparence totale sur la source |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Ouverture de la source originale depuis la bibliothèque (web)

```gherkin
Scenario: Thomas ouvre la source originale d'un article depuis sa bibliothèque
  GIVEN Thomas est authentifié sur Briefly AI (web)
    AND il consulte sa bibliothèque (/library)
    AND l'article "IA Act : nouvelles obligations" a une URL canonique "https://ft.com/content/..."
  WHEN il clique sur le lien "OUVRIR L'ORIGINAL"
  THEN le navigateur ouvre l'URL "https://ft.com/content/..." dans un nouvel onglet
    AND le lien HTML porte les attributs rel="noopener noreferrer" target="_blank"
    AND aucun paramètre de tracking Briefly n'est ajouté à l'URL (transparence)
    AND l'URL canonique a été validée côté serveur contre la whitelist des domaines autorisés
```

### Scénario alternatif 1 — Partage via Web Share API (mobile web)

```gherkin
Scenario: Thomas partage un article depuis son navigateur mobile
  GIVEN Thomas est authentifié sur Briefly AI dans un navigateur mobile supportant l'API Web Share
    AND il consulte un article dans sa bibliothèque
  WHEN il clique sur le bouton "Partager"
  THEN la boîte de dialogue de partage native du système s'ouvre (iOS/Android)
    AND elle propose de partager le titre de l'article et l'URL canonique de la source
    AND si l'API Web Share n'est pas supportée, le fallback "URL copiée dans le presse-papier" est affiché
```

### Scénario alternatif 2 — Partage natif depuis l'application Flutter

```gherkin
Scenario: Thomas partage un article depuis l'application mobile Flutter
  GIVEN Thomas est authentifié sur l'application mobile Briefly AI
    AND il consulte un article dans sa bibliothèque
  WHEN il tape sur le bouton "Partager" (icône de partage)
  THEN le plugin share_plus déclenche l'Intent natif Android / UIActivityViewController iOS
    AND le contenu partagé est : "{Titre de l'article} — via Briefly AI\n{URL canonique}"
    AND la source originale est incluse pour crédibilité éditoriale
```

### Scénario d'erreur 1 — URL canonique manquante ou invalide

```gherkin
Scenario: L'article n'a pas d'URL canonique disponible
  GIVEN un article a été ingéré sans URL canonique valide (flux RSS mal formé)
  WHEN l'utilisateur clique sur "OUVRIR L'ORIGINAL"
  THEN le bouton est affiché en état désactivé (disabled, grisé)
    AND un tooltip "Source originale non disponible" s'affiche au survol
    AND l'API GET /api/articles/{uuid}/original-url retourne HTTP 404 avec {"error": "URL originale indisponible"}
    AND aucune redirection n'est effectuée
```

### Scénario d'erreur 2 — Tentative de manipulation d'URL (protection SSRF)

```gherkin
Scenario: Un acteur malveillant tente d'injecter une URL interne via l'API
  GIVEN un attaquant est authentifié
    AND il modifie une requête pour pointer vers une URL interne "http://internal.service/admin"
  WHEN il appelle GET /api/articles/{uuid}/original-url
  THEN l'API valide l'URL contre la whitelist des domaines autorisés
    AND retourne HTTP 403 avec {"error": "URL de destination non autorisée"}
    AND aucune requête vers l'URL interne n'est émise
    AND l'événement de sécurité est loggé (user_id, URL ciblée, timestamp)
```

---

## Estimation & Priorisation

| Critère | Valeur |
|---------|--------|
| **Story Points** | 3 |
| **MoSCoW** | Could have |
| **Valeur métier** | Forte — crédibilité éditoriale ; viralité organique via partage |
| **Risque technique** | Faible — lecture seule ; protection SSRF via whitelist (impérative) |
| **Dépendances** | US-072 (bibliothèque), EPIC-003 (canonical_url stockée à l'ingestion) |
