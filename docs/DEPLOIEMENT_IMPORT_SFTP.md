# Déploiement automatique de l'import SFTP

## 📋 Résumé de la situation actuelle

### Mécanisme actuel (NON automatique)
- **Déclenchement** : Via JavaScript dans `public/dashboard.php` (ligne 1657)
- **Fréquence** : Toutes les 20 secondes via `setInterval()`
- **Endpoint** : `/import/run_import_if_due.php` (wrapper HTTP)
- **Problème** : Si personne n'ouvre le dashboard, l'import ne se déclenche jamais

### Cause racine
**Aucun mécanisme de cron/worker n'est configuré.** Le projet dépend uniquement des appels HTTP depuis le dashboard JavaScript.

---

## ✅ Solution : Automatisation via Cron

### Option 1 : Script Bash (Recommandé pour Linux)

#### 1. Créer le fichier .env à la racine du projet (si pas déjà présent)

```bash
# Exemple de .env
SFTP_HOST=votre-serveur-sftp.com
SFTP_USER=votre_utilisateur
SFTP_PASS=votre_mot_de_passe
SFTP_PORT=22
SFTP_TIMEOUT=15
SFTP_BATCH_LIMIT=20
SFTP_IMPORT_INTERVAL_SEC=300

# Variables MySQL (si nécessaire)
MYSQLHOST=localhost
MYSQLPORT=3306
MYSQLDATABASE=cccomputer
MYSQLUSER=root
MYSQLPASSWORD=
```

#### 2. Rendre le script exécutable

```bash
chmod +x scripts/run_sftp_import.sh
```

#### 3. Tester le script manuellement

```bash
# Tester depuis la racine du projet
./scripts/run_sftp_import.sh

# Ou avec le chemin absolu
/chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh
```

#### 4. Configurer le crontab

```bash
# Éditer le crontab
crontab -e

# Ajouter cette ligne (toutes les 5 minutes)
*/5 * * * * /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh >> /var/log/import_sftp_cron.log 2>&1

# Ou avec le script PHP (alternative)
*/5 * * * * /usr/bin/php /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.php >> /var/log/import_sftp_cron.log 2>&1
```

**⚠️ Important** : Remplacez `/chemin/absolu/vers/cccomputer` par le chemin réel de votre projet.

#### 5. Vérifier que le cron fonctionne

```bash
# Vérifier les logs
tail -f /var/log/import_sftp_cron.log
tail -f logs/sftp_import.log
tail -f logs/sftp_import_error.log

# Vérifier que le cron est actif
crontab -l
```

---

### Option 2 : Script PHP (Alternative)

Le script `scripts/run_sftp_import.php` fonctionne de la même manière mais est écrit en PHP pur.

**Avantages** :
- Pas besoin de bash
- Fonctionne sur tous les systèmes avec PHP CLI
- Gestion native des variables d'environnement PHP

**Usage** :
```bash
php scripts/run_sftp_import.php
```

**Crontab** :
```bash
*/5 * * * * /usr/bin/php /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.php >> /var/log/import_sftp_cron.log 2>&1
```

---

### Option 3 : Service systemd (Recommandé pour production)

#### 1. Créer le service systemd

Créez le fichier `/etc/systemd/system/cccomputer-sftp-import.service` :

```ini
[Unit]
Description=CCComputer SFTP Import Service
After=network.target mysql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/chemin/absolu/vers/cccomputer
EnvironmentFile=/chemin/absolu/vers/cccomputer/.env
ExecStart=/usr/bin/php /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.php
StandardOutput=append:/var/log/cccomputer-sftp-import.log
StandardError=append:/var/log/cccomputer-sftp-import-error.log

[Install]
WantedBy=multi-user.target
```

#### 2. Créer le timer systemd

Créez le fichier `/etc/systemd/system/cccomputer-sftp-import.timer` :

```ini
[Unit]
Description=CCComputer SFTP Import Timer
Requires=cccomputer-sftp-import.service

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=1min

[Install]
WantedBy=timers.target
```

#### 3. Activer et démarrer le timer

```bash
# Recharger systemd
sudo systemctl daemon-reload

# Activer le timer
sudo systemctl enable cccomputer-sftp-import.timer

# Démarrer le timer
sudo systemctl start cccomputer-sftp-import.timer

# Vérifier le statut
sudo systemctl status cccomputer-sftp-import.timer
sudo systemctl list-timers cccomputer-sftp-import.timer
```

#### 4. Vérifier les logs

```bash
# Logs du service
sudo journalctl -u cccomputer-sftp-import.service -f

# Logs du timer
sudo journalctl -u cccomputer-sftp-import.timer -f

# Logs applicatifs
tail -f /var/log/cccomputer-sftp-import.log
tail -f /var/log/cccomputer-sftp-import-error.log
tail -f /chemin/absolu/vers/cccomputer/logs/sftp_import.log
```

---

## 📝 Commandes recommandées

### Commande CLI directe (pour test)

```bash
# Avec le script bash
/chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh

# Avec le script PHP
/usr/bin/php /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.php
```

