<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * import/debug_imports.php
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
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Access denied. DEBUG_KEY required.']);
    exit;
}

// ====== CONFIGURATION ======
$htmlMode = isset($_GET['html']) && $_GET['html'] === '1';
$runSftp = isset($_GET['run_sftp']) && $_GET['run_sftp'] === '1';
$runWeb = isset($_GET['run_web']) && $_GET['run_web'] === '1';
$writeDb = isset($_GET['write_db']) && $_GET['write_db'] === '1';
$moveFile = isset($_GET['move']) && $_GET['move'] === '1';

// ====== INITIALISATION ======
$result = [
    'ok' => true,
    'ts' => date('Y-m-d H:i:s'),
    'env' => [],
    'db' => [],
    'sftp' => [],
    'web' => [],
    'errors' => []
];

function addError(array &$result, string $section, string $error, array $context = []): void {
    $result['ok'] = false;
    $result['errors'][] = [
        'section' => $section,
        'error' => $error,
        'context' => $context,
        'ts' => date('Y-m-d H:i:s')
    ];
}

function maskPassword(?string $pass): string {
    if (empty($pass)) return '(empty)';
    $len = strlen($pass);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($pass, 0, 2) . str_repeat('*', $len - 4) . substr($pass, -2);
}

// ====== SECTION ENV ======
function section_env(array &$result): void {
    $env = [];
    
    // PHP & App
    $env['php_version'] = PHP_VERSION;
    $env['app_env'] = getenv('APP_ENV') ?: 'not_set';
    $env['base_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                       '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . 
                       dirname($_SERVER['SCRIPT_NAME'] ?? '');
    
    // MySQL
    $mysqlHost = getenv('MYSQLHOST');
    $mysqlDb = getenv('MYSQLDATABASE');
    $mysqlUser = getenv('MYSQLUSER');
    $mysqlPass = getenv('MYSQLPASSWORD');
    $mysqlPort = getenv('MYSQLPORT') ?: '3306';
    
    $env['mysql'] = [
        'host' => $mysqlHost ?: 'not_set',
        'database' => $mysqlDb ?: 'not_set',
        'user' => $mysqlUser ?: 'not_set',
        'password' => $mysqlPass ? maskPassword($mysqlPass) : 'not_set',
        'port' => $mysqlPort,
        'dsn' => $mysqlHost ? "mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4" : 'not_set'
    ];
    
    // SFTP
    $sftpHost = getenv('SFTP_HOST');
    $sftpUser = getenv('SFTP_USER');
    $sftpPass = getenv('SFTP_PASS');
    $sftpPort = getenv('SFTP_PORT') ?: '22';
    $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
    
    $env['sftp'] = [
        'host' => $sftpHost ?: 'not_set',
        'user' => $sftpUser ?: 'not_set',
        'password' => $sftpPass ? maskPassword($sftpPass) : 'not_set',
        'port' => $sftpPort,
        'remote_dir' => $sftpRemoteDir
    ];
    
    // Web IONOS
    $webUrl = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
    $env['web_url'] = $webUrl;
    
    $result['env'] = $env;
}

// ====== HELPER: Safe JSON decode ======
function safe_json_decode($s) {
    if (!is_string($s) || $s === '') return null;
    $j = json_decode($s, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : null;
}

// ====== SECTION DB ======
function section_db(array &$result): void {
    $db = [];
    
    try {
        // Charger db.php
        $dbPath = dirname(__DIR__) . '/includes/db.php';
        if (!file_exists($dbPath)) {
            addError($result, 'db', "includes/db.php not found at $dbPath");
            $result['db'] = ['error' => 'db.php not found'];
            return;
        }
        
        require_once $dbPath;
        
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            addError($result, 'db', 'PDO not available after db.php load');
            $result['db'] = ['error' => 'PDO not available'];
            return;
        }
        
        $db['connection'] = 'ok';
        $db['pdo_class'] = get_class($pdo);
        
        // Test query
        try {
            $pdo->query("SELECT 1");
            $db['test_query'] = 'ok';
        } catch (Throwable $e) {
            addError($result, 'db', 'Test query failed: ' . $e->getMessage());
            $db['test_query'] = 'failed';
            $db['test_error'] = $e->getMessage();
            $db['error_info'] = $pdo->errorInfo();
        }
        
        // Check import_run table
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'import_run'");
            $tableExists = $stmt->rowCount() > 0;
            $db['import_run_exists'] = $tableExists;
        } catch (Throwable $e) {
            addError($result, 'db', 'Failed to check import_run table: ' . $e->getMessage());
            $db['import_run_exists'] = false;
        }
        
        // Last 10 imports SFTP + WEB_COMPTEUR
        $db['last_imports'] = [];
        try {
            $stmt = $pdo->query("
                SELECT id, ran_at, imported, skipped, ok, msg 
                FROM import_run 
                ORDER BY id DESC 
                LIMIT 50
            ");
            $allImports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sftpImports = [];
            $webImports = [];
            $lastRowDebug = null;
            
            foreach ($allImports as $row) {
                $msg = $row['msg'] ?? '';
                $msgType = gettype($msg);
                $msgLength = is_string($msg) ? strlen($msg) : 0;
                
                // Safe JSON decode
                $decoded = safe_json_decode($msg);
                $jsonError = null;
                $msgRaw = null;
                
                if ($decoded === null && !empty($msg)) {
                    // JSON decode failed
                    $jsonError = json_last_error_msg();
                    $msgRaw = is_string($msg) ? substr($msg, 0, 400) : (string)$msg;
                }
                
                // Extract source safely
                $source = null;
                if (is_array($decoded) && isset($decoded['source'])) {
                    $source = $decoded['source'];
                }
                
                // Debug info for last row processed
                $lastRowDebug = [
                    'id' => (int)($row['id'] ?? 0),
                    'msg_type' => $msgType,
                    'msg_length' => $msgLength,
                    'decoded_is_array' => is_array($decoded),
                    'source' => $source,
                    'json_error' => $jsonError,
                    'msg_raw_preview' => $msgRaw
                ];
                
                if ($source === 'SFTP' && count($sftpImports) < 10) {
                    $importData = [
                        'id' => (int)$row['id'],
                        'ran_at' => $row['ran_at'],
                        'ok' => (int)$row['ok'],
                        'imported' => (int)$row['imported'],
                        'skipped' => (int)$row['skipped']
                    ];
                    
                    if (is_array($decoded)) {
                        $importData['msg'] = $decoded;
                    } else {
                        $importData['msg_decoded'] = null;
                        $importData['msg_raw'] = $msgRaw;
                        $importData['json_error'] = $jsonError;
                    }
                    
                    $sftpImports[] = $importData;
                }
                
                if ($source === 'WEB_COMPTEUR' && count($webImports) < 10) {
                    $importData = [
                        'id' => (int)$row['id'],
                        'ran_at' => $row['ran_at'],
                        'ok' => (int)$row['ok'],
                        'imported' => (int)$row['imported'],
                        'skipped' => (int)$row['skipped']
                    ];
                    
                    if (is_array($decoded)) {
                        $importData['msg'] = $decoded;
                    } else {
                        $importData['msg_decoded'] = null;
                        $importData['msg_raw'] = $msgRaw;
                        $importData['json_error'] = $jsonError;
                    }
                    
                    $webImports[] = $importData;
                }
            }
            
            $db['last_imports'] = [
                'sftp' => $sftpImports,
                'web_compteur' => $webImports
            ];
            
            // Add debug info for last row processed
            if ($lastRowDebug !== null) {
                $db['last_row_debug'] = $lastRowDebug;
            }
            
        } catch (Throwable $e) {
            addError($result, 'db', 'Failed to fetch last imports: ' . $e->getMessage());
        }
        
    } catch (Throwable $e) {
        addError($result, 'db', 'Exception in section_db: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $db['error'] = $e->getMessage();
    }
    
    $result['db'] = $db;
}

// ====== SECTION SFTP SCAN ======
function section_sftp_scan(array &$result): void {
    $sftp = [];
    
    try {
        // Load autoload
        $autoloadPaths = [
            __DIR__ . '/../vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 3) . '/vendor/autoload.php',
        ];
        
        $autoloadFound = null;
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) {
                $autoloadFound = $path;
                break;
            }
        }
        
        if (!$autoloadFound) {
            addError($result, 'sftp', 'vendor/autoload.php not found');
            $result['sftp'] = ['error' => 'autoload not found'];
            return;
        }
        
        require_once $autoloadFound;
        $sftp['autoload'] = $autoloadFound;
        
        // SFTP connection
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $sftpRemoteDir = rtrim($sftpRemoteDir, '/') ?: '/';
        
        if (empty($sftpHost) || empty($sftpUser) || empty($sftpPass)) {
            addError($result, 'sftp', 'SFTP credentials missing');
            $result['sftp'] = ['error' => 'credentials missing'];
            return;
        }
        
        $sftp['config'] = [
            'host' => $sftpHost,
            'user' => $sftpUser,
            'port' => $sftpPort,
            'remote_dir' => $sftpRemoteDir
        ];
        
        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, 15);
        $sftp['connection'] = 'ok';
        
        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'sftp', 'SFTP login failed');
            $sftp['login'] = 'failed';
            $result['sftp'] = $sftp;
            return;
        }
        
        $sftp['login'] = 'ok';
        
        // Scan directory
        $files = $sftpConn->nlist($sftpRemoteDir);
        if ($files === false) {
            $files = $sftpConn->rawlist($sftpRemoteDir);
            if ($files !== false) {
                $files = array_keys($files);
            }
        }
        
        if ($files === false || !is_array($files)) {
            addError($result, 'sftp', 'Failed to list files');
            $sftp['scan'] = 'failed';
            $result['sftp'] = $sftp;
            return;
        }
        
        $totalFiles = count($files);
        $sftp['scan'] = [
            'total_files' => $totalFiles,
            'first_files' => array_slice($files, 0, 20)
        ];
        
        // Pattern matching
        $pattern = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        $sftp['pattern'] = $pattern;
        
        $matchedFiles = [];
        $matchDetails = [];
        
        foreach (array_slice($files, 0, 20) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $matchInfo = [
                'filename' => $entry,
                'match' => false,
                'reason' => null
            ];
            
            if (!preg_match('/\.csv$/i', $entry)) {
                $matchInfo['reason'] = 'wrong_extension';
                $matchDetails[] = $matchInfo;
                continue;
            }
            
            if (!preg_match($pattern, $entry, $matches)) {
                $matchInfo['reason'] = 'pattern_mismatch';
                $matchDetails[] = $matchInfo;
                continue;
            }
            
            $macRaw = $matches[1] ?? null;
            $dateStr = $matches[2] ?? null;
            $timeStr = $matches[3] ?? null;
            
            if (empty($macRaw) || strlen($macRaw) !== 12) {
                $matchInfo['reason'] = 'invalid_mac';
                $matchDetails[] = $matchInfo;
                continue;
            }
            
            $macNorm = strtoupper($macRaw);
            $year = substr($dateStr, 0, 4);
            $month = substr($dateStr, 4, 2);
            $day = substr($dateStr, 6, 2);
            $hour = substr($timeStr, 0, 2);
            $minute = substr($timeStr, 2, 2);
            $second = substr($timeStr, 4, 2);
            $timestamp = "$year-$month-$day $hour:$minute:$second";
            
            $matchInfo['match'] = true;
            $matchInfo['mac_norm'] = $macNorm;
            $matchInfo['timestamp'] = $timestamp;
            $matchDetails[] = $matchInfo;
            
            $matchedFiles[] = [
                'filename' => $entry,
                'mac_norm' => $macNorm,
                'timestamp' => $timestamp
            ];
        }
        
        // Count all matched files
        $allMatched = [];
        foreach ($files as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match($pattern, $entry)) {
                $allMatched[] = $entry;
            }
        }
        
        $sftp['match'] = [
            'matched_files_count' => count($allMatched),
            'first_20_details' => $matchDetails,
            'first_matched' => !empty($matchedFiles) ? $matchedFiles[0] : null
        ];
        
        $result['sftp'] = $sftp;
        
    } catch (Throwable $e) {
        addError($result, 'sftp', 'Exception in section_sftp_scan: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $result['sftp'] = array_merge($result['sftp'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== SECTION SFTP PROCESS ONE ======
function section_sftp_process_one(array &$result, bool $writeDb, bool $moveFile): void {
    if (!isset($result['sftp']['match']['first_matched'])) {
        addError($result, 'sftp_process', 'No matched file to process');
        return;
    }
    
    $fileInfo = $result['sftp']['match']['first_matched'];
    $filename = $fileInfo['filename'];
    
    $process = [
        'filename' => $filename,
        'mode' => $writeDb ? 'write' : 'dry-run',
        'download_ok' => false,
        'tmp_exists' => false,
        'tmp_size' => null,
        'filemtime_ok' => false,
        'parse_ok' => false,
        'mac_norm' => null,
        'timestamp' => null,
        'db_inserted' => false,
        'db_updated' => false,
        'moved' => false
    ];
    
    try {
        // Load autoload and connect SFTP
        $autoloadPaths = [
            __DIR__ . '/../vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        
        $autoloadFound = null;
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) {
                $autoloadFound = $path;
                break;
            }
        }
        
        if (!$autoloadFound) {
            addError($result, 'sftp_process', 'autoload not found');
            $result['sftp']['process'] = $process;
            return;
        }
        
        require_once $autoloadFound;
        
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $sftpRemoteDir = rtrim($sftpRemoteDir, '/') ?: '/';
        
        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, 15);
        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'sftp_process', 'SFTP login failed');
            $result['sftp']['process'] = $process;
            return;
        }
        
        // Download
        $remote = ($sftpRemoteDir === '/' ? '' : $sftpRemoteDir) . '/' . $filename;
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        
        $downloadOk = $sftpConn->get($remote, $tmp);
        $process['download_ok'] = $downloadOk;
        
        if (!$downloadOk) {
            addError($result, 'sftp_process', 'Download failed');
            @unlink($tmp);
            $result['sftp']['process'] = $process;
            return;
        }
        
        $process['tmp_exists'] = file_exists($tmp);
        $process['tmp_size'] = file_exists($tmp) ? filesize($tmp) : null;
        
        // filemtime
        $filemtime = @filemtime($tmp);
        $process['filemtime_ok'] = $filemtime !== false;
        if ($filemtime === false) {
            $filemtime = time();
        }
        
        // Parse CSV
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
        
        try {
            $csvData = parse_csv_kv($tmp);
            $process['parse_ok'] = true;
            $process['csv_fields'] = array_keys($csvData);
        } catch (Throwable $e) {
            addError($result, 'sftp_process', 'Parse failed: ' . $e->getMessage());
            $process['parse_error'] = $e->getMessage();
        }
        
        // Use filename info
        $process['mac_norm'] = $fileInfo['mac_norm'] ?? null;
        $process['timestamp'] = $fileInfo['timestamp'] ?? null;
        
        // DB insert (if writeDb)
        if ($writeDb && $process['parse_ok'] && $process['mac_norm'] && $process['timestamp']) {
            try {
                $dbPath = dirname(__DIR__) . '/includes/db.php';
                if (file_exists($dbPath)) {
                    require_once $dbPath;
                    
                    if (isset($pdo) && $pdo instanceof PDO) {
                        $FIELDS = [
                            'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
                            'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
                            'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
                            'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
                            'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
                        ];
                        
                        $cols = implode(',', $FIELDS) . ',DateInsertion';
                        $ph = ':' . implode(',:', $FIELDS) . ',NOW()';
                        $sql = "INSERT INTO compteur_relevee ($cols) VALUES ($ph) ON DUPLICATE KEY UPDATE DateInsertion = NOW()";
                        
                        $stmt = $pdo->prepare($sql);
                        
                        $binds = [];
                        foreach ($FIELDS as $f) {
                            $binds[":$f"] = $csvData[$f] ?? null;
                        }
                        
                        // Override with filename info
                        $binds[':MacAddress'] = $process['mac_norm'];
                        $binds[':Timestamp'] = $process['timestamp'];
                        
                        $stmt->execute($binds);
                        $rowCount = $stmt->rowCount();
                        
                        $process['db_inserted'] = $rowCount > 0;
                        $process['db_rowcount'] = $rowCount;
                        $process['db_error_info'] = $pdo->errorInfo();
                        
                    }
                }
            } catch (Throwable $e) {
                addError($result, 'sftp_process', 'DB insert failed: ' . $e->getMessage());
                $process['db_error'] = $e->getMessage();
            }
        }
        
        // Move file (if moveFile)
        if ($moveFile) {
            try {
                $targetDir = '/processed';
                $target = $targetDir . '/' . $filename;
                $moved = $sftpConn->rename($remote, $target);
                $process['moved'] = $moved;
                if (!$moved) {
                    $process['move_error'] = 'rename failed';
                }
            } catch (Throwable $e) {
                addError($result, 'sftp_process', 'Move failed: ' . $e->getMessage());
                $process['move_error'] = $e->getMessage();
            }
        }
        
        @unlink($tmp);
        
    } catch (Throwable $e) {
        addError($result, 'sftp_process', 'Exception: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $process['exception'] = $e->getMessage();
    }
    
    $result['sftp']['process'] = $process;
}

