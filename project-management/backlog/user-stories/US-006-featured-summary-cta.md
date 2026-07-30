# US-006 : Featured Summary desktop + CTA "Lire le brief complet"

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-006 |
| **EPIC parent** | EPIC-001 Daily Brief Core |
| **Persona** | P-001 Thomas — cadre dirigeant tech, 38 ans |
| **Story Points** | 5 (Fibonacci) |
| **Priorité** | Should Have (MoSCoW) |
| **Sprint** | sprint-003-consolidation |

## User Story (3 C)

### Carte

**En tant que** P-001 Thomas,
**Je veux** voir une synthèse narrative "Featured Summary" du Daily Brief en haut de la page (desktop uniquement), avec un CTA "Lire le brief complet" visible en permanence,
**Afin d'** obtenir en 2 minutes une vue d'ensemble éditoriale du brief avant de décider quelles histoires approfondir, et pouvoir accéder rapidement au détail complet.

### Conversation

- Le Featured Summary est-il une synthèse des 3 histoires ou un texte éditorial indépendant ? Décision : synthèse narrative produite par Mistral à partir des 3 histoires sélectionnées du jour — un seul appel IA agrégé (prompt multi-articles).
- Sur mobile, le Featured Summary est-il masqué ou réduit ? Décision : masqué sur mobile (viewport < 768px) — le brief mobile est d'abord les 3 histoires individuelles. Le Featured Summary est un différenciant desktop.
- Le CTA "Lire le brief complet" renvoie vers quoi ? Décision : ancre vers la section des 3 histoires sur la même page `/brief` (pas de navigation externe). Sticky en desktop (position: sticky top: 0 dans la barre de navigation).
- Quelle longueur pour la synthèse narrative ? Décision : 80 à 120 mots, ton éditorial, premier paragraphe narratif (pas de liste à puces — réservé aux condensés d'articles US-004).
- Le Featured Summary est-il pré-généré (batch 5h UTC) ou à la demande ? Décision : pré-généré en même temps que les condensés d'articles (même batch, message Messenger enrichi), stocké en base.
- Le badge "BRIEFLY AI:" s'applique-t-il aussi au Featured Summary ? Décision : oui — tout contenu IA porte l'accent émeraude #10B981 et le préfixe "BRIEFLY AI:".
- Fallback si Mistral est indisponible pour la synthèse globale ? Décision : afficher un texte généré localement "Voici les 3 histoires majeures du [date]." sans badge IA.

### Validation INVEST

- [x] **I**ndependent : dépend de US-004 (condensés IA en base) pour réutiliser le contexte des articles, mais techniquement indépendant (prompt séparé)
- [x] **N**egotiable : longueur (80-120 mots), ton (éditorial vs informatif), position du CTA (sticky vs statique)
- [x] **V**aluable : le Featured Summary est le "hook" qui accroche P-001 Thomas dès l'ouverture de l'application — différenciant premium fort
- [x] **E**stimable : pattern similaire à US-004 (Mistral + cache Redis + Twig), estimé 5 pts (complexité CSS sticky + responsive)
- [x] **S**ized : 5 pts, inférieur au seuil de 8 pts
- [x] **T**estable : critères visuels (desktop vs mobile), longueur du texte, badge présent, CTA fonctionnel

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **Domain Service** | `FeaturedSummaryService` | Compose un prompt multi-articles → appel Mistral → retour `FeaturedSummaryDTO` (text, model_version, generated_at) |
| **AI Client** | `MistralSummaryClient` (réutilisé) | Prompt agrégé : titre + extrait des 3 articles ; aucun identifiant utilisateur dans le prompt |
| **Cache** | Redis | Clé `briefly:featured_summary:{date}`, TTL 24h |
| **Base de données** | PostgreSQL | Table `daily_brief_summaries` (id UUID, brief_id FK UNIQUE, content TEXT, model_version VARCHAR, generated_at TIMESTAMP) |
| **Messenger Handler** | `GenerateDailyBriefHandler` (enrichi) | Après sélection US-002 et condensés US-004 : génère aussi le Featured Summary |
| **API Platform** | `GET /api/brief/today` | Champ `featured_summary` dans la réponse JSON:API |
| **Controller** | `BriefController::index()` | Charge `FeaturedSummaryService::getForToday()` et l'injecte dans le contexte Twig |
| **Template Twig** | `brief/index.html.twig` | Section `<section class="featured-summary hidden-mobile">` + badge "BRIEFLY AI:" émeraude + texte narratif + CTA button |
| **CSS/Responsive** | Tailwind / Stimulus | `@media (max-width: 768px) { .featured-summary { display: none; } }` + CTA sticky desktop |
| **RGPD** | Isolation prompts | Assert : aucun identifiant utilisateur dans le payload Mistral (test unitaire CI) |
| **OWASP** | Output escaping | Contenu Mistral rendu via `{{ summary | e }}` (Twig auto-escape) |

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Featured Summary affiché en desktop avec CTA

```gherkin
Scenario: Affichage du Featured Summary sur desktop avec badge IA et CTA
  GIVEN un DailyBrief avec status "ready" et un featured_summary associé existent pour la date du jour
  WHEN Thomas accède à /brief depuis un navigateur desktop (viewport ≥ 1024px)
  THEN une section "BRIEFLY AI:" avec l'accent émeraude #10B981 est visible en haut de page
  AND la synthèse narrative contient entre 80 et 120 mots (comptage côté serveur à la génération)
  AND le contenu est affiché en police de corps (pas de liste à puces)
  AND un bouton CTA "Lire le brief complet" est présent et renvoie via ancre (#brief-stories) vers les 3 histoires
  AND le CTA reste visible en sticky lors du scroll jusqu'à la section des histoires
```

### Scénario alternatif 1 — Featured Summary masqué sur mobile

```gherkin
Scenario: Absence du Featured Summary sur mobile (viewport < 768px)
  GIVEN un DailyBrief avec featured_summary existe pour le jour
  WHEN Thomas accède à /brief depuis son smartphone (viewport = 375px)
  THEN la section "BRIEFLY AI:" n'est pas visible (display: none en CSS)
  AND les 3 histoires individuelles (US-001) sont affichées directement sans section introductive
  AND le test Panther avec viewport mobile `assertSelectorNotExists('.featured-summary:visible')` passe
```

### Scénario alternatif 2 — Fallback texte générique si Mistral indisponible à la génération batch

```gherkin
Scenario: Fallback vers texte générique quand Mistral échoue pendant le batch 5h UTC
  GIVEN FeaturedSummaryService reçoit une exception Mistral pendant le batch
  WHEN le handler tente de générer le Featured Summary
  THEN un texte de fallback est persisté : "Voici les 3 histoires majeures du {date}."
  AND ce texte est affiché sans badge "BRIEFLY AI:" (pas de préfixe émeraude)
  AND un log WARNING `{"event": "featured_summary.fallback_used", "date": "YYYY-MM-DD"}` est enregistré
  AND la page s'affiche normalement avec les 3 histoires (pas de blocage)
```

### Scénario d'erreur 1 — Violation RGPD dans le prompt Featured Summary (test de non-régression)

```gherkin
Scenario: Garantie RGPD — aucun identifiant personnel dans le prompt Featured Summary
  GIVEN le batch de 5h UTC génère le Featured Summary
  WHEN FeaturedSummaryService compose le prompt pour Mistral
  THEN le payload JSON ne contient aucun champ user_id, session_id, email ou ip
  AND le test unitaire `FeaturedSummaryServiceTest::testPromptContainsNoPersonalData()` passe en CI
  AND seuls les champs article.title, article.excerpt, brief.date apparaissent dans le payload loggé
```

### Scénario d'erreur 2 — CTA "Lire le brief complet" brisé (ancre inexistante)

```gherkin
Scenario: CTA pointe vers une ancre absente du DOM (régression de template)
  GIVEN un déploiement modifie l'id de la section des histoires (renommage)
  WHEN Thomas clique sur "Lire le brief complet"
  THEN le navigateur ne navigue pas vers la mauvaise ancre (test Panther)
  AND le test E2E `assertSelectorExists('#brief-stories')` échoue en CI → la PR est bloquée
  AND le CTA est traité comme une ancre invalide si l'id disparaît, restant quand même cliquable sans effet visible
```
