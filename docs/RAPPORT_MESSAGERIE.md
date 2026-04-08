# Rapport de documentation - Page Messagerie CCComputer

**Date :** 6 mars 2025  
**Périmètre :** Page messagerie complète (chat général + messagerie privée)

---

## A. Vue d'ensemble

### Rôle de la page messagerie

La page messagerie (`public/messagerie.php`) fournit une interface unifiée pour :
- **Chat général** : messages visibles par tous les utilisateurs autorisés
- **Messagerie privée** : conversations 1-à-1 strictement isolées entre deux utilisateurs

### Objectifs fonctionnels

- Permettre l'échange de messages en temps réel (via polling)
- Gérer les images (upload, affichage, lightbox)
- Afficher le statut en ligne / hors ligne des interlocuteurs
- Supprimer automatiquement tout contenu après 24 heures
- Notifier les nouveaux messages (badge header, toast)
- Afficher les statuts reçu/lu pour les messages privés envoyés

---

## B. Fichiers impliqués

### Page principale

| Fichier | Rôle |
|---------|------|
| `public/messagerie.php` | Page principale avec onglets Général / Privé, HTML + JavaScript inline |

### Includes et dépendances

| Fichier | Rôle |
|---------|------|
| `includes/auth.php` | Vérification session utilisateur |
| `includes/auth_role.php` | `authorize_page('messagerie', [])` - accès via ACL ou tous les rôles |
| `includes/helpers.php` | `getPdo()`, `ensureCsrfToken()` |
| `includes/messagerie_purge.php` | Fonction `purgeMessagerie24h()` - utilisée uniquement par le script cron |
| `source/templates/header.php` | En-tête avec badge messagerie, lien, toast nouveaux messages |

### API Chat général

| Fichier | Rôle |
|---------|------|
| `API/chatroom_get.php` | GET - Récupérer les messages (limit, since_id) |
| `API/chatroom_send.php` | POST - Envoyer un message (JSON + CSRF) |
| `API/chatroom_upload_image.php` | POST - Upload d'image (FormData + CSRF) |
| `API/chatroom_get_notifications.php` | GET - Nombre de notifications non lues |
| `API/chatroom_mark_notifications_read.php` | POST - Marquer notifications comme lues |

### API Messagerie privée

| Fichier | Rôle |
|---------|------|
| `API/private_messages_get.php` | GET - Récupérer les messages entre deux utilisateurs, marquer lu |
| `API/private_messages_send.php` | POST - Envoyer un message privé |
| `API/private_messages_list_users.php` | GET - Liste des utilisateurs avec statut en ligne |

### API Présence / Badge

| Fichier | Rôle |
|---------|------|
| `API/user_online_status.php` | GET - Statut en ligne d'un utilisateur |
| `API/chatroom_get_online_users.php` | GET - Liste des utilisateurs en ligne + heartbeat |
| `API/messagerie_get_unread_count.php` | GET - Nombre de messages privés non lus (badge) |

### Assets

| Fichier | Rôle |
|---------|------|
| `assets/css/main.css` | Styles globaux |
| `assets/css/chatroom.css` | Styles messagerie (onglets, messages, statuts, lightbox) |
| `assets/css/header.css` | Styles badge, toast |
| `assets/js/api.js` | Chargé par la page (dépendances éventuelles) |

### Scripts

| Fichier | Rôle |
|---------|------|
| `scripts/messagerie_cleanup.php` | Cron - Purge général + privé + images |
| `scripts/private_messages_cleanup.php` | Déprécié - appelle la purge centralisée |

### Base de données

| Table | Rôle |
|-------|------|
| `chatroom_messages` | Messages du chat général |
| `chatroom_notifications` | Notifications (mentions, nouveaux messages) |
| `private_messages` | Messages privés 1-à-1 |
| `utilisateurs` | last_activity pour statut en ligne |

### Migrations SQL

| Fichier | Rôle |
|---------|------|
| `sql/migration_create_private_messages.sql` | Création table private_messages |
| `sql/migration_private_messages_read_status.sql` | Colonnes lu, delivered_at, read_at |
| `sql/run_migration_private_messages_read_status.php` | Script PHP de migration |

---

## C. Fonctionnalités actuelles

### Chat général

