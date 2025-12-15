# Correctifs Import SFTP - Résumé

## A) Diagnostic de l'erreur actuelle

### Points d'échec identifiés

1. **Pas de validation stricte MacAddress/Timestamp**
   - Avant : Vérification `empty()` insuffisante
   - Maintenant : Validation stricte avec trim + vérification NULL/EMPTY

2. **Pas de verrou anti-parallélisme**
   - Avant : Plusieurs imports peuvent tourner en même temps
   - Maintenant : Verrou MySQL GET_LOCK avec timeout 0

3. **Logs insuffisants**
   - Avant : Pas de PID, pas de contexte détaillé
   - Maintenant : PID dans tous les logs, contexte complet (mac, timestamp, filename, taille)

4. **ON DUPLICATE KEY UPDATE trop agressif**
   - Avant : Écrasait tous les champs y compris identification
   - Maintenant : Ne met à jour que les compteurs/toners, préserve Nom/Model/SerialNumber avec COALESCE

## B) Modifications apportées

### `API/scripts/upload_compteur.php`

1. **Ajout PID pour traçabilité**
   ```php
   $PID = getmypid();
   define('IMPORT_PID', $PID);
   ```

2. **Validation stricte avant insertion**
   ```php
   $macAddress = trim($values['MacAddress'] ?? '');
   $timestamp = trim($values['Timestamp'] ?? '');
   
   if (empty($macAddress) || empty($timestamp) || $macAddress === '' || $timestamp === '') {
       // Skip + log + déplacer vers /errors
   }
   ```

3. **ON DUPLICATE KEY UPDATE amélioré**
   ```sql
   ON DUPLICATE KEY UPDATE
       DateInsertion = NOW(),
       Status = VALUES(Status),
       -- Compteurs et toners uniquement
       TonerBlack = VALUES(TonerBlack),
       ...
       -- Préserver les champs d'identification si déjà présents
       Nom = COALESCE(Nom, VALUES(Nom)),
       Model = COALESCE(Model, VALUES(Model)),
       SerialNumber = COALESCE(SerialNumber, VALUES(SerialNumber))
   ```

4. **Compteurs séparés**
   - `$compteurs_inserted` : Nouveaux enregistrements
   - `$compteurs_updated` : Doublons mis à jour
   - `$compteurs_skipped` : Ignorés (erreurs, données manquantes)

5. **Logs détaillés avec contexte**
   - PID dans tous les logs
   - Taille et date des fichiers
   - Premier erreur SQL complète avec `errorInfo()`
   - Statistiques détaillées dans `import_run`

### `import/run_import_if_due.php`

1. **Verrou MySQL GET_LOCK**
   ```php
   $lockName = 'import_compteur_sftp';
   $stmtLock = $pdo->query("SELECT GET_LOCK('$lockName', 0) as lock_result");
   $lockAcquired = (int)($lockResult['lock_result'] ?? 0) === 1;
   
   if (!$lockAcquired) {
       return JSON {ok: false, reason: 'locked'};
   }
   
   // Libération dans finally/end
   $pdo->query("SELECT RELEASE_LOCK('$lockName')");
   ```

2. **Protection "import récent"**
   ```php
   SELECT COUNT(*) FROM import_run 
   WHERE ran_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
   AND msg LIKE '%"source":"SFTP"%'
   ```

3. **Réponse JSON structurée**
   ```json
   {
     "ok": true,
     "inserted": 5,
     "updated": 2,
     "skipped": 0,
     "duration_ms": 1234,
     "code": 0
   }
   ```
   Ou en cas d'erreur :
   ```json
   {
     "ok": false,
     "error": "...",
     "where": "script_execution",
     "details": {...}
   }
   ```

## C) Pourquoi ça ne retombera pas

### Protection contre les doublons

1. **Contrainte UNIQUE MySQL** : `(mac_norm, Timestamp)`
   - MySQL empêche physiquement les doublons
   - `mac_norm` est une colonne générée (uppercase sans `:`)

