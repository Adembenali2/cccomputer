# Guide : Import SFTP Automatique - Configuration Complète

## 🎯 Objectif

Faire en sorte que l'import SFTP soit **100% automatique** :
1. ✅ L'import se déclenche automatiquement via cron (même si le dashboard n'est pas ouvert)
2. ✅ Les résultats s'affichent automatiquement dans le dashboard quand tu l'ouvres
3. ✅ Pas besoin d'intervention manuelle

---

## 📋 Étape 1 : Configurer le Cron (Import Automatique)

### Option A : Script Bash (Recommandé)

#### 1. Créer le fichier `.env` à la racine du projet

```bash
# Variables SFTP
SFTP_HOST=votre-serveur-sftp.com
SFTP_USER=votre_utilisateur
SFTP_PASS=votre_mot_de_passe
SFTP_PORT=22
SFTP_TIMEOUT=15
SFTP_BATCH_LIMIT=20

# Variables MySQL
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

#### 3. Tester manuellement

```bash
# Depuis la racine du projet
./scripts/run_sftp_import.sh

# Vérifier les logs
tail -f logs/sftp_import.log
```

#### 4. Configurer le crontab

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

#### 5. Vérifier que le cron fonctionne

```bash
# Vérifier les logs du cron
tail -f /var/log/import_sftp_cron.log

# Vérifier les logs applicatifs
tail -f logs/sftp_import.log

# Vérifier que le cron est actif
crontab -l
```

### Option B : Script PHP (Alternative)

Si tu préfères utiliser PHP directement :

```bash
# Crontab
*/5 * * * * /usr/bin/php /chemin/absolu/vers/cccomputer/scripts/run_sftp_import.php >> /var/log/import_sftp_cron.log 2>&1
```

---

## 📋 Étape 2 : Vérifier que le Dashboard Affiche Automatiquement

Le dashboard est déjà configuré pour :
- ✅ Afficher les résultats automatiquement au chargement (via `refresh()`)
- ✅ Rafraîchir toutes les 10 secondes pour voir les nouveaux imports (cron)
- ✅ Déclencher un import immédiat au chargement (avec `force=1`)

### Fonctionnement automatique

1. **Au chargement du dashboard** :
   - Appel immédiat à `/import/run_import_if_due.php?limit=20&force=1`
   - Rafraîchissement du badge depuis la DB (`/import/last_import.php`)

2. **Toutes les 10 secondes** :
   - Rafraîchissement automatique du badge pour voir les imports du cron
   - Toast visible si un nouvel import a été détecté

3. **Toutes les 20 secondes** :
   - Tentative d'import si "due" (sans forcer)

### Vérification

1. Ouvrir le dashboard
2. Observer le badge "Import SFTP" en haut à droite
3. Le badge devrait afficher automatiquement le dernier résultat :
   - ✅ "Import SFTP OK — X inséré(s) — 2025-12-15 13:30:21"
   - ❌ "Import SFTP KO — ..." si erreur

---

## 🧪 Test Complet

### Test 1 : Vérifier que le cron fonctionne

```bash
# Attendre 5 minutes après la configuration du cron
# Vérifier les logs
tail -20 /var/log/import_sftp_cron.log
tail -20 logs/sftp_import.log

# Vérifier la DB
mysql -u root -p cccomputer -e "SELECT * FROM import_run WHERE msg LIKE '%\"source\":\"SFTP\"%' ORDER BY ran_at DESC LIMIT 5;"
```

**Résultat attendu** : Nouvelle entrée toutes les 5 minutes dans `import_run`

### Test 2 : Vérifier l'affichage automatique dans le dashboard

1. **Fermer tous les onglets du dashboard**
2. **Attendre 5 minutes** (pour qu'un import cron s'exécute)
3. **Ouvrir le dashboard**
4. **Observer le badge** : Il devrait afficher automatiquement le dernier résultat

**Résultat attendu** :
- ✅ Badge affiche "Import SFTP OK — X inséré(s) — [date récente]"
- ✅ Pas besoin de cliquer ou déclencher manuellement

### Test 3 : Vérifier le rafraîchissement automatique

1. **Ouvrir le dashboard**
2. **Ouvrir la console (F12)**
3. **Observer les logs** : `[IMPORT] /import/last_import.php → 200`
4. **Attendre 10 secondes** : Le badge devrait se rafraîchir automatiquement

**Résultat attendu** :
- ✅ Console montre des appels à `last_import.php` toutes les 10 secondes
- ✅ Badge se met à jour si un nouvel import est détecté

---

## 🔍 Vérification DB

### Vérifier les imports récents

```sql
SELECT 
    ran_at,
    imported,
    skipped,
    ok,
    JSON_EXTRACT(msg, '$.source') as source,
    JSON_EXTRACT(msg, '$.inserted') as inserted,
    JSON_EXTRACT(msg, '$.updated') as updated
