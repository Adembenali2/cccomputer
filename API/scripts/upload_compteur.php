<?php
declare(strict_types=1);

// ✅ Affiche toutes les erreurs PHP et PDO dans Railway
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// PID du processus pour tracer les exécutions concurrentes
$PID = getmypid();
define('IMPORT_PID', $PID);

// ====== DEBUG MODE: Helpers pour diagnostic précis ======
function log_import_run(?PDO $pdo, array $data, bool $ok): void {
    $data['ts'] = date('Y-m-d H:i:s');
    $msg = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    error_log('[IMPORT SFTP] ' . $msg);

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("INSERT INTO import_run(ran_at, imported, skipped, ok, msg) VALUES (NOW(), 0, 0, :ok, :msg)");
            $stmt->execute([
                ':ok'  => $ok ? 1 : 0,
                ':msg' => $msg,
            ]);
        } catch (Throwable $e) {
            // Si la DB n'est pas dispo, on ne bloque pas le debug
            error_log('[IMPORT SFTP] log_import_run failed: ' . $e->getMessage());
        }
    }
}

function debug_die(?PDO $pdo, string $stage, string $error, array $extra = [], int $code = 1): void {
    $payload = array_merge([
        'source' => 'SFTP',
        'stage'  => $stage,
        'error'  => $error,
        'file'   => __FILE__,
        'dir'    => __DIR__,
        'cwd'    => getcwd(),
        'php'    => PHP_VERSION,
        'include_path' => ini_get('include_path'),
        'user'   => function_exists('get_current_user') ? get_current_user() : null,
    ], $extra);

    log_import_run($pdo, $payload, false);
    http_response_code(500);
    echo "ERROR[$stage] $error\n";
    exit($code);
}

// Fonction de debug avec timestamp et PID
function debugLog(string $message, array $context = []): void {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logMsg = "[$timestamp] [PID:" . IMPORT_PID . "] [DEBUG] $message$contextStr\n";
    echo $logMsg;
    error_log($logMsg);
}

// ====== STAGE: bootstrap ======
log_import_run(null, ['source' => 'SFTP', 'stage' => 'bootstrap', 'msg' => 'Script démarré'], true);

// DEBUG BOOTSTRAP — on essaye de détecter autoload/db.php sans supposer le cwd
$pathsAutoload = [
    __DIR__ . '/vendor/autoload.php',            // si vendor est au même niveau (rare)
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',      // fréquent si script dans API/scripts
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];
$autoloadFound = null;
$autoloadChecks = [];
foreach ($pathsAutoload as $p) {
    $autoloadChecks[] = ['path' => $p, 'exists' => file_exists($p), 'realpath' => realpath($p) ?: null];
    if (file_exists($p)) { 
        $autoloadFound = $p; 
        break; 
    }
}

// ====== STAGE: autoload ======
if (!$autoloadFound) {
    debug_die(null, 'autoload', 'vendor/autoload.php introuvable', [
        'paths_tested' => $autoloadChecks,
    ], 2);
}
try {
    require_once $autoloadFound;
    log_import_run(null, ['source' => 'SFTP', 'stage' => 'autoload', 'msg' => 'autoload.php chargé', 'path' => $autoloadFound], true);
} catch (Throwable $e) {
    debug_die(null, 'autoload', 'Erreur lors du chargement de vendor/autoload.php: ' . $e->getMessage(), [
        'exception' => get_class($e),
        'path' => $autoloadFound,
        'paths_tested' => $autoloadChecks,
    ], 2);
}

// Même logique pour db.php (adapte si ton fichier s'appelle autrement)
$pathsDb = [
    __DIR__ . '/../../includes/db.php',
    dirname(__DIR__, 2) . '/includes/db.php',
    dirname(__DIR__, 3) . '/includes/db.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../..' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db.php',
];
$dbFound = null;
$dbChecks = [];
foreach ($pathsDb as $p) {
    $dbChecks[] = ['path' => $p, 'exists' => file_exists($p), 'realpath' => realpath($p) ?: null];
    if (file_exists($p)) { 
        $dbFound = $p; 
        break; 
    }
}

// ====== STAGE: db_include ======
if (!$dbFound) {
    debug_die(null, 'db_include', 'includes/db.php introuvable', [
        'paths_tested' => $dbChecks,
    ], 3);
}
try {
    require_once $dbFound;
    log_import_run(null, ['source' => 'SFTP', 'stage' => 'db_include', 'msg' => 'db.php chargé', 'path' => $dbFound], true);
} catch (Throwable $e) {
    debug_die(null, 'db_include', 'Erreur lors du chargement de includes/db.php: ' . $e->getMessage(), [
        'exception' => get_class($e),
        'path' => $dbFound,
        'paths_tested' => $dbChecks,
    ], 3);
}

// À partir d'ici tu peux récupérer $pdo depuis ton include db.php
// Et tu peux faire : log_import_run($pdo, ['source'=>'SFTP','stage'=>'bootstrap','msg'=>'autoload+db include OK'], true);

/**
 * upload_compteur.php (version avec logs détaillés et gestion d'erreurs)
 * - Connexion SFTP avec timeout
 * - Import CSV compteur_relevee
 * - Log dans import_run
 * - Gestion complète des erreurs et timeouts
 */

// ---------- Timeout global du script ----------
// Maximum 50 secondes pour éviter les blocages (laisser 10s de marge avant le timeout du parent)
set_time_limit(50);
$scriptStartTime = time();
$SCRIPT_TIMEOUT = 50;

debugLog("=== DÉBUT DU SCRIPT D'IMPORT SFTP ===", [
    'pid' => IMPORT_PID,
    'script_start' => date('Y-m-d H:i:s'),
    'timeout' => $SCRIPT_TIMEOUT,
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit')
]);

