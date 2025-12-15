<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * import/debug_sftp_deep.php
 * 
 * Diagnostic approfondi de l'import SFTP
 * - Identifie pourquoi l'import retourne 0 importés
 * - Teste connexion, scan, matching, parsing, insertion DB
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
$run = isset($_GET['run']) && $_GET['run'] === '1';
$limit = (int)($_GET['limit'] ?? 1);
$writeDb = isset($_GET['write_db']) && $_GET['write_db'] === '1';
$moveFile = isset($_GET['move']) && $_GET['move'] === '1';

// ====== INITIALISATION ======
$result = [
    'ok' => true,
    'ts' => date('Y-m-d H:i:s'),
    'env' => [],
    'sftp_scan' => [],
    'process' => [],
    'summary' => [],
    'errors' => []
];

// ====== HELPERS ======
function arr_get($v, $k, $default = null) {
    return (is_array($v) && array_key_exists($k, $v)) ? $v[$k] : $default;
}

function maskPassword(?string $pass): string {
    if (empty($pass)) return '(empty)';
    $len = strlen($pass);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($pass, 0, 2) . str_repeat('*', $len - 4) . substr($pass, -2);
}

function addError(array &$result, string $stage, string $file, string $error, array $context = []): void {
    $result['ok'] = false;
    $result['errors'][] = [
        'stage' => $stage,
        'file' => $file,
        'error' => $error,
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
        
        // Versions
        $env['php_version'] = PHP_VERSION;
        $env['php_os'] = PHP_OS;
        
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
            'password' => $sftpPass ? maskPassword($sftpPass) : 'not_set',
            'port' => $sftpPort,
            'timeout' => $sftpTimeout,
            'remote_dir' => $sftpRemoteDir
        ];
        
        // DSN MySQL
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
            'dsn' => ($mysqlHost && $mysqlDb) ? "mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4" : 'not_set'
        ];
        
    } catch (Throwable $e) {
        addError($result, 'env', '', 'Exception in section_env: ' . $e->getMessage());
    }
    
    $result['env'] = $env;
}

