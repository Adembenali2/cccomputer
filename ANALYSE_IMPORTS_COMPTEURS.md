# Analyse des Imports de Compteurs - cccomputer

## A) LOCALISATION DES 2 IMPORTS

### Import #1 : SFTP (Serveur FTP)
**Fichiers impliqués :**
- Point d'entrée JS : `public/dashboard.php` (lignes 1568-1649)
- Route de déclenchement : `import/run_import_if_due.php`
- Script d'import : `API/scripts/upload_compteur.php`
- Affichage statut : `import/last_import.php`

**Routes/endpoints :**
- POST `/import/run_import_if_due.php?limit=20` (appelé toutes les 20 secondes)
- GET `/import/last_import.php` (affichage du dernier import)

**Fonctions principales :**
- `run_import_if_due.php` : Anti-bouclage + lancement proc_open
- `upload_compteur.php` : Connexion SFTP, téléchargement CSV, parsing, insertion DB

---

### Import #2 : WEB_COMPTEUR (IONOS - Page web)
**Fichiers impliqués :**
- Point d'entrée JS : `public/dashboard.php` (lignes 1651-1732)
- Route de déclenchement : `import/run_import_web_if_due.php`
- Script d'import : `import/import_ancien_http.php`
- Affichage statut : `import/last_import_web.php`

**Routes/endpoints :**
- POST `/import/run_import_web_if_due.php` (appelé toutes les 2 minutes)
- GET `/import/last_import_web.php` (affichage du dernier import)

**Fonctions principales :**
- `run_import_web_if_due.php` : Anti-bouclage + lancement proc_open
- `import_ancien_http.php` : Téléchargement HTML, parsing DOM, insertion DB

---

## B) FLOW DÉTAILLÉ IMPORT #1 (SFTP)

### Étape 1 : Point d'entrée
- **Page** : `public/dashboard.php`
- **Event JS** : Interval automatique toutes les 20 secondes (ligne 1648)
- **Fonction** : `tick()` → `callJSON(SFTP_URL + '?limit=20')`
- **Méthode** : POST (fetch API)

### Étape 2 : Déclenchement conditionnel
- **Fichier** : `import/run_import_if_due.php`
- **Validation** :
  - Vérifie l'anti-bouclage dans `app_kv` (clé: `sftp_last_run`)
  - Intervalle minimum : 20 secondes (configurable via `SFTP_IMPORT_INTERVAL_SEC`)
  - Si pas due → retour JSON `{ran: false, reason: 'not_due'}`
  - Si due → met à jour `app_kv` et lance le script

### Étape 3 : Exécution du script d'import
- **Fichier** : `API/scripts/upload_compteur.php`
- **Lancement** : `proc_open()` avec timeout de 60 secondes
- **Processus** :
  1. Normalisation variables MySQL (env → Railway)
  2. Chargement `includes/db.php` → connexion PDO
  3. Chargement `vendor/autoload.php` → bibliothèque phpseclib3
  4. Connexion SFTP (variables env : `SFTP_HOST`, `SFTP_USER`, `SFTP_PASS`, `SFTP_PORT`)
  5. Liste fichiers CSV dans `/` (pattern : `COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv`)
  6. Limitation à 20 fichiers maximum (variable `SFTP_BATCH_LIMIT`)

### Étape 4 : Traitement des fichiers CSV
- **Téléchargement** : SFTP → fichier temporaire local
- **Parsing** : Format key-value CSV (fonction `parse_csv_kv()`)
  - Structure attendue : `clé,valeur` (lignes séparées)
  - Champs extraits : voir variable `$FIELDS` (33 champs)
- **Validation** : Vérifie présence de `MacAddress` et `Timestamp`
- **Si erreur** : Fichier déplacé vers `/errors` sur SFTP

### Étape 5 : Insertion en base
- **Table** : `compteur_relevee`
- **Requête** : `INSERT IGNORE INTO compteur_relevee (...) VALUES (...)`
- **Contrainte** : Pas de contrainte UNIQUE, utilise `INSERT IGNORE` pour éviter doublons
- **Index** : `ix_compteur_mac_ts` sur (`mac_norm`, `Timestamp`)
- **Transaction** : Transaction PDO par fichier (begin/commit)

