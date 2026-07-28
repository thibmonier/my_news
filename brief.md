# Brief initial — Briefly AI

> Plateforme d'agrégation et de synthèse d'actualités « native IA », web et mobile.
> Document issu du pitch initial, enrichi par l'analyse des maquettes Stitch du projet
> **Intelligent News Digest** (`projects/7076573032400883843`, 23 écrans, thèmes clair + sombre).
>
> *Convention : les libellés d'interface relevés dans les maquettes sont traduits, l'intitulé
> d'origine étant donné entre parenthèses pour garder la correspondance avec les écrans.*

---

## 1. Vision

Briefly AI transforme un flux d'actualités chaotique en un **brief quotidien** (« Daily Brief »)
structuré et exploitable. L'utilisateur n'ouvre pas une liste d'articles : il ouvre une synthèse
hiérarchisée, produite par IA, dans laquelle il choisit ce qu'il approfondit.

Positionnement produit : **fort signal, faible bruit**. Ton éditorial, pas racoleur.

## 2. Cibles

| Cible | Besoin | Traduction produit |
|---|---|---|
| Travailleurs du savoir | Rester à jour sans y passer des heures | Brief quotidien « trois histoires », condensés de 2 minutes |
| Chercheurs et analystes | Insights transverses sur des sources de qualité (The Economist, Wired, Harvard Business Review, MIT Tech Review) | Bibliothèque de synthèses, filtres, exploration par sujets |
| Lecteurs soucieux de vie privée | Maîtrise des données, traitement local | Réglages de confidentialité granulaires, préférence de traitement IA sur l'appareil, authentification biométrique |

## 3. Périmètre fonctionnel

### 3.1 Synthèse IA
- **Brief quotidien** (« Daily Brief ») : tableau de bord d'ouverture présentant les trois histoires
  majeures, numérotées (`01`, `02`, `03`), avec horodatage de dernière mise à jour (« LAST UPDATED:
  6:00 AM EST ») et appel à l'action « Lire le brief complet » (« READ FULL BRIEF »).
- **Synthèse à la une** (« Featured Summary », desktop) : une synthèse mise en avant, appel à l'action
  « Lire le brief de 2 minutes » (« Read 2-Minute Brief »).
- **Condensé IA par article** : bloc de trois à quatre puces en tête d'article, badge `auto_awesome`
  et intitulé « Condensé IA ».
- **Niveaux de détail réglables** : concis, détaillé, narratif (« Concise », « Detailed »,
  « Narrative ») — écran Compte, section Préférences.
- **Génération à la demande** : bouton « Générer une synthèse IA » (« GENERATE AI SUMMARY ») sur un
  article sauvegardé qui n'en possède pas encore.
- **Cartes typées** par nature d'information : insight IA (« AI INSIGHT »), géopolitique
  (« GEOPOLITICS »), productivité (« PRODUCTIVITY »), recherche (« RESEARCH »), durabilité
  (« SUSTAINABILITY »). Chaque synthèse est préfixée « BRIEFLY AI: » pour marquer l'origine machine.

### 3.2 Agrégation de sources
- **Google News** comme plateforme intégrée, avec sous-canaux : À la une, Technologie, Science.
- **Flux RSS/Atom manuels** : ajout (`add`), édition, suppression, action groupée
  « TOUT METTRE À JOUR ». Exemples présents en maquette : The Verge
  (`https://www.theverge.com/rss/index.xml`), TechCrunch (`https://techcrunch.com/feed/`).
- **Recherche** dans les sources.