### Crontab recommandé (toutes les 5 minutes)

```bash
*/5 * * * * /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh >> /var/log/import_sftp_cron.log 2>&1
```

### Crontab avec variables d'environnement explicites (si .env non chargé)

```bash
*/5 * * * * cd /chemin/absolu/vers/cccomputer && SFTP_HOST=votre-host SFTP_USER=votre-user SFTP_PASS=votre-pass /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh >> /var/log/import_sftp_cron.log 2>&1
```

---

## 🔍 Vérification et diagnostic

### 1. Vérifier que le script fonctionne

```bash
# Test manuel
cd /chemin/absolu/vers/cccomputer
./scripts/run_sftp_import.sh

# Vérifier les logs
tail -f logs/sftp_import.log
tail -f logs/sftp_import_error.log
```

### 2. Vérifier les variables d'environnement

```bash
# Dans le script bash, ajouter temporairement :
echo "SFTP_HOST=$SFTP_HOST"
echo "SFTP_USER=$SFTP_USER"
# etc.
```

### 3. Vérifier les permissions

```bash
# Le script doit être exécutable
ls -l scripts/run_sftp_import.sh

# Le répertoire logs doit être accessible en écriture
ls -ld logs/
chmod 755 logs/
```

### 4. Vérifier le cron

```bash
# Lister les crons actifs
crontab -l

# Vérifier les logs du cron
grep CRON /var/log/syslog | tail -20

# Tester le cron manuellement
sudo run-parts /etc/cron.d/
```

---

## 🚨 Dépannage

### Problème : "Variables d'environnement SFTP manquantes"

**Solution** :
1. Vérifier que le fichier `.env` existe à la racine du projet
2. Vérifier que les variables sont bien définies dans `.env`
3. Si le cron ne charge pas `.env`, définir les variables directement dans le crontab

### Problème : "Script introuvable"

**Solution** :
1. Vérifier que le chemin dans le crontab est absolu (pas relatif)
2. Vérifier que le script existe : `ls -l /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh`

### Problème : "Permission denied"

**Solution** :
```bash
chmod +x scripts/run_sftp_import.sh
chmod 755 logs/
```

### Problème : "PHP introuvable"

**Solution** :
1. Trouver le chemin de PHP : `which php` ou `whereis php`
2. Utiliser le chemin absolu dans le crontab : `/usr/bin/php` ou `/usr/local/bin/php`

### Problème : "Le cron ne s'exécute pas"

**Solution** :
1. Vérifier que le service cron est actif : `sudo systemctl status cron`
2. Vérifier les logs : `grep CRON /var/log/syslog`
3. Tester avec un cron simple : `* * * * * echo "test" >> /tmp/cron_test.log`

---

## 📊 Logs et monitoring

### Fichiers de logs créés

1. **`logs/sftp_import.log`** : Log principal avec toutes les exécutions
2. **`logs/sftp_import_error.log`** : Log des erreurs uniquement
3. **`/var/log/import_sftp_cron.log`** : Log du cron (si configuré)

### Format des logs

```
[2024-01-15 10:30:00] === Démarrage de l'import SFTP ===
[2024-01-15 10:30:00] PHP: /usr/bin/php
[2024-01-15 10:30:00] Script: /chemin/absolu/vers/cccomputer/API/scripts/upload_compteur.php
[2024-01-15 10:30:00] PID: 12345
[2024-01-15 10:30:05] === Import SFTP terminé avec succès ===
```

### Monitoring recommandé

```bash
# Surveiller les logs en temps réel
tail -f logs/sftp_import.log

# Compter les erreurs
grep -c "ERROR" logs/sftp_import_error.log

# Vérifier la dernière exécution
tail -20 logs/sftp_import.log
```

---

## ✅ Checklist de déploiement

- [ ] Fichier `.env` créé avec les variables SFTP
- [ ] Script `run_sftp_import.sh` rendu exécutable (`chmod +x`)
- [ ] Test manuel du script réussi
- [ ] Répertoire `logs/` créé et accessible en écriture
- [ ] Crontab configuré avec le chemin absolu
- [ ] Vérification que le cron s'exécute (logs)
- [ ] Monitoring des logs mis en place

---

## 🔄 Migration depuis le système actuel

Le système actuel (déclenchement via dashboard JavaScript) peut être conservé en parallèle. Les deux systèmes peuvent coexister :

- **Cron** : Exécution automatique toutes les 5 minutes (fiable, indépendant)
- **Dashboard** : Exécution à la demande quand un utilisateur ouvre le dashboard (complémentaire)

Le script `run_import_if_due.php` gère déjà un verrou MySQL pour éviter les exécutions parallèles, donc il n'y a pas de risque de conflit.

---

## 📚 Références

- Script d'import principal : `API/scripts/upload_compteur.php`
- Wrapper HTTP actuel : `import/run_import_if_due.php`
- Script wrapper CLI : `scripts/run_sftp_import.sh` ou `scripts/run_sftp_import.php`