FROM import_run
WHERE msg LIKE '%"source":"SFTP"%'
ORDER BY ran_at DESC
LIMIT 10;
```

### Vérifier que le cron s'exécute

```sql
-- Vérifier les imports des dernières heures
SELECT 
    DATE_FORMAT(ran_at, '%Y-%m-%d %H:%i') as minute,
    COUNT(*) as count,
    SUM(imported) as total_imported
FROM import_run
WHERE msg LIKE '%"source":"SFTP"%'
  AND ran_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY DATE_FORMAT(ran_at, '%Y-%m-%d %H:%i')
ORDER BY minute DESC;
```

**Résultat attendu** : Une entrée toutes les 5 minutes (ou selon la fréquence du cron)

---

## ✅ Checklist de Configuration

- [ ] Fichier `.env` créé avec les variables SFTP
- [ ] Script `run_sftp_import.sh` rendu exécutable
- [ ] Test manuel du script réussi
- [ ] Crontab configuré avec le chemin absolu
- [ ] Vérification que le cron s'exécute (logs)
- [ ] Dashboard affiche automatiquement les résultats au chargement
- [ ] Badge se rafraîchit toutes les 10 secondes
- [ ] Test avec fichiers SFTP réels

---

## 🚨 Dépannage

### Problème : Le cron ne s'exécute pas

**Solution** :
```bash
# Vérifier que le service cron est actif
sudo systemctl status cron

# Vérifier les logs système
grep CRON /var/log/syslog | tail -20

# Tester avec un cron simple
* * * * * echo "test" >> /tmp/cron_test.log
```

### Problème : Le dashboard n'affiche pas les résultats

**Solution** :
1. Ouvrir la console (F12)
2. Vérifier les erreurs : `[IMPORT] /import/last_import.php → ...`
3. Vérifier que la session est valide (cookies)
4. Vérifier la DB : `SELECT * FROM import_run ORDER BY ran_at DESC LIMIT 1;`

### Problème : Les résultats ne se rafraîchissent pas automatiquement

**Solution** :
1. Vérifier la console : Les appels à `last_import.php` doivent apparaître toutes les 10 secondes
2. Vérifier que JavaScript n'est pas bloqué
3. Vérifier les erreurs dans la console

---

## 📊 Résumé du Fonctionnement

### Flux Automatique Complet

```
┌─────────────────────────────────────────────────────────┐
│  CRON (toutes les 5 min)                                │
│  → scripts/run_sftp_import.sh                           │
│    → API/scripts/upload_compteur.php                    │
│      → Insertion dans import_run (DB)                   │
└─────────────────────────────────────────────────────────┘
                    │
                    │ (résultats en DB)
                    ↓
┌─────────────────────────────────────────────────────────┐
│  DASHBOARD (quand tu l'ouvres)                          │
│  → refresh() toutes les 10s                             │
│    → /import/last_import.php                            │
│      → Lecture depuis import_run (DB)                    │
│        → Affichage automatique dans le badge            │
└─────────────────────────────────────────────────────────┘
```

### Avantages

✅ **100% Automatique** : L'import se fait même si le dashboard n'est pas ouvert  
✅ **Affichage Automatique** : Les résultats apparaissent automatiquement dans le dashboard  
✅ **Pas d'Intervention** : Aucune action manuelle nécessaire  
✅ **Rafraîchissement** : Le badge se met à jour toutes les 10 secondes  
✅ **Double Sécurité** : Cron + Dashboard (les deux peuvent coexister)

---

## 🎉 Résultat Final

Une fois configuré, tu auras :

1. ✅ **Import automatique toutes les 5 minutes** (via cron)
2. ✅ **Affichage automatique des résultats** dans le dashboard
3. ✅ **Rafraîchissement automatique** toutes les 10 secondes
4. ✅ **Toast visible** quand un nouvel import est détecté
5. ✅ **Aucune intervention manuelle** nécessaire

**Tu n'as plus qu'à ouvrir le dashboard et voir les résultats ! 🚀**

