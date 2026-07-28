# ADR-002 : Technologie mobile — Flutter vs React Native vs natif vs KMP

**Date :** 2026-07-28
**Statut :** Accepté
**Décideurs :** Tech Lead, Product Owner
**Contraintes source :** T6 (Android + iOS obligatoires), §9.3 (décision tranchée)

---

## Contexte

Briefly AI doit délivrer une application mobile sur Android et iOS (contrainte T6). Les fonctionnalités mobiles requises sont identifiées dans le PRD (FR-039 à FR-044) et l'EPIC-005 :

- Consultation du Daily Brief avec cache offline (FR-040)
- Authentification JWT + biométrie Face ID / Touch ID (FR-030, FR-032)
- Notifications push quotidiennes FCM/APNs — 1 max/jour (FR-041, B5)
- Synthèse on-device opt-in Phi-3 Mini / Gemma 2B (FR-042, §9.4)
- Respect des guidelines Material Design 3 (Android) + Cupertino (iOS) (FR-043)
- Stockage sécurisé du refresh token (flutter_secure_storage)
- Distribution Google Play Store + App Store (FR-044)

L'équipe est de petite taille (Res1). Le backend Symfony 8 + API Platform 4 est non négociable et expose une API REST documentée OpenAPI 3.1 (FR-047) qui constitue le contrat partagé entre desktop et mobile.

La persona P-003 Marc (GrapheneOS, privacy-first, 44 ans) impose des exigences de sécurité et de contrôle local élevées, notamment l'absence de trackers tiers et la capacité d'exécution on-device.

---

## Décision

**Flutter (Dart) pour les applications Android et iOS.**

State management : BLoC (flutter_bloc) ou Riverpod — décision d'implémentation reportée au Sprint 3. Cache offline : Hive ou Isar (clé-valeur rapide pour les briefs) ou drift (SQL si requêtes complexes). Push : FCM + APNs via le package `firebase_messaging` + `flutter_local_notifications` (Notifee est un équivalent React Native — `flutter_local_notifications` couvre le même besoin). Secure storage : `flutter_secure_storage`. Biométrie : `local_auth`.

---

## Alternatives considérées

### Option A — Natif Swift (iOS) + Kotlin (Android)

| Critère | Évaluation |
|---------|------------|
| Performance | Maximum : accès direct aux APIs OS, GPU, capteurs |
| UX native | Parfaite conformité aux HIG Apple et Material Design |
| Codebases | 2 codebases distinctes (Swift + Kotlin), logique métier dupliquée |
| Équipe requise | 2 profils spécialisés (iOS dev + Android dev) |
| Maintenance | ×2 : chaque feature, chaque fix, chaque mise à jour API = double travail |
| On-device AI | Core ML (iOS) / TFLite (Android) : intégrations différentes à maintenir |

**Rejeté.** La contrainte Res1 (petite équipe) rend intenable la maintenance de deux codebases complètes. Le surcoût de développement initial est estimé à ×1,6-2 pour atteindre la parité fonctionnelle. La vélocité Sprint 3-4 (EPIC-005) serait incompatible avec le roadmap v1.

### Option B — React Native (avec ou sans Expo)

| Critère | Évaluation |
|---------|------------|
| Codebase | Unique (TypeScript/JavaScript) |
| Écosystème | Mature, nombreuses libs tierces |
| Performances listes longues | Dégradation connue sur listes > 500 items (bridge JS) — amélioré en New Architecture mais pas encore stabilisé en prod universelle (2026) |
| Cohérence stack | Introduit un second paradigme JS dans un projet Symfony/PHP — surcoût cognitif équipe |
| On-device AI | react-native-llm ou RNFS + bindings natifs : maturité insuffisante pour Phi-3 Mini quantisé 4-bit |
| flutter_secure_storage équivalent | react-native-keychain : équivalent fonctionnel |
| Dépendance Expo | Simplifie le démarrage mais contraint le déploiement et les modules natifs custom (on-device AI) |

**Rejeté.** Les performances de rendu de listes d'articles longues (flux paginé) restent un risque réel sur la New Architecture React Native pour une cible P95 NFR-002. L'introduction d'un stack JS dans un projet full-PHP augmente la charge cognitive de l'équipe (OPP-06 : stack unique comme levier de time-to-market). L'intégration on-device Phi-3 Mini est plus mature dans l'écosystème Flutter (via flutter_tflite ou llama.cpp FFI bindings).

### Option C — Kotlin Multiplatform (KMP)

| Critère | Évaluation |
|---------|------------|
| Partage de code | Logique métier partagée (Kotlin commun), UI native par plateforme |
| UI | Jetpack Compose (Android) + SwiftUI (iOS) — deux UI à maintenir |
| Maturité (2026) | Stable 1.0 depuis 2023, mais l'écosystème de libs reste fragmenté ; Compose Multiplatform (UI partagée) encore bêta sur iOS |
| Équipe | Kotlin requis + notions SwiftUI pour les devs iOS |
| On-device AI | ONNX Runtime Kotlin : en développement ; moins de documentation que TFLite Flutter |
| Bibliothèques | Moins de libs tierces prêtes à l'emploi que Flutter |

**Rejeté.** La maturité de l'écosystème KMP en 2026 est insuffisante pour une petite équipe visant un time-to-market Sprint 3 (risque RIS-10). L'UI reste dupliquée entre Android et iOS, ce qui annule l'avantage principal vs Flutter. Le coût d'entrée (Kotlin + SwiftUI + bibliothèques Multiplatform) est trop élevé.

### Option D (retenue) — Flutter (Dart)