// ---------- 0) Normaliser les variables d'env pour db.php ----------
debugLog("Étape 0: Normalisation des variables d'environnement MySQL");
(function (): void {
    $needs = !getenv('MYSQLHOST') || !getenv('MYSQLDATABASE') || !getenv('MYSQLUSER');
    debugLog("Variables MySQL présentes", [
        'MYSQLHOST' => getenv('MYSQLHOST') ? '✓' : '✗',
        'MYSQLDATABASE' => getenv('MYSQLDATABASE') ? '✓' : '✗',
        'MYSQLUSER' => getenv('MYSQLUSER') ? '✓' : '✗',
        'needs_normalization' => $needs
    ]);
    
    if (!$needs) {
        debugLog("Variables MySQL déjà configurées, pas de normalisation nécessaire");
        return;
    }

    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL') ?: '';
    debugLog("Tentative de normalisation depuis URL", ['url_present' => !empty($url)]);
    
    if (!$url) {
        debugLog("Aucune URL MySQL trouvée");
        return;
    }

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) {
        debugLog("Échec du parsing de l'URL MySQL", ['parsed' => $p]);
        return;
    }

    putenv("MYSQLHOST={$p['host']}");
    putenv("MYSQLPORT=" . ($p['port'] ?? '3306'));
    putenv("MYSQLUSER=" . urldecode($p['user']));
    putenv("MYSQLPASSWORD=" . (isset($p['pass']) ? urldecode($p['pass']) : ''));
    putenv("MYSQLDATABASE=" . ltrim($p['path'], '/'));
    
    debugLog("Variables MySQL normalisées", [
        'host' => $p['host'],
        'port' => $p['port'] ?? '3306',
        'user' => urldecode($p['user']),
        'database' => ltrim($p['path'], '/')
    ]);
})();

// ====== STAGE: db_connect ======
if (!isset($pdo) || !($pdo instanceof PDO)) {
    debug_die(null, 'db_connect', 'La variable $pdo n\'est pas définie ou n\'est pas une instance de PDO', [
        'pdo_set' => isset($pdo),
        'pdo_type' => gettype($pdo ?? null),
    ], 4);
}

log_import_run($pdo, ['source' => 'SFTP', 'stage' => 'db_connect', 'msg' => 'Connexion PDO établie', 'pdo_class' => get_class($pdo)], true);
debugLog("Connexion PDO établie", [
    'pdo_class' => get_class($pdo),
    'connection_status' => 'OK'
]);
echo "✅ Connexion à la base établie.\n";

// Note: La contrainte UNIQUE (mac_norm, Timestamp) doit exister en base.
// Aucun DDL n'est exécuté dans ce script pour garantir la stabilité.

use phpseclib3\Net\SFTP;
log_import_run($pdo, ['source' => 'SFTP', 'stage' => 'autoload', 'msg' => 'Classe SFTP importée', 'class_exists' => class_exists('phpseclib3\Net\SFTP')], true);
debugLog("Classe SFTP importée", ['class_exists' => class_exists('phpseclib3\Net\SFTP')]);

// Fonction pour vérifier le timeout
function checkTimeout(int $startTime, int $maxSeconds): void {
    if ((time() - $startTime) > $maxSeconds) {
        throw new RuntimeException("TIMEOUT: Le script a dépassé la limite de {$maxSeconds} secondes");
    }
}

// Fonction pour logger les erreurs dans la base
function logErrorToDB(?PDO $pdo, string $errorMsg): void {
    if (!$pdo) return;
    try {
        $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), 0, 0, 0, :msg)
        ")->execute([
            ':msg' => json_encode([
                'source' => 'SFTP',
                'error' => $errorMsg,
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {
        // Si on ne peut pas logger, on ignore (pour éviter les boucles)
    }
}

$sftp = null;

try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    
    debugLog("Étape 2.1: Récupération des variables d'environnement SFTP");
    // Utiliser uniquement les variables d'environnement pour la sécurité
    $sftp_host = getenv('SFTP_HOST') ?: '';
    $sftp_user = getenv('SFTP_USER') ?: '';
    $sftp_pass = getenv('SFTP_PASS') ?: '';
    $sftp_port = (int)(getenv('SFTP_PORT') ?: 22);
    $sftp_timeout = (int)(getenv('SFTP_TIMEOUT') ?: 15); // Timeout de connexion SFTP

    debugLog("Variables SFTP récupérées", [
        'SFTP_HOST' => $sftp_host ? '✓ (' . strlen($sftp_host) . ' chars)' : '✗',
        'SFTP_USER' => $sftp_user ? '✓ (' . strlen($sftp_user) . ' chars)' : '✗',
        'SFTP_PASS' => $sftp_pass ? '✓ (' . strlen($sftp_pass) . ' chars)' : '✗',
        'SFTP_PORT' => $sftp_port,
        'SFTP_TIMEOUT' => $sftp_timeout
    ]);

    if (empty($sftp_host) || empty($sftp_user) || empty($sftp_pass)) {
        $errorMsg = "Variables d'environnement SFTP manquantes (SFTP_HOST, SFTP_USER, SFTP_PASS)";
        debugLog("ERREUR FATALE", ['error' => $errorMsg]);
        echo "❌ Erreur: $errorMsg\n";
        logErrorToDB($pdo ?? null, $errorMsg);
        exit(1);
    }

    // ====== STAGE: sftp_connect ======
    debugLog("Étape 2.2: Création de l'instance SFTP", [
        'host' => $sftp_host,
        'port' => $sftp_port,
        'timeout' => $sftp_timeout
    ]);
    echo "🔌 Tentative de connexion SFTP à $sftp_host:$sftp_port (timeout: {$sftp_timeout}s)...\n";
    
    // Créer la connexion SFTP avec timeout explicite
    $connectionStart = microtime(true);
    try {
        $sftp = new SFTP($sftp_host, $sftp_port, $sftp_timeout);
        $connectionTime = round((microtime(true) - $connectionStart) * 1000, 2);
        debugLog("Instance SFTP créée", ['duration_ms' => $connectionTime]);
    } catch (Throwable $e) {
        debug_die($pdo, 'sftp_connect', "Erreur lors de la création de l'instance SFTP: " . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'host' => $sftp_host,
            'port' => $sftp_port,
            'timeout' => $sftp_timeout,
        ], 5);
    }
    
    // Tentative de login avec gestion d'erreur
    debugLog("Étape 2.3: Tentative de login SFTP", ['user' => $sftp_user]);
    $loginStart = microtime(true);
    $loginSuccess = false;
    try {
        $loginSuccess = $sftp->login($sftp_user, $sftp_pass);
        $loginTime = round((microtime(true) - $loginStart) * 1000, 2);
        debugLog("Login SFTP terminé", ['success' => $loginSuccess, 'duration_ms' => $loginTime]);
    } catch (Throwable $e) {
        $loginTime = round((microtime(true) - $loginStart) * 1000, 2);
        debug_die($pdo, 'sftp_connect', "Exception lors de la connexion SFTP: " . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'duration_ms' => $loginTime,
            'host' => $sftp_host,
            'port' => $sftp_port,
        ], 5);
    }
    
    if (!$loginSuccess) {
        debug_die($pdo, 'sftp_connect', "Échec de l'authentification SFTP", [
            'host' => $sftp_host,
            'port' => $sftp_port,
            'user' => $sftp_user,
        ], 5);
    }
    
    log_import_run($pdo, ['source' => 'SFTP', 'stage' => 'sftp_connect', 'msg' => 'Connexion SFTP établie', 'host' => $sftp_host, 'port' => $sftp_port], true);

    debugLog("Connexion SFTP établie avec succès");
    echo "✅ Connexion SFTP établie.\n";
    
} catch (Throwable $e) {
    $errorMsg = "Erreur fatale lors de la connexion SFTP: " . $e->getMessage();
    echo "❌ $errorMsg\n";
    logErrorToDB($pdo ?? null, $errorMsg);
    exit(1);
}