// ====== SECTION SFTP SCAN ======
function section_sftp_scan(array &$result): void {
    $scan = [];
    
    try {
        // Load autoload
        $autoloadPath = $result['env']['autoload_path'] ?? null;
        if (!$autoloadPath || $autoloadPath === 'not_found') {
            addError($result, 'sftp_scan', '', 'autoload.php not found');
            $result['sftp_scan'] = ['error' => 'autoload not found'];
            return;
        }
        
        require_once $autoloadPath;
        
        // SFTP connection
        $sftpHost = getenv('SFTP_HOST') ?: '';
        $sftpUser = getenv('SFTP_USER') ?: '';
        $sftpPass = getenv('SFTP_PASS') ?: '';
        $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
        $sftpTimeout = (int)(getenv('SFTP_TIMEOUT') ?: 15);
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $sftpRemoteDir = rtrim($sftpRemoteDir, '/') ?: '/';
        
        if (empty($sftpHost) || empty($sftpUser) || empty($sftpPass)) {
            addError($result, 'sftp_scan', '', 'SFTP credentials missing');
            $result['sftp_scan'] = ['error' => 'credentials missing'];
            return;
        }
        
        $sftp = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        $scan['connection'] = 'ok';
        
        if (!$sftp->login($sftpUser, $sftpPass)) {
            addError($result, 'sftp_scan', '', 'SFTP login failed');
            $scan['login'] = 'failed';
            $result['sftp_scan'] = $scan;
            return;
        }
        
        $scan['login'] = 'ok';
        
        // List files
        $files = $sftp->nlist($sftpRemoteDir);
        if ($files === false) {
            $rawFiles = $sftp->rawlist($sftpRemoteDir);
            if ($rawFiles !== false && is_array($rawFiles)) {
                $files = array_keys($rawFiles);
            }
        }
        
        if ($files === false || !is_array($files)) {
            addError($result, 'sftp_scan', '', 'Failed to list files');
            $scan['list'] = 'failed';
            $result['sftp_scan'] = $scan;
            return;
        }
        
        $totalFiles = count($files);
        $scan['total_files'] = $totalFiles;
        $scan['first_30'] = array_slice($files, 0, 30);
        
        // Patterns
        $patternStrict = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        $patternLoose = '/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i';
        
        $scan['patterns'] = [
            'strict' => $patternStrict,
            'loose' => $patternLoose
        ];
        
        // Match debug for first 30 files
        $matchDebug = [];
        $matchedStrict = [];
        $matchedLoose = [];
        
        foreach (array_slice($files, 0, 30) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $matchInfo = [
                'filename' => $entry,
                'matched_strict' => false,
                'matched_loose' => false,
                'reason' => null
            ];
            
            if (!preg_match('/\.csv$/i', $entry)) {
                $matchInfo['reason'] = 'wrong_extension';
                $matchDebug[] = $matchInfo;
                continue;
            }
            
            // Test strict pattern
            if (preg_match($patternStrict, $entry, $strictMatches)) {
                $matchInfo['matched_strict'] = true;
                $matchedStrict[] = $entry;
            }
            
            // Test loose pattern
            if (preg_match($patternLoose, $entry, $looseMatches)) {
                $matchInfo['matched_loose'] = true;
                if (!in_array($entry, $matchedLoose)) {
                    $matchedLoose[] = $entry;
                }
            }
            
            if (!$matchInfo['matched_strict'] && !$matchInfo['matched_loose']) {
                $matchInfo['reason'] = 'pattern_mismatch';
            }
            
            $matchDebug[] = $matchInfo;
        }
        
        // Count all matched files
        $allMatchedStrict = [];
        $allMatchedLoose = [];
        
        foreach ($files as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match($patternStrict, $entry)) {
                $allMatchedStrict[] = $entry;
            }
            if (preg_match($patternLoose, $entry)) {
                $allMatchedLoose[] = $entry;
            }
        }
        
        $scan['matched_strict_count'] = count($allMatchedStrict);
        $scan['matched_loose_count'] = count($allMatchedLoose);
        $scan['match_debug'] = $matchDebug;
        
        // Sort by timestamp (oldest first)
        $selectedFiles = [];
        foreach ($allMatchedStrict as $entry) {
            if (preg_match($patternStrict, $entry, $matches)) {
                $dateStr = $matches[2] ?? '';
                $timeStr = $matches[3] ?? '';
                if (strlen($dateStr) === 8 && strlen($timeStr) === 6) {
                    $timestamp = $dateStr . $timeStr;
                    $selectedFiles[] = [
                        'filename' => $entry,
                        'timestamp' => $timestamp,
                        'sort_key' => $timestamp
                    ];
                }
            }
        }
        
        usort($selectedFiles, function($a, $b) {
            return strcmp($a['sort_key'], $b['sort_key']);
        });
        
        $scan['selected_files'] = array_column($selectedFiles, 'filename');
        
        $result['sftp_scan'] = $scan;
        
    } catch (Throwable $e) {
        addError($result, 'sftp_scan', '', 'Exception: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $result['sftp_scan'] = array_merge($result['sftp_scan'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== SECTION PROCESS FILES ======
function section_process_files(array &$result, int $limit, bool $writeDb, bool $moveFile): void {
    $process = [
        'processed_files' => [],
        'summary' => [
            'processed_files' => 0,
            'downloaded_ok' => 0,
            'parsed_ok' => 0,
            'skipped_count' => 0,
            'inserted_count' => 0,
            'updated_count' => 0,
            'moved_count' => 0
        ]
    ];
    
    if (!isset($result['sftp_scan']['selected_files']) || empty($result['sftp_scan']['selected_files'])) {
        $result['process'] = $process;
        return;
    }
    
    $selectedFiles = array_slice($result['sftp_scan']['selected_files'], 0, $limit);
    
    try {
        // Load autoload and connect SFTP
        $autoloadPath = $result['env']['autoload_path'] ?? null;
        if (!$autoloadPath || $autoloadPath === 'not_found') {
            addError($result, 'process', '', 'autoload.php not found');
            $result['process'] = $process;
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
        
        $sftp = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        if (!$sftp->login($sftpUser, $sftpPass)) {
            addError($result, 'process', '', 'SFTP login failed');
            $result['process'] = $process;
            return;
        }
        
        // Load DB if needed
        $pdo = null;
        if ($writeDb) {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
                
                if (isset($pdo) && $pdo instanceof PDO) {
                    // Check table exists
                    try {
                        $stmt = $pdo->query("SHOW TABLES LIKE 'compteur_relevee'");
                        $tableExists = $stmt->rowCount() > 0;
                        if (!$tableExists) {
                            addError($result, 'process', '', 'Table compteur_relevee does not exist');
                        }
                    } catch (Throwable $e) {
                        addError($result, 'process', '', 'Failed to check table: ' . $e->getMessage());
                    }
                } else {
                    addError($result, 'process', '', 'PDO not available after db.php load');
                }
            } else {
                addError($result, 'process', '', 'db.php not found');
            }
        }
        
        $patternStrict = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        
        foreach ($selectedFiles as $filename) {
            $fileData = [
                'filename' => $filename,
                'download' => [],
                'parse' => [],
                'extraction' => [],
                'decision' => [],
                'db' => [],
                'move' => []
            ];
            
            try {
                $process['summary']['processed_files']++;
                
                // Download
                $remote = ($sftpRemoteDir === '/' ? '' : $sftpRemoteDir) . '/' . $filename;
                $tmp = tempnam(sys_get_temp_dir(), 'csv_');
                
                $downloadOk = $sftp->get($remote, $tmp);
                $fileData['download'] = [
                    'tmp_path' => $tmp,
                    'exists' => file_exists($tmp),
                    'size' => file_exists($tmp) ? filesize($tmp) : null,
                    'filemtime' => file_exists($tmp) ? @filemtime($tmp) : null,
                    'download_ok' => $downloadOk
                ];
                
                if (!$downloadOk || !file_exists($tmp)) {
                    addError($result, 'process', $filename, 'Download failed');
                    $process['summary']['skipped_count']++;
                    $process['processed_files'][] = $fileData;
                    continue;
                }
                
                $process['summary']['downloaded_ok']++;
                
                // Parse CSV
                $csvData = [];
                $keys = [];
                $preview = [];
                
                try {
                    if (($h = fopen($tmp, 'r')) !== false) {
                        while (($row = fgetcsv($h, 2000, ',')) !== false) {
                            if (isset($row[0], $row[1])) {
                                $key = trim($row[0]);
                                $value = trim((string)$row[1]);
                                $csvData[$key] = $value;
                                $keys[] = $key;
                            }
                        }
                        fclose($h);
                    }
                    
                    $previewCount = 0;
                    foreach ($csvData as $k => $v) {
                        if ($previewCount >= 10) break;
                        $preview[$k] = $v;
                        $previewCount++;
                    }
                    
                    $fileData['parse'] = [
                        'keys_count' => count($keys),
                        'keys' => $keys,
                        'preview' => $preview,
                        'parse_ok' => true
                    ];
                    
                    $process['summary']['parsed_ok']++;
                    
                } catch (Throwable $e) {
                    $fileData['parse'] = [
                        'parse_ok' => false,
                        'error' => $e->getMessage()
                    ];
                    addError($result, 'process', $filename, 'Parse failed: ' . $e->getMessage());
                    $process['summary']['skipped_count']++;
                    @unlink($tmp);
                    $process['processed_files'][] = $fileData;
                    continue;
                }
                
                // Extract MacAddress and Timestamp
                $macFromCsv = arr_get($csvData, 'MacAddress', '');
                $timestampFromCsv = arr_get($csvData, 'Timestamp', '');
                
                // Extract from filename
                $macFromFilename = null;
                $timestampFromFilename = null;
                
                if (preg_match($patternStrict, $filename, $matches)) {
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
                
                $fileData['extraction'] = [
                    'mac_from_csv' => $macFromCsv,
                    'timestamp_from_csv' => $timestampFromCsv,
                    'mac_from_filename' => $macFromFilename,
                    'timestamp_from_filename' => $timestampFromFilename
                ];
                
                // Decision
                $useMac = $macFromFilename ?: $macFromCsv;
                $useTimestamp = $timestampFromFilename ?: $timestampFromCsv;
                $skipReason = null;
                
                if (empty($useMac)) {
                    $skipReason = 'mac_missing';
                } elseif (empty($useTimestamp)) {
                    $skipReason = 'timestamp_missing';
                }
                
                $fileData['decision'] = [
                    'use_mac' => $useMac,
                    'use_mac_source' => $macFromFilename ? 'filename' : ($macFromCsv ? 'csv' : 'none'),
                    'use_timestamp' => $useTimestamp,
                    'use_timestamp_source' => $timestampFromFilename ? 'filename' : ($timestampFromCsv ? 'csv' : 'none'),
                    'skip_reason' => $skipReason
                ];
                
                if ($skipReason) {
                    $process['summary']['skipped_count']++;
                    @unlink($tmp);
                    $process['processed_files'][] = $fileData;
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
                        $errorInfo = $pdo->errorInfo();
                        
                        $isInsert = $rowCount === 1;
                        $isUpdate = $rowCount === 2;
                        
                        if ($isInsert) {
                            $process['summary']['inserted_count']++;
                        } elseif ($isUpdate) {
                            $process['summary']['updated_count']++;
                        }
                        
                        $fileData['db'] = [
                            'sql_used' => $sql,
                            'binds_used' => $binds,
                            'rowCount' => $rowCount,
                            'pdo_errorInfo' => $errorInfo,
                            'inserted' => $isInsert,
                            'updated' => $isUpdate
                        ];
                        
                    } catch (Throwable $e) {
                        $fileData['db'] = [
                            'error' => $e->getMessage(),
                            'pdo_errorInfo' => $pdo->errorInfo() ?? null
                        ];
                        addError($result, 'process', $filename, 'DB insert failed: ' . $e->getMessage());
                    }
                }
                
                // Move file if moveFile
                if ($moveFile) {
                    try {
                        // Ensure /processed exists
                        $processedDir = '/processed';
                        $dirs = $sftp->nlist('/');
                        $processedExists = in_array('processed', $dirs) || in_array('/processed', $dirs);
                        
                        if (!$processedExists) {
                            // Try to create directory
                            $sftp->mkdir($processedDir);
                        }
                        
                        $target = $processedDir . '/' . $filename;
                        $moved = $sftp->rename($remote, $target);
                        
                        $fileData['move'] = [
                            'moved_ok' => $moved,
                            'move_error' => $moved ? null : 'rename failed',
                            'sftp_last_error' => $sftp->getLastError() ?: null
                        ];
                        
                        if ($moved) {
                            $process['summary']['moved_count']++;
                        }
                        
                    } catch (Throwable $e) {
                        $fileData['move'] = [
                            'moved_ok' => false,
                            'move_error' => $e->getMessage(),
                            'sftp_last_error' => $sftp->getLastError() ?: null
                        ];
                        addError($result, 'process', $filename, 'Move failed: ' . $e->getMessage());
                    }
                }
                
                @unlink($tmp);
                
            } catch (Throwable $e) {
                addError($result, 'process', $filename, 'Exception: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
            
            $process['processed_files'][] = $fileData;
        }
        
    } catch (Throwable $e) {
        addError($result, 'process', '', 'Fatal exception: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    
    $result['process'] = $process;
    $result['summary'] = $process['summary'];
}

// ====== EXECUTION ======
try {
    section_env($result);
    section_sftp_scan($result);
    
    if ($run) {
        section_process_files($result, $limit, $writeDb, $moveFile);
    }
    
} catch (Throwable $e) {
    addError($result, 'main', '', 'Fatal exception: ' . $e->getMessage(), [
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
        <title>Debug SFTP Deep</title>
        <style>
            body { font-family: monospace; margin: 20px; background: #f5f5f5; }
            .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .error { color: red; }
            .ok { color: green; }
            .warning { color: orange; }
            pre { background: #f0f0f0; padding: 10px; overflow-x: auto; font-size: 12px; }
            h2 { margin-top: 0; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
        </style>
    </head>
    <body>
        <h1>Debug SFTP Deep - <?= htmlspecialchars($result['ts']) ?></h1>
        
        <div class="section">
            <h2>Status: <span class="<?= $result['ok'] ? 'ok' : 'error' ?>"><?= $result['ok'] ? 'OK' : 'ERROR' ?></span></h2>
        </div>
        
        <div class="section">
            <h2>Environment</h2>
            <pre><?= htmlspecialchars(json_encode($result['env'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>SFTP Scan</h2>
            <pre><?= htmlspecialchars(json_encode($result['sftp_scan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <?php if ($run): ?>
        <div class="section">
            <h2>Process Files</h2>
            <pre><?= htmlspecialchars(json_encode($result['process'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>Summary</h2>
            <pre><?= htmlspecialchars(json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        <?php endif; ?>
        
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

