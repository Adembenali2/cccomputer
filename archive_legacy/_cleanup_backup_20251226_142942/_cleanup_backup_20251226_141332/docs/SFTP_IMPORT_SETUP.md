# Guide d'installation - Import SFTP

Ce guide explique comment configurer l'import automatique SFTP qui s'exécute toutes les minutes.

## Prérequis

1. **Variables d'environnement configurées** :
   ```bash
   SFTP_HOST=sftp.example.com
   SFTP_USER=username
   SFTP_PASS=password
   SFTP_PORT=22          # Optionnel (défaut: 22)
   SFTP_DIR=.            # Optionnel (défaut: répertoire racine)
   ```

2. **Permissions** :
   - Le script doit avoir accès en lecture/écriture à la base de données MySQL
   - Le script doit pouvoir créer le dossier `logs/` (pour les logs si utilisé)

## Configuration Linux/Unix (Production)

### Méthode 1 : Utiliser crontab.example

```bash
# 1. Copier le fichier d'exemple
cp crontab.example /tmp/my_crontab

# 2. Éditer pour ajuster le chemin (remplacer /var/www/html par votre chemin)
nano /tmp/my_crontab

# 3. Installer le crontab
crontab /tmp/my_crontab

# 4. Vérifier
crontab -l
```

### Méthode 2 : Éditer directement crontab

```bash
# Éditer le crontab
crontab -e

# Ajouter cette ligne (ajuster les chemins)
* * * * * cd /var/www/html && /usr/bin/php scripts/import_sftp_cron.php >> /var/www/html/logs/sftp_import.log 2>&1
```

### Méthode 3 : Utiliser le script helper

```bash
# Rendre le script exécutable
chmod +x scripts/run_sftp_import.sh

# Ajouter au crontab
crontab -e

# Ajouter cette ligne
* * * * * cd /var/www/html && ./scripts/run_sftp_import.sh >> /var/www/html/logs/sftp_import.log 2>&1
```

## Configuration Windows (Développement local)

### Méthode 1 : Task Scheduler (Recommandé)

1. Ouvrir le **Planificateur de tâches** (Task Scheduler)
2. Cliquer sur **Créer une tâche de base**
3. **Nom** : "Import SFTP CCComputer"
4. **Déclencheur** : 
   - Répéter la tâche toutes les **1 minute**
   - Durée : **Indéfiniment**
5. **Action** : **Démarrer un programme**
   - Programme : `C:\xampp\php\php.exe` (ajuster selon votre installation)
   - Arguments : `C:\xampp\htdocs\cccomputer\scripts\import_sftp_cron.php`
   - Démarrer dans : `C:\xampp\htdocs\cccomputer`
6. **Conditions** : Décocher "Ne démarrer la tâche que si l'ordinateur est branché sur secteur"
7. **Paramètres** : Cocher "Autoriser l'exécution de la tâche à la demande"

### Méthode 2 : Script batch (Test manuel)

Double-cliquer sur `scripts\run_sftp_import.bat` pour tester manuellement.

## Test

### Test manuel

```bash
# Linux/Unix
php scripts/import_sftp_cron.php

# Windows (depuis le dossier du projet)
C:\xampp\php\php.exe scripts\import_sftp_cron.php
```

### Test avec mode dry-run (simulation)

```bash
SFTP_IMPORT_DRY_RUN=1 php scripts/import_sftp_cron.php
```

### Vérifier les logs

```bash
# Linux/Unix
tail -f /var/www/html/logs/sftp_import.log

# Ou vérifier dans la base de données
SELECT * FROM import_run WHERE msg LIKE '%"type":"sftp"%' ORDER BY ran_at DESC LIMIT 5;
```

## Monitoring sur le Dashboard

1. Connectez-vous en tant qu'**Admin**
2. Allez sur `/public/dashboard.php`
3. La card **"Import SFTP"** s'affiche automatiquement
4. Les notifications toast apparaissent lors des nouveaux runs
5. Le statut se rafraîchit automatiquement toutes les 30 secondes

## Dépannage

### Le cron ne s'exécute pas

```bash
# Vérifier que le cron est actif
crontab -l

# Vérifier les logs système
grep CRON /var/log/syslog | tail -20

# Vérifier les permissions du script
ls -la scripts/import_sftp_cron.php
chmod +x scripts/import_sftp_cron.php
```

### Erreur "Lock already acquired"

Cela signifie qu'une instance est déjà en cours. Normalement, le script devrait attendre. Si cela persiste :

```sql
-- Vérifier les locks actifs
SHOW PROCESSLIST;

-- Forcer la libération du lock (à utiliser avec précaution)
SELECT RELEASE_LOCK('import_sftp');
```

### Erreur de connexion SFTP

Vérifier :
1. Les variables d'environnement sont bien définies
2. Le serveur SFTP est accessible depuis le serveur
3. Les identifiants sont corrects
4. Le port SFTP est correct (défaut: 22)

### Pas de fichiers traités

Vérifier :
1. Les fichiers CSV sont bien dans le répertoire SFTP configuré (`SFTP_DIR`)
2. Les fichiers ont l'extension `.csv`
3. Les fichiers ne sont pas déjà dans `processed/` ou `errors/`
4. Le script a les permissions pour lire les fichiers

## Support

Pour plus d'informations, voir `PROJECT_OVERVIEW.md` section "2. Import SFTP".