### Étape 6 : Archivage
- **Succès** : Fichier déplacé vers `/processed` sur SFTP
- **Échec** : Fichier déplacé vers `/errors` sur SFTP

### Étape 7 : Journalisation
- **Table** : `import_run`
- **Données loggées** :
  - `ran_at` : Date/heure d'exécution
  - `imported` : Nombre de compteurs insérés
  - `skipped` : Nombre de fichiers en erreur
  - `ok` : 1 si succès, 0 si erreur
  - `msg` : JSON avec `source='SFTP'`, `files_processed`, `compteurs_inserted`, `files`, etc.

### Étape 8 : Affichage dans le dashboard
- **Endpoint** : GET `/import/last_import.php`
- **Filtre** : Récupère le dernier import avec `source='SFTP'`
- **Affichage** : Badge "Import SFTP" avec statut (✓/⏳/!)

---

## C) FLOW DÉTAILLÉ IMPORT #2 (WEB_COMPTEUR/IONOS)

### Étape 1 : Point d'entrée
- **Page** : `public/dashboard.php`
- **Event JS** : Interval automatique toutes les 2 minutes (ligne 1731)
- **Fonction** : `tick()` → `callJSON(WEB_URL)`
- **Méthode** : POST (fetch API)

### Étape 2 : Déclenchement conditionnel
- **Fichier** : `import/run_import_web_if_due.php`
- **Validation** :
  - Vérifie l'anti-bouclage dans `app_kv` (clé: `web_compteur_last_run`)
  - Intervalle minimum : 120 secondes (2 minutes) - configurable via `WEB_IMPORT_INTERVAL_SEC`
  - Si pas due → retour JSON `{ran: false, reason: 'not_due'}`
  - Si due → met à jour `app_kv` et lance le script

### Étape 3 : Exécution du script d'import
- **Fichier** : `import/import_ancien_http.php`
- **Lancement** : `proc_open()` (pas de timeout explicite)
- **Processus** :
  1. Normalisation variables MySQL (env → Railway)
  2. Chargement `includes/db.php` → connexion PDO
  3. Vérification contrainte UNIQUE sur `compteur_relevee_ancien` (crée si absente)
  4. Récupération dernier `Timestamp` importé (pour reprendre après)

### Étape 4 : Téléchargement HTML
- **URL source** : `https://cccomputer.fr/test_compteur.php`
- **Méthode** : `file_get_contents()` avec timeout 30s
- **User-Agent** : `Mozilla/5.0 (compatible; ImportBot/1.0)`
- **Si erreur** : Log dans `import_run` avec `ok=0` et exit

### Étape 5 : Parsing HTML
- **Bibliothèque** : DOMDocument + DOMXPath
- **Détection automatique** : Mapping des colonnes via l'en-tête `<thead>` ou `<th>`
  - Colonnes recherchées : MAC, Date, Ref Client, Marque, Modèle, Série, Total NB, Total Couleur, Toner K/C/M/Y, Status
- **Fallback** : Mapping par défaut si détection échoue (index colonnes fixes)
- **Filtrage** : Ne garde que les enregistrements avec `Timestamp > dernier Timestamp importé`
- **Extraction toner** : Parsing des valeurs depuis `<div class="toner">` ou texte brut

### Étape 6 : Validation et tri
- **Validation** : Vérifie présence de `MacAddress` et `Timestamp`
- **Tri** : Par `Timestamp` croissant (ASC) - du plus ancien au plus récent
- **Limite batch** : Maximum 20 lignes par exécution (variable `$BATCH`)

### Étape 7 : Insertion en base
- **Table** : `compteur_relevee_ancien`
- **Requête** : `INSERT INTO ... ON DUPLICATE KEY UPDATE ...`
- **Contrainte UNIQUE** : `uniq_mac_ts_ancien` sur (`mac_norm`, `Timestamp`)
- **Comportement** :
  - Si nouveau → INSERT (1 ligne affectée)
  - Si doublon → UPDATE (2 lignes affectées)
  - `mac_norm` est une colonne générée : `replace(upper(MacAddress), ':', '')`
