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
 *
 * Bonus:
 * - Override SFTP dir via ?dir=processed (ou ?dir=/processed)
 */

// ====== SÉCURITÉ ======
$isLocal =
    in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true) ||
    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

$debugKey   = getenv('DEBUG_KEY') ?: '';
$requestKey = $_GET['key'] ?? '';

if (!$isLocal && $debugKey && $requestKey !== $debugKey) {
    ob_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied. DEBUG_KEY required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== CONFIGURATION ======
$htmlMode = (isset($_GET['html']) && $_GET['html'] === '1');
$runSftp  = (isset($_GET['run_sftp']) && $_GET['run_sftp'] === '1');
$runWeb   = (isset($_GET['run_web']) && $_GET['run_web'] === '1');
$writeDb  = (isset($_GET['write_db']) && $_GET['write_db'] === '1');
$moveFile = (isset($_GET['move']) && $_GET['move'] === '1');

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
    if (is_array($v)) return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_object($v)) return '[object ' . get_class($v) . ']';
    if (is_resource($v)) return '[resource]';
    return (string)$v;
}

function arr_get($v, $k, $default = null) {
    return (is_array($v) && array_key_exists($k, $v)) ? $v[$k] : $default;
}

function ensure_array($v): array {
    return is_array($v) ? $v : [];
}

function as_array($v): array {
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

        $env['php'] = [
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time')
        ];

        $autoloadPaths = [
            $projectRoot . '/vendor/autoload.php',
            dirname($projectRoot) . '/vendor/autoload.php',
        ];
        $autoloadFound = null;
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) { $autoloadFound = $path; break; }
        }
        $env['autoload_path'] = $autoloadFound ?: 'not_found';
        $env['autoload_exists'] = ($autoloadFound !== null);

        $dbPath = $projectRoot . '/includes/db.php';
        $env['db_path'] = $dbPath;
        $env['db_exists'] = file_exists($dbPath);

        $uploadScriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
        $env['upload_script_path'] = $uploadScriptPath;
        $env['upload_script_exists'] = file_exists($uploadScriptPath);

        $sftpHost      = getenv('SFTP_HOST') ?: '';
        $sftpUser      = getenv('SFTP_USER') ?: '';
        $sftpPass      = getenv('SFTP_PASS') ?: '';
        $sftpPort      = getenv('SFTP_PORT') ?: '22';
        $sftpTimeout   = getenv('SFTP_TIMEOUT') ?: '15';
        $sftpRemoteDir = getenv('SFTP_REMOTE_DIR') ?: '/';
        $scanDir = trim((string)($_GET['dir'] ?? ''));

        $env['sftp'] = [
            'host' => $sftpHost ?: 'not_set',
            'user' => $sftpUser ?: 'not_set',
            'password' => mask_secret($sftpPass),
            'port' => $sftpPort,
            'timeout' => $sftpTimeout,
            'remote_dir' => $sftpRemoteDir,
            'scan_dir_param' => ($scanDir !== '' ? $scanDir : '(none)'),
        ];

        $mysqlHost = getenv('MYSQLHOST');
        $mysqlDb   = getenv('MYSQLDATABASE');
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

        $webUrl = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
        $env['web_url'] = $webUrl;

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
        addError($result, 'Exception in section_env: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    $result['env'] = $env;
}

// ====== SECTION DB ======
/**
 * Charge includes/db.php sans polluer les variables locales (évite collision $db, etc.)
 * Retourne un PDO si possible.
 */
function load_pdo_isolated(string $dbPath): ?PDO {
    if (!file_exists($dbPath)) return null;

    $pdo = (static function (string $__path): ?PDO {
        // variables possibles attendues dans db.php
        $pdo = null;
        $PDO = null;
        $db  = null;
        $conn = null;

        require $__path;

        if (isset($pdo) && $pdo instanceof PDO) return $pdo;
        if (isset($PDO) && $PDO instanceof PDO) return $PDO;
        if (isset($db)  && $db  instanceof PDO) return $db;
        if (isset($conn) && $conn instanceof PDO) return $conn;

        // si db.php set un global
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        if (isset($GLOBALS['PDO']) && $GLOBALS['PDO'] instanceof PDO) return $GLOBALS['PDO'];
        if (isset($GLOBALS['db'])  && $GLOBALS['db']  instanceof PDO) return $GLOBALS['db'];
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];

        return null;
    })($dbPath);

    return ($pdo instanceof PDO) ? $pdo : null;
}

