# Rapport d'analyse et d'audit - Page Messagerie CCComputer

**Date :** 6 mars 2025  
**Périmètre :** Tous les fichiers liés à la messagerie (chatroom + messagerie 1-à-1)

---

## A. FICHIERS LIÉS À LA MESSAGERIE

### Page principale
| Fichier | Rôle |
|---------|------|
| `public/messagerie.php` | Page principale affichant la chatroom globale (interface unique actuellement) |

### Includes et dépendances
| Fichier | Rôle |
|---------|------|
| `includes/auth.php` | Vérification session utilisateur |
| `includes/auth_role.php` | `authorize_page('messagerie', [])` - accès à tous les utilisateurs connectés |
| `includes/helpers.php` | `getPdo()`, `ensureCsrfToken()` |
| `source/templates/header.php` | En-tête avec badge messagerie, lien vers messagerie |

### API Chatroom (utilisées par messagerie.php)
| Fichier | Rôle |
|---------|------|
| `API/chatroom_get.php` | GET - Récupérer les messages (limit, since_id) |
| `API/chatroom_send.php` | POST - Envoyer un message (JSON + CSRF) |
| `API/chatroom_upload_image.php` | POST - Upload d'image (FormData + CSRF) |
| `API/chatroom_search_users.php` | GET - Recherche utilisateurs pour mentions @ |
| `API/chatroom_get_notifications.php` | GET - Nombre de notifications non lues |
| `API/chatroom_mark_notifications_read.php` | POST - Marquer notifications comme lues |

### API Messagerie 1-à-1 (badge header, autres pages)
| Fichier | Rôle |
|---------|------|
| `API/messagerie_get_unread_count.php` | GET - Nombre messages non lus (badge) |
| `API/messagerie_send.php` | POST - Envoyer message privé |
| `API/messagerie_reply.php` | POST - Répondre à un message |
| `API/messagerie_mark_read.php` | POST - Marquer comme lu |
| `API/messagerie_delete.php` | DELETE - Supprimer message |
| `API/messagerie_search_sav.php` | GET - Recherche SAV (pour liens) |
| `API/messagerie_search_livraisons.php` | GET - Recherche livraisons (pour liens) |
| `API/messagerie_get_first_sav.php` | GET - Premiers SAV |
| `API/messagerie_get_first_livraisons.php` | GET - Premières livraisons |
| `API/messagerie_get_first_clients.php` | GET - Premiers clients |

### Assets
| Fichier | Rôle |
|---------|------|
| `assets/css/main.css` | Styles globaux |
| `assets/css/chatroom.css` | Styles spécifiques chatroom |
| `assets/css/header.css` | Styles badge messagerie |
| `assets/js/api.js` | ApiClient, showNotification |

### Base de données
| Table | Rôle |
|-------|------|
| `chatroom_messages` | Messages de la chatroom globale |
| `chatroom_notifications` | Notifications (mentions, nouveaux messages) |
| `messagerie` | Messages privés 1-à-1 |
| `messagerie_lectures` | Lectures des messages broadcast |

### Scripts et utilitaires
| Fichier | Rôle |
|---------|------|
| `scripts/chatroom_cleanup.php` | Cron - Suppression messages > 24h |
| `API/_bootstrap.php` | Bootstrap session pour les API |
| `includes/api_helpers.php` | initApi, requireApiAuth, requireCsrfToken, getPdoOrFail |

---

## B. FONCTIONNEMENT ACTUEL

### Affichage
1. `public/messagerie.php` est chargé
2. Vérification auth via `authorize_page('messagerie', [])`
3. Nettoyage automatique des messages > 24h (exécuté à chaque chargement)
4. Inclusion de `header.php`, puis affichage du conteneur chatroom
5. JavaScript inline : `init()` charge les messages, démarre le polling

### Chargement des messages
- **Initial :** `GET /API/chatroom_get.php?limit=100`
- **Polling (nouveaux) :** `GET /API/chatroom_get.php?since_id={lastMessageId}`
- **Backoff exponentiel :** si pas de nouveaux messages, intervalle augmente (max 30s)

### Envoi de message
1. Texte + image optionnelle
2. Si image : upload via `POST /API/chatroom_upload_image.php` (FormData)
3. Envoi via `POST /API/chatroom_send.php` (JSON : message, mentions, image_path, csrf_token)

### Mentions @
- Saisie `@` déclenche recherche
- `GET /API/chatroom_search_users.php?q=...&limit=10`
- Pour extraire les IDs : `GET /API/chatroom_search_users.php?q=&limit=1000` (tous les utilisateurs)

### Notifications
- Badge header : `updateMessagerieBadge()` combine chatroom + messagerie 1-à-1
- À l'ouverture : `markNotificationsAsRead()` marque tout comme lu

### Flux de données
```
Frontend (messagerie.php)
    → fetch() → API (chatroom_*.php)
        → initApi() → session, PDO
        → requireApiAuth() / requireCsrfToken()
        → Requêtes SQL (PDO prepared statements)
        → jsonResponse()
    ← JSON
```

---

## C. PROBLÈMES DÉTECTÉS

