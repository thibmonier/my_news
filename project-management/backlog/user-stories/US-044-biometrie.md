# US-044 : Authentification biométrique (Face ID / Touch ID)

## Métadonnées

| Champ | Valeur |
|-------|--------|
| **ID** | US-044 |
| **EPIC parent** | EPIC-005 — Expérience Mobile Native |
| **Persona** | P-003 — Marc, développeur indépendant privacy-first |
| **Story Points** | 3 |
| **Priorité MoSCoW** | Should Have |
| **Sprint** | backlog |
| **Statut** | To Do |

---

## User Story — Carte

**En tant que** P-003 Marc, développeur indépendant privacy-first,
**Je veux** déverrouiller l'accès à l'application via Face ID ou Touch ID (avec fallback PIN) sans que mes données biométriques ne quittent jamais mon appareil,
**Afin de** protéger mes lectures sensibles contre un accès non autorisé, sans friction à chaque ouverture de l'app.

---

## Les 3 C

### Carte (résumé)

Après authentification initiale (EPIC-002), le refresh token JWT est chiffré et stocké dans `flutter_secure_storage` (iOS Keychain / Android Keystore). À chaque réouverture de l'app (foreground), si la biométrie est activée, `local_auth` demande Face ID / Touch ID. En cas de succès : déchiffrement du refresh token + appel silencieux `POST /api/token/refresh`. Fallback sur PIN applicatif en cas d'échec biométrique (≥ 2 tentatives). Aucune donnée biométrique ne transite par le réseau. Activation/désactivation dans l'écran Compte > Sécurité.

### Conversation

- Le PIN applicatif doit-il être distinct du PIN système de l'appareil ? (v1 : PIN système via `local_auth` authenticateWithBiometrics + deviceCredentials fallback)
- Doit-on désactiver la biométrie si l'utilisateur change d'empreinte sur le téléphone ? (oui : détection via `local_auth.getAvailableBiometrics()` au démarrage, demande re-authentification)
- Combien de tentatives biométriques avant verrouillage temporaire ? (confié au système OS, pas de logique applicative supplémentaire)
- La biométrie est-elle obligatoire ou optionnelle ? (strictement optionnelle, opt-in depuis les paramètres)
- Que se passe-t-il si l'appareil n'a pas de capteur biométrique ? (option masquée dans les paramètres, message explicatif)

### Confirmation (Critères d'acceptance)

Voir section Gherkin ci-dessous.

---

## Vertical Slicing

| Couche | Composant | Responsabilité |
|--------|-----------|----------------|
| **Flutter** | `local_auth` | Face ID / Touch ID / Android Biometric prompt |
| **Flutter** | `flutter_secure_storage` | Stockage chiffré du refresh token (iOS Keychain / Android Keystore) |
| **Flutter** | `BiometricLockScreen` | Écran de déverrouillage (si biométrie activée) |
| **Flutter** | `SecuritySettingsScreen` | Activation/désactivation biométrie |
| **API Platform** | `POST /api/token/refresh` | Renouvellement silencieux de l'access token |
| **Sécurité OWASP M1** | Jamais de données biométriques transmises au serveur | Improper Credential Usage |
| **Sécurité OWASP M2** | Clé Keychain/Keystore protégée par biométrie (`isProtectedByBiometricAuth`) | Inadequate Supply Chain Security |
| **RGPD** | Consentement explicite opt-in, explication on-device dans les paramètres | Traitement biométrique - donnée sensible Art. 9 RGPD |

---

## Critères d'acceptance (Gherkin SMART)

### Scénario nominal — Déverrouillage de l'app avec Face ID (iOS)

```gherkin
Scenario: Déverrouiller l'application avec Face ID après mise en arrière-plan
  GIVEN l'utilisateur a activé la biométrie dans Compte > Sécurité
    AND le refresh token est stocké chiffré dans flutter_secure_storage (iOS Keychain)
    AND l'app est revenue au premier plan après plus de 30 secondes en arrière-plan
  WHEN l'app présente l'écran de déverrouillage biométrique
    AND l'utilisateur regarde l'écran (Face ID)
  THEN la reconnaissance biométrique réussit en moins de 500 ms
    AND le refresh token est déchiffré depuis le Keychain
    AND POST /api/token/refresh est appelé silencieusement (HTTP 200, nouvel access token)
    AND l'utilisateur accède directement à l'onglet Flux sans écran de connexion
    AND aucune donnée biométrique n'a transité par le réseau (vérifiable : 0 appel réseau lié à la biométrie)
```

