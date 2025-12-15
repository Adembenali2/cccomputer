<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

/**
 * import/debug_import.php
 * 
 * Script de diagnostic complet pour les imports SFTP et IONOS
 * - Mode lecture seule par défaut (dry-run)
 * - Exécution optionnelle avec ?run_sftp=1&write_db=1&move=1
 * - Sortie JSON ou HTML selon ?html=1
 * - Sécurité : masque les mots de passe, vérifie DEBUG_KEY
 */

// ====== SÉCURITÉ ======
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || 
           (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
$debugKey = getenv('DEBUG_KEY') ?: '';
$requestKey = $_GET['key'] ?? '';

if (!$isLocal && $debugKey && $requestKey !== $debugKey) {
    ob_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied. DEBUG_KEY required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== CONFIGURATION ======
$htmlMode = isset($_GET['html']) && $_GET['html'] === '1';
$runSftp = isset($_GET['run_sftp']) && $_GET['run_sftp'] === '1';
$runWeb = isset($_GET['run_web']) && $_GET['run_web'] === '1';
$writeDb = isset($_GET['write_db']) && $_GET['write_db'] === '1';
$moveFile = isset($_GET['move']) && $_GET['move'] === '1';
$limit = (int)($_GET['limit'] ?? 3);
if ($limit <= 0) $limit = 3;

// ====== INITIALISATION ======
$result = [
    'ok' => true,
    'ts' => date('Y-m-d H:i:s'),
    'env' => [],
    'db' => [],
    'sftp' => [],
    'web' => [],
    'warnings' => [],
    'errors' => []
];

// ====== HELPERS ======
function mask_secret(?string $secret): string {
    if (empty($secret)) return '(empty)';
    $len = strlen($secret);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($secret, 0, 2) . str_repeat('*', $len - 4) . substr($secret, -2) . " (len:$len)";
}

function safe_scalar($v): string {
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_object($v)) {
        return '[object ' . get_class($v) . ']';
    }
    if (is_resource($v)) {
        return '[resource]';
    }
    return (string)$v;
}

function arr_get($v, $k, $default = null) {
    return (is_array($v) && array_key_exists($k, $v)) ? $v[$k] : $default;
}

function ensure_array($v): array {
    return is_array($v) ? $v : [];
}

function fetch_assoc_safe($stmt): array {
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : [];
}

function safe_json_decode($s, &$err = null): ?array {
    $err = null;
    if (!is_string($s) || $s === '') return null;
    $j = @json_decode($s, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $err = json_last_error_msg();
        return null;
    }
    return is_array($j) ? $j : null;
}

function addError(array &$result, string $error, array $context = []): void {
    $result['ok'] = false;
    $result['errors'][] = [
        'error' => $error,
        'context' => $context,
        'ts' => date('Y-m-d H:i:s')
    ];
}

function addWarning(array &$result, string $warning, array $context = []): void {
    $result['warnings'][] = [
        'warning' => $warning,
        'context' => $context,
        'ts' => date('Y-m-d H:i:s')
    ];
}

// ====== SECTION ENV ======
function section_env(array &$result): void {
    $env = [];
    
    try {
        $projectRoot = dirname(__DIR__);
        $env['project_root'] = $projectRoot;
        $env['project_root_exists'] = is_dir($projectRoot);
        
        // PHP
        $env['php'] = [
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time')
        ];
        
        // Chemins
        $autoloadPaths = [
            $projectRoot . '/vendor/autoload.php',
            dirname($projectRoot) . '/vendor/autoload.php',
        ];
        $autoloadFound = null;
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) {
                $autoloadFound = $path;
                break;
            }
        }
        $env['autoload_path'] = $autoloadFound ?: 'not_found';
        $env['autoload_exists'] = $autoloadFound !== null;
        
        $dbPath = $projectRoot . '/includes/db.php';
        $env['db_path'] = $dbPath;
        $env['db_exists'] = file_exists($dbPath);
        
        $uploadScriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
        $env['upload_script_path'] = $uploadScriptPath;
        $env['upload_script_exists'] = file_exists($uploadScriptPath);
        
        // Variables SFTP
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = getenv('SFTP_PORT') ?: '22';
        $sftpTimeout = getenv('SFTP_TIMEOUT') ?: '15';
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        
        $env['sftp'] = [
            'host' => $sftpHost ?: 'not_set',
            'user' => $sftpUser ?: 'not_set',
            'password' => mask_secret($sftpPass),
            'port' => $sftpPort,
            'timeout' => $sftpTimeout,
            'remote_dir' => $sftpRemoteDir
        ];
        
        // Variables MySQL
        $mysqlHost = getenv('MYSQLHOST');
        $mysqlDb = getenv('MYSQLDATABASE');
        $mysqlUser = getenv('MYSQLUSER');
        $mysqlPass = getenv('MYSQLPASSWORD');
        $mysqlPort = getenv('MYSQLPORT') ?: '3306';
        
        $env['mysql'] = [
            'host' => $mysqlHost ?: 'not_set',
            'database' => $mysqlDb ?: 'not_set',
            'user' => $mysqlUser ?: 'not_set',
            'password' => mask_secret($mysqlPass),
            'port' => $mysqlPort,
            'dsn' => ($mysqlHost && $mysqlDb) ? "mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4" : 'not_set'
        ];
        
        // WEB_URL (IONOS)
        $webUrl = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
        $env['web_url'] = $webUrl;
        
        // Résumé env_ok
        $missing = [];
        if (empty($sftpHost)) $missing[] = 'SFTP_HOST';
        if (empty($sftpUser)) $missing[] = 'SFTP_USER';
        if (empty($sftpPass)) $missing[] = 'SFTP_PASS';
        if (empty($mysqlHost)) $missing[] = 'MYSQLHOST';
        if (empty($mysqlDb)) $missing[] = 'MYSQLDATABASE';
        if (empty($mysqlUser)) $missing[] = 'MYSQLUSER';
        if (empty($mysqlPass)) $missing[] = 'MYSQLPASSWORD';
        
        $env['env_ok'] = empty($missing);
        $env['missing'] = $missing;
        
    } catch (Throwable $e) {
        addError($result, 'Exception in section_env: ' . $e->getMessage());
    }
    
    $result['env'] = $env;
}