### 3.3 Exploration par sujets
- Sujet chaud mis en avant (exemple en maquette : *Intelligence Artificielle — 124 nouveaux briefs
  aujourd'hui*).
- Catégories principales : Technologie, Science, Économie, Politique Monde.
- Autres canaux (« Other Channels ») : Culture, Santé, Sports, Espace, Crypto, Climat.
- Recherche transverse.

### 3.4 Bibliothèque personnelle
- **Contenus sauvegardés** (« Saved Content ») : archives de lecture longue et de synthèses.
- Filtres : tous les articles (« ALL ARTICLES ») ou synthèses uniquement (« SUMMARIES ONLY »).
- Métadonnées par élément : source, ancienneté (« il y a 2 h », « sauvegardé hier »), badge de
  synthèse IA (« AI SUMMARY »).
- Actions : mettre en favori, partager, ouvrir l'article d'origine (« OUVRIR L'ORIGINAL »).

### 3.5 Compte, confidentialité, monétisation
- **Profil** : nom complet, adresse e-mail, biographie professionnelle, enregistrement explicite.
- **Abonnement** : formule active « Briefly Premium », prochaine date de facturation, bénéfices
  listés (synthèses IA illimitées, indexation prioritaire des sources), gestion de l'abonnement.
  → Le modèle économique freemium/premium fait donc partie du périmètre dès la première version
  des maquettes.
- **Confidentialité** (interrupteurs) : analytique anonyme, recommandations personnalisées,
  authentification biométrique (Face ID / Touch ID), indexation par les moteurs de recherche.
- **Notifications** : digest par e-mail uniquement, notification mobile en temps réel, rapport
  hebdomadaire, ou silence total.
- **Suppression de compte** : zone dédiée, action destructive isolée du reste de l'écran.

### 3.6 Authentification
- Adresse e-mail et mot de passe, avec bascule d'affichage du mot de passe et lien « Mot de passe
  oublié ? ».
- Authentification déléguée : Google, GitHub.
- Création de compte.
- Liens légaux : politique de confidentialité, conditions d'utilisation.
- Authentification biométrique pour les accès récurrents, activable depuis le compte.

## 4. Architecture des écrans (état des maquettes)

Chaque écran existe en **thème clair** et **thème sombre** ; les écrans principaux existent en
**mobile (390 px)** et **desktop (1280 px)**.

| Écran | Mobile | Desktop | Sombre |
|---|---|---|---|
| Connexion | oui | oui | oui |
| Tableau de bord (brief quotidien) | oui | oui | oui |
| Lecture d'article | oui | oui | oui |
| Exploration par sujets | oui | oui | oui |
| Gestion des sources | oui | oui | oui |
| Articles sauvegardés | oui | — | oui |
| Gestion du compte | oui | oui | oui |

### Navigation
- **Mobile — barre de navigation basse, quatre onglets** : Flux (`rss_feed`), Explorer (`explore`),
  Sources (`settings_input_component`), Sauvegardés (`bookmark`). Sur l'écran Compte, le quatrième
  onglet devient Profil (`person`).
- **Mobile — barre d'application haute** : logo Briefly AI, bascule de thème (`light_mode`),
  notifications. En lecture d'article : retour, mise en favori, partage.
- **Desktop — barre latérale** : Flux, Explorer, Contenus sauvegardés, Profil, Réglages, Aide,
  plus l'action primaire « Résumer un nouveau contenu » (« Summarize New »). En-tête avec recherche,
  notifications et menu utilisateur. Pied de page : politique de confidentialité, conditions
  d'utilisation, contact, **accès API**.

## 5. Parcours clés

1. **Le brief du matin** — connexion (biométrie) → brief quotidien → « Lire le brief complet » →
   lecture d'article avec condensé IA en tête → mise en favori ou ouverture de l'article d'origine.
2. **La veille thématique** — Explorer → sujet chaud ou catégorie → flux d'articles → synthèse IA
   à la demande.
3. **La curation des entrées** — Sources → ajout d'un flux RSS/Atom ou activation d'un canal
   Google News → « TOUT METTRE À JOUR ».
4. **La reprise différée** — Sauvegardés → filtre « synthèses uniquement » → lecture longue.

## 6. Principes de conception

- **Clarté et concentration** : typographie éditoriale haute fidélité, longueurs de ligne contraintes
  (768 px maximum en lecture, 1120 px en navigation).
- **Adaptation au contexte** : bascule fluide entre le thème clair *Insight Minimalist* et le thème
  sombre *Insight Dark*.
- **Retour visuel intelligent** : l'accent émeraude/menthe est **réservé** aux éléments produits ou
  enrichis par l'IA (badges, synthèses, actions IA). Raccourci mental : vert égale machine.
- **Priorité au mobile** : navigation à une main, lecture en survol rapide, marges latérales serrées
  (16 px) mais respiration verticale généreuse.
- **Calme visuel** : pas d'ombres appuyées ; les cartes sont définies par une bordure de 1 px et des
  couches tonales.

## 7. Identité visuelle

**Système de design : « Insight Minimalist » (clair) et « Insight Dark » (sombre).**

### Typographie
| Rôle | Police | Usage |
|---|---|---|
| Titres | **Source Serif 4** | 40/48 px desktop, 30/36 px mobile, interlettrage `-0.02em` — autorité éditoriale |
| Corps de texte | **Inter** | 16/24 et 18/28 px — lisibilité neutre en lecture longue |
| Métadonnées et interface | **Hanken Grotesk** | libellés capitales 12 px avec interlettrage `+0.05em`, synthèses IA 14 px |

### Couleurs
| Jeton | Valeur | Usage |
|---|---|---|
| `emerald-accent` | `#10B981` | **exclusivement** l'IA : badges, synthèses, actions IA |
| `deep-indigo` | `#1E1B4B` | texte principal, navigation de haut niveau |
| `slate-gray` | `#64748B` | texte secondaire, métadonnées |
| `primary` | `#091426` | boutons primaires |
| `surface` / `background` | `#F7F9FB` | canevas « papier », fatigue oculaire réduite |
| `surface-container-lowest` | `#FFFFFF` | cartes |
| `surface-border` | `#E2E8F0` | bordures de 1 px, séparation sans ombre |
| `error` | `#BA1A1A` | actions destructives |

Thème sombre : bleu marine profond et gris ardoise.

### Formes et espacement
- Rayons : cartes et champs **4 px**, badges IA **2 px** (précision), avatars et logos de sources
  entièrement arrondis.
- Rythme vertical de 8 px ; espacements `stack-sm` 0,5 rem, `stack-md` 1,5 rem, `stack-lg` 3 rem ;
  gouttière 1,5 rem.
- Marges : 1 rem en mobile, 2,5 rem en desktop.

### Composants normalisés
Barre d'application haute, barre de navigation basse, barre latérale desktop, carte d'actualité
(bordure, icône de source, titre en serif, extrait « Briefly AI » sur fond gris), badge de synthèse
IA, boutons primaire / secondaire / action IA, champs de saisie minimalistes (focus indigo et
indicateur émeraude de 2 px), indicateur de progression de lecture (ligne émeraude de 2 px en haut
de la fenêtre). Transitions en fondu-glissement de 200 ms.

## 8. Contraintes et implications techniques

### 8.1 Pile technique imposée

**Backend**
- **Symfony 8**, exposé via **API Platform 4**.
- Serveur applicatif **FrankenPHP**.
- Base de données **PostgreSQL**.
- **Redis** pour le cache, les sessions et les files d'attente.
- Environnement de développement et de déploiement **conteneurisé avec Docker**.

**Frontend desktop**
- Deux options ouvertes, à trancher lors de l'**étape d'initialisation du projet** :
  1. **Headless** — application cliente autonome consommant la couche API exposée par API Platform.
  2. **Symfony** — rendu côté serveur au sein de la même application (Twig, et le cas échéant
     Turbo/Stimulus).
- Cette décision conditionne l'authentification (jetons contre session), la stratégie de cache et
  le découpage des équipes ; elle doit être arbitrée avant tout développement d'écran.

**Mobile**
- Disponibilité obligatoire sur **Android** et **iOS**.
- **Cache local** attendu : lecture hors ligne des briefs et des articles sauvegardés, réduction
  des appels réseau, synchronisation différée.
- **Notifications push** natives, en cohérence avec les quatre canaux de notification prévus
  (digest par e-mail, temps réel mobile, rapport hebdomadaire, silence).
- Consomme la même couche API que le frontend headless.

### 8.2 Implications produit

- **Multi-plateforme** : web responsive desktop et mobile, avec des fonctions natives attendues
  (biométrie Face ID / Touch ID, notifications mobiles en temps réel) → application mobile ou
  application web progressive dotée de capacités natives.
- **Chaîne d'ingestion** : connecteur Google News et analyseur RSS/Atom, déduplication,
  rafraîchissement par lot (« TOUT METTRE À JOUR »), planification du brief quotidien à 6 h locales.
- **Couche IA** : synthèse à plusieurs niveaux (concis, détaillé, narratif), regroupement des
  histoires majeures pour le brief quotidien, classification par sujets, génération à la demande.
  La préférence de **traitement sur l'appareil** doit être prise en charge pour la cible
  soucieuse de vie privée.
- **Traçabilité** : toute production IA doit être identifiable visuellement et rattachée à sa source
  d'origine (lien « OUVRIR L'ORIGINAL » systématique).
- **Facturation** : formules et quotas (synthèses illimitées, indexation prioritaire des sources).
- **API publique** : mentionnée dans le pied de page desktop (« API ACCESS ») — à qualifier.
- **Internationalisation** : les maquettes mêlent français et anglais → prévoir l'internationalisation
  dès le départ.

## 9. Points à trancher

1. **Architecture du frontend desktop** : headless sur la couche API ou rendu Symfony. Décision
   attendue à l'initialisation du projet — voir section 8.1.
2. **Langue du produit** : les maquettes mobiles sont partiellement en français, les écrans desktop
   en anglais. Choisir la langue de référence et la stratégie d'internationalisation.
3. **Technologie mobile** : natif par plateforme, ou base de code partagée pour Android et iOS ?
   Le choix conditionne la mise en œuvre du cache local et des notifications push.
4. **Étendue du traitement local** : synthèse entièrement locale (petit modèle de langue embarqué)
   ou approche hybride serveur/appareil ?
5. **Sources de la première version** : Google News et le RSS manuel suffisent-ils, ou faut-il des
   partenariats et un traitement des contenus payants (The Economist et Harvard Business Review
   apparaissent dans les maquettes) ?
6. **Modèle tarifaire** : la formule Premium est maquettée, mais ni le prix ni les limites de la
   formule gratuite ne le sont.
7. **API publique** : périmètre, tarification, calendrier.
8. **Notifications en temps réel** : infrastructure d'envoi et règles de déclenchement — quel signal
   justifie une alerte ?
9. **Écran des articles sauvegardés en desktop** : absent des maquettes, à produire.

---

*Sources : pitch initial « Project Brief: Briefly AI » et projet Stitch « Intelligent News Digest »
(système de design Insight Minimalist, 23 écrans mobile et desktop, thèmes clair et sombre).*