### Scénario alternatif 1 — Fallback sur credentials système si biométrie échoue

```gherkin
Scenario: Fallback sur PIN/code système après échec biométrique
  GIVEN la biométrie est activée dans les paramètres
    AND l'utilisateur est sur l'écran de déverrouillage biométrique
  WHEN la reconnaissance biométrique échoue (visage non reconnu, doigt mouillé)
  THEN le système propose automatiquement l'alternative (PIN / mot de passe système via local_auth deviceCredentials)
  WHEN l'utilisateur entre son PIN système correctement
  THEN l'app déverrouille normalement (même flux que succès biométrique)
    AND aucun blocage applicatif supplémentaire n'est ajouté (la gestion du nombre de tentatives est déléguée à l'OS)
```

### Scénario alternatif 2 — Désactivation de la biométrie depuis les paramètres

```gherkin
Scenario: Désactiver la biométrie depuis l'écran Sécurité
  GIVEN la biométrie est activée dans Compte > Sécurité
  WHEN l'utilisateur bascule le toggle "Face ID / Touch ID" sur OFF
    AND le système demande une confirmation biométrique avant désactivation
    AND l'utilisateur confirme avec Face ID
  THEN la biométrie est désactivée
    AND le refresh token reste dans flutter_secure_storage mais sans protection biométrique
    AND l'app ne demande plus de déverrouillage biométrique à l'ouverture
    AND le toggle affiche OFF et persiste après redémarrage de l'app
```

### Scénario d'erreur 1 — Appareil sans capteur biométrique

```gherkin
Scenario: Option biométrie masquée si l'appareil ne supporte pas la biométrie
  GIVEN l'utilisateur est sur l'écran Compte > Sécurité
    AND local_auth.getAvailableBiometrics() retourne une liste vide (aucun capteur)
  WHEN l'écran Sécurité se charge
  THEN l'option "Face ID / Touch ID" n'est pas affichée dans la liste des options
    AND un message informatif s'affiche : "La biométrie n'est pas disponible sur cet appareil."
    AND l'utilisateur peut toujours accéder à l'app normalement via JWT sans déverrouillage biométrique
```

### Scénario d'erreur 2 — Changement d'empreinte détecté sur l'appareil

```gherkin
Scenario: Re-authentification demandée si les données biométriques de l'appareil ont changé
  GIVEN la biométrie est activée
    AND l'utilisateur a ajouté une nouvelle empreinte dans les réglages iOS/Android entre deux sessions
  WHEN l'app revient au premier plan et tente d'utiliser local_auth
  THEN local_auth signale une erreur de type biometricChanged (PlatformException)
    AND l'app affiche un dialog : "Vos données biométriques ont changé. Reconnectez-vous pour sécuriser l'accès."
    AND l'utilisateur est redirigé vers l'écran de connexion (email + mot de passe)
    AND après reconnexion réussie, il peut réactiver la biométrie dans les paramètres
    AND le refresh token précédent est invalidé (DELETE /api/auth/sessions/{id})
```

---

## Estimation

- **Story Points** : 3
- **MoSCoW** : Should Have
- **Complexité** : local_auth + flutter_secure_storage + gestion des erreurs biométriques OS

## Validation INVEST

- [x] **Independent** : Dépend de US-040 (navigation) et EPIC-002 (JWT refresh token) mais découpage vertical valide
- [x] **Negotiable** : PIN applicatif vs PIN système, durée timeout en background discutables
- [x] **Valuable** : Besoin clé P-003 (privacy-first, protection lectures sensibles sans friction)
- [x] **Estimable** : 3 points, flutter_secure_storage + local_auth bien documentés
- [x] **Sized** : 3 points <= 8 pts
- [x] **Testable** : Tests unitaires déchiffrement Keychain, tests widget BiometricLockScreen (mock local_auth), tests OWASP M1 (réseau)
