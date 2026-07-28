# US-060 : Consulter et supprimer l'historique de lecture

## En-tête

| Champ | Valeur |
|-------|--------|
| **ID** | US-060 |
| **EPIC parent** | EPIC-007 — Privacy & On-Device AI |
| **Persona** | P-003 Marc (dev indépendant, privacy-first) |
| **Story points** | 5 (Fibonacci) |
| **Priorité** | Should have (MoSCoW) |
| **Sprint** | backlog |

---

## User Story (3 C)

### Carte

**En tant que** P-003 : Marc, développeur indépendant privacy-first,
**Je veux** consulter l'intégralité de mon historique de lecture et le supprimer définitivement depuis les paramètres de mon compte,
**Afin de** maîtriser les données que Briefly conserve sur moi et exercer mon droit à l'effacement sans passer par le support (RGPD Art. 17).

---

### Conversation

- L'historique comprend : articles lus (titre, source, date/heure, durée estimée), synthèses consultées (niveau choisi), recherches effectuées.
- **Suppression partielle en v1 ?** Non — suppression globale uniquement en v1 (période ou article par article reportée en v2).
- **Cascade BD ?** Oui — suppression en cascade PostgreSQL sur `reading_history`, `search_history`, entrées associées dans `favorites` ; trigger `audit_log` RGPD créé avec timestamp et user_id hashé.
- **Délai de grâce (corbeille 30 j) ?** Non — suppression immédiate et irréversible avec confirmation obligatoire.
- **Les données analytiques pseudonymisées sont-elles supprimées ?** Non — elles sont techniquement anonymes après pseudonymisation (voir US-065) ; l'utilisateur en est informé.
- **Disponibilité** : web (Twig/Turbo) ET mobile Flutter simultanément.
- **API** : rate limit `DELETE /api/me/history` à 3 appels/heure/utilisateur pour éviter les suppressions répétées accidentelles.

---

### Validation INVEST

- [x] **Independent** — indépendante de l'export JSON (US-061) et des réglages granulaires (US-063)
- [x] **Negotiable** — périmètre suppression partielle reporté en v2 ; contenu exact de l'historique ajustable
- [x] **Valuable** — droit RGPD fondamental, différenciateur confiance, réduction de l'appréhension des utilisateurs privacy-first
- [x] **Estimable** — 5 pts : UI web + UI Flutter + endpoint API Platform + cascade PostgreSQL + log RGPD
- [x] **Sized** — ≤ 8 pts
- [x] **Testable** — critères Gherkin SMART mesurables

---

## Vertical Slicing (couches traversées)

| Couche | Composant | Détail |
|--------|-----------|--------|
| **PostgreSQL** | Table `reading_history` | `user_id UUID FK, article_id UUID FK, read_at TIMESTAMPTZ, synthesis_level VARCHAR(20), read_duration_seconds INT` ; index sur `user_id` ; `ON DELETE CASCADE` depuis `users` |
| **PostgreSQL** | Table `audit_log_rgpd` | `action VARCHAR(50), user_id_hash VARCHAR(64), occurred_at TIMESTAMPTZ, metadata JSONB` |
| **API Platform** | `GET /api/me/history` | Paginé (20/page), JSON-LD, filtres date ; voter `HistoryVoter` (OWNER uniquement) |
| **API Platform** | `DELETE /api/me/history` | Voter ownership + rate limit Redis 3/h/user ; déclenche cascade + log RGPD ; retourne `{ deleted_count: N }` |
| **Symfony/Twig + Turbo** | `/account/privacy/history` | Liste paginée (Turbo Frame scroll infini) ; modale confirmation Stimulus avant suppression |
| **Flutter** | `PrivacyHistoryScreen` | Liste `ListView.builder` ; `AlertDialog` confirmation ; état post-suppression (page vide) |
| **Sécurité OWASP** | `HistoryVoter` | Vérifie `$user->getId() === $requestedUserId` ; UUID non séquentiel ; log tentative non autorisée |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal 01 — Consultation de l'historique paginé

```gherkin
Scenario: P-003 consulte son historique de lecture sur le web
  GIVEN Marc est authentifié et a lu 35 articles dans les 30 derniers jours
  WHEN il accède à Paramètres > Confidentialité > Historique de lecture
  THEN il voit la première page de 20 articles triés par date décroissante
  AND chaque entrée affiche : titre de l'article, source, date/heure de lecture, synthèse consultée (oui/non)
  AND un contrôle de pagination "Page suivante" permet d'accéder aux 15 articles restants
  AND le bouton "Supprimer tout l'historique" est actif
```

### Scénario alternatif 02 — Historique vide

```gherkin
Scenario: Historique vide pour un nouvel utilisateur
  GIVEN Marc est un utilisateur inscrit depuis 2 jours sans article lu
  WHEN il accède à Paramètres > Confidentialité > Historique de lecture
  THEN il voit un état vide avec le message "Aucun article lu pour le moment"
  AND le bouton "Supprimer tout l'historique" est désactivé (attribut disabled)
  AND aucune requête GET /api/me/history paginée n'est émise (optimisation)
```

### Scénario alternatif 03 — Confirmation de suppression avec décompte

```gherkin
Scenario: P-003 initie la suppression de son historique
  GIVEN Marc a un historique de 35 articles et 12 synthèses consultées
  WHEN il clique sur "Supprimer tout l'historique"
  THEN une modale de confirmation s'affiche avec :
    - "Cette action est irréversible"
    - "35 articles et 12 synthèses seront supprimés"
    - Note : "Vos données analytiques anonymisées ne sont pas affectées"
  AND deux boutons sont présents : "Annuler" (ferme la modale) et "Confirmer la suppression"
  WHEN Marc clique "Confirmer la suppression"
  THEN DELETE /api/me/history est appelé
  AND la liste s'affiche vide avec un message de confirmation "Votre historique a été supprimé"
  AND un log RGPD "history_deleted" est créé en base avec timestamp et user_id_hash
```

### Scénario d'erreur 04 — Erreur réseau pendant la suppression

```gherkin
Scenario: Erreur réseau pendant la suppression de l'historique
  GIVEN Marc confirme la suppression de son historique
  WHEN la requête DELETE /api/me/history reçoit une réponse HTTP 503
  THEN un message "Suppression temporairement indisponible, veuillez réessayer" s'affiche
  AND l'historique reste intact (aucune suppression partielle — la transaction PostgreSQL est atomique)
  AND le bouton "Supprimer tout l'historique" est réactivé immédiatement
  AND aucun log RGPD de suppression n'est créé
```

### Scénario d'erreur 05 — Accès non autorisé à l'historique d'un autre compte

```gherkin
Scenario: Tentative d'accès à l'historique d'un autre utilisateur
  GIVEN un utilisateur malveillant envoie GET /api/me/history avec un JWT valide mais tente d'injecter l'UUID d'un autre compte via un header ou paramètre
  WHEN le voter HistoryVoter évalue la requête
  THEN l'API retourne HTTP 403 Forbidden avec body { "error": "Access denied" }
  AND aucune donnée de l'autre utilisateur n'est exposée dans la réponse
  AND un log de sécurité est créé avec l'IP, le user_id du JWT et l'UUID cible
```
