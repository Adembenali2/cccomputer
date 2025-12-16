# Diagnostic : Import affiche des résultats mais fichiers non traités

## 🐛 Problème identifié

Le dashboard affiche "1 inséré(s)" mais les fichiers restent dans FileZilla (pas déplacés vers `/processed`).

### Causes possibles

1. **Le badge affiche un ancien résultat** (import précédent)
2. **L'import s'exécute mais échoue lors du déplacement** (et le code continue)
3. **L'import ne s'exécute pas vraiment** mais le badge affiche un résultat en cache

---

## 🔍 Diagnostic étape par étape

### Étape 1 : Vérifier les logs dans la console du navigateur

1. Ouvrir le dashboard
2. Ouvrir la console (F12)
3. Chercher les logs `[IMPORT]`

**Ce qu'on cherche** :
- `[IMPORT] Badge mis à jour` avec `ran_at` et `age_minutes`
- Si `age_minutes > 10`, c'est un ancien résultat

### Étape 2 : Vérifier la DB pour voir les imports récents

```sql
SELECT 
    id,
    ran_at,
    imported,
    skipped,
    ok,
    JSON_EXTRACT(msg, '$.source') as source,
    JSON_EXTRACT(msg, '$.inserted') as inserted,
    JSON_EXTRACT(msg, '$.updated') as updated,
    JSON_EXTRACT(msg, '$.processed_files') as processed_files,
    JSON_EXTRACT(msg, '$.files_error') as files_error,
    JSON_EXTRACT(msg, '$.processed_details') as processed_details
FROM import_run
WHERE msg LIKE '%"source":"SFTP"%'
ORDER BY ran_at DESC
LIMIT 5;
```

**Ce qu'on cherche** :
- `processed_files` : nombre de fichiers réellement traités
- `files_error` : nombre de fichiers en erreur
- `processed_details` : détails de chaque fichier (avec `moved_to`)

### Étape 3 : Vérifier les logs PHP

```bash
# Vérifier les logs d'erreur PHP
tail -100 /var/log/php_errors.log | grep "IMPORT SFTP"

# Ou vérifier les logs du script
tail -100 logs/sftp_import.log
tail -100 logs/sftp_import_error.log
```

**Ce qu'on cherche** :
- Messages `"Déplacement réussi"` ou `"ERREUR - Déplacement échoué"`
- Messages `"Fichier déplacé avec succès"` ou `"Impossible de déplacer"`

### Étape 4 : Vérifier FileZilla

1. Se connecter au serveur SFTP
2. Vérifier le répertoire `/` (racine)
3. Vérifier le répertoire `/processed`
4. Vérifier le répertoire `/errors`

**Ce qu'on cherche** :
- Les fichiers sont-ils toujours dans `/` ?
- Y a-t-il des fichiers dans `/processed` ?
- Y a-t-il des fichiers dans `/errors` ?

---

## 🔧 Corrections appliquées

### 1. Amélioration de `sftp_safe_move()`

**Ajouts** :
- ✅ Vérification que le fichier source existe avant déplacement
- ✅ Création automatique du répertoire `/processed` s'il n'existe pas
- ✅ Vérification après déplacement que le fichier est bien à la destination
- ✅ Logs détaillés à chaque étape

### 2. Amélioration des logs de déplacement

**Ajouts** :
- ✅ Log avant déplacement : `"Tentative de déplacement vers /processed"`
- ✅ Log après déplacement : `"Fichier déplacé avec succès"` ou `"ERREUR - Déplacement échoué"`
- ✅ Vérification que le fichier est bien présent à la destination

### 3. Amélioration du badge dans le dashboard

**Ajouts** :
- ✅ Vérification que le résultat est vraiment récent (< 10 minutes)
- ✅ Logs dans la console avec `age_minutes` pour voir l'âge du résultat
- ✅ Affichage de `(récent)` seulement si vraiment récent

---

## 🧪 Test de diagnostic

### Test 1 : Vérifier l'âge du résultat affiché

1. Ouvrir le dashboard
2. Ouvrir la console (F12)
3. Chercher : `[IMPORT] Badge mis à jour`
4. Vérifier `age_minutes`

**Si `age_minutes > 10`** : C'est un ancien résultat, l'import ne s'exécute pas vraiment.

### Test 2 : Forcer un nouvel import

1. Ouvrir la console
2. Exécuter :
```javascript
fetch('/import/run_import_if_due.php?limit=20&force=1', {
    method: 'POST',
    credentials: 'same-origin'
}).then(r => r.json()).then(console.log);
```

3. Observer la réponse JSON
4. Vérifier `inserted`, `updated`, `processed_files`

### Test 3 : Vérifier les détails dans la DB

```sql
SELECT 
    ran_at,
    imported,
    JSON_EXTRACT(msg, '$.processed_files') as processed_files,
    JSON_EXTRACT(msg, '$.files_error') as files_error,
    JSON_EXTRACT(msg, '$.processed_details') as processed_details
FROM import_run
WHERE msg LIKE '%"source":"SFTP"%'
  AND ran_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY ran_at DESC
LIMIT 1;
```

