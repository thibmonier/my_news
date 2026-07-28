# US-055 : Lien "API ACCESS" pied de page desktop

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-055 |
| **EPIC parent** | EPIC-006 — API Publique |
| **Persona** | P-003 — Marc, développeur indépendant |
| **Story Points** | 2 |
| **Priorité MoSCoW** | Could have |
| **Sprint** | backlog |

---

## Carte (User Story)

**En tant que** P-003 (Marc, développeur indépendant 44 ans, privacy-first)
**Je veux** voir un lien "API ACCESS" dans le pied de page de toutes les pages de l'interface web Briefly AI
**Afin de** découvrir et accéder facilement à la documentation et à la gestion de mes tokens API depuis l'interface, sans avoir à mémoriser l'URL directement.

---

## Conversation (Notes & Questions ouvertes)

- Visibilité : lien visible pour tous les visiteurs (connectés ou non) — la page `/developers` est publique.
- Texte exact : "API ACCESS" — en majuscules, cohérent avec la typographie du footer Briefly AI (ex: "CONFIDENTIALITÉ", "À PROPOS").
- Placement : nouvelle section "DÉVELOPPEURS" dans le footer, positionnée après les sections "LÉGAL" et "À PROPOS".
- Lien destination : `/developers` (Getting Started, plus accessible) plutôt que directement `/api/docs` (Swagger UI). La page `/developers` contient le lien vers Swagger UI.
- Badge accentué émeraude (#10B981) : non sur le lien footer lui-même (neutre) ; réservé aux badges IA sur la page `/developers`.
- Dépendance avec le layout : si le template `_footer.html.twig` existe déjà (créé dans EPIC-001/002), on y ajoute la section. Sinon, la créer.
- Test automatisé obligatoire : un test PHPUnit/Twig vérifiant la présence du lien sur plusieurs templates (accueil, article, compte).

---

## Validation INVEST

- [x] **Independent** : Livrable indépendamment de US-054 — le lien footer vers `/developers` peut exister avant ou après la page Getting Started elle-même
- [x] **Negotiable** : Texte ("API ACCESS" vs "API"), placement dans le footer (section "DÉVELOPPEURS" vs section existante), visibilité mobile vs desktop — tous discutables
- [x] **Valuable** : Découvrabilité de l'API sans mémoriser l'URL — condition nécessaire à l'adoption organique de l'API par les développeurs tiers
- [x] **Estimable** : 2 pts — modification partielle de `_footer.html.twig` + test PHPUnit `assertSelectorExists`
- [x] **Sized** : 2 points ≤ 8 points
- [x] **Testable** : 5 scénarios Gherkin SMART couvrant affichage footer, navigation vers /developers, cohérence sur page article, régression CI, route 404 sur /developers non créée

---

## Vertical Slicing (couches traversées)

| Couche | Travail |
|--------|---------|
| **Symfony/Twig** | Modification de `templates/partials/_footer.html.twig` — ajout d'une section `<div class="footer-section footer-section--devs">` avec le lien `<a href="/developers">API ACCESS</a>` |
| **Symfony Router** | Vérification que la route `developers` (nommée `app_developers`) est publique dans `security.yaml` (access_control IS_AUTHENTICATED_ANONYMOUSLY) |
| **CSS (Tailwind / variables CSS)** | Classe `.footer-link` existante appliquée — hover : couleur émeraude `#10B981` — uppercase via classe existante |
| **PHPUnit / Twig Tests** | `FooterTest::testApiAccessLinkPresent()` — assertSelectorExists('footer a[href="/developers"]:contains("API ACCESS")') — exécuté sur les pages d'accueil, article et compte |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Lien "API ACCESS" visible dans le footer (visiteur non connecté)

```gherkin
Scenario: Un visiteur non authentifié consulte le pied de page de la page d'accueil
  GIVEN un visiteur non connecté accède à "/"
  WHEN il fait défiler jusqu'au pied de page
  THEN une section "DÉVELOPPEURS" est visible dans le footer
  AND un lien libellé exactement "API ACCESS" est présent dans cette section
  AND le lien est en texte majuscule, cohérent avec la typographie du footer existant
  AND le lien est cliquable et son attribut href vaut "/developers"
```

### Scénario alternatif 1 — Navigation vers la page Getting Started depuis le footer

```gherkin
Scenario: Marc clique sur "API ACCESS" dans le footer depuis la page d'accueil
  GIVEN Marc est sur la page "/" de Briefly AI
  WHEN Marc clique sur le lien "API ACCESS" dans le footer
  THEN il est redirigé vers "/developers" avec le code HTTP 200
  AND la page "/developers" s'affiche correctement avec le getting started, les examples de code et la table des quotas
  AND aucune erreur 404 ni 500 n'est générée
```

### Scénario alternatif 2 — Lien présent sur la page de lecture d'un article (layout global)

```gherkin
Scenario: Le footer est cohérent sur une page de lecture d'article
  GIVEN Marc est authentifié et lit un article à "/article/<uuid>"
  WHEN il fait défiler jusqu'au pied de page
  THEN le lien "API ACCESS" est présent dans le footer, identique à celui de la page d'accueil
  AND la section "DÉVELOPPEURS" est visible sans défilement horizontal sur un écran 1 280px
```

### Scénario d'erreur 1 — Régression détectée par le test automatisé PHPUnit

```gherkin
Scenario: Un développeur modifie _footer.html.twig et supprime accidentellement la section DÉVELOPPEURS
  GIVEN le test FooterTest::testApiAccessLinkPresent() est présent dans la suite CI
  WHEN le template _footer.html.twig est modifié et le lien "API ACCESS" disparaît
  THEN le test échoue avec le message :
       "Failed asserting that the Crawler has a node matching CSS 'footer a[href=\"/developers\"]'"
  AND le pipeline CI bloque le merge de la Pull Request
```

### Scénario d'erreur 2 — Route /developers inaccessible (404) détectée en CI

```gherkin
Scenario: La route /developers n'est pas configurée dans le routeur Symfony
  GIVEN la route "app_developers" n'est pas définie dans routes.yaml ou le controller correspondant
  WHEN le test d'intégration accède à "/developers"
  THEN la réponse HTTP est 404
  AND le test échoue avec "Expected 200 got 404 for GET /developers"
  AND la CI bloque le déploiement en staging
```
