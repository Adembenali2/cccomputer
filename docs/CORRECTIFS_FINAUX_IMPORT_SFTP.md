# Correctifs Finaux Import SFTP - Sans DDL

## Modifications appliquées

### A) API/scripts/upload_compteur.php

#### 1. Suppression de tout DDL
- ❌ Supprimé : `ALTER TABLE ... ADD UNIQUE KEY`
- ❌ Supprimé : `CREATE TABLE IF NOT EXISTS import_run`
- ✅ Les contraintes et tables doivent exister en base

#### 2. Validation stricte MacAddress/Timestamp
```php
$macAddress = trim($values['MacAddress'] ?? '');
$timestamp = trim($values['Timestamp'] ?? '');

if (empty($macAddress) || empty($timestamp) || $macAddress === '' || $timestamp === '') {
    // Skip + log + déplacer vers /errors
    $compteurs_skipped++;
    sftp_safe_move($sftp, $remote, '/errors');
    continue;
}
```

#### 3. ON DUPLICATE KEY UPDATE amélioré
```sql
INSERT INTO compteur_relevee (...) VALUES (...)
ON DUPLICATE KEY UPDATE
    DateInsertion = NOW(),
    Status = VALUES(Status),
    -- Tous les champs de mesures (compteurs, toners)
    TonerBlack = VALUES(TonerBlack),
    ...
    TotalBW = VALUES(TotalBW),
    -- Règle COALESCE pour compléter sans écraser
    Nom = COALESCE(Nom, VALUES(Nom)),
    Model = COALESCE(Model, VALUES(Model)),
    SerialNumber = COALESCE(SerialNumber, VALUES(SerialNumber))
```

**Règle appliquée** : COALESCE pour Nom/Model/SerialNumber
- Si valeur existante est NULL → utilise la nouvelle valeur
- Si valeur existante est présente → garde l'ancienne (pas d'écrasement)

#### 4. Rollback en cas d'exception/timeout
```php
try {
    $pdo->beginTransaction();
    $st_compteur->execute($binds);
    $pdo->commit();
} catch (RuntimeException $e) {
    // Timeout
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    break;
} catch (Throwable $e) {
    // Erreur SQL
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log détaillé avec errorInfo()
    $errorInfo = $e->errorInfo ?? null;
    // ...
}
```

#### 5. Logs avec PID et nom de fichier
```php
$PID = getmypid();
define('IMPORT_PID', $PID);

debugLog("Insertion en base de données", [
    'pid' => IMPORT_PID,
    'filename' => $entry,
    'MacAddress' => $values['MacAddress'],
    'Timestamp' => $values['Timestamp'],
    'file_size' => $fileSize,
    'file_date' => $fileDate
]);
```

### B) import/run_import_if_due.php

#### 1. GET_LOCK au début (avant proc_open)
```php
$lockName = 'import_compteur_sftp';
$lockAcquired = false;

try {
    $stmtLock = $pdo->query("SELECT GET_LOCK('$lockName', 0) as lock_result");
    $lockResult = $stmtLock->fetch(PDO::FETCH_ASSOC);
    $lockAcquired = (int)($lockResult['lock_result'] ?? 0) === 1;
    
    if (!$lockAcquired) {
        echo json_encode([
            'ok' => false,
            'reason' => 'locked',
            'message' => 'Un import est déjà en cours (verrou MySQL actif)'
        ], ...);
        exit;
    }
} catch (Throwable $e) {
    // Erreur acquisition
    exit;
}
```

#### 2. RELEASE_LOCK toujours (finally équivalent)
```php
$releaseLock = function() use ($pdo, $lockName, &$lockAcquired) {
    if ($lockAcquired) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')");
            $lockAcquired = false;
        } catch (Throwable $e) {
            // Log erreur
        }
    }
};

// ... code ...

// Libération à la fin (même en cas d'erreur)
try {
    if (isset($releaseLock) && is_callable($releaseLock)) {
        $releaseLock();
    }
} catch (Throwable $e) {
    // Log
}
```

