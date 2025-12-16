# Vue d'ensemble du projet CCComputer

## 📋 Résumé exécutif

**CCComputer** est une application web PHP fullstack de gestion de photocopieurs et de clients. Le système permet de suivre les relevés de compteurs, gérer les clients, calculer les dettes, gérer les livraisons, le SAV, le stock, et communiquer via une messagerie interne.

**Type de projet** : Fullstack (PHP backend + frontend vanilla JavaScript)  
**Frameworks & technos** : PHP 8.0+, MySQL/MariaDB, PDO, Composer, Docker  
**Objectif principal** : Gestion complète d'une activité de location/maintenance de photocopieurs (clients, relevés, facturation, SAV, livraisons, stock)  
**Environnements** : Dev (XAMPP local), Production (Railway/IONOS avec Docker)  
**Mode de déploiement** : Docker (Dockerfile présent), serveur web (Apache/Caddy), CI/CD possible via Railway

---

## 1️⃣ Vue globale du projet

### Type de projet
- **Fullstack** : Backend PHP + Frontend vanilla JavaScript
- **Architecture** : MVC légère (en cours de migration progressive)
- **Pattern** : Monolithique avec séparation progressive des responsabilités

### Frameworks & technos utilisés

**Backend :**
- PHP 8.0+ (strict_types activé)
- MySQL/MariaDB (PDO)
- Composer pour la gestion des dépendances
- Architecture MVC légère (app/Models, app/Repositories, app/Services)

**Dépendances principales (composer.json) :**
- `phpseclib/phpseclib` : Connexions SFTP pour import de fichiers
- `tecnickcom/tcpdf` : Génération de PDF
- `phpmailer/phpmailer` : Envoi d'emails
- `monolog/monolog` : Logging
- `sentry/sentry` : Monitoring d'erreurs

**Frontend :**
- Vanilla JavaScript (pas de framework)
- CSS personnalisé
- API REST pour les appels AJAX

**Infrastructure :**
- Docker (Dockerfile présent)
- Apache (mod_rewrite activé)
- Caddy (Caddyfile présent pour déploiement alternatif)

### Objectif principal du site

Le site permet de :
1. **Gérer les clients** : CRUD complet, fiches détaillées, géolocalisation
2. **Suivre les photocopieurs** : Attribution aux clients, relevés de compteurs automatiques
3. **Importer les relevés** : Via SFTP (fichiers CSV) ou API IONOS
4. **Calculer les dettes** : Basé sur la consommation (N&B et couleur)
5. **Gérer les livraisons** : Planification, suivi, assignation aux livreurs
6. **Gérer le SAV** : Tickets, assignation aux techniciens, suivi
7. **Gérer le stock** : Papier, toner, LCD, PC avec mouvements
8. **Communiquer** : Messagerie interne et chatroom en temps réel
9. **Visualiser** : Cartes interactives (Leaflet), géocodage, itinéraires

### Environnements

**Développement :**
- XAMPP local (Windows)
- Base de données : `cccomputer` sur `localhost:3306`
- Configuration via variables d'environnement ou fichier `includes/db_config.local.php`

**Production :**
- Railway ou IONOS
- Variables d'environnement : `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`
- Docker container avec PHP 8.3-apache

### Mode de déploiement

1. **Docker** : Dockerfile présent, build avec `docker build`, run avec Apache
2. **Serveur web** : Apache avec mod_rewrite ou Caddy
3. **CI/CD** : Possible via Railway (déploiement automatique depuis Git)

---

## 2️⃣ Fonctionnalités du site (vue produit)

### Rôles utilisateurs

Les rôles sont stockés dans la table `utilisateurs` avec le champ `Emploi` (ENUM) :
- **Admin** : Accès complet à toutes les fonctionnalités
- **Dirigeant** : Accès quasi-complet (peut gérer utilisateurs, voir historique)
- **Chargé relation clients** : Gestion clients, messagerie, livraisons
- **Technicien** : SAV, interventions, notes techniques
- **Livreur** : Livraisons (uniquement celles qui lui sont assignées)
- **Secrétaire** : Accès limité (à confirmer dans le code)

**Système ACL** : Table `user_permissions` pour permissions granulaires par page (fallback sur les rôles si pas de permission explicite).

### Pages / écrans principaux

**Authentification :**
- `/public/login.php` : Connexion (email + mot de passe)
- `/redirection/` : Pages de redirection (accès interdit, compte désactivé, erreur connexion, validation connexion)

**Pages principales :**
- `/public/dashboard.php` : Tableau de bord avec statistiques (SAV, livraisons, clients, stock, historique)
- `/public/clients.php` : Liste et gestion des clients
- `/public/client_fiche.php` : Fiche détaillée d'un client
- `/public/photocopieurs_details.php` : Détails d'un photocopieur (relevés, consommation)
- `/public/livraison.php` : Gestion des livraisons
- `/public/sav.php` : Gestion du SAV (tickets)
- `/public/stock.php` : Gestion du stock (papier, toner, LCD, PC)
- `/public/paiements.php` : Gestion des paiements et dettes
- `/public/messagerie.php` : Messagerie interne (1-à-1) et chatroom (général)
- `/public/maps.php` : Carte interactive avec géolocalisation des clients
- `/public/agenda.php` : Planning (à confirmer dans le code)
- `/public/historique.php` : Historique des actions utilisateurs
- `/public/profil.php` : Gestion des utilisateurs
- `/public/scan_barcode.php` : Scan de codes-barres pour le stock
- `/public/print_labels.php` : Impression d'étiquettes

### Fonctionnalités clés

**1. Gestion des clients**
- CRUD complet (création, modification, suppression)
- Fiche détaillée avec toutes les informations (SIRET, TVA, IBAN, PDFs, etc.)
- Attribution de photocopieurs aux clients
- Géolocalisation automatique (géocodage)
- Recherche avancée

**2. Import automatique des relevés**
- **Import SFTP** : Téléchargement de fichiers CSV depuis un serveur SFTP
  - Pattern de fichiers : `COPIEUR_MAC-*.csv`
  - Déplacement automatique vers `/processed` après traitement
  - Gestion des erreurs (déplacement vers `/errors`)
  - Verrou MySQL pour éviter les exécutions parallèles
  - Intervalle configurable (20 secondes par défaut)
  - Badge dans le dashboard avec statut en temps réel
- **Import IONOS** : Import depuis une API IONOS (à confirmer dans le code)
- **Import ancien** : Import depuis l'ancien système (table `compteur_relevee_ancien`)

**3. Calcul des dettes**
- Basé sur la consommation entre deux relevés
- **N&B** : 0.05€ par copie si > 1000 copies/mois, sinon 0€
- **Couleur** : 0.09€ par copie
- Service dédié : `app/Services/DebtService.php`
- Service de consommation : `app/Services/ConsumptionService.php`

**4. Gestion des livraisons**
- Statuts : `planifiee`, `en_cours`, `livree`, `annulee`
- Assignation aux livreurs
- Types de produits : papier, toner, LCD, PC, autre
- Dates prévues et réelles
- Permissions : Admin/Dirigeant peuvent tout modifier, Livreurs uniquement leurs livraisons

**5. Gestion du SAV**
- Statuts : `ouvert`, `en_cours`, `resolu`, `annule`
- Priorités : `basse`, `normale`, `haute`, `urgente`
- Types de panne : `logiciel`, `materiel`, `piece_rechangeable`
- Assignation aux techniciens
- Suivi des interventions (temps estimé/réel, coût, satisfaction client)
- Pièces utilisées (table `sav_pieces_utilisees`)

