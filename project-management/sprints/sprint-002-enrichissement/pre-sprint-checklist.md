# Pre-Sprint Checklist — Sprint 002 Enrichissement

> À compléter avant le Sprint Planning (2026-08-11).
> Chaque point doit être coché par un responsable désigné.

---

## 1. Credentials OAuth

### Google OAuth2

- [ ] Console GCP : projet « Briefly AI dev » créé ou identifié
- [ ] OAuth 2.0 Client ID (type « Application Web ») créé
- [ ] Redirect URI enregistrée : `https://localhost/oauth/google/callback` (ou équivalent dev)
- [ ] `OAUTH_GOOGLE_CLIENT_ID` disponible dans `.env.local` (hors dépôt git)
- [ ] `OAUTH_GOOGLE_CLIENT_SECRET` disponible dans `.env.local` (hors dépôt git)
- [ ] Test manuel de connexion Google réussi en local

### GitHub OAuth App

- [ ] GitHub App (OAuth) créée sur le compte organisation ou personnel de dev
- [ ] Homepage URL et Authorization callback URL renseignées
- [ ] `OAUTH_GITHUB_CLIENT_ID` disponible dans `.env.local`
- [ ] `OAUTH_GITHUB_CLIENT_SECRET` disponible dans `.env.local`
- [ ] Test manuel de connexion GitHub réussi en local

---

## 2. Clé Mistral (condensés IA — US-004 / US-011)

- [ ] Clé API Mistral dédiée à l'environnement de dev générée (distincte de prod)
- [ ] `MISTRAL_API_KEY` disponible dans `.env.local`
- [ ] `MISTRAL_BASE_URL` pointant sur l'endpoint EU (conformité RGPD INV-6)
- [ ] Quota Mistral dev vérifié : suffisant pour 50+ appels/jour en dev
- [ ] `OPENAI_API_KEY` disponible comme fallback (`SUMMARY_FALLBACK_PROVIDER=openai`)
- [ ] Mock `MistralApiClient` configuré dans `phpunit.xml.dist` pour les tests CI (aucun appel réseau réel en CI)

---

## 3. Comptes de test

### Admin (P-004 Sophie — US-021)

- [ ] Compte utilisateur `ROLE_ADMIN` créé en base de dev (via fixture ou commande console)
- [ ] Email et mot de passe temporaire documentés dans le wiki d'équipe (hors dépôt git)
- [ ] Accès back-office vérifié : `/admin/sources` accessible avec ce compte

### Utilisateur nominal (P-001 Thomas — tests OAuth)

- [ ] Compte Google de test (adresse `@gmail.com` dédié au projet) créé ou identifié
- [ ] Compte GitHub de test créé ou identifié
- [ ] Fixtures `UserFactory` à jour pour simuler un utilisateur OAuth sans appel réseau

---

## 4. Infrastructure locale

- [ ] `docker compose up -d` : services pg, redis, mailer-stub démarrés sans erreur
- [ ] Redis accessible : `redis-cli ping` → PONG
- [ ] PostgreSQL migrations Sprint 1 à jour (`bin/console doctrine:migrations:migrate --no-interaction`)
- [ ] Variables d'environnement Sprint 2 ajoutées au `.env.local.dist` (valeurs de placeholder)
- [ ] CI GitHub Actions : secrets `OAUTH_GOOGLE_CLIENT_ID`, `OAUTH_GOOGLE_CLIENT_SECRET`, `OAUTH_GITHUB_CLIENT_ID`, `OAUTH_GITHUB_CLIENT_SECRET`, `MISTRAL_API_KEY` ajoutés dans les Settings du dépôt (ou mockés en CI)

---

## 5. Vérification Stitch (UI — INV-7 / ADR-011)

- [ ] Accès au projet Stitch `7076573032400883843` vérifié pour tous les membres de l'équipe
- [ ] Écrans référencés pour Sprint 2 identifiés : brief enrichi (US-004/005), sélecteur de niveau (US-011), login OAuth (US-031), back-office sources (US-021)
- [ ] Tokens de design (`design/design-tokens.md`) à jour et synchronisés depuis Stitch

---

## 6. Validation DoD Sprint 1 (prérequis)

- [ ] Toutes les US Sprint 1 marquées DONE (ou décision explicite du PO pour les US reportées)
- [ ] CI Sprint 1 verte sur la branche `main`
- [ ] Pas de dette technique bloquante identifiée en rétro Sprint 1 à traiter avant Sprint 2

---

## Responsabilités

| Tâche | Responsable | Échéance |
|-------|-------------|----------|
| Credentials OAuth Google | Dev backend | 2026-08-10 |
| Credentials OAuth GitHub | Dev backend | 2026-08-10 |
| Clé Mistral dev + fallback OpenAI | Tech Lead | 2026-08-10 |
| Compte admin test (ROLE_ADMIN) | Dev backend | 2026-08-10 |
| Vérification Stitch accès + tokens | Dev frontend | 2026-08-10 |
| Validation DoD Sprint 1 complète | Tech Lead (SM) | 2026-08-10 |