function section_db(array &$result): void {
    $dbInfo = [];

    try {
        $projectRoot = dirname(__DIR__);
        $dbPath = $projectRoot . '/includes/db.php';

        if (!file_exists($dbPath)) {
            addError($result, "includes/db.php not found at $dbPath");
            $result['db'] = ['error' => 'db.php not found'];
            return;
        }

        $pdo2 = load_pdo_isolated($dbPath);

        if (!($pdo2 instanceof PDO)) {
            addError($result, 'PDO not available after db.php load');
            $result['db'] = ['error' => 'PDO not available'];
            return;
        }

        $dbInfo['connection'] = 'ok';
        $dbInfo['pdo_class'] = get_class($pdo2);

        // Test query
        try {
            $pdo2->query("SELECT 1");
            $dbInfo['test_query'] = 'ok';
        } catch (Throwable $e) {
            addError($result, 'Test query failed: ' . $e->getMessage());
            $dbInfo['test_query'] = 'failed';
        }

        // Tables
        $tables = ['import_run', 'compteur_relevee', 'compteur_relevee_ancien'];
        $dbInfo['tables'] = [];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo2->query("SHOW TABLES LIKE '$table'");
                $dbInfo['tables'][$table] = ($stmt && $stmt->rowCount() > 0);
            } catch (Throwable $e) {
                $dbInfo['tables'][$table] = false;
                addWarning($result, "Failed to check table $table: " . $e->getMessage());
            }
        }

        // last 10 imports
        $dbInfo['last_10_imports'] = [];
        try {
            $stmt = $pdo2->query("
                SELECT id, ran_at, imported, skipped, ok, msg
                FROM import_run
                ORDER BY id DESC
                LIMIT 10
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $rows = ensure_array($rows);

            foreach ($rows as $r) {
                if (!is_array($r)) continue;

                $msg = arr_get($r, 'msg', '');
                $jsonError = null;
                $decoded = safe_json_decode($msg, $jsonError);
                $decodedArr = as_array($decoded);

                $type = 'other';
                if (arr_get($decodedArr, 'processed_files') !== null
                    || arr_get($decodedArr, 'inserted') !== null
                    || arr_get($decodedArr, 'matched_files') !== null) {
                    $type = 'summary';
                } elseif (arr_get($decodedArr, 'stage', '') === 'process_file') {
                    $type = 'process_file';
                }

                $item = [
                    'id' => (int)arr_get($r, 'id', 0),
                    'ran_at' => (string)arr_get($r, 'ran_at', ''),
                    'ok' => (int)arr_get($r, 'ok', 0),
                    'imported' => (int)arr_get($r, 'imported', 0),
                    'skipped' => (int)arr_get($r, 'skipped', 0),
                    'type' => $type
                ];

                if (!empty($decodedArr)) {
                    $item['msg'] = $decodedArr;
                } else {
                    $item['msg_decoded'] = null;
                    $item['msg_raw_preview'] = substr(safe_scalar($msg), 0, 400);
                    $item['json_error'] = $jsonError;
                }

                $dbInfo['last_10_imports'][] = $item;
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to fetch last imports: ' . $e->getMessage());
        }

        // last summary sftp
        $dbInfo['last_summary_sftp'] = null;
        try {
            $stmt = $pdo2->query("
                SELECT id, ran_at, imported, skipped, ok, msg
                FROM import_run
                WHERE msg LIKE '%processed_files%'
                ORDER BY id DESC
                LIMIT 1
            ");
            $row = fetch_assoc_safe($stmt);

            $msg = arr_get($row, 'msg', '');
            $jsonError = null;
            $decoded = safe_json_decode($msg, $jsonError);
            $decodedArr = as_array($decoded);

            if (!empty($row)) {
                $dbInfo['last_summary_sftp'] = [
                    'id' => (int)arr_get($row, 'id', 0),
                    'ran_at' => (string)arr_get($row, 'ran_at', ''),
                    'ok' => (int)arr_get($row, 'ok', 0),
                    'imported' => (int)arr_get($row, 'imported', 0),
                    'skipped' => (int)arr_get($row, 'skipped', 0),
                    'msg' => $decodedArr,
                    'inserted' => arr_get($decodedArr, 'inserted'),
                    'updated' => arr_get($decodedArr, 'updated'),
                    'matched_files' => arr_get($decodedArr, 'matched_files'),
                    'processed_files' => arr_get($decodedArr, 'processed_files'),
                    'json_error' => (!empty($decodedArr) ? null : $jsonError),
                ];
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to fetch last summary SFTP: ' . $e->getMessage());
        }

        // recent inserted
        $dbInfo['recent_rows_inserted'] = null;
        try {
            $stmt = $pdo2->query("
                SELECT COUNT(*) as cnt
                FROM compteur_relevee
                WHERE DateInsertion > NOW() - INTERVAL 10 MINUTE
            ");
            $row = fetch_assoc_safe($stmt);
            $dbInfo['recent_rows_inserted'] = (int)arr_get($row, 'cnt', 0);
        } catch (Throwable $e) {
            addWarning($result, 'Failed to count recent rows: ' . $e->getMessage());
        }

    } catch (Throwable $e) {
        addError($result, 'Exception in section_db: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $dbInfo = is_array($dbInfo) ? $dbInfo : [];
        $dbInfo['error'] = safe_scalar($e->getMessage());
    }

    $result['db'] = is_array($dbInfo) ? $dbInfo : [];
}

// ====== SECTION SFTP SCAN ======
function section_sftp_scan(array &$result): void {
    $sftpInfo = [];

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

        $dirParam = trim((string)($_GET['dir'] ?? ''));
        if ($dirParam !== '') {
            if ($dirParam === 'processed') $dirParam = '/processed';
            if ($dirParam[0] !== '/') $dirParam = '/' . $dirParam;
            $sftpRemoteDir = rtrim($dirParam, '/') ?: '/';
        }

        $sftpInfo['remote_dir_used'] = $sftpRemoteDir;

        if (empty($sftpHost) || empty($sftpUser) || empty($sftpPass)) {
            addError($result, 'SFTP credentials missing');
            $result['sftp'] = ['error' => 'credentials missing'];
            return;
        }

        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        $sftpInfo['connection'] = 'ok';

        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'SFTP login failed');
            $sftpInfo['login'] = 'failed';
            $result['sftp'] = $sftpInfo;
            return;
        }

        $sftpInfo['login'] = 'ok';

        $files = $sftpConn->nlist($sftpRemoteDir);
        if ($files === false) {
            $rawFiles = $sftpConn->rawlist($sftpRemoteDir);
            if ($rawFiles !== false && is_array($rawFiles)) {
                $files = array_keys($rawFiles);
            }
        }

        if ($files === false || !is_array($files)) {
            addError($result, 'Failed to list files');
            $sftpInfo['scan'] = 'failed';
            $result['sftp'] = $sftpInfo;
            return;
        }

        $totalEntries = count($files);
        $csvFiles = array_filter($files, static function($f) {
            return is_string($f) && preg_match('/\.csv$/i', $f);
        });
        $totalCsv = count($csvFiles);

        $pattern = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';
        $sftpInfo['pattern'] = $pattern;

        $matchDebug = [];
        foreach (array_slice($files, 0, 30) as $entry) {
            if (!is_string($entry)) continue;
            if ($entry === '.' || $entry === '..') continue;

            $matchInfo = ['filename' => $entry, 'match' => false, 'reason' => null];

            if (!preg_match('/\.csv$/i', $entry)) {
                $matchInfo['reason'] = 'wrong_extension';
                $matchDebug[] = $matchInfo;
                continue;
            }

            if (preg_match($pattern, $entry)) {
                $matchInfo['match'] = true;
            } else {
                $matchInfo['reason'] = 'pattern_mismatch';
            }

            $matchDebug[] = $matchInfo;
        }

        $allMatched = [];
        foreach ($files as $entry) {
            if (!is_string($entry)) continue;
            if ($entry === '.' || $entry === '..') continue;
            if (preg_match($pattern, $entry)) $allMatched[] = $entry;
        }

        $sftpInfo['scan'] = [
            'total_entries' => $totalEntries,
            'total_csv' => $totalCsv,
            'matched_count' => count($allMatched),
            'first_30' => array_slice($files, 0, 30),
            'match_debug' => $matchDebug
        ];

        $result['sftp'] = $sftpInfo;

    } catch (Throwable $e) {
        addError($result, 'Exception in section_sftp_scan: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $result['sftp'] = array_merge($result['sftp'] ?? [], ['exception' => $e->getMessage()]);
    }
}

// ====== SECTION SFTP PROCESS ======
function section_sftp_process(array &$result, int $limit, bool $writeDb, bool $moveFile): void {
    if (!isset($result['sftp']['scan']['matched_count']) || (int)$result['sftp']['scan']['matched_count'] === 0) {
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

        $sftpRemoteDir = $result['sftp']['remote_dir_used'] ?? (getenv('SFTP_REMOTE_DIR') ?: '/');
        $sftpRemoteDir = rtrim((string)$sftpRemoteDir, '/') ?: '/';

        $sftpConn = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, $sftpTimeout);
        if (!$sftpConn->login($sftpUser, $sftpPass)) {
            addError($result, 'SFTP login failed');
            $result['sftp']['process'] = $process;
            return;
        }

        $pdoDb = null;
        if ($writeDb) {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            $pdoDb = load_pdo_isolated($dbPath);
        }

        $pattern = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';

        $matchedFiles = [];
        if (isset($result['sftp']['scan']['match_debug']) && is_array($result['sftp']['scan']['match_debug'])) {
            foreach ($result['sftp']['scan']['match_debug'] as $matchInfo) {
                if (is_array($matchInfo) && arr_get($matchInfo, 'match') === true) {
                    $matchedFiles[] = (string)arr_get($matchInfo, 'filename', '');
                }
            }
        }
        $matchedFiles = array_values(array_filter($matchedFiles, static fn($x) => $x !== ''));
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

                $remote = ($sftpRemoteDir === '/' ? '' : $sftpRemoteDir) . '/' . $filename;

                $tmp = tempnam(sys_get_temp_dir(), 'csv_');
                if ($tmp === false) {
                    addError($result, "tempnam failed for $filename");
                    $process['errors_count']++;
                    $process['files'][] = $fileData;
                    continue;
                }

                $downloadOk = $sftpConn->get($remote, $tmp);
                $fileData['download_ok'] = (bool)$downloadOk;

                if (!$downloadOk || !file_exists($tmp)) {
                    addError($result, "Download failed: $filename", ['remote' => $remote]);
                    $process['errors_count']++;
                    $process['files'][] = $fileData;
                    @unlink($tmp);
                    continue;
                }

                $fileData['tmp_size'] = @filesize($tmp);

                $csvData = [];
                try {
                    $h = fopen($tmp, 'r');
                    if ($h !== false) {
                        while (($row = fgetcsv($h, 2000, ',')) !== false) {
                            if (isset($row[0], $row[1])) {
                                $csvData[trim((string)$row[0])] = trim((string)$row[1]);
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

                $macFromCsv = (string)arr_get($csvData, 'MacAddress', '');
                $timestampFromCsv = (string)arr_get($csvData, 'Timestamp', '');

                $macFromFilename = null;
                $timestampFromFilename = null;

                if (preg_match($pattern, $filename, $matches)) {
                    $macRaw = $matches[1] ?? null;
                    $dateStr = $matches[2] ?? null;
                    $timeStr = $matches[3] ?? null;

                    if ($macRaw && $dateStr && $timeStr) {
                        $macFromFilename = strtoupper((string)$macRaw);
                        $year = substr((string)$dateStr, 0, 4);
                        $month = substr((string)$dateStr, 4, 2);
                        $day = substr((string)$dateStr, 6, 2);
                        $hour = substr((string)$timeStr, 0, 2);
                        $minute = substr((string)$timeStr, 2, 2);
                        $second = substr((string)$timeStr, 4, 2);
                        $timestampFromFilename = "$year-$month-$day $hour:$minute:$second";
                    }
                }

                $fileData['extracted'] = [
                    'mac_from_csv' => $macFromCsv,
                    'timestamp_from_csv' => $timestampFromCsv,
                    'mac_from_filename' => $macFromFilename,
                    'timestamp_from_filename' => $timestampFromFilename
                ];

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

                if ($writeDb && ($pdoDb instanceof PDO)) {
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
                        $stmt = $pdoDb->prepare($sql);

                        $binds = [
                            ':Timestamp' => $useTimestamp,
                            ':MacAddress' => $useMac,
                            ':TotalPages' => (int)arr_get($csvData, 'TotalPages', 0),
                            ':TotalBW' => (int)arr_get($csvData, 'TotalBW', 0),
                            ':TotalColor' => (int)arr_get($csvData, 'TotalColor', 0),
                            ':Status' => (string)(arr_get($csvData, 'Status', '') ?: '')
                        ];

                        $stmt->execute($binds);
                        $rowCount = (int)$stmt->rowCount();

                        $isInsert = ($rowCount === 1);
                        $isUpdate = ($rowCount === 2);

                        if ($isInsert) $process['inserted']++;
                        if ($isUpdate) $process['updated']++;

                        $fileData['db'] = [
                            'rowCount' => $rowCount,
                            'inserted' => $isInsert,
                            'updated' => $isUpdate,
                            'errorInfo' => $stmt->errorInfo(),
                        ];
                    } catch (Throwable $e) {
                        $fileData['db']['error'] = $e->getMessage();
                        $process['errors_count']++;
                    }
                }

                if ($moveFile) {
                    try {
                        $processedDir = '/processed';
                        $target = $processedDir . '/' . $filename;
                        $moved = $sftpConn->rename($remote, $target);

                        $fileData['move'] = [
                            'moved_ok' => (bool)$moved,
                            'moved_to' => $moved ? $target : null,
                            'error' => $moved ? null : 'rename failed'
                        ];

                        if ($moved) $process['moved']++;
                    } catch (Throwable $e) {
                        $fileData['move']['error'] = $e->getMessage();
                        $process['errors_count']++;
                    }
                }

                @unlink($tmp);

            } catch (Throwable $e) {
                addError($result, "Exception processing $filename: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                $process['errors_count']++;
            }

            $process['files'][] = $fileData;
        }

    } catch (Throwable $e) {
        addError($result, 'Fatal exception in section_sftp_process: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    $result['sftp']['process'] = $process;
}

// ====== SECTION WEB IONOS ======
function section_web_ionos(array &$result, bool $runWeb): void {
    $web = [];

    try {
        $url = getenv('WEB_URL') ?: 'https://cccomputer.fr/test_compteur.php';
        $web['url'] = $url;

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

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table//tr');

        $web['parse'] = [
            'nb_tr' => $rows ? $rows->length : 0,
            'headers' => []
        ];

        if ($rows && $rows->length > 0) {
            $firstRow = $rows->item(0);
            if ($firstRow instanceof DOMElement) {
                $headers = $firstRow->getElementsByTagName('th');
                $headerTexts = [];
                for ($i = 0; $i < $headers->length; $i++) {
                    $headerTexts[] = trim((string)$headers->item($i)->textContent);
                }
                $web['parse']['headers'] = $headerTexts;
            }
        }

        $columnMap = [];
        if ($rows && $rows->length > 0) {
            $firstRow = $rows->item(0);
            if ($firstRow instanceof DOMElement) {
                $headers = $firstRow->getElementsByTagName('th');
                for ($i = 0; $i < $headers->length; $i++) {
                    $headerText = strtolower(trim((string)$headers->item($i)->textContent));
                    if (strpos($headerText, 'mac') !== false) $columnMap['mac'] = $i;
                    elseif (strpos($headerText, 'date') !== false || strpos($headerText, 'relevé') !== false) $columnMap['date'] = $i;
                }
            }
        }
        if (!isset($columnMap['mac'], $columnMap['date'])) $columnMap = ['mac' => 5, 'date' => 1];
        $web['mapping'] = $columnMap;

        $rowsParsed = [];
        $getCellText = static function($cell): string {
            return $cell ? trim((string)$cell->textContent) : '';
        };

        if ($rows) {
            foreach ($rows as $row) {
                if (!$row instanceof DOMElement) continue;
                if ($row->getElementsByTagName('th')->length > 0) continue;

                $cells = $row->getElementsByTagName('td');
                $mac  = isset($columnMap['mac'])  ? $getCellText($cells->item((int)$columnMap['mac']))  : '';
                $date = isset($columnMap['date']) ? $getCellText($cells->item((int)$columnMap['date'])) : '';

                if ($mac && $date) $rowsParsed[] = ['mac' => $mac, 'date' => $date];
            }
        }

        $web['rows_parsed'] = array_slice($rowsParsed, -20);
        $web['rows_total'] = count($rowsParsed);

        try {
            $dbPath = dirname(__DIR__) . '/includes/db.php';
            $pdoDb = load_pdo_isolated($dbPath);

            if ($pdoDb instanceof PDO) {
                $stmt = $pdoDb->query("SELECT MAX(Timestamp) as max_ts FROM compteur_relevee_ancien");
                $row = fetch_assoc_safe($stmt);
                $lastDbTs = arr_get($row, 'max_ts', null);

                $web['db'] = [
                    'last_db_ts' => $lastDbTs,
                    'rows_new_estimated' => null
                ];

                if ($lastDbTs) {
                    $newCount = 0;
                    foreach ($rowsParsed as $r) {
                        if (!is_array($r)) continue;
                        $rDate = (string)arr_get($r, 'date', '');
                        if ($rDate !== '' && $rDate > $lastDbTs) $newCount++;
                    }
                    $web['db']['rows_new_estimated'] = $newCount;
                }
            }
        } catch (Throwable $e) {
            addWarning($result, 'Failed to compare with DB: ' . $e->getMessage());
        }

        $result['web'] = $web;

    } catch (Throwable $e) {
        addError($result, 'Exception in section_web_ionos: ' . $e->getMessage(), [
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
        <h1>Debug Import - <?= htmlspecialchars((string)$result['ts']) ?></h1>

        <div class="section">
            <h2>Status: <span class="<?= ($result['ok'] ? 'ok' : 'error') ?>"><?= ($result['ok'] ? 'OK' : 'ERROR') ?></span></h2>
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