#### 3. App_kv conservé pour "not_due"
```php
$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$due = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

if (!$due) {
    $releaseLock();
    echo json_encode(['ok' => false, 'reason' => 'not_due', ...]);
    exit;
}
```

#### 4. Check import récent : log informatif uniquement (non bloquant)
```php
// Log informatif (non bloquant) sur les imports récents
try {
    $stmtRunning = $pdo->prepare("
        SELECT ran_at, imported 
        FROM import_run 
        WHERE ran_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        AND msg LIKE '%\"source\":\"SFTP\"%'
        ORDER BY ran_at DESC LIMIT 1
    ");
    $stmtRunning->execute();
    $recent = $stmtRunning->fetch(PDO::FETCH_ASSOC);
    if ($recent) {
        debugLog("Import récent détecté (informatif)", ['ran_at' => $recent['ran_at']]);
    }
} catch (Throwable $e) {
    // Log informatif uniquement, pas d'impact
}
```

#### 5. Réponse JSON structurée
```json
{
  "ok": true,
  "ran": true,
  "inserted": 5,
  "updated": 2,
  "skipped": 0,
  "errors": [],
  "duration_ms": 3456,
  "code": 0
}
```

En cas d'erreur :
```json
{
  "ok": false,
  "ran": true,
  "inserted": 0,
  "updated": 0,
  "skipped": 3,
  "errors": ["Erreur SQL: ..."],
  "error": "Erreur SQL: ...",
  "where": "script_execution",
  "details": {...},
  "duration_ms": 1234
}
```

En cas de verrou :
```json
{
  "ok": false,
  "reason": "locked",
  "message": "Un import est déjà en cours (verrou MySQL actif)"
}
```

## C) Checklist de tests

### Test 1 : Double run (idempotence)
```bash
# Premier run
php API/scripts/upload_compteur.php

# Deuxième run immédiatement après
php API/scripts/upload_compteur.php
```
**Résultat attendu** :
- 1er run : `inserted > 0`
- 2ème run : `updated > 0`, `inserted = 0`
- ✅ Pas de doublon dans la base

### Test 2 : Concurrence (lock)
```bash
# Terminal 1
curl -X POST http://localhost/import/run_import_if_due.php?limit=5

# Terminal 2 (immédiatement après, < 20s)
curl -X POST http://localhost/import/run_import_if_due.php?limit=5
```
**Résultat attendu** :
- 1er appel : `ok: true`, import se lance
- 2ème appel : `ok: false, reason: "locked"`
- ✅ Un seul import en cours à la fois

### Test 3 : Données manquantes
Créer un fichier CSV avec MacAddress ou Timestamp vide/manquant
```bash
php API/scripts/upload_compteur.php
```
**Résultat attendu** :
- Fichier déplacé vers `/errors`
- `skipped > 0` dans les logs
- Message : "Données manquantes (MacAddress/Timestamp)"
- ✅ Pas d'insertion avec données invalides

### Test 4 : Vérification SQL des doublons
```sql
-- Vérifier qu'il n'y a pas de doublons
SELECT mac_norm, Timestamp, COUNT(*) as cnt 
FROM compteur_relevee 
GROUP BY mac_norm, Timestamp 
HAVING cnt > 1;

-- Doit retourner 0 lignes
```

### Test 5 : Vérification de la contrainte UNIQUE
```sql
-- Vérifier que la contrainte existe
SELECT 
    CONSTRAINT_NAME, 
    CONSTRAINT_TYPE 
FROM information_schema.TABLE_CONSTRAINTS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'compteur_relevee'
AND CONSTRAINT_TYPE = 'UNIQUE'
AND CONSTRAINT_NAME LIKE '%mac%timestamp%';

-- Doit retourner au moins 1 ligne
```

### Test 6 : Vérification ON DUPLICATE KEY UPDATE
```sql
-- Insérer un doublon volontairement (devrait être UPDATE, pas erreur)
INSERT INTO compteur_relevee (MacAddress, Timestamp, ...) VALUES (...)
ON DUPLICATE KEY UPDATE TotalPages = VALUES(TotalPages);

-- Vérifier que c'est un UPDATE (affected_rows = 2)
```