- Affichage des messages dans un ordre chronologique
- Envoi de messages texte ou image
- Polling toutes les 2 secondes pour nouveaux messages
- Marquage des notifications comme lues à l'ouverture de l'onglet
- Purge automatique après 24h
- Bouton « Actualiser » pour recharger manuellement

### Messagerie privée

- Liste des utilisateurs avec recherche
- Sélection d'un utilisateur pour ouvrir la conversation
- Affichage des messages uniquement entre moi et l'interlocuteur
- Envoi de messages texte ou image
- Polling toutes les 3 secondes pour nouveaux messages
- Statuts reçu/lu pour les messages envoyés (○ envoyé, ✓ reçu, ✓✓ lu)
- Purge automatique après 24h
- Isolation stricte des conversations (vidage du conteneur au changement)

### Images / captures

- Upload via bouton ou sélection fichier
- Coller une image depuis le presse-papier (paste)
- Formats acceptés : JPEG, PNG, GIF, WebP
- Taille max : 5 MB
- Aperçu avant envoi
- Lightbox au clic sur une image affichée
- Stockage dans `/uploads/chatroom/` (partagé général + privé)

### Statuts en ligne / hors ligne

- Indicateur dans la liste des utilisateurs (point vert / gris)
- Indicateur dans l'en-tête de conversation sélectionnée
- Basé sur `last_activity` (activité dans les 5 dernières minutes)
- Heartbeat via `chatroom_get_online_users.php` toutes les 30 secondes

### Suppression automatique après 24h

- Exécutée **uniquement** via cron : `scripts/messagerie_cleanup.php`
- Plus de purge au chargement de page (production-ready)
- Tables : `chatroom_messages`, `private_messages`
- Suppression des fichiers images associés
- `chatroom_notifications` : cascade via FK sur suppression

### Notifications / refresh

- Badge header : chatroom_notifications + private_messages non lus
- Toast « X nouveau(x) message(s) » quand le badge passe de 0 à > 0 (hors page messagerie)
- Mise à jour du badge toutes les 10 secondes
- Mise à jour à la visibilité de l'onglet (visibilitychange)

---

## D. Flux techniques

### Chargement d'une conversation

**Chat général :**
1. `loadGeneralMessages(false)` → `GET /API/chatroom_get.php?limit=100`
2. Réponse JSON → `renderGeneralMessages(messages, false)`
3. Polling : `loadGeneralMessages(true)` → `GET /API/chatroom_get.php?since_id={lastId}`

**Messagerie privée :**
1. `selectUser(userId)` → vide le conteneur, `loadPrivateMessages(false)`
2. `GET /API/private_messages_get.php?with={userId}&limit=100`
3. API marque les messages reçus comme lu (delivered_at, read_at)
4. Réponse → `renderPrivateMessages(messages, false)`
5. Polling : `loadPrivateMessages(true)` → `GET /API/private_messages_get.php?with={userId}&since_id={lastId}`

### Envoi d'un message

**Général :**
1. Si image : `POST /API/chatroom_upload_image.php` (FormData)
2. `POST /API/chatroom_send.php` (JSON : message, image_path, csrf_token)
3. Réponse → `renderGeneralMessages([data.message], true)`

**Privé :**
1. Si image : `POST /API/chatroom_upload_image.php` (FormData)
2. `POST /API/private_messages_send.php` (JSON : id_receiver, message, image_path, csrf_token)
3. Réponse → `renderPrivateMessages([data.message], true)`

### Logique de présence utilisateur

- `last_activity` dans `utilisateurs` mis à jour par `chatroom_get_online_users.php` (toutes les 30s)
- `private_messages_list_users.php` : calcule `online` si `last_activity` < 5 min
- `user_online_status.php` : vérifie si un utilisateur est en ligne (last_activity < 5 min)

### Logique d'expiration / suppression

- `purgeMessagerie24h($pdo)` dans `includes/messagerie_purge.php`
- Appelée uniquement par `scripts/messagerie_cleanup.php` (cron)
- Récupère les messages avec `date_envoi < NOW() - 24h`
- Supprime les fichiers images via `resolveImagePathForPurge()` (essaie DOCUMENT_ROOT, project root, project/public)
- Supprime les lignes en base (DELETE avec IN batch)

