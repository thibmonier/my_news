# US-015 : ~~Synthèse on-device opt-in Flutter + Phi-3 Mini (P-003)~~ [SPLIT]

> **Statut : SPLIT** — Cette US a été découpée en 2 US enfants pour améliorer la granularité de planification et de livraison. Elle n'est **pas comptabilisée** dans les totaux du backlog (ni en points ni en nombre d'US).

## En-tête (archivé)

| Champ | Valeur |
|-------|--------|
| **ID** | US-015 |
| **EPIC parent** | EPIC-002 — Moteur de Synthèse IA |
| **Persona** | P-003 Marc — Développeur indépendant, 44 ans, privacy-first |
| **~~Story Points~~** | ~~8~~ (remplacés par les points des US enfants) |
| **Statut** | **SPLIT — non comptabilisée** |

---

## US enfants (à utiliser à la place)

| ID | Titre | Points | Sprint |
|----|-------|--------|--------|
| [US-015a](US-015a-telechargement-modele-on-device.md) | Téléchargement du modèle Phi-3 Mini on-device (Flutter) | 3 | backlog |
| [US-015b](US-015b-inference-locale-flutter-synthese-concise.md) | Inférence locale Phi-3 Mini pour synthèse concise (Flutter on-device) | 5 | backlog |
| **Total** | | **8** | |

---

## Raison du découpage

L'US originale de 8 pts recouvrait deux responsabilités distinctes, livrables indépendamment l'une de l'autre :

1. **Téléchargement du modèle (US-015a — 3 pts)** : UX download, barre de progression, reprise réseau, vérification SHA-256, consentement RGPD. Livrable sans l'inférence — Marc peut vérifier que son consentement est pris en compte et que le modèle est stocké localement.

2. **Inférence locale (US-015b — 5 pts)** : runtime ML (`onnxruntime_flutter`), dispatch `SynthesisService` (serveur vs on-device), badge cadenas, gestion OOM, suppression du modèle. Requiert US-015a comme prérequis.

Ce découpage permet au Tech Lead de planifier le téléchargement (US-015a) dans un sprint et l'inférence (US-015b) dans le suivant, améliorant la granularité de suivi et la flexibilité de séquencement.