**6. Gestion du stock**
- **4 catalogues** : `paper_catalog`, `toner_catalog`, `lcd_catalog`, `pc_catalog`
- **Mouvements** : Tables `*_moves` avec raisons (ajustement, achat, retour, correction)
- **Stock client** : Table `client_stock` pour le stock attribué aux clients
- **Codes-barres** : Support des codes-barres et QR codes
- **Scan** : Page dédiée pour scanner les codes-barres

**7. Messagerie interne**
- **Messagerie 1-à-1** : Table `messagerie`
  - Envoi de messages entre utilisateurs
  - Liens vers clients, livraisons, SAV
  - Réponses par texte ou emoji
  - Marquage lu/non lu
  - Suppression côté expéditeur/destinataire
- **Chatroom générale** : Table `chatroom_messages`
  - Messages publics visibles par tous
  - Mentions (@username)
  - Upload d'images
  - Notifications (table `chatroom_notifications`)
  - Nettoyage automatique après 24h

**8. Cartes interactives**
- Bibliothèque Leaflet
- Géocodage automatique des adresses clients
- Calcul d'itinéraires (OSRM)
- Recherche de clients
- Ajout de clients à la route

**9. Historique des actions**
- Table `historique` : Toutes les actions utilisateurs
- Logs : user_id, action, details, ip_address, date_action
- Affichage dans `/public/historique.php`

### Règles métier visibles

1. **Calcul des dettes** :
   - N&B : Gratuit si ≤ 1000 copies/mois, sinon 0.05€/copie
   - Couleur : 0.09€/copie toujours
   - Calcul basé sur la différence entre deux relevés de compteur

2. **Permissions par rôle** :
   - Admin/Dirigeant : Accès complet
   - Livreurs : Peuvent modifier uniquement leurs livraisons assignées
   - Techniciens : Accès SAV et notes techniques
   - Système ACL : Permissions granulaires par page via `user_permissions`

3. **Import SFTP** :
   - Intervalle minimum : 20 secondes (configurable via `SFTP_IMPORT_INTERVAL_SEC`)
   - Verrou MySQL pour éviter les doublons
   - Déplacement automatique des fichiers traités
   - Gestion des erreurs avec logs détaillés

4. **Stock** :
   - Stock calculé dynamiquement via SUM des `qty_delta` dans les tables `*_moves`
   - Vérification de stock insuffisant avant sortie
   - Transactions pour éviter les race conditions

5. **Messagerie** :
   - Messages chatroom supprimés après 24h
   - Images uploadées dans `/uploads/chatroom/`
   - Notifications pour mentions et nouveaux messages

### Actions possibles par rôle

**Admin :**
- Toutes les actions (CRUD complet sur tous les modules)

**Dirigeant :**
- Presque toutes les actions (à confirmer les restrictions exactes)

**Chargé relation clients :**
- Gérer les clients
- Créer/modifier les livraisons
- Envoyer des messages
- Voir les paiements

**Technicien :**
- Gérer le SAV (créer, modifier, résoudre)
- Ajouter des notes techniques
- Voir les photocopieurs et relevés

**Livreur :**
- Voir et modifier uniquement ses livraisons assignées
- Marquer les livraisons comme livrées
- Voir les clients pour les livraisons

**Secrétaire :**
- Actions limitées (à confirmer dans le code)

---

## 3️⃣ Parcours utilisateur

### Inscription

**À confirmer** : Le système semble gérer uniquement les utilisateurs créés par les administrateurs. Pas de page d'inscription publique visible.

**Création d'utilisateur** (par Admin) :
1. Accès à `/public/profil.php`
2. Formulaire de création avec : email, nom, prénom, téléphone, emploi, date_debut, mot de passe
3. Validation des données (email, longueur mot de passe min 8 caractères)
4. Hash du mot de passe avec `password_hash()` (PASSWORD_BCRYPT, cost 10)
5. Insertion dans `utilisateurs` avec statut `actif` par défaut

### Connexion

**Flow de connexion :**

1. **Page de connexion** (`/public/login.php`)
   - Formulaire : email + mot de passe
   - Token CSRF pour protection

2. **Traitement** (`/source/connexion/login_process.php`)
   - Vérification CSRF
   - Recherche utilisateur par email
   - Vérification mot de passe avec `password_verify()`
   - Vérification statut = `actif`
   - Rehash si nécessaire (mise à jour vers cost 10)
   - Régénération session ID
   - Écriture session : user_id, user_email, user_nom, user_prenom, emploi, csrf_token
   - Mise à jour `last_activity` dans `utilisateurs`
   - Redirection vers `/public/dashboard.php`

3. **Vérifications post-connexion** (`includes/auth.php`)
   - Vérification session toutes les requêtes
   - Vérification statut utilisateur toutes les 30 secondes
   - Régénération session ID toutes les 15 minutes
   - Mise à jour `last_activity` toutes les 30 secondes

### Utilisation principale du site

**Dashboard** (`/public/dashboard.php`) :
1. Affichage des statistiques :
   - Nombre de SAV à traiter (ouvert + en_cours)
   - Nombre de livraisons à faire (planifiee + en_cours)
   - Nombre de clients (limité à 500 par défaut)
   - Statistiques stock (catégories, produits)
   - Historique par jour
   - Badges d'import SFTP et IONOS (statut en temps réel)

2. Import automatique SFTP :
   - Déclenchement immédiat au chargement (avec `force=1`)
   - Vérification toutes les 20 secondes (si "due")
   - Badge mis à jour automatiquement
   - Toasts pour les erreurs/succès

3. Navigation :
   - Menu header avec liens vers toutes les pages
   - Cartes cliquables pour accéder aux modules

**Gestion clients** (`/public/clients.php`) :
1. Liste des clients avec recherche
2. Filtres possibles (à confirmer)
3. Création/modification via formulaires
4. Fiche détaillée (`/public/client_fiche.php`) :
   - Informations complètes
   - Photocopieurs attribués
   - Historique des relevés
   - Calcul des dettes
   - Actions (modifier, supprimer selon permissions)