// ---------- Création dossiers SFTP avec gestion d'erreurs ----------
try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    @$sftp->mkdir('/processed');
    @$sftp->mkdir('/errors');
} catch (Throwable $e) {
    echo "⚠️ Avertissement: Impossible de créer les dossiers SFTP: " . $e->getMessage() . "\n";
    // On continue quand même, les dossiers peuvent déjà exister
}

function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/' . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.' . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// ---------- 3) Utilitaires ----------
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

$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "🚀 Traitement des fichiers CSV...\n";

// ---------- 4) Requêtes PDO ----------
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
// Utilisation de ON DUPLICATE KEY UPDATE : ne mettre à jour que les champs de compteurs/toners
// NE PAS écraser les champs d'identification (MacAddress, Timestamp, IpAddress) qui doivent rester stables
$sql_compteur  = "
    INSERT INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)
    ON DUPLICATE KEY UPDATE
        DateInsertion = NOW(),
        Status = VALUES(Status),
        TonerBlack = VALUES(TonerBlack),
        TonerCyan = VALUES(TonerCyan),
        TonerMagenta = VALUES(TonerMagenta),
        TonerYellow = VALUES(TonerYellow),
        TotalPages = VALUES(TotalPages),
        FaxPages = VALUES(FaxPages),
        CopiedPages = VALUES(CopiedPages),
        PrintedPages = VALUES(PrintedPages),
        BWCopies = VALUES(BWCopies),
        ColorCopies = VALUES(ColorCopies),
        MonoCopies = VALUES(MonoCopies),
        BichromeCopies = VALUES(BichromeCopies),
        BWPrinted = VALUES(BWPrinted),
        BichromePrinted = VALUES(BichromePrinted),
        MonoPrinted = VALUES(MonoPrinted),
        ColorPrinted = VALUES(ColorPrinted),
        TotalColor = VALUES(TotalColor),
        TotalBW = VALUES(TotalBW),
        -- Ne mettre à jour Nom/Model/SerialNumber que si les valeurs actuelles sont NULL
        Nom = COALESCE(Nom, VALUES(Nom)),
        Model = COALESCE(Model, VALUES(Model)),
        SerialNumber = COALESCE(SerialNumber, VALUES(SerialNumber))
";
$st_compteur   = $pdo->prepare($sql_compteur);

// Note: La table import_run doit exister en base.
// Aucun DDL n'est exécuté dans ce script pour garantir la stabilité.

// ---------- 4.5) Limite de fichiers ----------
// Maximum 20 fichiers CSV par exécution (configurable via SFTP_BATCH_LIMIT)
$MAX_FILES = (int)(getenv('SFTP_BATCH_LIMIT') ?: 20);
if ($MAX_FILES <= 0) $MAX_FILES = 20;
if ($MAX_FILES > 20) $MAX_FILES = 20; // Limite absolue de 20 fichiers

$files_processed = 0;
$compteurs_inserted = 0;
$compteurs_updated = 0;
$compteurs_skipped = 0;
$files_error = 0;
$files_list = []; // Liste des fichiers traités pour le log

// ---------- 5) Parcours fichiers avec timeout et gestion d'erreurs ----------
// ====== STAGE: scan_files ======
$REMOTE_DIR = getenv('SFTP_REMOTE_DIR') ?: '/';
$REMOTE_DIR = rtrim($REMOTE_DIR, '/') ?: '/';

