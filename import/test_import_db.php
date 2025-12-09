<?php
/**
 * Script de test pour identifier où l'import bloque dans la base de données
 * Simule exactement le processus d'import et teste chaque étape
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

echo "=== TEST IMPORT BASE DE DONNÉES ===\n\n";

$projectRoot = dirname(__DIR__);

// Fonction de debug
function debugLog(string $message, array $context = []): void {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    echo "[$timestamp] $message$contextStr\n";
}

// 1. Charger la connexion à la base
debugLog("Étape 1: Chargement de la connexion à la base de données");
try {
    require_once $projectRoot . '/includes/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        die("❌ Erreur: \$pdo non défini\n");
    }
    debugLog("✓ Connexion PDO établie", ['class' => get_class($pdo)]);
} catch (Throwable $e) {
    die("❌ Erreur chargement db.php: " . $e->getMessage() . "\n");
}

// 2. Vérifier la table compteur_relevee
debugLog("\nÉtape 2: Vérification de la table compteur_relevee");
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'compteur_relevee'");
    $tableExists = $stmt->rowCount() > 0;
    debugLog("Table existe", ['exists' => $tableExists]);
    
    if ($tableExists) {
        // Vérifier la structure
        $stmt = $pdo->query("DESCRIBE compteur_relevee");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debugLog("Colonnes de la table", ['count' => count($columns)]);
        
        // Vérifier les contraintes
        $stmt = $pdo->query("SHOW CREATE TABLE compteur_relevee");
        $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
        if (isset($createTable['Create Table'])) {
            $createSql = $createTable['Create Table'];
            debugLog("Contraintes UNIQUE détectées", [
                'has_unique' => strpos($createSql, 'UNIQUE') !== false,
                'has_primary' => strpos($createSql, 'PRIMARY KEY') !== false
            ]);
        }
    } else {
        debugLog("⚠️ Table compteur_relevee n'existe pas");
    }
} catch (Throwable $e) {
    debugLog("❌ Erreur vérification table", ['error' => $e->getMessage()]);
}

// 3. Tester une insertion simple
debugLog("\nÉtape 3: Test d'insertion simple");
$testData = [
    'Timestamp' => '2025-01-01 12:00:00',
    'IpAddress' => '192.168.1.1',
    'Nom' => 'TEST_CLIENT',
    'Model' => 'TEST_MODEL',
    'SerialNumber' => 'TEST_SERIAL',
    'MacAddress' => '00:11:22:33:44:55',
    'Status' => 'OK',
    'TonerBlack' => 50,
    'TonerCyan' => 50,
    'TonerMagenta' => 50,
    'TonerYellow' => 50,
    'TotalPages' => 1000,
    'FaxPages' => 0,
    'CopiedPages' => 500,
    'PrintedPages' => 500,
    'BWCopies' => 300,
    'ColorCopies' => 200,
    'MonoCopies' => 300,
    'BichromeCopies' => 0,
    'BWPrinted' => 300,
    'BichromePrinted' => 0,
    'MonoPrinted' => 300,
    'ColorPrinted' => 200,
    'TotalColor' => 200,
    'TotalBW' => 800
];

$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

try {
    $cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
    $ph_compteur = ':' . implode(',:', $FIELDS) . ',NOW()';
    $sql_compteur = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
    
    debugLog("Préparation de la requête", ['sql' => $sql_compteur]);
    $st_compteur = $pdo->prepare($sql_compteur);
    
    $binds = [];
    foreach ($FIELDS as $f) {
        $binds[":$f"] = $testData[$f] ?? null;
    }
    
    debugLog("Exécution de l'insertion test", ['binds_count' => count($binds)]);
    $pdo->beginTransaction();
    $st_compteur->execute($binds);
    $rowCount = $st_compteur->rowCount();
    $pdo->commit();
    
    debugLog("✓ Insertion test réussie", [
        'row_count' => $rowCount,
        'mac' => $testData['MacAddress'],
        'timestamp' => $testData['Timestamp']
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    debugLog("❌ Erreur insertion test", [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'sql_state' => $e instanceof PDOException ? $e->errorInfo[0] : 'N/A',
        'driver_code' => $e instanceof PDOException ? $e->errorInfo[1] : 'N/A',
        'driver_message' => $e instanceof PDOException ? $e->errorInfo[2] : 'N/A'
    ]);
}

// 4. Tester avec des données réelles depuis un fichier CSV
debugLog("\nÉtape 4: Test avec données réelles depuis SFTP");
try {
    require_once $projectRoot . '/vendor/autoload.php';
    use phpseclib3\Net\SFTP;
    
    $sftp_host = getenv('SFTP_HOST') ?: '';
    $sftp_user = getenv('SFTP_USER') ?: '';
    $sftp_pass = getenv('SFTP_PASS') ?: '';
    $sftp_port = (int)(getenv('SFTP_PORT') ?: 22);
    $sftp_timeout = (int)(getenv('SFTP_TIMEOUT') ?: 30);
    
    if (empty($sftp_host) || empty($sftp_user) || empty($sftp_pass)) {
        debugLog("⚠️ Variables SFTP manquantes, test SFTP ignoré");
    } else {
        debugLog("Connexion SFTP", ['host' => $sftp_host, 'port' => $sftp_port]);
        $sftp = new \phpseclib3\Net\SFTP($sftp_host, $sftp_port, $sftp_timeout);
        
        if (!$sftp->login($sftp_user, $sftp_pass)) {
            debugLog("❌ Échec login SFTP");
        } else {
            debugLog("✓ Login SFTP réussi");
            
            // Lister les fichiers
            $files = $sftp->nlist('/');
            $csvFiles = array_filter($files, function($f) {
                return preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $f);
            });
            
            debugLog("Fichiers CSV trouvés", ['count' => count($csvFiles)]);
            
            if (count($csvFiles) > 0) {
                // Prendre le premier fichier
                $testFile = array_values($csvFiles)[0];
                debugLog("Test avec fichier", ['file' => $testFile]);
                
                // Télécharger
                $tmp = tempnam(sys_get_temp_dir(), 'csv_test_');
                if ($sftp->get('/' . $testFile, $tmp)) {
                    debugLog("✓ Fichier téléchargé", ['size' => filesize($tmp)]);
                    
                    // Parser
                    function parse_csv_kv(string $filepath): array {
                        $data = [];
                        if (($h = fopen($filepath, 'r')) !== false) {
                            while (($row = fgetcsv($h, 2000, ',')) !== false) {
                                if (isset($row[0], $row[1])) {
                                    $data[trim($row[0])] = trim((string)$row[1]);
                                }
                            }
                            fclose($h);
                        }
                        return $data;
                    }
                    
                    $row = parse_csv_kv($tmp);
                    debugLog("CSV parsé", [
                        'keys_count' => count($row),
                        'MacAddress' => $row['MacAddress'] ?? 'NULL',
                        'Timestamp' => $row['Timestamp'] ?? 'NULL'
                    ]);
                    
                    if (!empty($row['MacAddress']) && !empty($row['Timestamp'])) {
                        // Tester l'insertion
                        $values = [];
                        foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;
                        
                        debugLog("Tentative d'insertion réelle", [
                            'MacAddress' => $values['MacAddress'],
                            'Timestamp' => $values['Timestamp']
                        ]);
                        
                        try {
                            $binds = [];
                            foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
                            
                            $pdo->beginTransaction();
                            debugLog("Transaction démarrée");
                            
                            $st_compteur->execute($binds);
                            $rowCount = $st_compteur->rowCount();
                            
                            debugLog("Requête exécutée", [
                                'row_count' => $rowCount,
                                'affected_rows' => $st_compteur->rowCount()
                            ]);
                            
                            if ($rowCount === 1) {
                                debugLog("✓ Insertion réussie - nouvelle ligne");
                            } elseif ($rowCount === 0) {
                                debugLog("⚠️ Insertion ignorée - ligne déjà présente (INSERT IGNORE)");
                            } else {
                                debugLog("⚠️ Résultat inattendu", ['row_count' => $rowCount]);
                            }
                            
                            $pdo->commit();
                            debugLog("✓ Transaction commitée");
                            
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                                debugLog("Transaction rollback");
                            }
                            debugLog("❌ ERREUR INSERTION RÉELLE", [
                                'error' => $e->getMessage(),
                                'code' => $e->getCode(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'sql_state' => $e instanceof PDOException ? $e->errorInfo[0] : 'N/A',
                                'driver_code' => $e instanceof PDOException ? $e->errorInfo[1] : 'N/A',
                                'driver_message' => $e instanceof PDOException ? $e->errorInfo[2] : 'N/A',
                                'error_info' => $e instanceof PDOException ? $e->errorInfo : 'N/A',
                                'trace' => $e->getTraceAsString()
                            ]);
                            
                            // Afficher la requête SQL complète pour debug
                            $sqlWithValues = $sql_compteur;
                            foreach ($binds as $key => $value) {
                                $sqlWithValues = str_replace($key, is_null($value) ? 'NULL' : "'$value'", $sqlWithValues);
                            }
                            debugLog("SQL avec valeurs", ['sql' => $sqlWithValues]);
                        }
                    } else {
                        debugLog("❌ Données manquantes dans le CSV", [
                            'MacAddress' => $row['MacAddress'] ?? 'NULL',
                            'Timestamp' => $row['Timestamp'] ?? 'NULL'
                        ]);
                    }
                    
                    @unlink($tmp);
                } else {
                    debugLog("❌ Échec téléchargement fichier");
                }
            }
        }
    }
} catch (Throwable $e) {
    debugLog("❌ Erreur test SFTP", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

// 5. Vérifier les dernières insertions
debugLog("\nÉtape 5: Vérification des dernières insertions");
try {
    $stmt = $pdo->query("SELECT * FROM compteur_relevee ORDER BY DateInsertion DESC LIMIT 5");
    $lastInserts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debugLog("Dernières insertions", ['count' => count($lastInserts)]);
    foreach ($lastInserts as $idx => $row) {
        debugLog("Insertion #" . ($idx + 1), [
            'MacAddress' => $row['MacAddress'] ?? 'NULL',
            'Timestamp' => $row['Timestamp'] ?? 'NULL',
            'DateInsertion' => $row['DateInsertion'] ?? 'NULL'
        ]);
    }
} catch (Throwable $e) {
    debugLog("❌ Erreur vérification insertions", ['error' => $e->getMessage()]);
}

// 6. Vérifier la table import_run
debugLog("\nÉtape 6: Vérification de la table import_run");
try {
    $stmt = $pdo->query("SELECT * FROM import_run ORDER BY id DESC LIMIT 5");
    $lastRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debugLog("Derniers imports", ['count' => count($lastRuns)]);
    foreach ($lastRuns as $idx => $row) {
        $msg = json_decode($row['msg'] ?? '{}', true);
        debugLog("Import #" . ($idx + 1), [
            'id' => $row['id'],
            'ran_at' => $row['ran_at'],
            'imported' => $row['imported'],
            'skipped' => $row['skipped'],
            'ok' => $row['ok'],
            'source' => $msg['source'] ?? 'N/A',
            'error' => $msg['error'] ?? null
        ]);
    }
} catch (Throwable $e) {
    debugLog("❌ Erreur vérification import_run", ['error' => $e->getMessage()]);
}

echo "\n=== FIN DU TEST ===\n";