**Gestion SAV** (`/public/sav.php`) :
1. Liste des tickets avec filtres (toutes, urgent, aujourd'hui, archive)
2. Tri par priorité (urgente > haute > normale > basse)
3. Création de ticket (via dashboard ou page SAV)
4. Modification (statut, priorité, assignation technicien)
5. Notes techniques (réservées aux techniciens)

**Gestion livraisons** (`/public/livraison.php`) :
1. Liste des livraisons avec filtres par statut
2. Création (via dashboard ou page livraisons)
3. Modification (statut, date réelle, commentaire)
4. Assignation livreur
5. Restrictions : Livreurs ne peuvent modifier que leurs livraisons

**Gestion stock** (`/public/stock.php`) :
1. Vue par catégorie (papier, toner, LCD, PC)
2. Mouvements (ajout, sortie, ajustement)
3. Scan codes-barres
4. Impression d'étiquettes

**Messagerie** (`/public/messagerie.php`) :
1. Onglets : Messagerie (1-à-1) et Chatroom (général)
2. Messagerie :
   - Liste des conversations
   - Recherche de clients/livraisons/SAV pour créer un message
   - Envoi de messages
   - Réponses par texte ou emoji
3. Chatroom :
   - Messages en temps réel (refresh toutes les 2 secondes)
   - Mentions (@username)
   - Upload d'images
   - Notifications pour mentions

**Cartes** (`/public/maps.php`) :
1. Carte Leaflet avec tous les clients géocodés
2. Recherche de clients
3. Calcul d'itinéraires (OSRM)
4. Ajout de clients à la route

### Actions critiques

**Créer un client** :
1. Formulaire dans `/public/clients.php`
2. Validation : email, SIRET, champs obligatoires
3. Insertion dans `clients`
4. Géocodage automatique de l'adresse (si configuré)
5. Log dans `historique`

**Modifier un client** :
1. Formulaire pré-rempli
2. Validation identique à la création
3. UPDATE dans `clients`
4. Log dans `historique`

**Attribuer un photocopieur à un client** :
1. Via `/public/client_fiche.php` ou API `/API/clients/attribuer_photocopieur.php`
2. Insertion dans `photocopieurs_clients` (lien client ↔ photocopieur via MAC)
3. Vérification unicité (SerialNumber, mac_norm)

**Créer une livraison** :
1. Via dashboard ou `/public/livraison.php`
2. Formulaire : client, livreur, référence, adresse, objet, date prévue, produit
3. Insertion dans `livraisons` avec statut `planifiee`
4. Log dans `historique`

**Créer un SAV** :
1. Via dashboard ou `/public/sav.php`
2. Formulaire : client, photocopieur (mac_norm), référence, description, priorité, type panne
3. Insertion dans `sav` avec statut `ouvert`
4. Log dans `historique`

**Calculer une dette** :
1. Via `/public/paiements.php` ou API
2. Sélection période (début, fin)
3. Calcul consommation via `ConsumptionService`
4. Calcul dette via `DebtService`
5. Affichage détaillé (N&B, couleur, montants)

**Importer des relevés** :
1. Automatique : Script SFTP s'exécute toutes les 20 secondes (si "due")
2. Manuel : Via `/public/run-import.php` ou appel API avec `force=1`
3. Processus :
   - Connexion SFTP
   - Téléchargement fichiers CSV (pattern `COPIEUR_MAC-*.csv`)
   - Parsing CSV
   - Insertion dans `compteur_relevee` (avec vérification doublons)
   - Déplacement fichier vers `/processed` ou `/errors`
   - Log dans `import_run`

### Déconnexion / expiration session

**Déconnexion manuelle** :
1. Lien dans le header (`/includes/logout.php`)
2. Nettoyage session
3. Suppression cookie session
4. Régénération session ID
5. Redirection vers `/public/login.php`

**Expiration session** :
- Vérification toutes les 30 secondes dans `includes/auth.php`
- Si utilisateur inactif ou désactivé :
  - Nettoyage session
  - Suppression cookie
  - Message d'erreur dans `$_SESSION['login_error']`
  - Redirection vers `/public/login.php`

**Timeout session** :
- Configuration dans `includes/session_config.php`
- Durée par défaut : à confirmer (probablement 24h ou selon `session.gc_maxlifetime`)

---

## 4️⃣ Architecture technique

### Organisation des dossiers importants

```
cccomputer/
├── API/                    # Endpoints API REST (JSON)
│   ├── clients/            # API clients
│   ├── scripts/            # Scripts d'import
│   └── *.php              # Endpoints API (chatroom, messagerie, dashboard, etc.)
├── app/                    # Architecture MVC légère
│   ├── Models/             # Modèles de données (Client, Photocopieur, Releve)
│   ├── Repositories/       # Accès aux données (ClientRepository, CompteurRepository)
│   └── Services/           # Logique métier (ConsumptionService, DebtService)
├── assets/                 # Assets statiques
│   ├── css/               # Feuilles de style
│   ├── js/                # JavaScript
│   ├── images/            # Images
│   └── logos/             # Logos
├── cache/                 # Cache (APCu ou fichiers)
├── config/                # Configuration centralisée
│   ├── app.php            # Config app (limites, upload, rate limiting)
│   └── sentry.php         # Config Sentry
├── docs/                  # Documentation
├── includes/              # Fichiers PHP partagés
│   ├── auth.php           # Authentification et session
│   ├── auth_role.php      # Vérification rôles et permissions
│   ├── db_connection.php  # Connexion PDO (Singleton)
│   ├── db.php             # Initialisation PDO (legacy)
│   ├── helpers.php         # Fonctions utilitaires
│   ├── Validator.php       # Validation de données
│   ├── CacheHelper.php     # Gestion du cache
│   ├── Logger.php          # Logging
│   ├── ErrorHandler.php    # Gestion d'erreurs
│   ├── rate_limiter.php    # Rate limiting
│   ├── security_headers.php # Headers de sécurité
│   └── session_config.php  # Configuration session
├── import/                # Scripts d'import
│   ├── run_import_if_due.php      # Orchestrateur import SFTP
│   ├── run_import_web_if_due.php  # Orchestrateur import IONOS
│   └── *.php              # Autres scripts d'import
├── public/                # Pages publiques (vues)
│   ├── *.php              # Pages principales
│   └── ajax/              # Endpoints AJAX
├── redirection/           # Pages de redirection (erreurs, accès interdit)
├── scripts/               # Scripts utilitaires
├── source/               # Code source partagé
│   ├── connexion/         # Traitement connexion
│   └── templates/         # Templates (header, etc.)
├── sql/                   # Scripts SQL et migrations
├── tests/                 # Tests unitaires et d'intégration
├── uploads/               # Fichiers uploadés
│   └── chatroom/          # Images chatroom
├── vendor/                # Dépendances Composer
├── index.php              # Point d'entrée (redirection)
├── router.php             # Routeur pour serveur PHP intégré
├── health.php             # Health check
├── Dockerfile             # Image Docker
├── Caddyfile              # Configuration Caddy
└── composer.json           # Dépendances
```

### Frontend ↔ Backend

**Communication :**
- **REST API** : Endpoints dans `/API/*.php` retournent du JSON
- **Formulaires HTML** : POST vers `/public/*.php` ou `/source/*.php`
- **AJAX** : Appels fetch() vers `/API/*.php` ou `/public/ajax/*.php`

**Format des réponses API :**
```json
{
  "ok": true/false,
  "data": {...},
  "error": "message d'erreur",
  "reason": "not_due|locked|auth_failed|..."
}
```

**Authentification API :**
- Session PHP (cookies)
- Vérification via `includes/auth.php` dans chaque endpoint
- Token CSRF pour les requêtes POST

**Exemples d'endpoints :**
- `/API/chatroom_get.php` : Récupérer les messages chatroom
- `/API/chatroom_send.php` : Envoyer un message
- `/API/dashboard_get_sav.php` : Liste des SAV
- `/API/dashboard_create_delivery.php` : Créer une livraison
- `/API/messagerie_send.php` : Envoyer un message privé
- `/API/maps_geocode.php` : Géocoder une adresse
- `/API/osrm_route.php` : Calculer un itinéraire

### Où se trouve la logique métier

**Architecture MVC légère (en cours de migration) :**

1. **Models** (`app/Models/`) :
   - `Client.php` : Modèle Client
   - `Photocopieur.php` : Modèle Photocopieur
   - `Releve.php` : Modèle Releve (avec méthodes de calcul)

2. **Repositories** (`app/Repositories/`) :
   - `ClientRepository.php` : Accès aux données clients
   - `CompteurRepository.php` : Accès aux relevés de compteurs

3. **Services** (`app/Services/`) :
   - `ConsumptionService.php` : Calcul des consommations
   - `DebtService.php` : Calcul des dettes

**Code legacy (à migrer progressivement) :**
- Logique métier directement dans `/public/*.php` et `/API/*.php`
- Requêtes SQL directes avec PDO
- Calculs inline (ex: calcul dettes dans `public/paiements.php`)

### Gestion de l'état

**Session PHP :**
- Stockage : Cookies (session PHP)
- Données stockées :
  - `user_id`, `user_email`, `user_nom`, `user_prenom`, `emploi`
  - `csrf_token`
  - `last_regenerate`, `last_activity_update`, `user_status_check_time`
  - `flash` (messages flash pour PRG pattern)

**Cache :**
- **APCu** ou **fichiers** via `CacheHelper.php`
- Utilisé pour : liste clients dashboard, permissions rôles
- TTL configurable dans `config/app.php`

**État frontend :**
- Variables JavaScript globales dans les pages
- Pas de state management centralisé (vanilla JS)

### Gestion des erreurs et validations

**Validation :**
- Classe `Validator.php` : Validation centralisée (email, téléphone, SIRET, etc.)
- Fonctions dans `includes/helpers.php` : `validateEmail()`, `validateString()`, etc.
- Validation côté serveur dans tous les formulaires
- Validation côté client (HTML5) pour UX

**Gestion d'erreurs :**
- `ErrorHandler.php` : Gestionnaire d'erreurs centralisé
- Try/catch dans tous les endpoints API
- Logging via `Logger.php` et `error_log()`
- Sentry pour monitoring production (si configuré)

**Messages d'erreur :**
- JSON pour les API : `{"ok": false, "error": "message"}`
- Flash messages pour les formulaires (pattern PRG)
- Toasts JavaScript pour les notifications

---

## 5️⃣ Base de données (très détaillé)

### Type de DB

**MySQL/MariaDB** avec charset `utf8mb4` et collation `utf8mb4_general_ci` ou `utf8mb4_0900_ai_ci`

### ORM / driver utilisé

**PDO** (PHP Data Objects) avec :
- Mode exceptions activé (`PDO::ERRMODE_EXCEPTION`)
- Mode fetch par défaut : `PDO::FETCH_ASSOC`
- Préparation des requêtes obligatoire (protection injection SQL)
- Pas d'ORM (requêtes SQL directes)

**Connexion centralisée :**
- `includes/db_connection.php` : Classe `DatabaseConnection` (Singleton)
- `includes/db.php` : Initialisation legacy (variable globale `$pdo`)

### Tables / collections principales

#### 1. `utilisateurs`
**Description** : Utilisateurs du système  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `Email` (VARCHAR(255), UNIQUE)
- `password` (VARCHAR(255), hash bcrypt)
- `nom`, `prenom` (VARCHAR(100))
- `telephone` (VARCHAR(20))
- `Emploi` (ENUM: 'Admin', 'Dirigeant', 'Chargé relation clients', 'Technicien', 'Livreur', 'Secrétaire')
- `statut` (ENUM: 'actif', 'inactif')
- `date_debut`, `date_creation`, `date_modification` (DATE/DATETIME)
- `last_activity` (DATETIME, nullable)

**Relations** :
- FK vers `historique.user_id`
- FK vers `messagerie.id_expediteur`, `messagerie.id_destinataire`
- FK vers `sav.id_technicien`
- FK vers `livraisons.id_livreur`
- FK vers `chatroom_messages.id_user`
- FK vers `user_permissions.user_id`

#### 2. `clients`
**Description** : Clients de l'entreprise  
**Champs principaux** :
- `id` (INT, PK)
- `numero_client` (VARCHAR(50), UNIQUE)
- `raison_sociale` (VARCHAR(255))
- `adresse`, `code_postal`, `ville` (VARCHAR)
- `adresse_livraison` (VARCHAR(255), nullable)
- `livraison_identique` (TINYINT(1))
- `siret` (VARCHAR(14))
- `numero_tva` (VARCHAR(50), nullable)
- `depot_mode` (ENUM: 'espece', 'cheque', 'virement', 'paiement_carte')
- `nom_dirigeant`, `prenom_dirigeant` (VARCHAR(100), nullable)
- `telephone1`, `telephone2` (VARCHAR(20))
- `email` (VARCHAR(255))
- `parrain` (VARCHAR(100), nullable)
- `offre` (ENUM: 'packbronze', 'packargent')
- `date_creation`, `date_dajout` (TIMESTAMP)
- `pdf1` à `pdf5`, `pdfcontrat` (VARCHAR(255), nullable, chemins fichiers)
- `iban` (VARCHAR(34), nullable)

**Relations** :
- FK vers `photocopieurs_clients.id_client`
- FK vers `sav.id_client`
- FK vers `livraisons.id_client`
- FK vers `client_stock.id_client`
- FK vers `client_geocode.id_client` (si table existe)

#### 3. `compteur_relevee`
**Description** : Relevés de compteurs (importés)  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `Timestamp` (DATETIME)
- `IpAddress` (VARCHAR(50), nullable)
- `Nom` (VARCHAR(255), nullable)
- `Model` (VARCHAR(100), nullable)
- `SerialNumber` (VARCHAR(100), nullable)
- `MacAddress` (VARCHAR(50), nullable)
- `Status` (VARCHAR(50), nullable)
- `TonerBlack`, `TonerCyan`, `TonerMagenta`, `TonerYellow` (INT, nullable)
- `TotalPages`, `FaxPages`, `CopiedPages`, `PrintedPages` (INT, nullable)
- `BWCopies`, `ColorCopies`, `MonoCopies`, `BichromeCopies` (INT, nullable)
- `BWPrinted`, `BichromePrinted`, `MonoPrinted`, `ColorPrinted` (INT, nullable)
- `TotalColor`, `TotalBW` (INT, nullable)
- `DateInsertion` (DATETIME, nullable)
- `mac_norm` (CHAR(12), GENERATED ALWAYS AS replace(upper(MacAddress), ':', ''))

**Index** :
- `ix_compteur_date` sur `Timestamp`
- `ix_compteur_mac_ts` sur `mac_norm`, `Timestamp`

**Table similaire** : `compteur_relevee_ancien` (structure identique, pour migration)

#### 4. `photocopieurs_clients`
**Description** : Lien entre clients et photocopieurs  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_client` (INT, nullable, FK vers `clients.id`)
- `SerialNumber` (VARCHAR(100), nullable, UNIQUE)
- `MacAddress` (VARCHAR(50), nullable)
- `mac_norm` (CHAR(12), GENERATED, UNIQUE)

**Relations** :
- FK vers `clients.id`

#### 5. `sav`
**Description** : Tickets SAV  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_client` (INT, nullable, FK)
- `mac_norm` (CHAR(12), nullable, index)
- `id_technicien` (INT, nullable, FK vers `utilisateurs.id`)
- `reference` (VARCHAR(64), UNIQUE)
- `description` (TEXT)
- `date_ouverture` (DATE)
- `date_intervention_prevue` (DATE, nullable)
- `temps_intervention_estime`, `temps_intervention_reel` (DECIMAL(4,2), nullable, heures)
- `cout_intervention` (DECIMAL(10,2), nullable, euros)
- `date_fermeture` (DATE, nullable)
- `satisfaction_client` (TINYINT, nullable, 1-5)
- `commentaire_client` (TEXT, nullable)
- `statut` (ENUM: 'ouvert', 'en_cours', 'resolu', 'annule')
- `priorite` (ENUM: 'basse', 'normale', 'haute', 'urgente')
- `type_panne` (ENUM: 'logiciel', 'materiel', 'piece_rechangeable', nullable)
- `commentaire` (TEXT, nullable)
- `notes_techniques` (TEXT, nullable, réservé aux techniciens)
- `created_at`, `updated_at` (DATETIME)

**Relations** :
- FK vers `clients.id`
- FK vers `utilisateurs.id` (technicien)
- FK vers `sav_pieces_utilisees.id_sav`

#### 6. `livraisons`
**Description** : Livraisons de produits  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_client` (INT, nullable, FK)
- `id_livreur` (INT, nullable, FK vers `utilisateurs.id`)
- `reference` (VARCHAR(64), UNIQUE)
- `adresse_livraison` (VARCHAR(255))
- `objet` (VARCHAR(255))
- `date_prevue` (DATE)
- `date_reelle` (DATE, nullable)
- `statut` (ENUM: 'planifiee', 'en_cours', 'livree', 'annulee')
- `commentaire` (TEXT, nullable)
- `product_type` (ENUM: 'papier', 'toner', 'lcd', 'pc', 'autre', nullable)
- `product_id` (INT, nullable)
- `product_qty` (INT, nullable)
- `created_at`, `updated_at` (DATETIME)

**Relations** :
- FK vers `clients.id`
- FK vers `utilisateurs.id` (livreur)

#### 7. `messagerie`
**Description** : Messages privés entre utilisateurs  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_expediteur` (INT, FK vers `utilisateurs.id`)
- `id_destinataire` (INT, nullable, FK vers `utilisateurs.id`)
- `sujet` (VARCHAR(255))
- `message` (TEXT)
- `type_lien` (ENUM: 'client', 'livraison', 'sav', nullable)
- `id_lien` (INT, nullable)
- `id_message_parent` (INT, nullable, FK vers `messagerie.id`)
- `type_reponse` (ENUM: 'text', 'emoji')
- `emoji_code` (VARCHAR(10), nullable)
- `lu` (TINYINT(1), default 0)
- `date_envoi` (DATETIME)
- `date_lecture` (DATETIME, nullable)
- `supprime_expediteur`, `supprime_destinataire` (TINYINT(1), default 0)

**Relations** :
- FK vers `utilisateurs.id` (expéditeur, destinataire)
- FK vers `messagerie.id` (parent)
- FK vers `messagerie_lectures.id_message`

#### 8. `chatroom_messages`
**Description** : Messages publics du chatroom  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_user` (INT, FK vers `utilisateurs.id`)
- `message` (TEXT)
- `date_envoi` (DATETIME)
- `mentions` (TEXT, nullable, JSON array d'IDs utilisateurs)
- `type_lien` (ENUM: 'client', 'livraison', 'sav', nullable)
- `id_lien` (INT, nullable)
- `image_path` (VARCHAR(255), nullable, si colonne existe)

**Relations** :
- FK vers `utilisateurs.id`
- FK vers `chatroom_notifications.id_message`

#### 9. `chatroom_notifications`
**Description** : Notifications pour le chatroom  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `id_user` (INT, FK vers `utilisateurs.id`)
- `id_message` (INT, FK vers `chatroom_messages.id`)
- `type` (ENUM: 'mention', 'message')
- `lu` (TINYINT(1), default 0)
- `date_creation` (DATETIME)

**Relations** :
- FK vers `utilisateurs.id`
- FK vers `chatroom_messages.id`

#### 10. `historique`
**Description** : Historique des actions utilisateurs  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `user_id` (INT, nullable, FK vers `utilisateurs.id`)
- `action` (VARCHAR(50))
- `details` (TEXT, nullable)
- `ip_address` (VARCHAR(45), nullable)
- `date_action` (DATETIME)

**Index** :
- `idx_user_id` sur `user_id`
- `idx_date_action` sur `date_action`

#### 11. `import_run`
**Description** : Logs des imports  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `ran_at` (DATETIME)
- `imported` (INT)
- `skipped` (INT)
- `ok` (TINYINT(1))
- `msg` (TEXT, nullable, JSON avec détails)

#### 12. `user_permissions`
**Description** : Permissions granulaires par utilisateur et page  
**Champs principaux** :
- `id` (INT, PK, AUTO_INCREMENT)
- `user_id` (INT, FK vers `utilisateurs.id`)
- `page` (VARCHAR(100))
- `allowed` (TINYINT(1), 1=autorisé, 0=interdit)
- `created_at`, `updated_at` (DATETIME)

**Index unique** : `uq_user_page` sur `user_id`, `page`

#### 13. Tables de stock

**`paper_catalog`** : Catalogue papier
- `id`, `marque`, `modele`, `poids`, `barcode`, `qr_code_path`

**`paper_moves`** : Mouvements papier
- `id`, `paper_id` (FK), `qty_delta`, `reason` (ENUM), `reference`, `user_id`, `created_at`
- Stock = SUM(`qty_delta`)

**`toner_catalog`** : Catalogue toner
- `id`, `marque`, `modele`, `couleur`, `barcode`, `qr_code_path`, `qty_stock`

**`toner_moves`** : Mouvements toner (structure similaire à `paper_moves`)

**`lcd_catalog`** : Catalogue écrans LCD
- `id`, `marque`, `reference`, `etat`, `modele`, `taille`, `resolution`, `connectique`, `prix`, `barcode`, `qr_code_path`, `qty_stock`

**`lcd_moves`** : Mouvements LCD

**`pc_catalog`** : Catalogue PC
- `id`, `etat`, `reference`, `marque`, `modele`, `cpu`, `ram`, `stockage`, `os`, `gpu`, `reseau`, `ports`, `prix`, `barcode`, `qr_code_path`, `qty_stock`

**`pc_moves`** : Mouvements PC

**`client_stock`** : Stock attribué aux clients
- `id`, `id_client` (FK), `product_type` (ENUM), `product_id`, `qty_stock`

#### 14. Autres tables

**`app_kv`** : Key-value store pour configuration
- `k` (VARCHAR(64), PK), `v` (TEXT)

**`ionos_cursor`** : Curseur pour import IONOS
- `id` (TINYINT), `last_ts` (DATETIME), `last_mac` (CHAR(12))

**`sftp_jobs`** : Jobs d'import SFTP (à confirmer utilisation)
- `id`, `status` (ENUM), `created_at`, `started_at`, `finished_at`, `summary` (JSON), `error`, `triggered_by`

**`client_geocode`** : Géocodage des clients (si migration appliquée)
- `id_client` (FK), `latitude`, `longitude`, `address`, `geocoded_at`

**`factures`** : Factures (si table existe)
- Structure à confirmer dans le code

### Relations entre les entités

**Graphe des relations principales :**

```
utilisateurs
  ├── historique (user_id)
  ├── messagerie (id_expediteur, id_destinataire)
  ├── sav (id_technicien)
  ├── livraisons (id_livreur)
  ├── chatroom_messages (id_user)
  ├── chatroom_notifications (id_user)
  └── user_permissions (user_id)

clients
  ├── photocopieurs_clients (id_client)
  ├── sav (id_client)
  ├── livraisons (id_client)
  ├── client_stock (id_client)
  └── client_geocode (id_client)

photocopieurs_clients
  └── (lien via mac_norm avec compteur_relevee.mac_norm)

compteur_relevee
  └── (lien via mac_norm avec photocopieurs_clients.mac_norm)

sav
  ├── clients (id_client)
  ├── utilisateurs (id_technicien)
  └── sav_pieces_utilisees (id_sav)

livraisons
  ├── clients (id_client)
  └── utilisateurs (id_livreur)

messagerie
  ├── utilisateurs (id_expediteur, id_destinataire)
  └── messagerie (id_message_parent, auto-référence)

chatroom_messages
  ├── utilisateurs (id_user)
  └── chatroom_notifications (id_message)
```

### Champs importants par table

**Voir section "Tables / collections principales" ci-dessus pour les détails complets.**

### Migrations

**Emplacement** : `/sql/` et `/sql/migrations/`

**Scripts de migration identifiés :**
- `sql/run_migration_sav.php` : Création table SAV
- `sql/run_migration_client_geocode.php` : Ajout géocodage clients
- `sql/run_migration_client_stock.php` : Création table client_stock
- `sql/run_migration_user_permissions.php` : Création table user_permissions
- `sql/run_migration_last_activity.php` : Ajout champ last_activity
- `sql/migrations/add_client_geocode_table.sql` : SQL pour géocodage
- `sql/migrations/add_indexes_optimization.sql` : Optimisation index

**Comment exécuter** :
- Scripts PHP : `php sql/run_migration_*.php`
- Scripts SQL : Import direct dans MySQL

**À noter** : Les migrations vérifient généralement si la table/colonne existe avant de créer.

### Seeds

**À confirmer** : Pas de seeds identifiés dans le code. Les données initiales sont probablement insérées manuellement ou via des scripts SQL.

---

## 6️⃣ Flux de données (scénarios concrets)

### Scénario 1 : Authentification (Login)

**Page déclencheuse** : `/public/login.php`

**Flow complet** :

1. **Formulaire soumis** (POST vers `/source/connexion/login_process.php`)
   - Données : `email`, `password`, `csrf_token`

2. **Validation CSRF**
   - Vérification `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])`
   - Si échec : Redirection vers login avec erreur

3. **Recherche utilisateur**
   ```sql
   SELECT id, Email, password, nom, prenom, telephone, Emploi, statut, ...
   FROM utilisateurs 
   WHERE Email = :email
   ```

4. **Vérification mot de passe**
   - `password_verify($pass, $user['password'])`
   - Si échec : Redirection avec erreur "Adresse e-mail ou mot de passe incorrect"

5. **Vérification statut**
   - Si `statut !== 'actif'` : Redirection avec erreur "Votre compte est désactivé"

6. **Rehash si nécessaire**
   - `password_needs_rehash()` → mise à jour si cost < 10

7. **Écriture session**
   - `session_regenerate_id(true)`
   - `$_SESSION['user_id']`, `$_SESSION['user_email']`, etc.
   - `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`

8. **Mise à jour DB**
   ```sql
   UPDATE utilisateurs SET last_activity = NOW() WHERE id = ?
   ```

9. **Redirection**
   - `header('Location: /public/dashboard.php')`

**Réponse backend** : Redirection HTTP 302

**Mise à jour UI** : Page dashboard chargée

---

### Scénario 2 : Import automatique SFTP

**Page déclencheuse** : `/public/dashboard.php` (chargement automatique)

**Flow complet** :

1. **Appel JavaScript** (au chargement du dashboard)
   ```javascript
   fetch('/import/run_import_if_due.php?limit=20&force=1', {
     method: 'POST',
     credentials: 'same-origin'
   })
   ```

2. **Vérification auth** (`/import/run_import_if_due.php`)
   - `require_once 'includes/auth.php'` → Redirection si non connecté

3. **Acquisition verrou MySQL**
   ```sql
   SELECT GET_LOCK('import_compteur_sftp', 0)
   ```
   - Si verrou non acquis : Retour JSON `{"ok": false, "reason": "locked"}`

4. **Vérification "due"**
   - Lecture `app_kv` : `SELECT v FROM app_kv WHERE k = 'sftp_last_run'`
   - Calcul : `elapsed = time() - lastTimestamp`
   - Si `elapsed < INTERVAL` et `!force` : Retour JSON `{"ok": false, "reason": "not_due"}`

5. **Exécution script import**
   - `exec("php API/scripts/upload_compteur.php", $output, $code)`
   - Timeout : 60 secondes max

6. **Script upload_compteur.php** :
   - Connexion SFTP (phpseclib)
   - Liste fichiers : Pattern `COPIEUR_MAC-*.csv`
   - Pour chaque fichier :
     - Téléchargement
     - Parsing CSV
     - Insertion dans `compteur_relevee` (avec vérification doublons via `mac_norm` + `Timestamp`)
     - Déplacement vers `/processed` ou `/errors`
   - Log dans `import_run`

7. **Mise à jour timestamp**
   ```sql
   INSERT INTO app_kv (k, v) VALUES ('sftp_last_run', NOW())
   ON DUPLICATE KEY UPDATE v = NOW()
   ```

8. **Libération verrou**
   ```sql
   SELECT RELEASE_LOCK('import_compteur_sftp')
   ```

9. **Réponse JSON**
   ```json
   {
     "ok": true,
     "ran": true,
     "inserted": 5,
     "updated": 2,
     "skipped": 0,
     "next_due_in_sec": 280
   }
   ```

10. **Mise à jour UI**
    - Badge import mis à jour avec le résultat
    - Toast de succès si éléments traités

**Validations** :
- Auth : Session valide
- Lock : Pas d'import en cours
- Due : Intervalle respecté (ou force=1)
- SFTP : Connexion réussie
- CSV : Format valide
- DB : Pas de doublons (mac_norm + Timestamp)

**Lecture DB** :
- `app_kv` : Dernière exécution
- `compteur_relevee` : Vérification doublons

**Écriture DB** :
- `compteur_relevee` : Insertion nouveaux relevés
- `import_run` : Log de l'import
- `app_kv` : Mise à jour timestamp

---

### Scénario 3 : Création d'une livraison

**Page déclencheuse** : `/public/dashboard.php` (clic sur bouton "Créer livraison")

**Flow complet** :

1. **Formulaire modal** (JavaScript)
   - Champs : client, livreur, référence, adresse, objet, date prévue, produit

2. **Soumission** (POST vers `/API/dashboard_create_delivery.php`)
   ```javascript
   fetch('/API/dashboard_create_delivery.php', {
     method: 'POST',
     body: JSON.stringify({...}),
     headers: {'Content-Type': 'application/json'}
   })
   ```

3. **Vérification auth** (`/API/dashboard_create_delivery.php`)
   - `require_once 'includes/auth.php'`

4. **Validation CSRF**
   - Vérification token CSRF

5. **Validation données**
   - Référence unique
   - Client existe
   - Livreur existe (si fourni)
   - Date prévue valide

6. **Insertion DB**
   ```sql
   INSERT INTO livraisons (
     id_client, id_livreur, reference, adresse_livraison,
     objet, date_prevue, statut, product_type, product_id, product_qty
   ) VALUES (...)
   ```
   - Statut par défaut : `'planifiee'`

7. **Log historique**
   ```sql
   INSERT INTO historique (user_id, action, details, ip_address, date_action)
   VALUES (?, 'create_delivery', ?, ?, NOW())
   ```

8. **Réponse JSON**
   ```json
   {
     "ok": true,
     "id": 123,
     "message": "Livraison créée avec succès"
   }
   ```

9. **Mise à jour UI**
   - Fermeture modal
   - Rafraîchissement liste livraisons
   - Toast de succès

**Validations** :
- Auth : Utilisateur connecté
- CSRF : Token valide
- Référence : Unique dans `livraisons`
- Client : Existe dans `clients`
- Livreur : Existe dans `utilisateurs` (si fourni)
- Permissions : Vérification via `authorize_page()` si nécessaire

**Lecture DB** :
- `clients` : Vérification existence
- `utilisateurs` : Vérification livreur
- `livraisons` : Vérification référence unique

**Écriture DB** :
- `livraisons` : Insertion
- `historique` : Log action

---

### Scénario 4 : Calcul d'une dette client

**Page déclencheuse** : `/public/paiements.php` ou `/public/client_fiche.php`

**Flow complet** :

1. **Sélection période** (formulaire)
   - Date début, date fin
   - MAC du photocopieur (ou sélection client)

2. **Appel service** (PHP backend)
   ```php
   $debtService = new DebtService($consumptionService);
   $debt = $debtService->calculateDebtForPeriod($client, $macNorm, $periodStart, $periodEnd);
   ```

3. **Service ConsumptionService**
   - Récupération relevé début : `CompteurRepository->findPeriodStartCounter($macNorm, $periodStart)`
   - Récupération relevé fin : `CompteurRepository->findPeriodEndCounter($macNorm, $periodEnd)`
   - Calcul consommation :
     - `bw = end->totalBw - start->totalBw`
     - `color = end->totalColor - start->totalColor`

4. **Service DebtService**
   - Calcul montant N&B :
     - Si `bw > 1000` : `bwAmount = bw * 0.05`
     - Sinon : `bwAmount = 0`
   - Calcul montant couleur : `colorAmount = color * 0.09`
   - Total : `debt = bwAmount + colorAmount`

5. **Réponse**
   ```php
   [
     'debt' => 125.50,
     'bw_consumption' => 1500,
     'color_consumption' => 200,
     'bw_amount' => 75.00,
     'color_amount' => 18.00,
     'period_start' => DateTime,
     'period_end' => DateTime
   ]
   ```

6. **Affichage UI**
   - Tableau détaillé avec consommation et montants
   - Total dette

**Validations** :
- Période : Date début < date fin
- Relevés : Existence des relevés début et fin
- MAC : Existe dans `photocopieurs_clients`

**Lecture DB** :
- `compteur_relevee` : Relevés pour la période
- `photocopieurs_clients` : Vérification MAC

**Écriture DB** : Aucune (calcul uniquement)

---

### Scénario 5 : Envoi d'un message dans le chatroom

**Page déclencheuse** : `/public/messagerie.php` (onglet Chatroom)

**Flow complet** :

1. **Saisie message** (textarea)
   - Support mentions : `@username`
   - Support upload image (optionnel)

2. **Envoi** (POST vers `/API/chatroom_send.php`)
   ```javascript
   fetch('/API/chatroom_send.php', {
     method: 'POST',
     body: JSON.stringify({
       message: "...",
       mentions: [1, 2, 3], // IDs utilisateurs mentionnés
       type_lien: 'client',
       id_lien: 123
     })
   })
   ```

3. **Vérification auth** (`/API/chatroom_send.php`)
   - `require_once 'includes/auth.php'`

4. **Validation CSRF**
   - Vérification token

5. **Traitement mentions**
   - Extraction `@username` du message
   - Recherche IDs utilisateurs
   - Stockage dans `mentions` (JSON array)

6. **Upload image** (si présent)
   - Validation type (jpg, png)
   - Validation taille (max 10MB)
   - Sauvegarde dans `/uploads/chatroom/`
   - Chemin stocké dans `image_path`

7. **Insertion message**
   ```sql
   INSERT INTO chatroom_messages (
     id_user, message, date_envoi, mentions, type_lien, id_lien, image_path
   ) VALUES (...)
   ```

8. **Création notifications**
   - Pour chaque mention : Insertion dans `chatroom_notifications`
   ```sql
   INSERT INTO chatroom_notifications (id_user, id_message, type, lu)
   VALUES (?, ?, 'mention', 0)
   ```

9. **Réponse JSON**
   ```json
   {
     "ok": true,
     "id": 456,
     "message": "Message envoyé"
   }
   ```

10. **Mise à jour UI**
    - Ajout message dans la liste (sans refresh)
    - Scroll automatique
    - Notification badge mis à jour

**Validations** :
- Auth : Utilisateur connecté
- CSRF : Token valide
- Message : Non vide, longueur max (5000 caractères)
- Mentions : Utilisateurs existants
- Image : Type et taille valides

**Lecture DB** :
- `utilisateurs` : Recherche mentions
- `chatroom_messages` : Dernier ID pour notifications

**Écriture DB** :
- `chatroom_messages` : Insertion
- `chatroom_notifications` : Création notifications
- Fichier système : Upload image

---

## 7️⃣ Sécurité & Auth

### Type d'authentification

**Session PHP** avec cookies :
- Stockage : Cookies HTTP (session PHP)
- Configuration : `includes/session_config.php`
- Paramètres :
  - `session.cookie_httponly = 1` (protection XSS)
  - `session.cookie_secure = 1` (HTTPS uniquement en production)
  - `session.cookie_samesite = 'Strict'`
  - Régénération ID toutes les 15 minutes

**Pas de JWT** : Authentification basée uniquement sur les sessions PHP.

### Autorisations (roles, guards, middlewares)

**Système de rôles** :
- Rôles stockés dans `utilisateurs.Emploi` (ENUM)
- Vérification via `includes/auth_role.php` :
  - `authorize_roles(['Admin'])` : Vérifie si utilisateur a le rôle
  - `requireAdmin()` : Accès Admin uniquement
  - `requireCommercial()` : Accès Chargé relation clients ou Admin
  - `authorize_page('dashboard', [])` : Vérifie permission page avec ACL

**Système ACL** :
- Table `user_permissions` : Permissions granulaires par utilisateur et page
- Fallback sur rôles si pas de permission explicite
- Fonction `checkPagePermission($page, $allowed_roles)` :
  1. Vérifie `user_permissions` pour l'utilisateur et la page
  2. Si permission existe : Utilise cette valeur
  3. Sinon : Utilise les rôles par défaut

**Guards/Middlewares** :
- `includes/auth.php` : Vérifie session sur chaque page protégée
- `includes/auth_role.php` : Vérifie rôles/permissions
- Redirection automatique vers `/redirection/acces_interdit.php` si accès refusé

### Endpoints protégés

**Tous les endpoints** (sauf login et health) :
- `/public/*.php` : Require `includes/auth.php`
- `/API/*.php` : Require `includes/auth.php`
- `/import/*.php` : Require `includes/auth.php`

**Endpoints publics** :
- `/public/login.php` : Connexion
- `/health.php` : Health check
- `/index.php` : Redirection

**Protection CSRF** :
- Tous les formulaires POST : Token CSRF requis
- Vérification via `verifyCsrfToken()` ou `assertValidCsrf()`
- Token stocké dans `$_SESSION['csrf_token']`

### Gestion des secrets et variables d'environnement

**Variables d'environnement** :
- `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` : Connexion DB
- `SFTP_IMPORT_INTERVAL_SEC` : Intervalle import SFTP (défaut: 20)
- Variables Sentry (si configuré) : `SENTRY_DSN`, etc.

**Configuration** :
- Priorité 1 : Variables d'environnement (Railway, Docker)
- Priorité 2 : Fichier `includes/db_config.local.php` (local, non versionné)
- Priorité 3 : Valeurs par défaut (XAMPP local)

**Secrets** :
- Mots de passe : Hash bcrypt (cost 10)
- Tokens CSRF : Générés avec `random_bytes(32)`
- Sessions : ID régénérés régulièrement

**À noter** : Le fichier `includes/db_config.local.php` n'est pas versionné (à créer localement si nécessaire).

---

## 8️⃣ Services externes (si présents)

### Paiement

**À confirmer** : Pas de service de paiement externe identifié dans le code. La page `/public/paiements.php` semble gérer uniquement le calcul et l'affichage des dettes, pas les paiements en ligne.

### Email

**PHPMailer** (dépendance Composer) :
- Package : `phpmailer/phpmailer ^6.9`
- Utilisation : À confirmer dans le code (probablement pour notifications)

### Stockage fichiers

**Stockage local** :
- `/uploads/chatroom/` : Images chatroom
- PDFs clients : Chemins stockés dans `clients.pdf1` à `pdf5`, `pdfcontrat`
- QR codes : Chemins dans `*_catalog.qr_code_path`

**À confirmer** : Pas de stockage cloud identifié (S3, etc.).

### APIs tierces

**1. Géocodage** :
- Endpoint : `/API/maps_geocode.php`
- Service utilisé : À confirmer (probablement Nominatim ou Google Maps)
- Utilisation : Géocodage des adresses clients pour affichage sur carte

**2. Calcul d'itinéraires (OSRM) :**
- Endpoint : `/API/osrm_route.php`
- Service : OSRM (Open Source Routing Machine)
- Utilisation : Calcul d'itinéraires entre points sur la carte

**3. Import IONOS :**
- Script : `/import/run_import_web_if_due.php`
- Service : API IONOS (à confirmer)
- Utilisation : Import de relevés depuis l'API IONOS

**4. SFTP :**
- Bibliothèque : `phpseclib/phpseclib`
- Utilisation : Connexion SFTP pour téléchargement fichiers CSV
- Configuration : Credentials SFTP (à confirmer où stockés)

**5. Sentry (monitoring) :**
- Package : `sentry/sentry ^4.0`
- Configuration : `config/sentry.php`
- Utilisation : Monitoring d'erreurs en production

---

## 9️⃣ Commandes & configuration

### Commandes pour lancer le projet

**Développement local (XAMPP) :**
1. Démarrer XAMPP (Apache + MySQL)
2. Importer la base de données : `sql/railway.sql`
3. Configurer la connexion DB :
   - Créer `includes/db_config.local.php` (non versionné) OU
   - Utiliser les valeurs par défaut (localhost, root, pas de mot de passe)
4. Installer les dépendances :
   ```bash
   composer install
   ```
5. Accéder à : `http://localhost/cccomputer/`

**Docker :**
```bash
# Build
docker build -t cccomputer .

# Run
docker run -p 80:80 \
  -e MYSQLHOST=... \
  -e MYSQLPORT=3306 \
  -e MYSQLDATABASE=cccomputer \
  -e MYSQLUSER=... \
  -e MYSQLPASSWORD=... \
  cccomputer
```

**Serveur PHP intégré (dev) :**
```bash
php -S localhost:8000 router.php
```

### Build

**Pas de build nécessaire** : PHP interprété directement.

**Dépendances** :
```bash
composer install        # Production
composer install --dev # Avec dev dependencies (PHPUnit)
```

### Tests

**Framework** : PHPUnit 10.0+

**Emplacement** : `/tests/`

**Tests identifiés** :
- `/tests/Unit/ConsumptionCalculatorTest.php`
- `/tests/Unit/DebtCalculatorTest.php`
- `/tests/Unit/ValidatorTest.php`
- `/tests/Api/ClientsApiTest.php`

**Exécution** :
```bash
vendor/bin/phpunit
# ou
composer test  # Si script défini dans composer.json
```

**Configuration** : `phpunit.xml`

### Migrations DB

**Scripts PHP** :
```bash
php sql/run_migration_sav.php
php sql/run_migration_client_geocode.php
php sql/run_migration_client_stock.php
php sql/run_migration_user_permissions.php
php sql/run_migration_last_activity.php
```

**Scripts SQL** :
```bash
mysql -u user -p database < sql/migrations/add_client_geocode_table.sql
mysql -u user -p database < sql/migrations/add_indexes_optimization.sql
```

**Import base complète** :
```bash
mysql -u user -p database < sql/railway.sql
```

### Variables d'environnement attendues

**Base de données** :
- `MYSQLHOST` : Host MySQL (défaut: localhost)
- `MYSQLPORT` : Port MySQL (défaut: 3306)
- `MYSQLDATABASE` : Nom de la base (défaut: cccomputer)
- `MYSQLUSER` : Utilisateur MySQL (défaut: root)
- `MYSQLPASSWORD` : Mot de passe MySQL (défaut: vide)

**Import SFTP** :
- `SFTP_IMPORT_INTERVAL_SEC` : Intervalle minimum entre imports (défaut: 20)

**Sentry** (si configuré) :
- `SENTRY_DSN` : DSN Sentry pour monitoring

**À noter** : Les credentials SFTP ne sont pas dans les variables d'environnement identifiées. À confirmer où ils sont stockés (probablement dans un fichier de config non versionné).

---

## 🔟 Livrable final

Ce document (`PROJECT_OVERVIEW.md`) constitue la synthèse complète du projet CCComputer.

### Résumé exécutif

Application web PHP fullstack de gestion de photocopieurs avec import automatique de relevés, calcul de dettes, gestion SAV/livraisons/stock, messagerie interne, et cartes interactives.

### Fonctionnalités

- Gestion clients complète
- Import automatique relevés (SFTP, IONOS)
- Calcul dettes basé sur consommation
- Gestion SAV avec tickets et assignation techniciens
- Gestion livraisons avec assignation livreurs
- Gestion stock (papier, toner, LCD, PC)
- Messagerie interne et chatroom
- Cartes interactives avec géocodage et itinéraires

### Architecture

- Backend PHP 8.0+ avec PDO
- Architecture MVC légère (en cours de migration)
- Frontend vanilla JavaScript
- API REST pour communication AJAX
- Session PHP pour authentification
- Cache APCu/fichiers

### Base de données

- MySQL/MariaDB avec 20+ tables
- Relations bien définies avec foreign keys
- Index pour performance
- Migrations disponibles

### Flux clés

- Authentification avec session PHP
- Import automatique SFTP toutes les 20 secondes
- Création livraisons/SAV avec permissions
- Calcul dettes via services dédiés
- Messagerie en temps réel

### Sécurité

- Session PHP avec régénération ID
- CSRF protection sur tous les formulaires
- Système de rôles + ACL granulaires
- Validation des données
- Headers de sécurité

### Commandes utiles

- `composer install` : Installer dépendances
- `php sql/run_migration_*.php` : Exécuter migrations
- `vendor/bin/phpunit` : Lancer tests
- Docker : `docker build` et `docker run`

---

**Document créé le** : 2024  
**Dernière mise à jour** : 2024  
**Auteur** : Analyse automatique du code  
**Version** : 1.0