### 1. XSS - mentionName non échappé (CRITIQUE)
**Fichier :** `public/messagerie.php`  
**Ligne :** ~324  
**Problème :** Dans `formatMessageContent()`, le remplacement des mentions injecte `mentionName` sans échappement :
```javascript
content = content.replace(mentionRegex, (match, mentionName) => {
    return `<span class="mention">${mentionName}</span>`;
});
```
**Impact :** Un utilisateur peut envoyer `@<script>alert(1)</script>` ou `@<img src=x onerror=alert(1)>` et exécuter du code dans le navigateur de tous les destinataires.  
**Solution :** Utiliser `escapeHtml(mentionName)`.

---

### 2. chatroom_mark_notifications_read sans CSRF (MAJEUR)
**Fichier :** `API/chatroom_mark_notifications_read.php`  
**Problème :** Endpoint POST modifiant des données sans vérification CSRF.  
**Impact :** Attaque CSRF possible : un site malveillant peut forcer un utilisateur connecté à marquer toutes ses notifications comme lues.  
**Solution :** Ajouter le token CSRF dans le body JSON et appeler `requireCsrfToken()`.

---

### 3. chatroom_search_users - limite trop basse (MOYEN)
**Fichier :** `API/chatroom_search_users.php`  
**Ligne :** 23  
**Problème :** `$limit = min((int)($_GET['limit'] ?? 10), 20)` - plafonne à 20.  
**Impact :** `extractMentionIds()` appelle avec `limit=1000` pour charger tous les utilisateurs, mais ne reçoit que 20. Les mentions @user ne fonctionnent que pour les 20 premiers utilisateurs.  
**Solution :** Augmenter la limite max (ex. 500 pour les requêtes sans recherche) ou différencier selon le cas d'usage.

---

### 4. Image fallback inexistante (MINEUR)
**Fichier :** `public/messagerie.php`  
**Ligne :** ~477  
**Problème :** `onerror="this.src='/assets/images/image-error.png'"` - le fichier n'existe pas (dossier `assets/images` absent).  
**Impact :** Icône cassée en cas d'erreur de chargement d'image.  
**Solution :** Créer l'asset ou utiliser une alternative (data URI, placeholder).

---

### 5. chatroom_get_notifications - session non démarrée (MINEUR)
**Fichier :** `API/chatroom_get_notifications.php`  
**Problème :** Appelle `$_SESSION['user_id']` avant `initApi()` dans le flux. Si la session n'est pas active, peut générer des notices.  
**État actuel :** initApi() est appelé avant le check, donc OK. À vérifier l'ordre.

---

### 6. Nettoyage 24h sur chaque chargement (MINEUR - performance)
**Fichier :** `public/messagerie.php`  
**Lignes :** 31-99  
**Problème :** Le nettoyage des messages > 24h (et suppression des fichiers images) est exécuté à chaque chargement de la page.  
**Impact :** Charge inutile sur la base et le disque si la page est fréquemment visitée.  
**Solution recommandée :** Déplacer dans un cron (comme `scripts/chatroom_cleanup.php`) ou utiliser un verrou/limite temporelle.

---

### 7. Requête DELETE subquery MySQL (MINEUR)
**Fichier :** `public/messagerie.php`  
**Ligne :** 81-85  
**Problème :** `DELETE FROM chatroom_notifications WHERE id_message NOT IN (SELECT id FROM chatroom_messages)` - certaines versions MySQL peuvent avoir des problèmes avec ce pattern.  
**Alternative :** Utiliser une jointure ou un DELETE en deux étapes.

---

## D. CORRECTIONS PROPOSÉES

| # | Problème | Fichier | Action |
|---|----------|---------|--------|
| 1 | XSS mentionName | `public/messagerie.php` | Échapper `mentionName` dans formatMessageContent |
| 2 | CSRF mark_notifications | `API/chatroom_mark_notifications_read.php` | Ajouter requireCsrfToken + token dans body |
| 2b | CSRF frontend | `public/messagerie.php` | Inclure csrf_token dans markNotificationsAsRead |
| 3 | Limite search_users | `API/chatroom_search_users.php` | Augmenter limite max à 500 |
| 4 | Image fallback | `public/messagerie.php` | Utiliser data URI ou placeholder inline |

---

## E. CODE CORRIGÉ

Les corrections ont été appliquées :

1. **public/messagerie.php**
   - `formatMessageContent()` : `escapeHtml(mentionName)` pour éviter XSS
   - `markNotificationsAsRead()` : ajout de `csrf_token` dans le body JSON
   - Image onerror : suppression de la référence à l'image inexistante, utilisation de `alt` et `opacity` uniquement

2. **API/chatroom_mark_notifications_read.php**
   - Lecture unique de `php://input` (ou `$GLOBALS['RAW_BODY']`)
   - Vérification CSRF via `requireCsrfToken($csrfToken)`

3. **API/chatroom_search_users.php**
   - Limite max : 500 pour requête vide (liste complète), 50 pour recherche
   - Permet à `extractMentionIds()` de récupérer jusqu'à 500 utilisateurs
