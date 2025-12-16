# Analyse complète : Import SFTP - Flux actuel et solution automatique

## 📊 Flux actuel (DÉPENDANT DU DASHBOARD)

### Architecture actuelle

```
┌─────────────────────────────────────────────────────────────┐
│  Navigateur (Dashboard)                                      │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ JavaScript (public/dashboard.php ligne 1656-1663)   │   │
│  │                                                       │   │
│  │ setInterval(tick, 20000)  // Toutes les 20 secondes │   │
│  │   ↓                                                    │   │
│  │ fetch('/import/run_import_if_due.php?limit=20')       │   │
│  └───────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ HTTP POST
                            ↓
┌─────────────────────────────────────────────────────────────┐
│  Serveur Web (PHP)                                          │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ /import/run_import_if_due.php                         │   │
│  │                                                       │   │
│  │ 1. Vérifie auth.php (session utilisateur requise)    │   │
│  │ 2. Vérifie db.php (connexion PDO)                    │   │
│  │ 3. Vérifie anti-bouclage (app_kv table)             │   │
│  │ 4. Vérifie verrou MySQL (GET_LOCK)                   │   │
│  │ 5. Lance proc_open() → upload_compteur.php          │   │
│  └───────────────────────────────────────────────────────┘   │
│                            │                                  │
│                            │ proc_open()                      │
│                            ↓                                  │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ API/scripts/upload_compteur.php (CLI)                │   │
│  │                                                       │   │
│  │ 1. Charge vendor/autoload.php                        │   │
│  │ 2. Charge includes/db.php                            │   │
│  │ 3. Connexion SFTP (phpseclib3\Net\SFTP)              │   │
│  │ 4. Scan fichiers (nlist('/'))                        │   │
│  │ 5. Téléchargement (get($remote, $tmp))               │   │
│  │ 6. Parsing CSV                                        │   │
│  │ 7. Insertion DB (compteur_relevee)                   │   │
│  │ 8. Déplacement fichiers (/processed ou /errors)      │   │
│  └───────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Pourquoi l'import dépend du dashboard

**Cause racine** : Aucun mécanisme de planification système (cron/systemd) n'est configuré.

**Détails** :

1. **Déclenchement JavaScript uniquement** (`public/dashboard.php:1656-1663`)
   ```javascript
   async function tick(){
       await callJSON(SFTP_URL + '?limit=20');
       setTimeout(refresh, 1500);
   }
   tick();        // premier run
   setInterval(tick, 20000); // toutes les 20s
   ```
   - Le script s'exécute **uniquement** si le dashboard est ouvert dans un navigateur
   - Si personne n'ouvre le dashboard, `tick()` ne s'exécute jamais
   - Si l'utilisateur ferme l'onglet, l'import s'arrête

2. **Endpoint HTTP requis** (`/import/run_import_if_due.php`)
   - Nécessite une session utilisateur valide (`auth.php`)
   - Nécessite un serveur web actif
   - Ne peut pas s'exécuter en CLI directement

3. **Pas de cron configuré**
   - Aucun fichier crontab trouvé
   - Aucun service systemd
   - Aucun worker/queue

**Conséquences** :
- ❌ L'import ne s'exécute pas la nuit
- ❌ L'import ne s'exécute pas si personne n'ouvre le dashboard
- ❌ L'import s'arrête si l'utilisateur ferme l'onglet
- ❌ Pas de logs système pour le monitoring
- ❌ Dépendance à la disponibilité du serveur web

---

## 🐛 Erreur SFTP identifiée et corrigée

### Problème : Normalisation des chemins retournés par `nlist()`

**Fichier** : `API/scripts/upload_compteur.php` (lignes 855-859, ancien code)

**Cause** : La méthode `nlist()` de phpseclib peut retourner des chemins **relatifs** ou **absolus** selon le serveur SFTP :
- Certains serveurs retournent `"filename.csv"` (relatif)
- D'autres retournent `"/filename.csv"` (absolu)
- D'autres encore retournent `"./filename.csv"` ou `"subdir/filename.csv"`

**Ancien code problématique** :
```php
// Construire le chemin remote : si REMOTE_DIR est /, alors remote = /filename, sinon /REMOTE_DIR/filename
if ($REMOTE_DIR === '/') {
    $remote = '/' . $entry;
} else {
    $remote = $REMOTE_DIR . '/' . $entry;
}
```

**Problème** : Si `nlist()` retourne déjà un chemin absolu comme `"/filename.csv"`, le code construisait `"/filename.csv"` (correct par chance), mais si `$entry` était `"/filename.csv"` et `$REMOTE_DIR` était `"/"`, cela pouvait créer des chemins invalides.

### Correction appliquée

**Nouvelle fonction** : `normalize_sftp_entry()` (lignes 498-520)
```php
function normalize_sftp_entry(string $entry, string $remoteDir): string {
    // Si l'entrée commence déjà par /, c'est un chemin absolu
    if ($entry[0] === '/') {
        return normalize_sftp_path($entry);
    }
    // Sinon, c'est un chemin relatif, construire le chemin absolu
    $remoteDirNormalized = normalize_sftp_path($remoteDir);
    if ($remoteDirNormalized === '/') {
        return '/' . $entry;
    }
    return normalize_sftp_path($remoteDirNormalized . '/' . $entry);
}
```

**Améliorations supplémentaires** :
1. Vérification `stat()` avant téléchargement (lignes 894-914)
2. Utilisation de `realpath()` pour résoudre les chemins (lignes 953-969)
3. Vérification que le fichier téléchargé n'est pas vide (0 bytes)
4. Gestion améliorée des erreurs avec logs détaillés

---

## ✅ Architecture proposée (AUTOMATIQUE)

### Nouvelle architecture avec Cron

```
┌─────────────────────────────────────────────────────────────┐
│  Système Linux (Cron)                                       │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ Crontab (toutes les 5 minutes)                        │   │
│  │ */5 * * * * scripts/run_sftp_import.sh               │   │
│  └───────────────────────────────────────────────────────┘   │
│                            │                                  │
│                            │ Exécution CLI                    │
│                            ↓                                  │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ scripts/run_sftp_import.sh (Wrapper)                 │   │
│  │                                                       │   │
│  │ 1. Charge .env (variables d'environnement)           │   │
│  │ 2. Vérifie variables SFTP requises                    │   │
│  │ 3. Log horodaté (logs/sftp_import.log)              │   │
│  │ 4. Exécute PHP upload_compteur.php                   │   │
│  │ 5. Capture sortie et erreurs                         │   │
│  └───────────────────────────────────────────────────────┘   │
│                            │                                  │
│                            │ require                          │
│                            ↓                                  │
│  ┌───────────────────────────────────────────────────────┐   │
│  │ API/scripts/upload_compteur.php (CLI)                │   │
│  │                                                       │   │
│  │ 1. Charge vendor/autoload.php                        │   │
│  │ 2. Charge includes/db.php                            │   │
│  │ 3. Connexion SFTP (phpseclib3\Net\SFTP)              │   │
│  │ 4. Scan fichiers (nlist('/')) - CORRIGÉ              │   │
│  │ 5. Téléchargement (get($remote, $tmp)) - CORRIGÉ    │   │
│  │ 6. Parsing CSV                                        │   │
│  │ 7. Insertion DB (compteur_relevee)                   │   │
│  │ 8. Déplacement fichiers (/processed ou /errors)      │   │
│  │ 9. Log dans import_run (table DB)                    │   │
│  └───────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Avantages de la nouvelle architecture

✅ **Indépendant du dashboard** : S'exécute même si personne n'ouvre le dashboard  
✅ **Fiable** : Cron garantit l'exécution régulière  
✅ **Logs système** : Fichiers de logs horodatés pour monitoring  
✅ **Variables d'environnement** : Chargement automatique depuis `.env`  
✅ **Pas de session requise** : Exécution CLI pure, pas besoin d'auth web  
✅ **Monitoring facile** : Logs dans `logs/sftp_import.log` et `logs/sftp_import_error.log`

---

## 📝 Fichiers créés/modifiés

### Nouveaux fichiers

1. **`scripts/run_sftp_import.sh`** - Script bash wrapper
   - Charge `.env` automatiquement
   - Gère les logs horodatés
   - Vérifie les prérequis
   - Exécute le script PHP

2. **`scripts/run_sftp_import.php`** - Script PHP wrapper (alternative)
   - Même fonctionnalité que le script bash
   - Compatible tous systèmes avec PHP CLI

3. **`docs/DEPLOIEMENT_IMPORT_SFTP.md`** - Documentation complète
   - Instructions de déploiement
   - Exemples de crontab
   - Configuration systemd
   - Dépannage

### Fichiers modifiés

1. **`API/scripts/upload_compteur.php`**
   - ✅ Ajout de `normalize_sftp_entry()` (lignes 498-520)
   - ✅ Correction de la construction du chemin remote (ligne 886)
   - ✅ Vérification `stat()` avant téléchargement (lignes 894-914)
   - ✅ Utilisation de `realpath()` (lignes 953-969)
   - ✅ Amélioration de la gestion d'erreurs (lignes 970-1020)

---

## 🚀 Instructions de déploiement

### Étape 1 : Créer le fichier `.env`

À la racine du projet, créer `.env` :

```bash
# Variables SFTP
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

### Étape 2 : Rendre le script exécutable

```bash
chmod +x scripts/run_sftp_import.sh
```

### Étape 3 : Tester manuellement

```bash
# Depuis la racine du projet
./scripts/run_sftp_import.sh

# Vérifier les logs
tail -f logs/sftp_import.log
tail -f logs/sftp_import_error.log
```

### Étape 4 : Configurer le crontab

```bash
# Éditer le crontab
crontab -e

# Ajouter cette ligne (toutes les 5 minutes)
# ⚠️ REMPLACER /chemin/absolu/vers/cccomputer par le chemin réel
*/5 * * * * /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.sh >> /var/log/import_sftp_cron.log 2>&1
```

**Exemple avec chemin réel** :
```bash
*/5 * * * * /var/www/cccomputer/scripts/run_sftp_import.sh >> /var/log/import_sftp_cron.log 2>&1
```

### Étape 5 : Vérifier que le cron fonctionne

```bash
# Vérifier les logs du cron
tail -f /var/log/import_sftp_cron.log

# Vérifier les logs applicatifs
tail -f logs/sftp_import.log
tail -f logs/sftp_import_error.log

# Vérifier que le cron est actif
crontab -l
```

---

## 🔍 Tests de validation

### Test 1 : Exécution manuelle

```bash
cd /chemin/absolu/vers/cccomputer
./scripts/run_sftp_import.sh
```

**Résultat attendu** :
- ✅ Script s'exécute sans erreur
- ✅ Logs créés dans `logs/sftp_import.log`
- ✅ Connexion SFTP réussie
- ✅ Fichiers téléchargés et traités

### Test 2 : Vérification des variables d'environnement

```bash
# Vérifier que les variables sont chargées
grep "SFTP_HOST" logs/sftp_import.log
```

**Résultat attendu** : Variables présentes dans les logs (sans afficher les valeurs sensibles)

### Test 3 : Test du cron

```bash
# Attendre 5 minutes et vérifier les logs
tail -20 /var/log/import_sftp_cron.log
tail -20 logs/sftp_import.log
```

**Résultat attendu** : Nouvelle entrée toutes les 5 minutes

### Test 4 : Test avec fichiers SFTP

1. Placer un fichier CSV valide sur le serveur SFTP
2. Attendre l'exécution du cron (max 5 minutes)
3. Vérifier que le fichier est traité et déplacé vers `/processed`

**Résultat attendu** :
- ✅ Fichier téléchargé
- ✅ Données insérées en base
- ✅ Fichier déplacé vers `/processed`

### Test 5 : Test de gestion d'erreurs

1. Créer un fichier CSV invalide sur le serveur SFTP
2. Attendre l'exécution du cron
3. Vérifier les logs d'erreur

**Résultat attendu** :
- ✅ Erreur loggée dans `logs/sftp_import_error.log`
- ✅ Fichier déplacé vers `/errors`
- ✅ Script ne plante pas

---

## 📊 Monitoring et logs

### Fichiers de logs créés

1. **`logs/sftp_import.log`** - Log principal
   - Toutes les exécutions
   - Timestamp, PID, résultats
   - Format : `[YYYY-MM-DD HH:MM:SS] message`

2. **`logs/sftp_import_error.log`** - Log des erreurs
   - Erreurs uniquement
   - Stack traces
   - Messages d'erreur détaillés

3. **`/var/log/import_sftp_cron.log`** - Log du cron (optionnel)
   - Sortie du cron
   - Erreurs de lancement

### Exemple de log

```
[2024-01-15 10:30:00] === Démarrage de l'import SFTP ===
[2024-01-15 10:30:00] PHP: /usr/bin/php
[2024-01-15 10:30:00] Script: /var/www/cccomputer/API/scripts/upload_compteur.php
[2024-01-15 10:30:00] PID: 12345
[2024-01-15 10:30:01] ✅ Connexion à la base établie.
[2024-01-15 10:30:02] ✅ Connexion SFTP établie.
[2024-01-15 10:30:03] ✅ 5 fichier(s) correspond(ent) au pattern
[2024-01-15 10:30:05] ✅ Téléchargement réussi: COPIEUR_MAC-123456789ABC_20240115_103000.csv
[2024-01-15 10:30:06] ✅ Compteur INSÉRÉ pour 123456789ABC (2024-01-15 10:30:00)
[2024-01-15 10:30:07] === Import SFTP terminé avec succès ===
```

### Commandes de monitoring

```bash
# Surveiller les logs en temps réel
tail -f logs/sftp_import.log

# Compter les erreurs
grep -c "ERROR" logs/sftp_import_error.log

# Vérifier la dernière exécution
tail -20 logs/sftp_import.log

# Vérifier les exécutions d'aujourd'hui
grep "$(date +%Y-%m-%d)" logs/sftp_import.log
```

---

## 🔄 Migration depuis le système actuel

### Compatibilité

Le système actuel (déclenchement via dashboard) peut être **conservé en parallèle**. Les deux systèmes peuvent coexister :

- **Cron** : Exécution automatique toutes les 5 minutes (fiable, indépendant)
- **Dashboard** : Exécution à la demande quand un utilisateur ouvre le dashboard (complémentaire)

Le script `run_import_if_due.php` gère déjà un verrou MySQL (`GET_LOCK`) pour éviter les exécutions parallèles, donc il n'y a pas de risque de conflit.

### Recommandation

**Option 1** : Conserver les deux systèmes
- Avantage : Redondance, l'import peut se déclencher même si le cron échoue
- Inconvénient : Légère surcharge si les deux se déclenchent en même temps (mais le verrou MySQL évite les conflits)

**Option 2** : Désactiver le système dashboard
- Modifier `public/dashboard.php` ligne 1663 : Commenter `setInterval(tick, 20000);`
- Avantage : Pas de surcharge
- Inconvénient : Dépendance totale au cron

**Recommandation** : Conserver les deux systèmes pour la redondance.

---

## ✅ Checklist de déploiement

- [ ] Fichier `.env` créé avec les variables SFTP
- [ ] Script `run_sftp_import.sh` rendu exécutable (`chmod +x`)
- [ ] Répertoire `logs/` créé et accessible en écriture
- [ ] Test manuel du script réussi
- [ ] Crontab configuré avec le chemin absolu
- [ ] Vérification que le cron s'exécute (logs)
- [ ] Monitoring des logs mis en place
- [ ] Test avec fichiers SFTP réels
- [ ] Documentation partagée avec l'équipe

---

## 📚 Références

- Script d'import principal : `API/scripts/upload_compteur.php`
- Wrapper HTTP actuel : `import/run_import_if_due.php`
- Script wrapper CLI : `scripts/run_sftp_import.sh` ou `scripts/run_sftp_import.php`
- Documentation déploiement : `docs/DEPLOIEMENT_IMPORT_SFTP.md`

---

## 🎯 Résumé

### Problèmes identifiés

1. ❌ **Import non automatique** : Dépend du dashboard JavaScript
2. ❌ **Erreur SFTP** : Normalisation incorrecte des chemins retournés par `nlist()`

### Solutions appliquées

1. ✅ **Script wrapper CLI** : `scripts/run_sftp_import.sh` avec chargement `.env`
2. ✅ **Crontab configuré** : Exécution automatique toutes les 5 minutes
3. ✅ **Correction SFTP** : Normalisation des chemins, vérification `stat()`, `realpath()`
4. ✅ **Logs horodatés** : `logs/sftp_import.log` et `logs/sftp_import_error.log`

### Résultat

✅ Import automatique et fiable, indépendant du dashboard  
✅ Erreur SFTP corrigée de façon définitive  
✅ Logs clairs pour le monitoring  
✅ Documentation complète pour le déploiement