// ====== SECTION WEB IONOS ======
function section_web_ionos(array &$result, bool $writeDb): void {
    $web = [];
    
    try {
        $url = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
        $web['url'] = $url;
        
        // Download HTML
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'method' => 'GET'
            ]
        ]);
        
        $html = @file_get_contents($url, false, $context);
        
        if ($html === false) {
            addError($result, 'web', 'Failed to download HTML');
            $web['http_ok'] = false;
            $result['web'] = $web;
            return;
        }
        
        $web['http_ok'] = true;
        $web['size'] = strlen($html);
        $web['first_200_chars_hash'] = md5(substr($html, 0, 200));
        
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
        
        if (empty($columnMap['mac']) || empty($columnMap['date'])) {
            $columnMap = [
                'mac' => 5,
                'date' => 1
            ];
        }
        
        $web['mapping'] = $columnMap;
        
        // DB: last timestamp
        try {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
                
                if (isset($pdo) && $pdo instanceof PDO) {
                    $stmt = $pdo->query("SELECT MAX(Timestamp) as max_ts FROM compteur_relevee_ancien");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $web['db'] = [
                        'last_db_ts' => $row['max_ts'] ?? null,
                        'rows_total' => null,
                        'rows_new' => null,
                        'max_ts_page' => null
                    ];
                    
                    // Count rows
                    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM compteur_relevee_ancien");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $web['db']['rows_total'] = (int)($row['cnt'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $web['db_error'] = $e->getMessage();
        }
        
        $result['web'] = $web;
        
    } catch (Throwable $e) {
        addError($result, 'web', 'Exception in section_web_ionos: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $result['web'] = array_merge($result['web'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== EXECUTION ======
try {
    section_env($result);
    section_db($result);
    section_sftp_scan($result);
    
    if ($runSftp) {
        section_sftp_process_one($result, $writeDb, $moveFile);
    }
    
    if ($runWeb) {
        section_web_ionos($result, $writeDb);
    }
    
} catch (Throwable $e) {
    addError($result, 'main', 'Fatal exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    $result['ok'] = false;
}

// ====== OUTPUT ======
if ($htmlMode) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Debug Imports</title>
        <style>
            body { font-family: monospace; margin: 20px; background: #f5f5f5; }
            .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .error { color: red; }
            .ok { color: green; }
            pre { background: #f0f0f0; padding: 10px; overflow-x: auto; }
            h2 { margin-top: 0; }
        </style>
    </head>
    <body>
        <h1>Debug Imports - <?= htmlspecialchars($result['ts']) ?></h1>
        
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
        
        <?php if (!empty($result['errors'])): ?>
        <div class="section">
            <h2>Errors</h2>
            <pre><?= htmlspecialchars(json_encode($result['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