| Critère | Évaluation |
|---------|------------|
| Codebase | Unique (Dart) |
| Performance rendu | Moteur Impeller (remplaçant Skia, 2026 par défaut) : 60 FPS stables sur listes longues |
| UI | Widget-based, Material Design 3 + Cupertino adaptatifs (FR-043) |
| Offline cache | Hive, Isar, drift — multiple options matures |
| Push | firebase_messaging + flutter_local_notifications (FCM + APNs) |
| Biométrie | local_auth (Face ID, Touch ID, empreinte Android) |
| Secure storage | flutter_secure_storage (Keychain iOS, Keystore Android) |
| On-device AI | flutter_tflite, llama.cpp via FFI : Phi-3 Mini quantisé 4-bit — documenté et testé |
| i18n | flutter_localizations + intl (ARB) — conforme NFR-021/023 |
| State management | BLoC ou Riverpod : deux options stables, équipe choisit Sprint 3 |
| Distribution | Pub.dev : écosystème fourni, Google Play + App Store natifs |

---

## Conséquences

### Positives

- **Codebase unique** : une seule base de code Dart couvre Android et iOS. Chaque User Story EPIC-005 est livrée une seule fois, sans port iOS/Android séparé. La vélocité est ×1,5 à ×2 par rapport au natif pour la parité fonctionnelle (OPP-06).
- **Performances proches du natif** : le moteur Impeller de Flutter restitue les widgets en 60 FPS constants via GPU. Les listes d'articles paginées (flux RSS ingesté) restent fluides sans bridge JS (NFR-004 : synthèse on-device < 15 s P95 atteignable).
- **On-device AI crédible** : l'intégration Phi-3 Mini / Gemma 2B quantisé 4-bit via llama.cpp FFI ou flutter_tflite est documentée sur iOS 15+ / Android 10+. C'est le différenciateur privacy de P-003 Marc et l'opportunité OPP-01 (premier mover on-device crédible).
- **API Platform comme contrat pivot** : Flutter consomme exclusivement l'API REST API Platform (FR-039). Le contrat OpenAPI 3.1 est la source de vérité unique — pas de couplage entre le code Dart et le code Twig desktop.
- **Sécurité mobile** : JWT (access 15 min, refresh 7 jours) stocké dans flutter_secure_storage (Keychain/Keystore chiffré). La biométrie local_auth déverrouille le refresh token sans ré-authentification serveur (FR-032). Aucun token exposé au runtime JS (contrairement à React Native avec bridge).
- **Privacy by design pour P-003** : GrapheneOS est supporté (Android standard), aucune dépendance à Google Play Services non optionnelle — firebase_messaging est optionnel (P-003 peut ne pas activer les notifications). Le mode on-device ne transmet aucune requête réseau pendant la synthèse.
- **Internationalisation** : flutter_localizations + intl couvre les formats date/heure/devise par locale (NFR-021 à NFR-023), même couche que symfony/translation côté serveur — cohérence conceptuelle.

### Négatives

- **Dart comme langue supplémentaire** : l'équipe backend PHP/Symfony doit apprendre Dart. La courbe d'apprentissage est estimée à 2-4 semaines pour un développeur PHP confirmé (syntaxe proche, typage strict, tooling similaire). Elle est inférieure à Swift + Kotlin combinés.
- **Taille de l'APK/IPA** : une application Flutter pèse 8-15 Mo en release (vs 3-6 Mo en natif). Acceptable pour une app de lecture ; le modèle Phi-3 Mini quantisé 4-bit (~1 Go) est téléchargé à la demande (opt-in), pas inclus dans le bundle de base.
- **Écosystème tiers moins riche que React Native** : certaines intégrations spécifiques (SDK analytics maison, libs de niche) peuvent nécessiter un plugin custom via FFI. Pour les fonctionnalités Briefly AI v1, toutes les libs requises existent et sont stables (voir tableau Option D).
- **Revue App Store Apple** : les apps Flutter sont soumises aux mêmes règles que les apps natives. Le modèle on-device doit être présenté clairement comme opt-in (FR-042) pour éviter les refus liés à la consommation de ressources.

### Contraintes respectées

| Contrainte | Satisfaction |
|------------|-------------|
| T6 — Android + iOS | Flutter couvre les deux plateformes depuis une codebase unique |
| T7 — API unique mobile + frontend | Flutter consomme exclusivement API Platform REST |
| T8 — i18n dès Sprint 1 | flutter_localizations + intl (ARB) opérationnel Sprint 3 |
| R1 — RGPD | Pas d'identifiant dans les requêtes LLM (FR-018), données EU (NFR-016) |
| R2 — AI Act | Synthèse on-device clairement opt-in, badge "On-device" affiché (FR-042) |
| §9.4 — Hybride on-device | Phi-3 Mini via flutter_tflite / llama.cpp FFI : opt-in, concis uniquement |

### Impact sur les personas

| Persona | Bénéfice |
|---------|---------|
| **P-001 Thomas** | App fluide (60 FPS, Impeller) pour lecture liste articles dans le métro ; cache offline (FR-040) pour les tunnels |
| **P-002 Priya** | Consultation du brief sur iPad Mini ; export Markdown disponible aussi sur mobile |
| **P-003 Marc** | On-device Phi-3 Mini : synthèse crypto sans données sortantes (FR-042, US-062) ; flutter_secure_storage garantit que le refresh token ne quitte jamais le Keystore Android |

---

## Notes de révision

Cette décision sera réexaminée si :
- Compose Multiplatform (KMP UI partagée) atteint une stabilité production avant Sprint 4.
- Les performances on-device Phi-3 Mini dépassent NFR-004 (> 15 s P95) sur les terminaux cibles.
- L'App Store Apple rejette l'application pour motif lié au runtime Flutter (historiquement résolu depuis Flutter 2.x).

**Prochaine révision planifiée :** Sprint 3 Review (première livraison EPIC-005 Walking Skeleton mobile).