### Test 7 : Vérification du verrou
```sql
-- Vérifier les verrous actifs
SELECT * FROM performance_schema.metadata_locks 
WHERE OBJECT_NAME = 'import_compteur_sftp';

-- Ou via MySQL (version 8+)
SHOW PROCESSLIST;
```

## D) Vérification SQL des doublons

### 1. Vérifier l'absence de doublons
```sql
SELECT mac_norm, Timestamp, COUNT(*) as cnt 
FROM compteur_relevee 
GROUP BY mac_norm, Timestamp 
HAVING cnt > 1;
```
**Résultat attendu** : 0 lignes (aucun doublon)

### 2. Vérifier la contrainte UNIQUE
```sql
SHOW CREATE TABLE compteur_relevee;
```
**Chercher** : `UNIQUE KEY` avec `mac_norm` et `Timestamp`

### 3. Vérifier les imports récents
```sql
SELECT 
    id, 
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

### 4. Tester l'idempotence manuellement
```sql
-- Insérer un test
INSERT INTO compteur_relevee (MacAddress, Timestamp, TotalPages, DateInsertion)
VALUES ('AA:BB:CC:DD:EE:FF', '2024-12-19 10:00:00', 100, NOW())
ON DUPLICATE KEY UPDATE TotalPages = VALUES(TotalPages);

-- Relancer la même insertion
INSERT INTO compteur_relevee (MacAddress, Timestamp, TotalPages, DateInsertion)
VALUES ('AA:BB:CC:DD:EE:FF', '2024-12-19 10:00:00', 200, NOW())
ON DUPLICATE KEY UPDATE TotalPages = VALUES(TotalPages);

-- Vérifier qu'il n'y a qu'une seule ligne
SELECT * FROM compteur_relevee 
WHERE MacAddress = 'AA:BB:CC:DD:EE:FF' 
AND Timestamp = '2024-12-19 10:00:00';

-- Doit retourner 1 ligne avec TotalPages = 200 (mis à jour)
```

## E) Diff des fichiers modifiés

### API/scripts/upload_compteur.php

**Supprimé** :
- Section création contrainte UNIQUE (lignes ~133-160)
- `CREATE TABLE IF NOT EXISTS import_run` (lignes ~377-391)

**Ajouté** :
- Validation stricte MacAddress/Timestamp avec skip
- Logs avec PID et nom de fichier
- Compteurs séparés (inserted, updated, skipped)
- Rollback explicite dans catch

**Modifié** :
- ON DUPLICATE KEY UPDATE avec COALESCE pour Nom/Model/SerialNumber

### import/run_import_if_due.php

**Ajouté** :
- GET_LOCK au début (avant proc_open)
- RELEASE_LOCK en fin (finally équivalent)
- Parsing de la sortie pour extraire inserted/updated/skipped
- Réponse JSON structurée avec `ok`, `inserted`, `updated`, `skipped`, `errors`, `duration_ms`

**Modifié** :
- Check import récent : log informatif uniquement (non bloquant)

## F) Pourquoi ça ne retombera pas

1. **Pas de DDL** : Aucune création/modification de structure → stabilité garantie
2. **UNIQUE constraint** : MySQL empêche physiquement les doublons au niveau base
3. **ON DUPLICATE KEY UPDATE** : Gère proprement les doublons (UPDATE au lieu d'erreur)
4. **GET_LOCK** : Empêche les exécutions concurrentes au niveau MySQL
5. **Validation stricte** : Skip des données invalides avant insertion
6. **Rollback** : Transaction annulée en cas d'erreur/timeout
7. **RELEASE_LOCK toujours** : Verrou libéré même en cas d'erreur (finally)

## Résumé

- ✅ Aucun DDL dans le script
- ✅ Idempotent (relançable sans doublons)
- ✅ Anti-parallélisme via GET_LOCK
- ✅ Validation stricte des données
- ✅ Rollback en cas d'erreur
- ✅ Logs détaillés avec PID
- ✅ Réponse JSON structurée