2. **ON DUPLICATE KEY UPDATE**
   - Si doublon détecté → UPDATE au lieu de INSERT
   - `rowCount = 2` indique une mise à jour
   - Pas de doublon possible grâce à la contrainte

3. **Validation stricte**
   - MacAddress/Timestamp vides → Skip immédiat
   - Pas d'insertion de données invalides

### Protection contre les exécutions concurrentes

1. **Verrou MySQL GET_LOCK**
   - Verrou nommé au niveau MySQL
   - Timeout 0 = échec immédiat si verrouillé
   - Libération garantie (try/finally)

2. **Vérification import récent**
   - Évite de relancer si import < 60s
   - Basé sur `import_run.ran_at`

3. **Anti-bouclage app_kv**
   - Intervalle minimum 20 secondes
   - Check avant acquisition du verrou

### Import idempotent

1. **Fichiers déplacés vers /processed**
   - Ne seront pas retraités lors du prochain scan
   - Archivage automatique après traitement

2. **ON DUPLICATE KEY UPDATE**
   - Relancer plusieurs fois le même fichier → UPDATE au lieu d'erreur
   - Pas de doublon même si script relancé

## D) Exemple de réponse JSON

### Succès
```json
{
  "ok": true,
  "ran": true,
  "inserted": 5,
  "updated": 2,
  "skipped": 0,
  "stdout": "...",
  "stderr": "",
  "last_run": "2024-12-19 10:30:00",
  "code": 0,
  "timeout": false,
  "error": null,
  "duration_ms": 3456
}
```

### Verrouillé (concurrent)
```json
{
  "ok": false,
  "reason": "locked",
  "message": "Un import est déjà en cours (verrou MySQL actif)"
}
```

### Erreur
```json
{
  "ok": false,
  "ran": true,
  "inserted": 0,
  "updated": 0,
  "skipped": 3,
  "error": "Erreur SQL: Duplicate entry...",
  "where": "script_execution",
  "details": {
    "code": 1,
    "stdout_length": 1234,
    "stderr_length": 567
  },
  "duration_ms": 1234
}
```

## E) Tests

### Test 1 : Import normal
```bash
php API/scripts/upload_compteur.php
```
Attendu : Traitement des fichiers, logs avec PID, statistiques

### Test 2 : Relance (idempotence)
```bash
php API/scripts/upload_compteur.php
php API/scripts/upload_compteur.php  # Relancer immédiatement
```
Attendu : 2ème run → `updated > 0`, pas de doublon

### Test 3 : Concurrence (lock)
```bash
# Terminal 1
curl -X POST http://localhost/import/run_import_if_due.php

# Terminal 2 (immédiatement après)
curl -X POST http://localhost/import/run_import_if_due.php
```
Attendu : 1 seul passe, 1 retourne `{ok: false, reason: 'locked'}`

### Test 4 : Données manquantes
Créer un fichier CSV avec MacAddress ou Timestamp vide
```bash
php API/scripts/upload_compteur.php
```
Attendu : Fichier déplacé vers /errors, `skipped > 0` dans les logs

### Test 5 : Vérification base
```sql
-- Vérifier qu'il n'y a pas de doublons
SELECT mac_norm, Timestamp, COUNT(*) as cnt 
FROM compteur_relevee 
GROUP BY mac_norm, Timestamp 
HAVING cnt > 1;
```
Attendu : 0 lignes (pas de doublons grâce à UNIQUE)

## F) Checklist de validation

- [x] PID dans tous les logs
- [x] Validation stricte MacAddress/Timestamp
- [x] ON DUPLICATE KEY UPDATE (ne casse pas identification)
- [x] Verrou MySQL GET_LOCK
- [x] Protection import récent
- [x] Compteurs séparés (inserted/updated/skipped)
- [x] Logs d'erreur SQL détaillés
- [x] Réponse JSON structurée
- [x] Libération garantie du verrou
- [x] Fichiers déplacés vers /processed

