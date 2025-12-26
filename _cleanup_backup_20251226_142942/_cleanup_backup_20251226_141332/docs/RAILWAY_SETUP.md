# Configuration Railway pour Import SFTP

Ce guide explique comment configurer l'import SFTP automatique sur Railway.

## Option 1 : Service Worker (Recommandé)

Railway permet de créer plusieurs services dans un même projet. Créez un service worker séparé pour le cron.

### Configuration via Railway Dashboard

1. **Dans votre projet Railway**, allez dans **Settings** → **New Service**
2. **Service Type** : Choisir "Empty Service" ou "Worker"
3. **Nom du service** : `sftp-import-cron`
4. **Start Command** :
   ```bash
   while true; do php scripts/import_sftp_cron.php; sleep 60; done
   ```
5. **Variables d'environnement** : Assurez-vous que toutes les variables SFTP sont définies :
   - `SFTP_HOST`
   - `SFTP_USER`
   - `SFTP_PASS`
   - `SFTP_PORT` (optionnel, défaut: 22)
   - `SFTP_DIR` (optionnel, défaut: `.`)

### Configuration via Railway Dashboard (Méthode recommandée)

Railway détecte automatiquement le Dockerfile et utilise la commande `CMD` définie dans le Dockerfile (`apache2-foreground`). Vous n'avez pas besoin de définir de `startCommand` pour le service web.

Pour le service worker cron, créez un nouveau service via le Dashboard avec la commande :
```bash
while true; do php scripts/import_sftp_cron.php; sleep 60; done
```

## Option 2 : Cron Job via Railway Cron

Railway propose aussi des cron jobs natifs (si disponible dans votre plan).

1. **Dans Railway Dashboard**, allez dans votre service
2. **Settings** → **Cron Jobs** (si disponible)
3. **Ajouter un cron job** :
   - **Schedule** : `* * * * *` (toutes les minutes)
   - **Command** : `php scripts/import_sftp_cron.php`

## Option 3 : Script de démarrage avec sleep

Si vous ne pouvez pas créer un service séparé, vous pouvez modifier le script de démarrage principal pour inclure le cron :

**⚠️ Non recommandé** car cela peut affecter les performances du service web.

## Vérification

### Vérifier que le service worker fonctionne

1. Allez dans **Railway Dashboard** → Votre projet
2. Vérifiez que le service `sftp-import-cron` est actif
3. Consultez les logs du service worker
4. Vérifiez dans votre base de données :
   ```sql
   SELECT * FROM import_run 
   WHERE msg LIKE '%"type":"sftp"%' 
   ORDER BY ran_at DESC 
   LIMIT 5;
   ```

### Vérifier les notifications sur le dashboard

1. Connectez-vous en tant qu'Admin
2. Allez sur `/public/dashboard.php`
3. Les notifications toast devraient apparaître automatiquement à chaque nouveau run
4. Le statut se rafraîchit toutes les 30 secondes

## Dépannage

### Le service worker ne démarre pas

- Vérifiez les logs dans Railway Dashboard
- Vérifiez que PHP est disponible dans le conteneur
- Vérifiez que les variables d'environnement sont bien définies

### Les notifications n'apparaissent pas

- Vérifiez que vous êtes connecté en tant qu'Admin
- Ouvrez la console du navigateur (F12) pour voir les erreurs JavaScript
- Vérifiez que l'endpoint `/API/import/sftp_status.php` fonctionne

### Le script s'exécute mais ne trouve pas de fichiers

- Vérifiez que les variables d'environnement SFTP sont correctes
- Vérifiez que le répertoire SFTP contient des fichiers `.csv`
- Vérifiez les logs du service worker dans Railway

## Notes importantes

- **Coût** : Un service worker séparé consomme des ressources supplémentaires. Vérifiez votre plan Railway.
- **Redondance** : Le script utilise un lock MySQL pour éviter les exécutions parallèles, donc même si plusieurs instances tournent, une seule traitera les fichiers à la fois.
- **Logs** : Les logs sont stockés dans la base de données (`import_run` et `import_run_item`), pas dans des fichiers.