- **Transaction** : Une transaction globale pour tous les relevés du batch

### Étape 8 : Journalisation
- **Table** : `import_run`
- **Données loggées** :
  - `ran_at` : Date/heure d'exécution
  - `imported` : Nombre de relevés insérés (nouveaux)
  - `skipped` : Nombre de relevés mis à jour ou ignorés
  - `ok` : 1 si succès, 0 si erreur
  - `msg` : JSON avec `source='WEB_COMPTEUR'`, `processed`, `inserted`, `updated`, `remaining_estimate`, `files`, `errors`

### Étape 9 : Affichage dans le dashboard
- **Endpoint** : GET `/import/last_import_web.php`
- **Filtre** : Récupère le dernier import avec `source='WEB_COMPTEUR'`
- **Affichage** : Badge "import IONOS" avec statut (✓/⏳/!)

---

## D) DIFFÉRENCES CLÉS ENTRE LES 2 IMPORTS

| Critère | Import #1 (SFTP) | Import #2 (WEB_COMPTEUR) |
|---------|------------------|--------------------------|
| **Source** | Serveur SFTP (fichiers CSV) | Page web HTML (table) |
| **Table destination** | `compteur_relevee` | `compteur_relevee_ancien` |
| **Format données** | CSV key-value (clé,valeur) | Table HTML (DOM parsing) |
| **Fréquence** | Toutes les 20 secondes | Toutes les 2 minutes |
| **Batch limit** | 20 fichiers CSV | 20 lignes HTML |
| **Gestion doublons** | `INSERT IGNORE` | `INSERT ... ON DUPLICATE KEY UPDATE` |
| **Contrainte UNIQUE** | ❌ Pas de contrainte (seulement index) | ✅ Contrainte sur (`mac_norm`, `Timestamp`) |
| **Colonne mac_norm** | Générée (STORED) | Générée (STORED) |
| **Transaction** | Par fichier | Globale pour le batch |
| **Reprise** | Re-démarre depuis le début | Reprend depuis `MAX(Timestamp)` |
| **Archivage** | Fichiers déplacés vers `/processed` ou `/errors` | Pas d'archivage (données web) |
| **Timeout** | 60 secondes (proc_open) + 50 secondes (script) | Pas de timeout explicite |
| **Source log** | `source='SFTP'` | `source='WEB_COMPTEUR'` |

**Pourquoi 2 imports ?**
- **SFTP** : Import des nouveaux relevés depuis un serveur FTP externe (format CSV standardisé)
- **WEB_COMPTEUR** : Import des anciens relevés depuis une page web IONOS (format HTML legacy)
- Les deux sources alimentent des tables différentes pour des raisons historiques/maintenance

---

## E) DIAGNOSTIC DES ERREURS

### Problèmes identifiés dans le code

#### ❌ PROBLÈME #1 : Import SFTP - Pas de contrainte UNIQUE
**Fichier** : `API/scripts/upload_compteur.php` ligne 337
**Symptôme** : `INSERT IGNORE` peut laisser passer des doublons si la requête échoue pour autre raison
**Cause** : La table `compteur_relevee` n'a **pas** de contrainte UNIQUE sur (`mac_norm`, `Timestamp`), seulement un index
**Impact** : Risque de doublons si même `MacAddress` + même `Timestamp` sont insérés plusieurs fois

