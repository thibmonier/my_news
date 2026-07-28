# Definition of Done — Briefly AI

> Une User Story n'est DONE que lorsque TOUS les criteres suivants sont satisfaits.
> Aucune exception, aucun "presque" acceptable.

**Version** : 1.0.0 | **Applicable a partir de** : Sprint 001

---

## 1. Code

### Qualite generale
- [ ] Code ecrit, fonctionnel et deploye sur l'environnement de staging
- [ ] PSR-12 respecte : `php vendor/bin/php-cs-fixer fix --dry-run` = 0 diff
- [ ] PHPStan niveau max : `php vendor/bin/phpstan analyse` = 0 erreur
- [ ] Dart analyzer strict : `dart analyze` = 0 warning, 0 error
- [ ] SOLID + KISS + DRY + YAGNI respectes (valide en code review)
- [ ] Complexite cyclomatique < 10 par methode
- [ ] Methodes < 20 lignes
- [ ] Pas de code mort, pas de TODO non rattache a un ticket
- [ ] Pas de var_dump, dd(), console.log() oublies

### Architecture hexagonale (Symfony)
- [ ] Domaine (Entites, Value Objects, Interfaces) sans import d'infrastructure
- [ ] Application (Services, Handlers) depend d'interfaces, jamais d'implementations
- [ ] Infrastructure (Repositories Doctrine, Clients API, Mailers) implements les interfaces du domaine
- [ ] UUID v4 non sequentiels sur toutes les nouvelles entites persistees (cf ADR-006)

### Entites & base de donnees
- [ ] Migration Doctrine generee et reversible : `php bin/console doctrine:migrations:diff` = 0 diff apres migration
- [ ] Pas de modification de migration existante (nouvelle migration obligatoire)
- [ ] Index PostgreSQL explicites sur les colonnes de filtre / jointure
- [ ] Champs sensibles identifies et proteges (pas de mot de passe en clair)

---

## 2. Tests

### Couverture minimale
- [ ] Couverture de code globale >= 80% (lignes + branches)
- [ ] Couverture >= 90% sur les services de domaine (logique metier critique)