debugLog("Étape 5: Liste des fichiers sur le serveur SFTP", ['remote_dir' => $REMOTE_DIR]);
try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    
    echo "📂 Liste des fichiers sur le serveur SFTP (répertoire: $REMOTE_DIR)...\n";
    $listStart = microtime(true);
    $files = $sftp->nlist($REMOTE_DIR);
    $listTime = round((microtime(true) - $listStart) * 1000, 2);
    
    // Essayer aussi rawlist() pour plus de détails
    $rawFiles = false;
    try {
        $rawFiles = $sftp->rawlist($REMOTE_DIR);
    } catch (Throwable $e) {
        debugLog("rawlist() non disponible", ['error' => $e->getMessage()]);
    }
    
    $totalFiles = is_array($files) ? count($files) : 0;
    $firstFiles = is_array($files) ? array_slice($files, 0, 20) : [];
    
    log_import_run($pdo, [
        'source' => 'SFTP',
        'stage' => 'scan_files',
        'remote_dir' => $REMOTE_DIR,
        'total_files' => $totalFiles,
        'first_files' => $firstFiles,
        'nlist_result_type' => gettype($files),
        'rawlist_available' => $rawFiles !== false,
        'duration_ms' => $listTime,
    ], true);
    
    debugLog("Résultat de nlist('$REMOTE_DIR')", [
        'result' => $files === false ? 'false' : 'array',
        'count' => $totalFiles,
        'duration_ms' => $listTime,
        'first_20' => $firstFiles
    ]);
    
    if ($files === false) {
        $errorMsg = "Impossible de lister les fichiers du dossier SFTP: $REMOTE_DIR";
        debugLog("ERREUR FATALE", ['error' => $errorMsg]);
        debug_die($pdo, 'scan_files', $errorMsg, [
            'remote_dir' => $REMOTE_DIR,
        ], 6);
    }
    
    if (!is_array($files)) {
        $errorMsg = "nlist('$REMOTE_DIR') n'a pas retourné un tableau (type: " . gettype($files) . ")";
        debugLog("ERREUR FATALE", ['error' => $errorMsg, 'type' => gettype($files)]);
        debug_die($pdo, 'scan_files', $errorMsg, [
            'remote_dir' => $REMOTE_DIR,
            'result_type' => gettype($files),
        ], 6);
    }
    
    echo "✅ $totalFiles entrées trouvées dans $REMOTE_DIR\n";
    debugLog("Fichiers listés avec succès", ['total' => $totalFiles]);
    
} catch (Throwable $e) {
    $errorMsg = "Erreur lors de la liste des fichiers SFTP: " . $e->getMessage();
    debugLog("ERREUR FATALE", [
        'error' => $errorMsg,
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    debug_die($pdo, 'scan_files', $errorMsg, [
        'remote_dir' => $REMOTE_DIR,
        'exception' => get_class($e),
    ], 6);
}

// Pattern de matching : COPIEUR_MAC-<MAC12>_YYYYMMDD_HHMMSS.csv
$CSV_PATTERN = '/^COPIEUR_MAC-([A-F0-9]{12})_(\d{8})_(\d{6})\.csv$/i';

// Initialiser les variables de comptage
$skippedNonMatching = 0;
$alreadyProcessed = 0;
$downloadErrors = 0;
$processedFiles = [];
$csvFiles = [];

if (is_array($files) && count($files) > 0) {
    // ====== STAGE: match_debug ======
    debugLog("Étape 5.1: Filtrage des fichiers CSV");
    $csvFiles = [];
    $matchDebug = [];
    $skippedNonMatching = 0;
    $skippedDirs = 0;
    $first20ForDebug = array_slice($files, 0, 20);
    
    foreach ($first20ForDebug as $entry) {
        $matchInfo = [
            'filename' => $entry,
            'match' => false,
            'reason' => null,
        ];
        
        if ($entry === '.' || $entry === '..') {
            $matchInfo['reason'] = 'directory_entry';
            $skippedDirs++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        // Vérifier l'extension
        if (!preg_match('/\.csv$/i', $entry)) {
            $matchInfo['reason'] = 'wrong_extension';
            $skippedNonMatching++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        // Vérifier le pattern complet
        if (!preg_match($CSV_PATTERN, $entry, $matches)) {
            $matchInfo['reason'] = 'pattern_mismatch';
            $skippedNonMatching++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        // Extraire mac_norm (12 caractères hexadécimaux), date (YYYYMMDD), heure (HHMMSS)
        $macRaw = $matches[1] ?? null;
        $dateStr = $matches[2] ?? null; // YYYYMMDD
        $timeStr = $matches[3] ?? null; // HHMMSS
        
        if (empty($macRaw) || strlen($macRaw) !== 12 || !preg_match('/^[A-F0-9]{12}$/i', $macRaw)) {
            $matchInfo['reason'] = 'invalid_mac';
            $skippedNonMatching++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        if (empty($dateStr) || strlen($dateStr) !== 8 || !preg_match('/^\d{8}$/', $dateStr)) {
            $matchInfo['reason'] = 'invalid_date_format';
            $skippedNonMatching++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        if (empty($timeStr) || strlen($timeStr) !== 6 || !preg_match('/^\d{6}$/', $timeStr)) {
            $matchInfo['reason'] = 'invalid_time_format';
            $skippedNonMatching++;
            $matchDebug[] = $matchInfo;
            continue;
        }
        
        // Normaliser la MAC (uppercase, sans tirets)
        $macNorm = strtoupper($macRaw);
        
        // Construire Timestamp au format YYYY-MM-DD HH:MM:SS
        $year = substr($dateStr, 0, 4);
        $month = substr($dateStr, 4, 2);
        $day = substr($dateStr, 6, 2);
        $hour = substr($timeStr, 0, 2);
        $minute = substr($timeStr, 2, 2);
        $second = substr($timeStr, 4, 2);
        $timestamp = "$year-$month-$day $hour:$minute:$second";
        
        $matchInfo['match'] = true;
        $matchInfo['mac_norm'] = $macNorm;
        $matchInfo['date'] = $dateStr;
        $matchInfo['time'] = $timeStr;
        $matchInfo['timestamp'] = $timestamp;
        $matchDebug[] = $matchInfo;
    }
    
    log_import_run($pdo, [
        'source' => 'SFTP',
        'stage' => 'match_debug',
        'pattern' => $CSV_PATTERN,
        'first_20_debug' => $matchDebug,
        'skipped_dirs' => $skippedDirs,
        'skipped_nonmatching' => $skippedNonMatching,
    ], true);
    
    // Filtrer tous les fichiers (pas seulement les 20 premiers)
    $matchedFiles = [];
    foreach ($files as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!preg_match($CSV_PATTERN, $entry, $fileMatches)) {
            $skippedNonMatching++;
            continue;
        }
        
        // Extraire les informations du nom de fichier
        $macRaw = $fileMatches[1] ?? null;
        $dateStr = $fileMatches[2] ?? null;
        $timeStr = $fileMatches[3] ?? null;
        
        if (empty($macRaw) || empty($dateStr) || empty($timeStr)) {
            $skippedNonMatching++;
            continue;
        }
        
        // Normaliser la MAC
        $macNorm = strtoupper($macRaw);
        
        // Construire Timestamp
        $year = substr($dateStr, 0, 4);
        $month = substr($dateStr, 4, 2);
        $day = substr($dateStr, 6, 2);
        $hour = substr($timeStr, 0, 2);
        $minute = substr($timeStr, 2, 2);
        $second = substr($timeStr, 4, 2);
        $timestamp = "$year-$month-$day $hour:$minute:$second";
        
        $matchedFiles[] = [
            'filename' => $entry,
            'mac_norm' => $macNorm,
            'timestamp' => $timestamp,
        ];
        
        $csvFiles[] = $entry;
    }
    
    // ====== STAGE: match_files ======
    log_import_run($pdo, [
        'source' => 'SFTP',
        'stage' => 'match_files',
        'matched_files' => count($matchedFiles),
        'matched_details' => array_slice($matchedFiles, 0, 20), // Limiter à 20 pour le log
    ], true);
    
    debugLog("Fichiers CSV filtrés", [
        'total_files' => count($files),
        'csv_files' => count($csvFiles),
        'matched_files' => count($matchedFiles),
        'skipped_nonmatching' => $skippedNonMatching,
        'max_limit' => $MAX_FILES,
        'pattern' => $CSV_PATTERN
    ]);
    
    // Vérifier que matched_files > 0
    if (count($matchedFiles) === 0) {
        echo "⚠️ Aucun fichier ne correspond au pattern $CSV_PATTERN\n";
        log_import_run($pdo, [
            'source' => 'SFTP',
            'stage' => 'match_files',
            'error' => 'Aucun fichier ne correspond au pattern',
            'pattern' => $CSV_PATTERN,
            'total_files' => count($files),
        ], false);
    } else {
        echo "✅ " . count($matchedFiles) . " fichier(s) correspond(ent) au pattern\n";
    }
    
    // Trier par nom (pour traiter dans l'ordre chronologique si possible)
    sort($csvFiles);
    
    // Limiter à MAX_FILES
    if (count($csvFiles) > $MAX_FILES) {
        $csvFiles = array_slice($csvFiles, 0, $MAX_FILES);
        echo "ℹ️ Limitation à $MAX_FILES fichiers CSV (limite maximale)\n";
        debugLog("Fichiers limités", ['avant' => count($csvFiles) + (count($files) - count($csvFiles)), 'apres' => $MAX_FILES]);
    }
    
    $found = false;
    $processedFiles = [];
    $alreadyProcessed = 0;
    $downloadErrors = 0;
    
    // Créer un index des fichiers matchés pour récupérer mac_norm et timestamp
    $fileInfoIndex = [];
    foreach ($matchedFiles as $info) {
        $fileInfoIndex[$info['filename']] = $info;
    }
    
    foreach ($csvFiles as $entry) {
        try {
            checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
            
            $found = true;
            $files_processed++;
            $remote = ($REMOTE_DIR === '/' ? '' : $REMOTE_DIR) . '/' . $entry;
            
            // Récupérer les infos extraites du nom de fichier
            $fileInfo = $fileInfoIndex[$entry] ?? null;
            $macNormFromFilename = $fileInfo['mac_norm'] ?? null;
            $timestampFromFilename = $fileInfo['timestamp'] ?? null;
            
            // ====== STAGE: process_file ======
            $fileDebug = [
                'filename' => $entry,
                'remote' => $remote,
                'download_ok' => false,
                'parse_ok' => false,
                'mac_norm' => $macNormFromFilename,
                'timestamp' => $timestampFromFilename,
                'db_inserted' => false,
                'db_updated' => false,
                'moved_to' => null,
            ];
            
            echo "📥 Téléchargement de $entry...\n";
            $tmp = tempnam(sys_get_temp_dir(), 'csv_');
            
            // Tentative de téléchargement avec gestion d'erreur
            debugLog("Téléchargement SFTP", ['remote' => $remote, 'local' => $tmp]);
            $downloadStart = microtime(true);
            $downloadSuccess = false;
            try {
                $downloadSuccess = $sftp->get($remote, $tmp);
                $downloadTime = round((microtime(true) - $downloadStart) * 1000, 2);
                debugLog("Résultat du téléchargement", [
                    'success' => $downloadSuccess,
                    'duration_ms' => $downloadTime,
                    'file_size' => $downloadSuccess && file_exists($tmp) ? filesize($tmp) : 'N/A'
                ]);
            } catch (Throwable $e) {
                $downloadTime = round((microtime(true) - $downloadStart) * 1000, 2);
                debugLog("Exception lors du téléchargement", [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'duration_ms' => $downloadTime
                ]);
                echo "❌ Exception lors du téléchargement de $entry: " . $e->getMessage() . "\n";
                $downloadSuccess = false;
            }
            
            if (!$downloadSuccess) {
                $fileDebug['download_ok'] = false;
                $fileDebug['moved_to'] = 'errors';
                $downloadErrors++;
                echo "❌ Erreur téléchargement $entry\n";
                try {
                    [$moved, $target] = sftp_safe_move($sftp, $remote, '/errors');
                    $fileDebug['moved_to'] = $moved ? $target : 'errors_failed';
                } catch (Throwable $e) {
                    echo "⚠️ Impossible de déplacer $entry vers /errors: " . $e->getMessage() . "\n";
                    $fileDebug['move_error'] = $e->getMessage();
                }
                @unlink($tmp);
                $files_error++;
                log_import_run($pdo, array_merge(['source' => 'SFTP', 'stage' => 'process_file'], $fileDebug), false);
                continue;
            }
            
            $fileDebug['download_ok'] = true;
            echo "✅ Téléchargement réussi: $entry\n";
            
            // Parser le CSV
            debugLog("Parsing du fichier CSV", ['file' => $entry, 'tmp_path' => $tmp]);
            try {
                if (!file_exists($tmp)) {
                    throw new RuntimeException("Fichier temporaire introuvable: $tmp");
                }
                $fileSize = filesize($tmp);
                debugLog("Fichier temporaire vérifié", ['size' => $fileSize, 'readable' => is_readable($tmp)]);
                
                $parseStart = microtime(true);
                $row = parse_csv_kv($tmp);
                $parseTime = round((microtime(true) - $parseStart) * 1000, 2);
                debugLog("CSV parsé", ['duration_ms' => $parseTime, 'keys_count' => count($row)]);
                
                @unlink($tmp);
                
                // Extraire les valeurs du CSV
                $values = [];
                foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;
                
                $fileDebug['parse_ok'] = true;
                debugLog("Valeurs extraites du CSV", [
                    'MacAddress' => $values['MacAddress'] ?? 'NULL',
                    'Timestamp' => $values['Timestamp'] ?? 'NULL',
                    'total_fields' => count($values)
                ]);

                // Utiliser mac_norm et timestamp extraits du nom de fichier (priorité sur CSV)
                if (empty($macNormFromFilename) || empty($timestampFromFilename)) {
                    $fileDebug['parse_ok'] = false;
                    $fileDebug['parse_error'] = 'missing_filename_info';
                    $fileDebug['moved_to'] = 'errors';
                    $errorMsg = "Impossible d'extraire mac_norm/timestamp du nom de fichier pour $entry";
                    debugLog("SKIP fichier - info nom fichier manquante", [
                        'error' => $errorMsg,
                        'mac_norm' => $macNormFromFilename ?: 'NULL/EMPTY',
                        'timestamp' => $timestampFromFilename ?: 'NULL/EMPTY',
                        'filename' => $entry
                    ]);
                    echo "⚠️ $errorMsg → /errors\n";
                    try {
                        [$moved, $target] = sftp_safe_move($sftp, $remote, '/errors');
                        $fileDebug['moved_to'] = $moved ? $target : 'errors_failed';
                    } catch (Throwable $e) {
                        debugLog("Erreur déplacement fichier", ['error' => $e->getMessage()]);
                        $fileDebug['move_error'] = $e->getMessage();
                    }
                    $compteurs_skipped++;
                    $files_error++;
                    log_import_run($pdo, array_merge(['source' => 'SFTP', 'stage' => 'process_file'], $fileDebug), false);
                    continue;
                }
                
                // Utiliser mac_norm et timestamp du nom de fichier (priorité)
                // Le MacAddress du CSV peut être utilisé comme fallback, mais on préfère celui du nom de fichier
                $macAddress = $macNormFromFilename;
                $timestamp = $timestampFromFilename;
                
                // S'assurer que les valeurs sont bien définies
                $values['MacAddress'] = $macAddress;
                $values['Timestamp'] = $timestamp;
                $fileDebug['mac_norm'] = $macAddress;
                $fileDebug['timestamp'] = $timestamp;
                
                debugLog("Valeurs finales utilisées", [
                    'MacAddress' => $macAddress,
                    'Timestamp' => $timestamp,
                    'source' => 'filename'
                ]);
            } catch (Throwable $e) {
                @unlink($tmp);
                $errorMsg = "Erreur lors du parsing CSV pour $entry: " . $e->getMessage();
                debugLog("ERREUR parsing", [
                    'error' => $errorMsg,
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                echo "❌ $errorMsg\n";
                try {
                    sftp_safe_move($sftp, $remote, '/errors');
                } catch (Throwable $moveErr) {
                    debugLog("Erreur déplacement fichier", ['error' => $moveErr->getMessage()]);
                }
                $files_error++;
                continue;
            }
            
        } catch (RuntimeException $e) {
            // Timeout - arrêter le traitement
            echo "⏱️ TIMEOUT: Arrêt du traitement des fichiers\n";
            debugLog("TIMEOUT détecté", ['error' => $e->getMessage()]);
            break;
        } catch (Throwable $e) {
            echo "❌ Erreur lors du traitement de $entry: " . $e->getMessage() . "\n";
            debugLog("ERREUR traitement fichier", [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $files_error++;
            continue;
        }

        // Insertion en base de données
        $fileSize = file_exists($tmp ?? '') ? filesize($tmp) : 'N/A';
        $fileDate = date('Y-m-d H:i:s', filemtime($tmp ?? __FILE__));
        debugLog("Insertion en base de données", [
            'MacAddress' => $values['MacAddress'],
            'Timestamp' => $values['Timestamp'],
            'filename' => $entry,
            'file_size' => $fileSize,
            'file_date' => $fileDate
        ]);
        try {
            // Vérifier le timeout avant l'insertion
            checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
            
            $insertStart = microtime(true);
            $pdo->beginTransaction();
            debugLog("Transaction démarrée", ['mac' => $values['MacAddress'], 'timestamp' => $values['Timestamp']]);
            
            $binds = [];
            foreach ($FIELDS as $f) {
                $binds[":$f"] = $values[$f] ?? null;
            }
            
            debugLog("Exécution de la requête INSERT", [
                'binds_count' => count($binds),
                'mac' => $values['MacAddress'],
                'timestamp' => $values['Timestamp']
            ]);
            
            $st_compteur->execute($binds);
            $insertTime = round((microtime(true) - $insertStart) * 1000, 2);
            
            $rowCount = $st_compteur->rowCount();
            debugLog("Requête exécutée", [
                'row_count' => $rowCount,
                'duration_ms' => $insertTime,
                'mac' => $values['MacAddress'],
                'timestamp' => $values['Timestamp']
            ]);

            // Avec ON DUPLICATE KEY UPDATE :
            // - rowCount = 1 : Nouvel enregistrement inséré
            // - rowCount = 2 : Enregistrement mis à jour (doublon détecté via UNIQUE constraint)
            if ($rowCount === 1) {
                $compteurs_inserted++;
                $fileDebug['db_inserted'] = true;
                echo "✅ Compteur INSÉRÉ pour {$values['MacAddress']} ({$values['Timestamp']})\n";
                debugLog("Compteur inséré avec succès", [
                    'inserted' => 1,
                    'total_inserted' => $compteurs_inserted
                ]);
            } elseif ($rowCount === 2) {
                $compteurs_updated++;
                $fileDebug['db_updated'] = true;
                echo "ℹ️ Déjà présent: compteur MIS À JOUR pour {$values['MacAddress']} ({$values['Timestamp']})\n";
                debugLog("Compteur mis à jour (ON DUPLICATE KEY UPDATE)", [
                    'updated' => 1,
                    'total_updated' => $compteurs_updated,
                    'row_count' => $rowCount
                ]);
            } else {
                $compteurs_skipped++;
                $alreadyProcessed++;
                echo "⚠️ Aucune modification: compteur pour {$values['MacAddress']} ({$values['Timestamp']}) - rowCount=$rowCount\n";
                debugLog("Aucune modification effectuée", [
                    'skipped' => 1,
                    'total_skipped' => $compteurs_skipped,
                    'row_count' => $rowCount
                ]);
            }

            $pdo->commit();
            debugLog("Transaction commitée", ['mac' => $values['MacAddress']]);

            // Ajouter à la liste des fichiers traités (même si le déplacement échoue)
            $files_list[] = $entry;
            
            try {
                [$okMove, $target] = sftp_safe_move($sftp, $remote, '/processed');
                if (!$okMove) {
                    $fileDebug['moved_to'] = 'processed_failed';
                    echo "⚠️ Impossible de déplacer $entry vers /processed\n";
                } else {
                    $fileDebug['moved_to'] = $target;
                    echo "📦 Archivé: $entry → /processed\n";
                }
            } catch (Throwable $e) {
                $fileDebug['moved_to'] = 'processed_error';
                $fileDebug['move_error'] = $e->getMessage();
                echo "⚠️ Erreur lors du déplacement de $entry: " . $e->getMessage() . "\n";
                // On continue quand même, le fichier est déjà traité
            }
            
            $processedFiles[] = $fileDebug;
            log_import_run($pdo, array_merge(['source' => 'SFTP', 'stage' => 'process_file'], $fileDebug), true);

        } catch (RuntimeException $e) {
            // Timeout - arrêter le traitement
            if (isset($pdo) && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackErr) {
                    // Ignorer les erreurs de rollback
                }
            }
            echo "⏱️ TIMEOUT: Arrêt du traitement (fichier: $entry)\n";
            break;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    debugLog("Transaction rollback effectué");
                } catch (Throwable $rollbackErr) {
                    debugLog("Erreur lors du rollback", ['error' => $rollbackErr->getMessage()]);
                }
            }
            
            // Log détaillé de l'erreur SQL avec contexte complet
            $errorDetails = [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mac' => $values['MacAddress'] ?? 'N/A',
                'timestamp' => $values['Timestamp'] ?? 'N/A',
                'filename' => $entry ?? 'N/A'
            ];
            
            if ($e instanceof PDOException) {
                $errorInfo = $e->errorInfo ?? $e->getCode();
                if (is_array($errorInfo)) {
                    $errorDetails['sql_state'] = $errorInfo[0] ?? 'N/A';
                    $errorDetails['driver_code'] = $errorInfo[1] ?? 'N/A';
                    $errorDetails['driver_message'] = $errorInfo[2] ?? 'N/A';
                } else {
                    $errorDetails['pdo_code'] = $errorInfo;
                }
                
                // Afficher la requête SQL avec les valeurs pour debug
                $sqlDebug = $sql_compteur;
                foreach ($binds ?? [] as $key => $value) {
                    $displayValue = is_null($value) ? 'NULL' : (is_string($value) ? "'" . substr($value, 0, 50) . "'" : $value);
                    $sqlDebug = str_replace($key, $displayValue, $sqlDebug);
                }
                $errorDetails['sql_debug'] = substr($sqlDebug, 0, 500); // Limiter la taille
                
                debugLog("ERREUR PDO DÉTAILLÉE - PREMIÈRE ERREUR SQL", $errorDetails);
            } else {
                debugLog("ERREUR NON-PDO", $errorDetails);
            }
            
            echo "❌ [ERREUR PDO] " . $e->getMessage() . "\n";
            if ($e instanceof PDOException && isset($errorInfo) && is_array($errorInfo) && isset($errorInfo[2])) {
                echo "   SQL Error: " . $errorInfo[2] . "\n";
                echo "   SQL State: " . ($errorInfo[0] ?? 'N/A') . "\n";
                echo "   Driver Code: " . ($errorInfo[1] ?? 'N/A') . "\n";
            }
            
            $compteurs_skipped++;
            $compteurs_skipped++;
            try {
                sftp_safe_move($sftp, $remote, '/errors');
            } catch (Throwable $moveErr) {
                echo "⚠️ Impossible de déplacer $entry vers /errors: " . $moveErr->getMessage() . "\n";
            }
            $files_error++;
        }
    }

    if (!$found) {
        echo "⚠️ Aucun fichier CSV trouvé sur le SFTP.\n";
        log_import_run($pdo, [
            'source' => 'SFTP',
            'stage' => 'scan_files',
            'remote_dir' => $REMOTE_DIR,
            'total_files' => $totalFiles ?? 0,
            'matched_files' => 0,
            'pattern' => $CSV_PATTERN,
            'msg' => 'Aucun fichier CSV correspondant au pattern trouvé',
        ], true);
    }
}

// ---------- 6) Journal du run ----------
try {
    $duration = time() - $scriptStartTime;
    // Créer un message JSON structuré pour différencier les sources
    $summaryData = [
        'source' => 'SFTP',
        'remote_dir' => $REMOTE_DIR,
        'pid' => IMPORT_PID,
        'total_files' => $totalFiles ?? 0,
        'matched_files' => count($csvFiles ?? []),
        'processed_files' => $files_processed,
        'files_error' => $files_error,
        'files_success' => max(0, $files_processed - $files_error),
        'skipped_nonmatching' => $skippedNonMatching ?? 0,
        'already_processed' => $alreadyProcessed ?? 0,
        'download_errors' => $downloadErrors ?? 0,
        'inserted' => $compteurs_inserted,
        'updated' => $compteurs_updated,
        'skipped' => $compteurs_skipped,
        'max_files_limit' => $MAX_FILES,
        'pattern' => $CSV_PATTERN,
        'duration_sec' => $duration,
        'first_files' => $firstFiles ?? [],
        'files' => array_slice($files_list, 0, 20), // Limiter à 20 pour éviter un JSON trop gros
        'processed_details' => array_slice($processedFiles ?? [], 0, 10) // Limiter à 10 détails
    ];
    
    $summary = json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Aussi retourner en stdout pour le dashboard
    echo "\n=== RÉSULTAT FINAL ===\n";
    echo json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    // skipped = fichiers en erreur + compteurs ignorés
    $totalSkipped = $files_error + $compteurs_skipped;
    $isOk = ($files_error === 0 && $files_processed > 0 && ($compteurs_inserted > 0 || $compteurs_updated > 0)) 
            || ($files_processed === 0); // OK si aucun fichier à traiter
    $stmt->execute([
        ':imported' => $compteurs_inserted, // Nombre de compteurs réellement insérés
        ':skipped'  => $totalSkipped,
        ':ok'       => $isOk ? 1 : 0,
        ':msg'      => $summary,
    ]);
    echo "📝 [IMPORT_RUN] Ligne insérée: $files_processed fichiers traités, $compteurs_inserted insérés, $compteurs_updated mis à jour, $totalSkipped ignorés\n";
    debugLog("Import terminé avec succès", [
        'inserted' => $compteurs_inserted,
        'updated' => $compteurs_updated,
        'skipped' => $totalSkipped,
        'duration_sec' => $duration
    ]);
} catch (Throwable $e) {
    echo "❌ [IMPORT_RUN] Erreur INSERT: " . $e->getMessage() . "\n";
    debugLog("ERREUR lors de la journalisation", [
        'error' => $e->getMessage(),
        'exception' => get_class($e)
    ]);
}

// ---------- Gestion d'erreur finale ----------
// S'assurer qu'une erreur est toujours loggée en cas d'échec
if ($files_error > 0 && $compteurs_inserted === 0 && $files_processed > 0) {
    try {
        $errorSummary = json_encode([
            'source' => 'SFTP',
            'error' => "Tous les fichiers ont échoué ($files_error erreur(s) sur $files_processed fichier(s))",
            'files_processed' => $files_processed,
            'files_error' => $files_error,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), 0, :skipped, 0, :msg)
        ")->execute([
            ':skipped' => $files_error,
            ':msg' => $errorSummary
        ]);
    } catch (Throwable $e) {
        // Ignorer les erreurs de log final
    }
}

echo "-----------------------------\n";
$duration = time() - $scriptStartTime;
debugLog("=== FIN DU SCRIPT D'IMPORT SFTP ===", [
    'pid' => IMPORT_PID,
    'script_end' => date('Y-m-d H:i:s'),
    'duration_sec' => $duration,
    'files_processed' => $files_processed,
    'compteurs_inserted' => $compteurs_inserted,
    'compteurs_updated' => $compteurs_updated,
    'compteurs_skipped' => $compteurs_skipped,
    'files_error' => $files_error
]);
echo "✅ Traitement terminé en {$duration} seconde(s).\n";
echo "   → $compteurs_inserted insérés, $compteurs_updated mis à jour, $compteurs_skipped ignorés\n";