#### ❌ PROBLÈME #2 : Import WEB - Contrainte UNIQUE créée à chaque exécution
**Fichier** : `import/import_ancien_http.php` ligne 89
**Symptôme** : Tentative de création de contrainte à chaque run (ignorée si existe, mais log d'erreur)
**Cause** : `ALTER TABLE ... ADD UNIQUE KEY` sans vérification préalable de l'existence
**Impact** : Erreur MySQL ignorée mais visible dans les logs

#### ❌ PROBLÈME #3 : Import WEB - Pas de gestion d'erreur pour le parsing HTML
**Fichier** : `import/import_ancien_http.php` ligne 131-152
**Symptôme** : Si la structure HTML change, le parsing peut échouer silencieusement
**Cause** : Pas de validation robuste du mapping des colonnes
**Impact** : Données non importées sans message d'erreur clair

#### ❌ PROBLÈME #4 : Import SFTP - Transaction par fichier vs timeout global
**Fichier** : `API/scripts/upload_compteur.php` ligne 574-599
**Symptôme** : Si timeout pendant le traitement, la transaction peut rester ouverte
**Cause** : Timeout de 50s sur le script mais transaction non rollback explicitement en cas de timeout
**Impact** : Verrous potentiels en base

#### ❌ PROBLÈME #5 : Import WEB - Pas de timeout sur proc_open
**Fichier** : `import/run_import_web_if_due.php` ligne 78-89
**Symptôme** : Si le script s'accroche, le processus peut rester bloqué indéfiniment
**Cause** : Pas de timeout explicite comme dans `run_import_if_due.php`
**Impact** : Processus zombie possible

#### ❌ PROBLÈME #6 : Import SFTP - Vérification timeout dans la boucle mais pas au niveau global
**Fichier** : `API/scripts/upload_compteur.php` ligne 445
**Symptôme** : La fonction `checkTimeout()` est appelée dans la boucle mais peut être bypassée
**Cause** : Pas de vérification systématique avant chaque opération longue
**Impact** : Risque de dépassement du timeout

### Messages d'erreur probables

Pour identifier l'erreur exacte, vérifier :
1. **Console JavaScript** (F12) : Erreurs fetch/JSON
2. **Logs PHP** : `error_log()` dans les scripts
3. **Table `import_run`** : Dernière entrée avec `ok=0`
4. **Table `app_kv`** : Vérifier les valeurs de `sftp_last_run` et `web_compteur_last_run`

---

## F) CORRECTIFS PROPOSÉS

### Correctif #1 : Ajouter contrainte UNIQUE pour import SFTP

**Fichier** : `API/scripts/upload_compteur.php`
**Ligne** : Après la connexion PDO (ligne ~120)

```php
// Vérifier/créer la contrainte UNIQUE sur (mac_norm, Timestamp) pour compteur_relevee
try {
    // Vérifier si la contrainte existe déjà
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'compteur_relevee'
        AND CONSTRAINT_NAME = 'uniq_mac_ts_sftp'
        AND CONSTRAINT_TYPE = 'UNIQUE'
    ");
    $exists = (int)$stmt->fetchColumn() > 0;
    
    if (!$exists) {
        debugLog("Création de la contrainte UNIQUE sur compteur_relevee");
        $pdo->exec("
            ALTER TABLE `compteur_relevee` 
            ADD UNIQUE KEY `uniq_mac_ts_sftp` (`mac_norm`, `Timestamp`)
        ");
        echo "✅ Contrainte UNIQUE créée sur compteur_relevee\n";
    }
} catch (Throwable $e) {
    debugLog("Avertissement contrainte UNIQUE", ['error' => $e->getMessage()]);
    // Continuer même si la contrainte existe déjà
}
```

**Changement requête** : Remplacer `INSERT IGNORE` par `INSERT ... ON DUPLICATE KEY UPDATE` (comme l'import WEB)

```php
// Ligne 337 - REMPLACER
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";

// PAR
$sql_compteur  = "
    INSERT INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)
    ON DUPLICATE KEY UPDATE
        DateInsertion = NOW(),
        Nom = VALUES(Nom),
        Model = VALUES(Model),
        SerialNumber = VALUES(SerialNumber)
";
```

---

### Correctif #2 : Améliorer gestion contrainte UNIQUE pour import WEB

**Fichier** : `import/import_ancien_http.php`
**Ligne** : 86-93

```php
// Vérifier si la contrainte existe avant de la créer
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'compteur_relevee_ancien'
        AND CONSTRAINT_NAME = 'uniq_mac_ts_ancien'
        AND CONSTRAINT_TYPE = 'UNIQUE'
    ");
    $exists = (int)$stmt->fetchColumn() > 0;
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE `compteur_relevee_ancien` ADD UNIQUE KEY `uniq_mac_ts_ancien` (`mac_norm`,`Timestamp`)");
        error_log('import_ancien_http: Contrainte UNIQUE créée');
    }
} catch (Throwable $e) {
    // La contrainte existe déjà ou erreur - continuer
    error_log('import_ancien_http: Contrainte UNIQUE déjà présente ou erreur: ' . $e->getMessage());
}
```

---

### Correctif #3 : Ajouter validation robuste pour parsing HTML

**Fichier** : `import/import_ancien_http.php`
**Ligne** : Après ligne 214 (après le fallback mapping)

```php
// Validation du mapping des colonnes essentielles
if (empty($columnMap['mac']) || empty($columnMap['date'])) {
    $errorMsg = json_encode([
        'source'=>'WEB_COMPTEUR',
        'processed'=>0,
        'inserted'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'error'=>'Impossible de détecter les colonnes MAC et Date dans le tableau HTML'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR ANCIEN Colonnes MAC/Date non détectées\n";
    exit(1);
}
```

---

### Correctif #4 : Améliorer gestion timeout pour import SFTP

**Fichier** : `API/scripts/upload_compteur.php`
**Ligne** : 567-615 (dans la boucle d'insertion)

```php
// AVANT l'insertion, vérifier le timeout
try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    
    $insertStart = microtime(true);
    $pdo->beginTransaction();
    debugLog("Transaction démarrée");
    
    // ... insertion ...
    
    $pdo->commit();
    debugLog("Transaction commitée");
    
} catch (RuntimeException $e) {
    // Timeout - rollback et arrêt
    if (isset($pdo) && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
            debugLog("Transaction rollback (timeout)");
        } catch (Throwable $rollbackErr) {
            debugLog("Erreur rollback timeout", ['error' => $rollbackErr->getMessage()]);
        }
    }
    echo "⏱️ TIMEOUT: Arrêt du traitement (fichier: $entry)\n";
    debugLog("TIMEOUT détecté", ['error' => $e->getMessage()]);
    break;
} catch (Throwable $e) {
    // ... gestion erreur existante ...
}
```

---

### Correctif #5 : Ajouter timeout pour import WEB

**Fichier** : `import/run_import_web_if_due.php`
**Ligne** : Après ligne 76

```php
// Timeout maximum : 120 secondes (2x l'intervalle normal)
$TIMEOUT_SEC = 120;
$startTime = time();

$proc = proc_open($cmd, $desc, $pipes, $projectRoot, $env);
$out = $err = '';
$code = null;
$timeoutReached = false;

if (is_resource($proc)) {
    // Configurer les pipes en mode non-bloquant
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    $read = [$pipes[1], $pipes[2]];
    
    // Lire les pipes avec timeout
    while (is_resource($proc)) {
        $status = proc_get_status($proc);
        
        // Vérifier le timeout
        $elapsed = time() - $startTime;
        if ($elapsed > $TIMEOUT_SEC) {
            $timeoutReached = true;
            proc_terminate($proc, SIGTERM);
            sleep(2);
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, SIGKILL);
            }
            $err = "TIMEOUT: Le processus a dépassé la limite de {$TIMEOUT_SEC} secondes";
            $code = -1;
            break;
        }
        
        // Si le processus est terminé, lire les dernières données
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }
        
        // Lire les données disponibles (non-bloquant)
        $changed = @stream_select($read, $write, $except, 1);
        if ($changed === false) {
            usleep(100000);
            continue;
        }
        
        if ($changed > 0) {
            foreach ($read as $pipe) {
                if ($pipe === $pipes[1]) {
                    $data = stream_get_contents($pipes[1]);
                    if ($data !== false && $data !== '') $out .= $data;
                } elseif ($pipe === $pipes[2]) {
                    $data = stream_get_contents($pipes[2]);
                    if ($data !== false && $data !== '') $err .= $data;
                }
            }
        } else {
            usleep(100000);
        }
    }
    
    // Lire les dernières données restantes
    $remainingOut = stream_get_contents($pipes[1]);
    $remainingErr = stream_get_contents($pipes[2]);
    if ($remainingOut !== false) $out .= $remainingOut;
    if ($remainingErr !== false) $err .= $remainingErr;
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    if (is_resource($proc)) {
        $code = proc_close($proc);
    }
} else {
    $err = 'Impossible de créer le processus';
    $code = -1;
}

// Vérifier si le processus a échoué
$success = ($code === 0 && !$timeoutReached);
if (!$success && empty($err) && !$timeoutReached) {
    $err = "Processus terminé avec le code de sortie: $code";
}

echo json_encode([
    'ran'      => true,
    'stdout'   => trim($out),
    'stderr'   => trim($err),
    'last_run' => date('Y-m-d H:i:s'),
    'code'     => $code,
    'success'  => $success,
    'timeout'  => $timeoutReached
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

---

### Correctif #6 : Améliorer logs et messages d'erreur

**Pour les deux imports**, ajouter des logs détaillés :

```php
// Exemple pour import SFTP - après chaque étape critique
debugLog("Fichier CSV traité", [
    'filename' => $entry,
    'mac' => $values['MacAddress'] ?? 'NULL',
    'timestamp' => $values['Timestamp'] ?? 'NULL',
    'row_count' => $rowCount,
    'inserted' => $rowCount === 1
]);

// Exemple pour import WEB - après parsing HTML
error_log('import_ancien_http: HTML parsé - ' . count($rowsData) . ' lignes candidates, mapping: ' . json_encode($columnMap));
```

---

## TESTS MINIMAUX POUR VÉRIFIER

### Test Import SFTP

1. **Vérifier connexion SFTP** :
```bash
php API/scripts/upload_compteur.php
```
Attendu : Connexion réussie + liste fichiers

2. **Vérifier endpoint déclenchement** :
```bash
curl -X POST http://localhost/import/run_import_if_due.php?limit=5
```
Attendu : JSON avec `ran: true` ou `ran: false` (selon anti-bouclage)

3. **Vérifier dernière import** :
```bash
curl http://localhost/import/last_import.php
```
Attendu : JSON avec `has_run: true` et données du dernier import

### Test Import WEB

1. **Vérifier téléchargement HTML** :
```bash
php import/import_ancien_http.php
```
Attendu : Parsing HTML réussi + insertion/mise à jour

2. **Vérifier endpoint déclenchement** :
```bash
curl -X POST http://localhost/import/run_import_web_if_due.php
```
Attendu : JSON avec `ran: true` ou `ran: false` (selon anti-bouclage)

3. **Vérifier dernière import** :
```bash
curl http://localhost/import/last_import_web.php
```
Attendu : JSON avec `has_run: true` et données du dernier import

---

## CHECKLIST DE VALIDATION

- [ ] Les deux imports se déclenchent automatiquement (dashboard)
- [ ] Les contraintes UNIQUE existent sur les deux tables
- [ ] Pas d'erreur MySQL dans les logs
- [ ] Les doublons sont correctement gérés (UPDATE au lieu de INSERT)
- [ ] Les timeouts sont respectés
- [ ] Les transactions sont correctement commitées/rollbackées
- [ ] Les logs dans `import_run` sont corrects (source, imported, ok)
- [ ] Les badges du dashboard affichent le bon statut

---

## INFORMATIONS MANQUANTES À DEMANDER

Si les erreurs persistent après ces correctifs, fournir :

1. **Message d'erreur exact** (console JS, logs PHP, table `import_run`)
2. **Fichier importé** (nom du CSV pour SFTP, URL pour WEB)
3. **Chemin/route appelée** (URL complète de l'endpoint qui échoue)

---

**Date d'analyse** : 2024-12-19
**Version code analysé** : Commit actuel du repository cccomputer