### Types de tests obligatoires
- [ ] **PHPUnit — Unit** : logique metier (Services, Value Objects, Domain Events)
- [ ] **PHPUnit — Integration** : Repositories Doctrine avec base de test
- [ ] **PHPUnit — ApiTestCase** : tous les endpoints API Platform (statuts HTTP + schemas JSON)
- [ ] **Flutter — Widget Tests** : chaque widget non-trivial
- [ ] **Flutter — Integration Tests** : parcours utilisateurs critiques (si applicable a l'US)

### Qualite des tests
- [ ] Format Arrange-Act-Assert respecte
- [ ] Noms de tests explicites : `test_should_<behavior>_when_<condition>`
- [ ] 0 test commente
- [ ] 0 test skip sans justification dans le commit
- [ ] Tests independants (pas d'ordre d'execution impose)
- [ ] Factories / Fixtures pour la creation de donnees de test

### CI
- [ ] Pipeline GitHub Actions verte sur la branche de la PR
- [ ] Pas de regression sur les tests existants

---

## 3. Securite — OWASP Top 10:2025

### Controle d'acces (OWASP #1)
- [ ] Voter Symfony sur chaque operation protegee (propriete + role)
- [ ] UUID non sequentiels comme identifiants publics (pas d'ID auto-increment expose)
- [ ] Validation SSRF sur toutes les URLs fournies par l'utilisateur (whitelist domaines)
- [ ] Principe du moindre privilege sur les requetes SQL (pas de SELECT * non justifie)

### Cryptographie (OWASP #2)
- [ ] Mots de passe haches avec Argon2id (memory=131072, time=3, parallelism=1)
- [ ] JAMAIS MD5, SHA1, bcrypt pour les nouveaux mots de passe
- [ ] JWT avec EdDSA (Ed25519) ou RS256 minimum (jamais HS256 avec secret faible)
- [ ] TLS 1.3 sur tous les endpoints (HSTS max-age >= 31536000)

### Injection (OWASP #3)
- [ ] Requetes parametrees uniquement (Doctrine Query Builder / DQL, pas de SQL brut concatene)
- [ ] Validation et sanitization des entrees utilisateur (Symfony Constraints)
- [ ] Echappement des sorties dans Twig (`{{ var }}` pas `{{ var|raw }}` sans justification)

### Configuration (OWASP #5)
- [ ] Headers securite presents sur toutes les reponses HTTP :
  - `Content-Security-Policy` (CSP Level 3)
  - `Strict-Transport-Security` (HSTS)
  - `X-Frame-Options: DENY`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy`
  - `Cross-Origin-Opener-Policy: same-origin`
  - `Cross-Origin-Embedder-Policy: require-corp`
- [ ] Messages d'erreur generiques en production (pas de stack trace exposee)
- [ ] Mode debug desactive en prod

### Supply Chain (OWASP #6)
- [ ] Pas de nouvelle dependance sans audit (licence + CVE)
- [ ] Versions pinned (pas de `^` ou `~` sans validation equipe)

### Authentification (OWASP #8)
- [ ] CSRF actif sur tous les formulaires Twig (`{{ csrf_token() }}`)
- [ ] Rate limiting Redis sur /login et /register (5 tentatives / 15 min / IP)
- [ ] Sessions : HttpOnly + Secure + SameSite=Strict
- [ ] Pas de secret, cle API ou credential dans le code source (variables d'environnement)

### Logging (OWASP #9)
- [ ] Evenements de securite loggues (tentatives de login, acces refuses, modifications sensibles)
- [ ] Jamais de mot de passe, token ou PII dans les logs

### IA & RGPD (specifique Briefly AI)
- [ ] **Assert CI bloquant** : 0 donnee personnelle (user_id, email, IP, nom) dans les prompts Mistral / OpenAI
- [ ] 0 identifiant utilisateur dans les cles Redis de quota (UUID uniquement)
- [ ] 0 identifiant utilisateur dans les messages Symfony Messenger

---

## 4. API Platform

- [ ] Serialization groups configures (`normalizationContext`, `denormalizationContext`)
- [ ] Validation Constraints sur toutes les proprietes exposees
- [ ] Pagination obligatoire sur les collections (curseur ou offset, max 50 items)
- [ ] Security expressions sur les operations (voters)
- [ ] OpenAPI generee sans erreur : `openapi-generator validate` = 0 erreur (CI bloquant)
- [ ] Endpoints internes exclus de l'OpenAPI publique (`openapi: false`)
- [ ] Pas de nested object inutile dans les reponses (denormaliseur strict)

---

## 5. Vertical Slice

### Fidélité design (US UI — cf ADR-011, INV-7)
- [ ] L'écran correspond à l'**écran Stitch référencé** (`design/screen-inventory.md`) — layout, composants, thèmes clair + sombre
- [ ] Valeurs de design issues UNIQUEMENT des tokens (`design/design-tokens.md` / `.css`) — **0 hex/taille/espacement en dur**
- [ ] Émeraude `#10B981` réservée à l'IA ; badge IA rayon 2px ; traçabilité IA non basée sur la seule couleur (texte + icône)
- [ ] **Lighthouse Accessibility ≥ 90** sur la page (CI/PR), en plus de WCAG 2.1 AA
- [ ] Aucun écran inventé hors Stitch (écran manquant → généré via Stitch d'abord)

### Web (Symfony UX)
- [ ] Controller Symfony → Service domaine → Repository → PostgreSQL
- [ ] Templates Twig respectant les tokens `design-tokens.css` (design system Insight Minimalist / Insight Dark)
- [ ] Turbo Frames / Turbo Streams fonctionnels pour les mises a jour partielles
- [ ] Stimulus Controller si interaction JS requise
- [ ] Responsive (mobile-first, viewport 320px — 1440px)
- [ ] WCAG 2.1 AA : contraste, attributs ARIA, navigation clavier

### Mobile Flutter (si applicable a l'US)
- [ ] Modele Dart + Repository + Bloc/Riverpod + Screen implemntes
- [ ] iOS 15+ et Android 10+ (cibles minimales)
- [ ] Material 3 + accent emeraude #10B981 pour les elements IA
- [ ] 60 FPS : pas de jank mesure sur appareil physique
- [ ] `dart analyze` strict : 0 avertissement
- [ ] Widget tests passants

### Parite Web / Mobile (si US couvre les deux)
- [ ] Meme comportement metier (memes regles, memes erreurs)
- [ ] Meme API Platform consommee
- [ ] Donnees synchronisees (pas de delta de 24h entre les deux)

---

## 6. RGPD

- [ ] Consentement explicite horodate collecte avant toute donnee personnelle
- [ ] Droit a l'oubli : cascade `ON DELETE CASCADE` sur toutes les FK utilisateur
- [ ] Portabilite JSON documentee et testee (si applicable)
- [ ] Log RGPD pour les operations sensibles (modification email, suppression compte, export)
- [ ] 0 donnee personnelle dans les prompts IA (assert CI bloquant, voir section 3)
- [ ] Retention des donnees definie et respectee (pas de donnees indefinies)
- [ ] 0 FK vers `users` dans les tables d'analytics (pseudonymisation HMAC-SHA256)

---

## 7. i18n

- [ ] `symfony/translation` ICU utilise : pas de chaine hardcodee en PHP ou Twig
- [ ] Toute nouvelle chaine UI ajoutee dans `translations/messages+intl-icu.en.yaml` (EN reference)
- [ ] Traduction francaise fournie dans `translations/messages+intl-icu.fr.yaml`
- [ ] Pluralisation ICU respectee (pas de `if count > 1` dans les templates)
- [ ] Dates et heures formatees avec `IntlDateFormatter` (pas de `date()` brute)

---

## 8. Documentation

- [ ] PHPDoc sur tous les services, interfaces et Value Objects publics
- [ ] DartDoc sur toutes les classes et methodes publiques Flutter
- [ ] `CHANGELOG.md` mis a jour avec la feature ou le fix
- [ ] ADR cree si decision architecturale majeure prise pendant l'US
- [ ] Criteres d'acceptance Gherkin de l'US passes en revue et marques valides

---

## 9. Performance

- [ ] API Platform endpoints : temps de reponse P95 < 200ms (hors appels IA externes)
- [ ] Pas de N+1 queries Doctrine (utilisation de `JOIN FETCH` ou `addSelect`)
- [ ] Index PostgreSQL verifie sur les colonnes de filtre des nouvelles requetes
- [ ] Cache Redis utilise sur les donnees couteuses (TTL defini)
- [ ] Assets Symfony UX : CSS/JS bundles < 50 Ko gzip par page

---

## 10. Code Review

- [ ] >= 1 approbation par un pair (dev different de l'auteur)
- [ ] Pas de commentaire "Request Changes" ouvert non resolu
- [ ] Checklist PR completee par l'auteur avant la review
- [ ] Self-review effectuee (auteur a relu son propre diff)
- [ ] Branche a jour avec `main` (rebase ou merge)

---

## 11. CI/CD (obligatoire pour merger)

- [ ] `php vendor/bin/php-cs-fixer fix --dry-run` : 0 diff
- [ ] `php vendor/bin/phpstan analyse` : 0 erreur
- [ ] `php bin/phpunit --coverage-min 80` : passe
- [ ] `dart analyze` : 0 warning
- [ ] `flutter test` : 100% passing
- [ ] `openapi-generator validate` : 0 erreur (si modification API)
- [ ] Assert 0 PII dans les prompts IA : passe (CI bloquant)
- [ ] Pipeline GitHub Actions complete verte

---

## Checklist Rapide (affichage PR)

```
## DoD Checklist

### Code
- [ ] PSR-12 / PHPStan max / Dart analyzer : 0 erreur
- [ ] Architecture hexagonale respectee
- [ ] UUID v4 sur les nouvelles entites

### Tests
- [ ] Couverture >= 80%
- [ ] PHPUnit + Flutter tests passants
- [ ] CI verte

### Securite
- [ ] Voters Symfony / CSRF / Rate limit
- [ ] Headers OWASP presents
- [ ] 0 PII dans les prompts IA (assert CI bloquant)

### Qualite
- [ ] Vertical slice complet (web + mobile si applicable)
- [ ] OpenAPI valide
- [ ] i18n : 0 chaine hardcodee

### RGPD
- [ ] Consentement / cascade FK / log sensible

### Review
- [ ] >= 1 approbation pair
- [ ] 0 commentaire bloquant ouvert
```

---

**Derniere mise a jour** : 2026-07-28
**Auteur** : Tech Lead (CSM) — The Bearded CTO
**Version** : 1.0.0