---

## E. Base de données

### Tables utilisées

| Table | Colonnes principales |
|-------|----------------------|
| `chatroom_messages` | id, id_user, message, date_envoi, mentions, image_path |
| `chatroom_notifications` | id, id_user, id_message, type, lu, date_creation |
| `private_messages` | id, id_sender, id_receiver, message, image_path, date_envoi, lu, delivered_at, read_at |
| `utilisateurs` | id, nom, prenom, last_activity, statut |

### Relations

- `chatroom_notifications.id_message` → `chatroom_messages.id` (ON DELETE CASCADE)
- `chatroom_notifications.id_user` → `utilisateurs.id`
- `private_messages.id_sender` / `id_receiver` → `utilisateurs.id`

### Logique de stockage

- **Général** : un message = une ligne dans `chatroom_messages` ; notifications créées pour chaque utilisateur (sauf expéditeur)
- **Privé** : un message = une ligne dans `private_messages` avec id_sender et id_receiver
- **Images** : chemin relatif `/uploads/chatroom/filename.ext` stocké dans image_path

---

## F. Sécurité

### Authentification

- `authorize_page('messagerie', [])` : accès via ACL ou tous les rôles
- `requireApiAuth()` sur tous les endpoints API (sauf chatroom_get_notifications et messagerie_get_unread_count qui retournent 0 si non connecté)

### CSRF

- Token CSRF dans `session` via `ensureCsrfToken()`
- Envoi dans le body JSON pour chatroom_send, chatroom_mark_notifications_read, private_messages_send
- Envoi dans FormData pour chatroom_upload_image
- Vérification via `requireCsrfToken($token)` côté API

### Permissions

- Vérification que le destinataire existe et est actif (private_messages_send)
- Filtrage strict par conversation : `(id_sender, id_receiver)` ou `(id_receiver, id_sender)` pour private_messages_get

### Validations

- Longueur message : max 5000 caractères
- Image : MIME type (JPEG, PNG, GIF, WebP), taille max 5 MB, extension dérivée du MIME
- Chemin image : regex `/^\/uploads\/chatroom\/[a-zA-Z0-9_\-\.]+$/` pour éviter path traversal

### Protections SQL / XSS / upload

- PDO + prepared statements
- `escapeHtml()` sur tout contenu affiché (message, noms, URLs)
- URLs transformées en liens avec `escapeHtml(url)` dans le href
- `is_uploaded_file()` pour upload

---

## G. État actuel de la messagerie

### Ce qui fonctionne

- Chat général : affichage, envoi, images, polling, purge 24h
- Messagerie privée : liste utilisateurs, sélection, affichage isolé, envoi, images, statuts reçu/lu
- Présence : indicateurs en ligne / hors ligne
- Badge header : somme chatroom + privé
- Toast : nouveaux messages hors page messagerie
- Marquage lu : général à l'ouverture, privé à la récupération des messages

### Ce qui est partiellement fonctionnel