**Vérifier** :
- `processed_files` : Combien de fichiers ont été traités ?
- `files_error` : Y a-t-il des erreurs ?
- `processed_details` : Voir les détails de chaque fichier (chercher `moved_to`)

### Test 4 : Vérifier les logs de déplacement

Dans les logs PHP, chercher :
```
[IMPORT SFTP] {"source":"SFTP","stage":"process_file","moved_to":"/processed/filename.csv",...}
```

**Si `moved_to` est `null` ou `processed_failed`** : Le déplacement a échoué.

---

## 🚨 Solutions selon le diagnostic

### Cas 1 : Badge affiche un ancien résultat

**Symptôme** : `age_minutes > 10` dans la console

**Solution** :
- L'import ne s'exécute pas vraiment
- Vérifier que le cron est configuré (voir `docs/GUIDE_IMPORT_AUTOMATIQUE.md`)
- Vérifier les logs du cron : `tail -f /var/log/import_sftp_cron.log`

### Cas 2 : Import s'exécute mais déplacement échoue

**Symptôme** : `processed_files > 0` mais fichiers toujours dans `/`

**Solution** :
- Vérifier les permissions SFTP sur `/processed`
- Vérifier les logs : `"ERREUR - Déplacement échoué"`
- Vérifier que le répertoire `/processed` existe et est accessible en écriture

### Cas 3 : Import s'exécute mais aucun fichier traité

**Symptôme** : `processed_files = 0` et `files_error > 0`

**Solution** :
- Vérifier les logs : `"ERREUR téléchargement"` ou `"SKIP"`
- Vérifier que les fichiers correspondent au pattern : `COPIEUR_MAC-*.csv`
- Vérifier les erreurs dans `logs/sftp_import_error.log`

---

## 📊 Exemple de log attendu

### Log de succès (fichier déplacé)

```
[2024-01-15 13:30:00] Tentative de déplacement SFTP
  from: /COPIEUR_MAC-123456789ABC_20240115_133000.csv
  to: /processed/COPIEUR_MAC-123456789ABC_20240115_133000.csv
  source_exists: true
  source_size: 1024

[2024-01-15 13:30:01] Déplacement réussi (première tentative)
  from: /COPIEUR_MAC-123456789ABC_20240115_133000.csv
  to: /processed/COPIEUR_MAC-123456789ABC_20240115_133000.csv

[2024-01-15 13:30:01] Vérification après déplacement
  source_exists: false
  target_exists: true
  target_size: 1024

[2024-01-15 13:30:01] Fichier déplacé avec succès
  filename: COPIEUR_MAC-123456789ABC_20240115_133000.csv
  from: /COPIEUR_MAC-123456789ABC_20240115_133000.csv
  to: /processed/COPIEUR_MAC-123456789ABC_20240115_133000.csv
```

### Log d'erreur (déplacement échoué)

```
[2024-01-15 13:30:00] Tentative de déplacement SFTP
  from: /COPIEUR_MAC-123456789ABC_20240115_133000.csv
  to: /processed/COPIEUR_MAC-123456789ABC_20240115_133000.csv

[2024-01-15 13:30:01] ERREUR - Toutes les tentatives de déplacement ont échoué
  from: /COPIEUR_MAC-123456789ABC_20240115_133000.csv
  target: /processed/COPIEUR_MAC-123456789ABC_20240115_133000.csv
  rename_result: false
  sftp_errors: ["Permission denied"]
```

---

## ✅ Checklist de diagnostic

- [ ] Console navigateur : Vérifier `age_minutes` du résultat affiché
- [ ] DB : Vérifier `processed_files` et `files_error` dans `import_run`
- [ ] Logs PHP : Chercher `"Déplacement réussi"` ou `"ERREUR - Déplacement échoué"`
- [ ] FileZilla : Vérifier que les fichiers sont dans `/processed` ou toujours dans `/`
- [ ] Logs détaillés : Vérifier `processed_details` dans la DB pour voir `moved_to`

---

## 🔧 Actions correctives

### Si le badge affiche un ancien résultat

1. Configurer le cron (voir `docs/GUIDE_IMPORT_AUTOMATIQUE.md`)
2. Forcer un import immédiat : `fetch('/import/run_import_if_due.php?limit=20&force=1', ...)`
3. Vérifier que le badge se met à jour

### Si le déplacement échoue

1. Vérifier les permissions SFTP sur `/processed`
2. Vérifier que le répertoire `/processed` existe
3. Vérifier les logs pour voir l'erreur exacte
4. Tester manuellement le déplacement dans FileZilla

### Si aucun fichier n'est traité

1. Vérifier que les fichiers correspondent au pattern : `COPIEUR_MAC-*.csv`
2. Vérifier les erreurs dans `logs/sftp_import_error.log`
3. Vérifier la connexion SFTP

---

**Utilise ce guide pour diagnostiquer pourquoi les fichiers ne sont pas déplacés !**