// ====== SECTION DB ======
function get_pdo_from_anywhere(): ?PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (isset($GLOBALS['PDO']) && $GLOBALS['PDO'] instanceof PDO) return $GLOBALS['PDO'];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];
    return null;
}

function section_db(array &$result): void {
    $db = [];
    
    try {
        $projectRoot = dirname(__DIR__);
        $dbPath = $projectRoot . '/includes/db.php';
        
        if (!file_exists($dbPath)) {
            addError($result, "includes/db.php not found at $dbPath");
            $result['db'] = ['error' => 'db.php not found'];
            return;
        }
        
        require_once $dbPath;
        
        // Récupérer PDO de manière robuste
        $pdoLocal = null;
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdoLocal = $pdo;
        }
        $pdo2 = $pdoLocal ?: get_pdo_from_anywhere();
        
        if ($pdo2 === null) {
            addError($result, 'PDO not available after db.php load');
            $result['db'] = ['error' => 'PDO not available'];
            return;
        }
        
        $db['connection'] = 'ok';
        $db['pdo_class'] = get_class($pdo2);
        $db['pdo_source'] = $pdoLocal ? 'local' : 'globals';
        
        // Test query
        try {
            $pdo2->query("SELECT 1");
            $db['test_query'] = 'ok';
        } catch (Throwable $e) {
            addError($result, 'Test query failed: ' . $e->getMessage());
            $db['test_query'] = 'failed';
        }
        
        // Vérifier existence tables
        $tables = ['import_run', 'compteur_relevee', 'compteur_relevee_ancien'];
        $db['tables'] = [];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo2->query("SHOW TABLES LIKE '$table'");
                $exists = $stmt->rowCount() > 0;
                $db['tables'][$table] = $exists;
            } catch (Throwable $e) {
                $db['tables'][$table] = false;
                addWarning($result, "Failed to check table $table: " . $e->getMessage());
            }
        }
        
        // Lire les 10 dernières lignes import_run
        $db['last_10_imports'] = [];
        try {
            $stmt = $pdo2->query("
                SELECT id, ran_at, imported, skipped, ok, msg 
                FROM import_run 
                ORDER BY id DESC 
                LIMIT 10
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = ensure_array($rows);
            
            // Debug anti-crash
            $db['debug_line_362'] = [
                'rows_type' => gettype($rows),
                'rows_is_array' => is_array($rows),
                'rows_count' => is_array($rows) ? count($rows) : 0,
                'first_row_type' => null,
                'first_row_is_array' => null,
                'first_row_msg_type' => null,
                'first_row_decoded_type' => null,
                'first_row_decoded_is_array' => null
            ];
            
            if (!empty($rows) && is_array($rows) && isset($rows[0])) {
                $firstRow = $rows[0];
                // Normaliser : toujours un array
                $firstRow = is_array($firstRow) ? $firstRow : [];
                
                $db['debug_line_362']['first_row_type'] = gettype($firstRow);
                $db['debug_line_362']['first_row_is_array'] = is_array($firstRow);
                
                if (is_array($firstRow) && !empty($firstRow)) {
                    $firstMsg = arr_get($firstRow, 'msg', '');
                    $db['debug_line_362']['first_row_msg_type'] = gettype($firstMsg);
                    
                    $jsonErr = null;
                    $firstDecoded = safe_json_decode($firstMsg, $jsonErr);
                    $db['debug_line_362']['first_row_decoded_type'] = gettype($firstDecoded);
                    $db['debug_line_362']['first_row_decoded_is_array'] = is_array($firstDecoded);
                }
            }
            
            foreach ($rows as $r) {
                // Protection : vérifier que $r est un array
                if (!is_array($r)) {
                    addWarning($result, 'Non-array row encountered in import_run', [
                        'row_type' => gettype($r),
                        'row_preview' => substr(safe_scalar($r), 0, 200)
                    ]);
                    continue;
                }
                
                $msg = arr_get($r, 'msg', '');
                $jsonError = null;
                $decoded = safe_json_decode($msg, $jsonError);
                // Garde-fou : forcer null si pas array (ne jamais supposer array)
                if (!is_array($decoded)) {
                    $decoded = null;
                }
                
                $type = 'other';
                if (is_array($decoded)) {
                    $source = arr_get($decoded, 'source', null);
                    // Utiliser arr_get() pour être sûr (isset() est OK mais arr_get() est plus sûr)
                    $hasProcessedFiles = arr_get($decoded, 'processed_files') !== null;
                    $hasInserted = arr_get($decoded, 'inserted') !== null;
                    $hasMatchedFiles = arr_get($decoded, 'matched_files') !== null;
                    
                    if ($hasProcessedFiles || $hasInserted || $hasMatchedFiles) {
                        $type = 'summary';
                    } elseif (arr_get($decoded, 'stage') === 'process_file') {
                        $type = 'process_file';
                    }
                }
                
                $importData = [
                    'id' => (int)arr_get($r, 'id', 0),
                    'ran_at' => arr_get($r, 'ran_at', ''),
                    'ok' => (int)arr_get($r, 'ok', 0),
                    'imported' => (int)arr_get($r, 'imported', 0),
                    'skipped' => (int)arr_get($r, 'skipped', 0),
                    'type' => $type
                ];
                
                if (is_array($decoded)) {
                    $importData['msg'] = $decoded;
                } else {
                    $importData['msg_decoded'] = null;
                    $importData['msg_raw_preview'] = substr(safe_scalar($msg), 0, 400);
                    $importData['json_error'] = $jsonError;
                }
                
                $db['last_10_imports'][] = $importData;
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to fetch last imports: ' . $e->getMessage());
        }
        
        // Dernier résumé SFTP
        $db['last_summary_sftp'] = null;
        try {
            $stmt = $pdo2->query("
                SELECT id, ran_at, imported, skipped, ok, msg 
                FROM import_run 
                WHERE msg LIKE '%processed_files%' 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $row = fetch_assoc_safe($stmt);
            
            // Crash shield - debug anti-crash ligne ~428
            $msg = arr_get($row, 'msg', '');
            $jsonError = null;
            $decoded = safe_json_decode($msg, $jsonError);
            // Garde-fou : forcer null si pas array (ne jamais supposer array)
            if (!is_array($decoded)) {
                $decoded = null;
            }
            
            $db['debug_line_428'] = [
                'row_type' => gettype($row),
                'row_is_array' => is_array($row),
                'row_keys' => is_array($row) ? array_keys($row) : null,
                'msg_type' => gettype($msg),
                'msg_length' => is_string($msg) ? strlen($msg) : 0,
                'decoded_type' => gettype($decoded),
                'decoded_is_array' => is_array($decoded),
                'decoded_keys' => is_array($decoded) ? array_keys($decoded) : null,
                'json_error' => $jsonError
            ];
            
            // Protection : vérifier que $row est un array (devrait toujours être le cas après normalisation)
            if (!empty($row) && is_array($row)) {
                $db['last_summary_sftp'] = [
                    'id' => (int)arr_get($row, 'id', 0),
                    'ran_at' => arr_get($row, 'ran_at', ''),
                    'ok' => (int)arr_get($row, 'ok', 0),
                    'imported' => (int)arr_get($row, 'imported', 0),
                    'skipped' => (int)arr_get($row, 'skipped', 0),
                    'msg' => $decoded,
                    'inserted' => is_array($decoded) ? arr_get($decoded, 'inserted') : null,
                    'updated' => is_array($decoded) ? arr_get($decoded, 'updated') : null,
                    'matched_files' => is_array($decoded) ? arr_get($decoded, 'matched_files') : null,
                    'processed_files' => is_array($decoded) ? arr_get($decoded, 'processed_files') : null
                ];
            } elseif (!empty($row)) {
                // $row existe mais n'est pas un array (ne devrait jamais arriver après normalisation)
                addWarning($result, 'last_summary_sftp row is not an array after normalization', [
                    'row_type' => gettype($row),
                    'row_preview' => substr(safe_scalar($row), 0, 200)
                ]);
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to fetch last summary SFTP: ' . $e->getMessage());
        }
        
        // Lignes insérées récemment
        $db['recent_rows_inserted'] = null;
        try {
            $stmt = $pdo2->query("
                SELECT COUNT(*) as cnt 
                FROM compteur_relevee 
                WHERE DateInsertion > NOW() - INTERVAL 10 MINUTE
            ");
            $row = fetch_assoc_safe($stmt);
            
            // Protection : utiliser arr_get() au lieu d'accès direct
            if (!empty($row) && is_array($row)) {
                $db['recent_rows_inserted'] = (int)arr_get($row, 'cnt', 0);
            } elseif (!empty($row)) {
                addWarning($result, 'recent_rows_inserted row is not an array after normalization', [
                    'row_type' => gettype($row),
                    'row_preview' => substr(safe_scalar($row), 0, 200)
                ]);
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to count recent rows: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        addError($result, 'Exception in section_db: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        // S'assurer que $db est un array avant d'y ajouter une clé
        if (!is_array($db)) {
            $db = [];
        }
        $db['error'] = safe_scalar($e->getMessage());
    }
    
    // S'assurer que $db est toujours un array avant de l'assigner
    if (!is_array($db)) {
        $db = [];
    }
    $result['db'] = $db;
}

// ====== SECTION SFTP SCAN ======
function section_sftp_scan(array &$result): void {
    $sftp = [];
    
    try {
        $autoloadPath = $result['env']['autoload_path'] ?? null;
        if (!$autoloadPath || $autoloadPath === 'not_found') {
            addError($result, 'autoload.php not found');
            $result['sftp'] = ['error' => 'autoload not found'];
            return;
        }
        
        require_once $autoloadPath;
        
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
        $sftpTimeout = (int)(getenv('SFTP_TIMEOUT') ?: 15);
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $sftpRemoteDir = rtrim($sftpRemoteDir, '/') ?: '/';
        
        if (empty($sftpHost) || empty($sftpUser) || empty($sftpPass)) {
            addError($result, 'SFTP credentials missing');
            $result['sftp'] = ['error' => 'credentials missing'];
            return;
        }
        
        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        $sftp['connection'] = 'ok';
        
        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'SFTP login failed');
            $sftp['login'] = 'failed';
            $result['sftp'] = $sftp;
            return;
        }
        
        $sftp['login'] = 'ok';
        
        // List files
        $files = $sftpConn->nlist($sftpRemoteDir);
        if ($files === false) {
            $rawFiles = $sftpConn->rawlist($sftpRemoteDir);
            if ($rawFiles !== false && is_array($rawFiles)) {
                $files = array_keys($rawFiles);
            }
        }
        
        if ($files === false || !is_array($files)) {
            addError($result, 'Failed to list files');
            $sftp['scan'] = 'failed';
            $result['sftp'] = $sftp;
            return;
        }
        
        $totalEntries = count($files);
        $csvFiles = array_filter($files, function($f) {
            return preg_match('/\.csv$/i', $f);
        });
        $totalCsv = count($csvFiles);
        
        // Pattern
        $pattern = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        $sftp['pattern'] = $pattern;
        
        // Match debug for first 30 files
        $matchDebug = [];
        $matchedCount = 0;
        
        foreach (array_slice($files, 0, 30) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $matchInfo = [
                'filename' => $entry,
                'match' => false,
                'reason' => null
            ];
            
            if (!preg_match('/\.csv$/i', $entry)) {
                $matchInfo['reason'] = 'wrong_extension';
                $matchDebug[] = $matchInfo;
                continue;
            }
            
            if (preg_match($pattern, $entry)) {
                $matchInfo['match'] = true;
                $matchedCount++;
            } else {
                $matchInfo['reason'] = 'pattern_mismatch';
            }
            
            $matchDebug[] = $matchInfo;
        }
        
        // Count all matched
        $allMatched = [];
        foreach ($files as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match($pattern, $entry)) {
                $allMatched[] = $entry;
            }
        }
        
        $sftp['scan'] = [
            'total_entries' => $totalEntries,
            'total_csv' => $totalCsv,
            'matched_count' => count($allMatched),
            'first_30' => array_slice($files, 0, 30),
            'match_debug' => $matchDebug
        ];
        
        $result['sftp'] = $sftp;
        
    } catch (Throwable $e) {
        addError($result, 'Exception in section_sftp_scan: ' . $e->getMessage());
        $result['sftp'] = array_merge($result['sftp'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== SECTION SFTP PROCESS ======
function section_sftp_process(array &$result, int $limit, bool $writeDb, bool $moveFile): void {
    if (!isset($result['sftp']['scan']['matched_count']) || $result['sftp']['scan']['matched_count'] === 0) {
        $result['sftp']['process'] = ['error' => 'No matched files to process'];
        return;
    }
    
    $process = [
        'processed' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'moved' => 0,
        'errors_count' => 0,
        'files' => []
    ];
    
    try {
        $autoloadPath = $result['env']['autoload_path'] ?? null;
        if (!$autoloadPath || $autoloadPath === 'not_found') {
            addError($result, 'autoload.php not found');
            $result['sftp']['process'] = $process;
            return;
        }
        
        require_once $autoloadPath;
        
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
        $sftpTimeout = (int)(getenv('SFTP_TIMEOUT') ?: 15);
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $sftpRemoteDir = rtrim($sftpRemoteDir, '/') ?: '/';
        
        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'SFTP login failed');
            $result['sftp']['process'] = $process;
            return;
        }
        
        // Load DB if needed
        $pdo = null;
        if ($writeDb) {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
                $pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : get_pdo_from_anywhere();
            }
        }
        
        $pattern = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        $matchedFiles = [];
        
        // Get matched files from scan
        if (isset($result['sftp']['scan']['match_debug'])) {
            foreach ($result['sftp']['scan']['match_debug'] as $matchInfo) {
                if (arr_get($matchInfo, 'match') === true) {
                    $matchedFiles[] = arr_get($matchInfo, 'filename');
                }
            }
        }
        
        $matchedFiles = array_slice($matchedFiles, 0, $limit);
        
        foreach ($matchedFiles as $filename) {
            $fileData = [
                'filename' => $filename,
                'download_ok' => false,
                'tmp_size' => null,
                'parse_ok' => false,
                'extracted' => [],
                'decision' => [],
                'db' => [],
                'move' => []
            ];
            
            try {
                $process['processed']++;
                
                // Download
                $remote = ($sftpRemoteDir === '/' ? '' : $sftpRemoteDir) . '/' . $filename;
                $tmp = tempnam(sys_get_temp_dir(), 'csv_');
                
                $downloadOk = $sftpConn->get($remote, $tmp);
                $fileData['download_ok'] = $downloadOk;
                
                if (!$downloadOk || !file_exists($tmp)) {
                    addError($result, "Download failed: $filename");
                    $process['errors_count']++;
                    $process['files'][] = $fileData;
                    continue;
                }
                
                $fileData['tmp_size'] = filesize($tmp);
                
                // Parse CSV
                $csvData = [];
                try {
                    if (($h = fopen($tmp, 'r')) !== false) {
                        while (($row = fgetcsv($h, 2000, ',')) !== false) {
                            if (isset($row[0], $row[1])) {
                                $csvData[trim($row[0])] = trim((string)$row[1]);
                            }
                        }
                        fclose($h);
                    }
                    $fileData['parse_ok'] = true;
                } catch (Throwable $e) {
                    $fileData['parse_error'] = $e->getMessage();
                    $process['errors_count']++;
                    @unlink($tmp);
                    $process['files'][] = $fileData;
                    continue;
                }
                
                // Extract from CSV
                $macFromCsv = arr_get($csvData, 'MacAddress', '');
                $timestampFromCsv = arr_get($csvData, 'Timestamp', '');
                
                // Extract from filename
                $macFromFilename = null;
                $timestampFromFilename = null;
                
                if (preg_match($pattern, $filename, $matches)) {
                    $macRaw = $matches[1] ?? null;
                    $dateStr = $matches[2] ?? null;
                    $timeStr = $matches[3] ?? null;
                    
                    if ($macRaw && $dateStr && $timeStr) {
                        $macFromFilename = strtoupper($macRaw);
                        $year = substr($dateStr, 0, 4);
                        $month = substr($dateStr, 4, 2);
                        $day = substr($dateStr, 6, 2);
                        $hour = substr($timeStr, 0, 2);
                        $minute = substr($timeStr, 2, 2);
                        $second = substr($timeStr, 4, 2);
                        $timestampFromFilename = "$year-$month-$day $hour:$minute:$second";
                    }
                }
                
                $fileData['extracted'] = [
                    'mac_from_csv' => $macFromCsv,
                    'timestamp_from_csv' => $timestampFromCsv,
                    'mac_from_filename' => $macFromFilename,
                    'timestamp_from_filename' => $timestampFromFilename
                ];
                
                // Decision
                $useMac = $macFromFilename ?: $macFromCsv;
                $useTimestamp = $timestampFromFilename ?: $timestampFromCsv;
                
                $fileData['decision'] = [
                    'use_mac' => $useMac,
                    'use_timestamp' => $useTimestamp,
                    'skip_reason' => (empty($useMac) || empty($useTimestamp)) ? 'missing_data' : null
                ];
                
                if (empty($useMac) || empty($useTimestamp)) {
                    $process['skipped']++;
                    @unlink($tmp);
                    $process['files'][] = $fileData;
                    continue;
                }
                
                // DB Insert if writeDb
                if ($writeDb && $pdo instanceof PDO) {
                    try {
                        $sql = "
                            INSERT INTO compteur_relevee (
                                Timestamp, MacAddress, DateInsertion, 
                                TotalPages, TotalBW, TotalColor, Status
                            ) VALUES (
                                :Timestamp, :MacAddress, NOW(),
                                :TotalPages, :TotalBW, :TotalColor, :Status
                            )
                            ON DUPLICATE KEY UPDATE
                                DateInsertion = NOW(),
                                TotalPages = VALUES(TotalPages),
                                TotalBW = VALUES(TotalBW),
                                TotalColor = VALUES(TotalColor),
                                Status = VALUES(Status)
                        ";
                        
                        $stmt = $pdo->prepare($sql);
                        $binds = [
                            ':Timestamp' => $useTimestamp,
                            ':MacAddress' => $useMac,
                            ':TotalPages' => (int)arr_get($csvData, 'TotalPages', 0),
                            ':TotalBW' => (int)arr_get($csvData, 'TotalBW', 0),
                            ':TotalColor' => (int)arr_get($csvData, 'TotalColor', 0),
                            ':Status' => arr_get($csvData, 'Status', '') ?: null
                        ];
                        
                        $stmt->execute($binds);
                        $rowCount = $stmt->rowCount();
                        $isInsert = $rowCount === 1;
                        $isUpdate = $rowCount === 2;
                        
                        if ($isInsert) {
                            $process['inserted']++;
                        } elseif ($isUpdate) {
                            $process['updated']++;
                        }
                        
                        $fileData['db'] = [
                            'rowCount' => $rowCount,
                            'inserted' => $isInsert,
                            'updated' => $isUpdate,
                            'errorInfo' => $pdo->errorInfo()
                        ];
                    } catch (Throwable $e) {
                        $fileData['db']['error'] = $e->getMessage();
                        $process['errors_count']++;
                    }
                }
                
                // Move file if moveFile
                if ($moveFile) {
                    try {
                        $processedDir = '/processed';
                        $target = $processedDir . '/' . $filename;
                        $moved = $sftpConn->rename($remote, $target);
                        $fileData['move'] = [
                            'moved_ok' => $moved,
                            'moved_to' => $moved ? $target : null,
                            'error' => $moved ? null : 'rename failed'
                        ];
                        if ($moved) {
                            $process['moved']++;
                        }
                    } catch (Throwable $e) {
                        $fileData['move']['error'] = $e->getMessage();
                    }
                }
                
                @unlink($tmp);
                
            } catch (Throwable $e) {
                addError($result, "Exception processing $filename: " . $e->getMessage());
                $process['errors_count']++;
            }
            
            $process['files'][] = $fileData;
        }
        
    } catch (Throwable $e) {
        addError($result, 'Fatal exception in section_sftp_process: ' . $e->getMessage());
    }
    
    $result['sftp']['process'] = $process;
}

// ====== SECTION WEB IONOS ======
function section_web_ionos(array &$result, bool $runWeb): void {
    $web = [];
    
    try {
        $url = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
        $web['url'] = $url;
        
        // Test HEAD/GET léger
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'user_agent' => 'Mozilla/5.0',
                'method' => 'GET'
            ]
        ]);
        
        $html = @file_get_contents($url, false, $context);
        
        if ($html === false) {
            addError($result, 'Failed to download HTML from ' . $url);
            $web['http_ok'] = false;
            $result['web'] = $web;
            return;
        }
        
        $web['http_ok'] = true;
        $web['size'] = strlen($html);
        $web['hash_first_200'] = md5(substr($html, 0, 200));
        
        if (!$runWeb) {
            $result['web'] = $web;
            return;
        }
        
        // Parse DOM
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table//tr');
        
        $web['parse'] = [
            'nb_tr' => $rows->length,
            'headers' => []
        ];
        
        // Detect headers
        if ($rows->length > 0) {
            $firstRow = $rows->item(0);
            if ($firstRow instanceof DOMElement) {
                $headers = $firstRow->getElementsByTagName('th');
                $headerTexts = [];
                for ($i = 0; $i < $headers->length; $i++) {
                    $headerTexts[] = trim($headers->item($i)->textContent);
                }
                $web['parse']['headers'] = $headerTexts;
            }
        }
        
        // Mapping detection
        $columnMap = [];
        if ($rows->length > 0) {
            $firstRow = $rows->item(0);
            if ($firstRow instanceof DOMElement) {
                $headers = $firstRow->getElementsByTagName('th');
                for ($i = 0; $i < $headers->length; $i++) {
                    $headerText = strtolower(trim($headers->item($i)->textContent));
                    if (strpos($headerText, 'mac') !== false) $columnMap['mac'] = $i;
                    elseif (strpos($headerText, 'date') !== false || strpos($headerText, 'relevé') !== false) $columnMap['date'] = $i;
                }
            }
        }
        
        if (!isset($columnMap['mac'], $columnMap['date'])) {
            $columnMap = ['mac' => 5, 'date' => 1];
        }
        
        $web['mapping'] = $columnMap;
        
        // Extract rows
        $rowsParsed = [];
        $getCellText = function($cell) {
            return $cell ? trim($cell->textContent) : '';
        };
        
        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) continue;
            if ($row->getElementsByTagName('th')->length > 0) continue;
            
            $cells = $row->getElementsByTagName('td');
            $mac = isset($columnMap['mac']) ? $getCellText($cells->item($columnMap['mac'])) : '';
            $date = isset($columnMap['date']) ? $getCellText($cells->item($columnMap['date'])) : '';
            
            if ($mac && $date) {
                $rowsParsed[] = ['mac' => $mac, 'date' => $date];
            }
        }
        
        $web['rows_parsed'] = array_slice($rowsParsed, -20);
        $web['rows_total'] = count($rowsParsed);
        
        // Compare with DB
        try {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
                $pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : get_pdo_from_anywhere();
                
                if ($pdo instanceof PDO) {
                    $stmt = $pdo->query("SELECT MAX(Timestamp) as max_ts FROM compteur_relevee_ancien");
                    $row = fetch_assoc_safe($stmt);
                    $lastDbTs = arr_get($row, 'max_ts', null);
                    
                    $web['db'] = [
                        'last_db_ts' => $lastDbTs,
                        'rows_new_estimated' => null
                    ];
                    
                    if ($lastDbTs) {
                        $newCount = 0;
                        foreach ($rowsParsed as $r) {
                            // Protection : utiliser arr_get()
                            if (!is_array($r)) {
                                continue;
                            }
                            $rDate = arr_get($r, 'date', '');
                            if ($rDate && $rDate > $lastDbTs) {
                                $newCount++;
                            }
                        }
                        $web['db']['rows_new_estimated'] = $newCount;
                    }
                }
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to compare with DB: ' . $e->getMessage());
        }
        
        $result['web'] = $web;
        
    } catch (Throwable $e) {
        addError($result, 'Exception in section_web_ionos: ' . $e->getMessage());
        $result['web'] = array_merge($result['web'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== EXECUTION ======
try {
    section_env($result);
    section_db($result);
    section_sftp_scan($result);
    
    if ($runSftp) {
        section_sftp_process($result, $limit, $writeDb, $moveFile);
    }
    
    section_web_ionos($result, $runWeb);
    
} catch (Throwable $e) {
    addError($result, 'Fatal exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    $result['ok'] = false;
}

// ====== OUTPUT ======
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ($htmlMode) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Debug Import</title>
        <style>
            body { font-family: monospace; margin: 20px; background: #f5f5f5; }
            .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .error { color: red; }
            .ok { color: green; }
            .warning { color: orange; }
            pre { background: #f0f0f0; padding: 10px; overflow-x: auto; font-size: 12px; }
            h2 { margin-top: 0; }
        </style>
    </head>
    <body>
        <h1>Debug Import - <?= htmlspecialchars($result['ts']) ?></h1>
        
        <div class="section">
            <h2>Status: <span class="<?= $result['ok'] ? 'ok' : 'error' ?>"><?= $result['ok'] ? 'OK' : 'ERROR' ?></span></h2>
        </div>
        
        <div class="section">
            <h2>Environment</h2>
            <pre><?= htmlspecialchars(json_encode($result['env'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>Database</h2>
            <pre><?= htmlspecialchars(json_encode($result['db'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>SFTP</h2>
            <pre><?= htmlspecialchars(json_encode($result['sftp'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>Web IONOS</h2>
            <pre><?= htmlspecialchars(json_encode($result['web'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <?php if (!empty($result['warnings'])): ?>
        <div class="section warning">
            <h2>Warnings</h2>
            <pre><?= htmlspecialchars(json_encode($result['warnings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($result['errors'])): ?>
        <div class="section error">
            <h2>Errors</h2>
            <pre><?= htmlspecialchars(json_encode($result['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
} else {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