- **Migration lu/delivered_at/read_at** : si la migration n'est pas exécutée, les statuts reçu/lu ne s'affichent pas (mais pas d'erreur)
- **Chemin images purge** : `baseDir` = `dirname(__DIR__)` depuis includes = racine projet. Si la config serveur place les uploads ailleurs (ex. DOCUMENT_ROOT différent), la purge pourrait ne pas trouver les fichiers.

### Ce qui est fragile

- Pas de WebSocket : polling uniquement

### Ce qui est obsolète

- **API ancienne messagerie** : `messagerie_send.php`, `messagerie_reply.php`, `messagerie_mark_read.php`, `messagerie_delete.php` utilisent la table `messagerie` (ancienne structure). Non utilisées par la page messagerie actuelle.
- **scripts/private_messages_cleanup.php** : déprécié, remplacé par `scripts/messagerie_cleanup.php` (mais reste compatible)

---

## H. Erreurs / incohérences restantes

### 1. Potentiel chemin de purge incorrect (MOYEN)

**Fichier :** `includes/messagerie_purge.php`  
**Problème :** `$baseDir = dirname(__DIR__)` = racine du projet. L'upload utilise `$_SERVER['DOCUMENT_ROOT']` ou `dirname(__DIR__)` depuis API. Si DOCUMENT_ROOT pointe vers un sous-dossier (ex. `public/`), les uploads peuvent être dans `public/uploads/chatroom` alors que la purge cherche dans `project_root/uploads/chatroom`.  
**Impact :** Les images expirées ne seraient pas supprimées du disque.  
**Recommandation :** Utiliser la même logique que `chatroom_upload_image.php` pour déterminer le chemin de base.

### 2. Pas de mentions @ dans le chat général (MINEUR)

**Constat :** La table `chatroom_messages` a une colonne `mentions` et `chatroom_send.php` gère les mentions, mais la page `messagerie.php` actuelle n'a pas d'UI pour saisir des mentions @ ni pour les afficher dans le message.  
**Impact :** Les mentions sont stockées mais non exploitées côté interface.  
**Recommandation :** Ajouter l'UI de mentions ou documenter l'absence de cette fonctionnalité.

### 3. chatroom_search_users non utilisé (MINEUR)

**Constat :** L'API `chatroom_search_users.php` existe mais n'est pas appelée par `messagerie.php`.  
**Impact :** Aucun si les mentions ne sont pas utilisées.

### 4. selectUser sans stopPrivatePolling avant (MINEUR)

**Constat :** Lors du changement d'utilisateur, `startPrivatePolling()` est appelé mais `stopPrivatePolling()` ne l'est pas explicitement avant (car `selectUser` est appelé au clic, pas au switch d'onglet). Le polling privé continue quand on est sur l'onglet privé.  
**Impact :** Comportement correct. Le polling est bien arrêté par `stopPrivatePolling()` dans `switchTab` quand on passe à l'onglet général.

### 5. loadingIndicator recréé mais variable non mise à jour (MINEUR)

**Constat :** Dans `selectUser`, on fait `privateMessagesContainer.innerHTML = '<div class="chatroom-loading" id="loadingIndicator">Chargement...</div>'`. La constante `loadingIndicator` définie au chargement de la page pointe vers l'ancien élément détaché.  
**Impact :** Aucun, car le code n'utilise plus `loadingIndicator` pour les cas critiques (on utilise `privateMessagesContainer` directement).

---

## I. Recommandations

### Corrections recommandées

1. **Documenter** : Indiquer clairement que la migration `run_migration_private_messages_read_status.php` doit être exécutée pour les statuts reçu/lu.
2. **Cron** : Configurer le cron `0 * * * * php /chemin/scripts/messagerie_cleanup.php` en production.

### Améliorations futures possibles

- WebSocket pour les mises à jour en temps réel
- ~~Déplacer la purge 24h dans un cron uniquement~~ (fait)
- UI pour les mentions @ dans le chat général
- Indicateur de « frappe en cours » pour les messages privés

### Nettoyage éventuel

- **Fichiers obsolètes** : `API/messagerie_send.php`, `messagerie_reply.php`, `messagerie_mark_read.php`, `messagerie_delete.php` et APIs associées (messagerie_search_sav, etc.) si l'ancienne messagerie n'est plus utilisée.
- **RAPPORT_MESSAGERIE.md** à la racine : contenu obsolète, peut être supprimé ou renvoyé vers `docs/RAPPORT_MESSAGERIE.md`.

---

## Annexe : Audit qualité

### Vérifications effectuées

| Vérification | Résultat |
|--------------|----------|
| Erreurs de logique | Aucune détectée |
| Erreurs de rendu | Isolation des conversations privées correcte |
| Erreurs de flux privé/général | Séparation nette, pas de mélange |
| Erreurs d'état de conversation | `requestedUserId` vérifié pour éviter réponses asynchrones périmées |
| Erreurs de polling/refresh | Intervalles distincts (2s général, 3s privé), arrêt au changement d'onglet |
| Erreurs de chemins image | Potentiel chemin purge différent de l'upload (voir H.1) |
| Erreurs de permissions | Filtrage strict par conversation |
| Erreurs de sécurité | CSRF, PDO, escapeHtml en place |
| Incohérences frontend/backend | Contrats JSON respectés |
| Fichiers obsolètes | APIs messagerie_* (ancienne table) identifiées |

---

*Document généré à partir de l'analyse du code réel du projet CCComputer.*
