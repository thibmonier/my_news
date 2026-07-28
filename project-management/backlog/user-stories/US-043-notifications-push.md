# US-043 : Notifications push Daily Brief (FCM/APNs)

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-043 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-001 — Thomas, cadre dirigeant tech |
| **Story Points** | 5 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-001 Thomas, cadre dirigeant tech,
**Je veux** recevoir une notification push unique par jour quand mon Daily Brief est disponible, à une heure que je définis, avec possibilité de désactiver depuis les paramètres,
**Afin de** ne jamais manquer mon briefing matinal sans être submergé par des alertes superflues tout au long de la journée.

---

## Les 3 C

### Carte (résumé)

Intégration FCM (Android) + APNs (iOS) via `firebase_messaging`. Au premier lancement (post-authentification), demande de permission via dialog explicatif RGPD. Token push enregistré côté serveur (`POST /api/push-tokens`). Notification quotidienne déclenchée par Symfony Scheduler après génération du Daily Brief (1 push/jour/utilisateur maximum). Tap sur la notification → deep link GoRouter `/brief/today`. Opt-out disponible dans l'écran Compte. Heure de notification configurable (défaut : 07:30 heure locale utilisateur).

### Conversation

- L'heure de notification est-elle configurable au sein de l'application ou seulement côté back ? (configurable dans l'app, persistée via `PATCH /api/notification-preferences`)
- Doit-on envoyer la notification même si l'utilisateur a déjà ouvert le brief ? (v1 : oui, pas de tracking d'ouverture)
- Le contenu de la notification (rich notification) doit-il inclure les titres des 3 histoires ? (oui : titre + 1 ligne de description de la première histoire)
- Breaking news push en v1 ? (non, explicitement hors périmètre v1)
- Que se passe-t-il si l'utilisateur n'a pas ouvert l'app depuis 30 jours ? (v1 : notification toujours envoyée)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `firebase_messaging`, `flutter_local_notifications` | Réception push, affichage local, deep link |
| **Flutter** | GoRouter redirect sur `/brief/today` | Navigation depuis notification |
| **Flutter** | `NotificationPreferencesScreen` | Opt-in/out + heure de notification |
| **API Platform** | `POST /api/push-tokens` | Enregistrement token FCM/APNs |
| **API Platform** | `DELETE /api/push-tokens/{token}` | Désabonnement (opt-out) |
| **API Platform** | `PATCH /api/notification-preferences` | Heure de notification |
| **Symfony Scheduler** | `DailyBriefNotificationTask` | Déclenchement 1 push/jour après génération |
| **Symfony Messenger** | `SendPushNotificationHandler` | Envoi asynchrone via Firebase Admin SDK |
| **RGPD** | Consentement explicite avant activation, dialog d'explication | CNIL, EULA Apple/Google |
| **Sécurité** | Token push stocké chiffré, associé au compte (pas anonyme) | OWASP M9 - Insecure Data Storage |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Réception et ouverture de la notification Daily Brief

```gherkin
Scenario: Recevoir la notification Daily Brief et accéder au brief via tap
  GIVEN l'utilisateur a accordé la permission de notifications lors de l'onboarding
    AND son token FCM/APNs est enregistré côté serveur (POST /api/push-tokens -> HTTP 201)
    AND le Daily Brief du jour vient d'être généré (07:30 heure locale utilisateur)
  WHEN le Symfony Scheduler déclenche l'envoi de la notification
  THEN l'utilisateur reçoit une notification avec :
    - Titre : "Votre Daily Brief est prêt"
    - Corps : "01 — [titre de la première histoire]"
    - Icône : logo Briefly AI
  WHEN l'utilisateur tape sur la notification (app en background ou fermée)
  THEN l'app s'ouvre et navigue directement vers l'écran Daily Brief (deep link /brief/today)
    AND le Daily Brief du jour est affiché avec les 3 histoires
```

### Scénario alternatif 1 — Notification reçue avec l'app en arrière-plan (iOS)

```gherkin
Scenario: Notification affichée dans le centre de notifications iOS (app en background)
  GIVEN l'utilisateur a l'app Briefly en arrière-plan (non fermée)
    AND le Daily Brief est disponible
  WHEN la notification push arrive depuis APNs
  THEN la notification s'affiche dans le centre de notifications iOS sans ouvrir l'app
    AND le badge de l'icône Briefly est incrémenté à 1
  WHEN l'utilisateur swipe la notification
  THEN l'app passe au premier plan et navigue vers /brief/today
    AND le badge est remis à 0
```

### Scénario alternatif 2 — Désactivation des notifications depuis l'écran Compte

```gherkin
Scenario: Opt-out des notifications depuis les paramètres de l'app
  GIVEN l'utilisateur est sur l'écran Compte, section "Notifications"
    AND les notifications Daily Brief sont activées
  WHEN l'utilisateur bascule le toggle "Daily Brief" sur OFF
  THEN DELETE /api/push-tokens/{token} est appelé (HTTP 204)
    AND aucune notification push n'est envoyée à cet appareil les jours suivants
    AND le toggle reste sur OFF après fermeture et réouverture de l'app
    AND un lien "Réactiver les notifications" reste disponible dans les paramètres
```

### Scénario d'erreur 1 — Token FCM invalide ou expiré

```gherkin
Scenario: Renouvellement automatique du token push invalide
  GIVEN le token FCM de l'utilisateur est devenu invalide (réinstallation de l'app)
  WHEN firebase_messaging détecte un token refreshed (onTokenRefresh callback)
  THEN l'app appelle automatiquement POST /api/push-tokens avec le nouveau token (HTTP 201)
    AND l'ancien token est supprimé côté serveur
    AND la prochaine notification est correctement reçue avec le nouveau token
    AND aucune action n'est requise de l'utilisateur
```

### Scénario d'erreur 2 — Permission de notifications refusée par l'utilisateur (iOS)

```gherkin
Scenario: Gestion du refus de permission de notifications
  GIVEN l'utilisateur est au premier lancement de l'app (post-connexion)
  WHEN le dialog de demande de permission iOS s'affiche
    AND l'utilisateur tape "Ne pas autoriser"
  THEN aucun token push n'est enregistré côté serveur
    AND l'app continue à fonctionner normalement (sans notifications)
    AND dans l'écran Compte, section Notifications, un message explicatif s'affiche :
      "Notifications désactivées. Activez-les dans les réglages iOS > Briefly AI."
    AND un bouton "Ouvrir les réglages" redirige vers les réglages iOS de l'application
    AND la limite de 1 push/jour s'applique dès que les notifications sont réactivées
```

---

## Estimation

- **Story Points** : 5
- **MoSCoW** : Should Have
- **Complexité** : FCM + APNs intégration cross-platform + Symfony Scheduler + RGPD consentement

## Validation INVEST

- [x] **Independent** : Dépend de US-040 (navigation) et EPIC-003 (Daily Brief généré) mais découpage vertical valide
- [x] **Negotiable** : Heure par défaut, richesse du contenu, gestion 30 jours inactifs discutables
- [x] **Valuable** : Engagement quotidien et rétention (Thomas ne manque jamais son briefing)
- [x] **Estimable** : 5 points, intégration FCM/APNs connue + Symfony Scheduler
- [x] **Sized** : 5 points <= 8 pts
- [x] **Testable** : Tests unitaires SendPushNotificationHandler, tests intégration token registration, tests E2E deep link
