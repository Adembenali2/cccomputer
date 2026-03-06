# Messagerie CCComputer - Guide Production

## Architecture

### 1. Chat général
- **Table :** `chatroom_messages`
- **APIs :** `chatroom_get.php`, `chatroom_send.php`, `chatroom_upload_image.php`
- **Notifications :** `chatroom_notifications` (badge)
- **Polling :** 2 secondes

### 2. Messagerie privée
- **Table :** `private_messages`
- **APIs :** `private_messages_get.php`, `private_messages_send.php`, `private_messages_list_users.php`
- **Badge :** `messagerie_get_unread_count.php` (private_messages lu=0)
- **Polling :** 3 secondes

### 3. Purge 24h (CRON uniquement)

**Script :** `scripts/messagerie_cleanup.php`

**Cron à configurer :**
```bash
# Toutes les heures
0 * * * * cd /chemin/vers/cccomputer-1 && php scripts/messagerie_cleanup.php
```

**Important :** La purge ne s'exécute plus au chargement de la page. Seul le cron doit l'exécuter.

### 4. Migrations SQL

Si les colonnes `lu`, `delivered_at`, `read_at` n'existent pas sur `private_messages` :
```bash
php sql/run_migration_private_messages_read_status.php
```

### 5. Fichiers obsolètes (ancienne messagerie)

Ces APIs utilisent la table `messagerie` (structure différente) et ne sont pas utilisées par la page messagerie actuelle :
- `API/messagerie_send.php`
- `API/messagerie_reply.php`
- `API/messagerie_mark_read.php`
- `API/messagerie_delete.php`
- `API/messagerie_search_sav.php`
- `API/messagerie_search_livraisons.php`
- `API/messagerie_get_first_sav.php`
- `API/messagerie_get_first_livraisons.php`
- `API/messagerie_get_first_clients.php`

À archiver ou supprimer si l'ancienne messagerie n'est plus utilisée.
