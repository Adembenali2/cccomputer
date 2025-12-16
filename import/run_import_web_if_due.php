<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Activer les logs d'erreur
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Ne pas afficher dans la réponse JSON
ini_set('log_errors', '1');

$projectRoot = dirname(__DIR__);

// Fonction de debug - centralisée dans includes/debug_helpers.php
require_once __DIR__ . '/../includes/debug_helpers.php';
// Wrapper pour préserver la compatibilité avec les appels existants
if (!function_exists('debugLog')) {
    function debugLog(string $message, array $context = []): void {
        \debugLog($message, $context, 'run_import_web_if_due', false);
    }
}

debugLog("=== DÉBUT run_import_web_if_due.php (IONOS) ===", ['project_root' => $projectRoot]);

try {
    require_once $projectRoot . '/includes/auth.php';
    debugLog("includes/auth.php chargé");
} catch (Throwable $e) {
    debugLog("ERREUR chargement auth.php", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'ran' => false,
        'reason' => 'auth_failed',
        'error' => 'Erreur chargement auth.php: ' . $e->getMessage(),
        'message' => 'Erreur d\'authentification'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once $projectRoot . '/includes/db.php';
    debugLog("includes/db.php chargé");
} catch (Throwable $e) {
    debugLog("ERREUR chargement db.php", ['error' => $e->getMessage()]);
    echo json_encode(['ran' => false, 'error' => 'Erreur chargement db.php: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Table KV pour anti-bouclage
 */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_kv (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/**
 * Table import_run pour logging
 */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS import_run (
    id INT NOT NULL AUTO_INCREMENT,
    ran_at DATETIME NOT NULL,
    imported INT NOT NULL,
    skipped INT NOT NULL,
    ok TINYINT(1) NOT NULL,
    msg TEXT,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Intervalle minimum entre deux exécutions (en secondes)
// Par défaut 60 secondes (1 minute) pour IONOS
$INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 60);
$key      = 'ionos_last_run'; // Clé dédiée IONOS (séparée de SFTP)

// ---------- VERROU ANTI-PARALLÉLISME avec GET_LOCK ----------
// Lock dédié IONOS (séparé de SFTP)
$lockName = 'import_ionos';
$lockAcquired = false;
try {
    // Tentative d'acquisition du verrou (timeout 0 = échec immédiat si verrouillé)
    $stmtLock = $pdo->prepare("SELECT GET_LOCK(:lock_name, 0) as lock_result");
    $stmtLock->execute([':lock_name' => $lockName]);
    $lockResult = $stmtLock->fetch(PDO::FETCH_ASSOC);
    $lockAcquired = (int)($lockResult['lock_result'] ?? 0) === 1;
    
    if (!$lockAcquired) {
        debugLog("Verrou non acquis - import IONOS déjà en cours");
        echo json_encode([
            'ok' => false,
            'ran' => false,
            'reason' => 'locked',
            'message' => 'Un import IONOS est déjà en cours (verrou MySQL actif)',
            'last_run_at' => null,
            'next_due_in_sec' => null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    debugLog("Verrou MySQL acquis", ['lock_name' => $lockName]);
} catch (Throwable $e) {
    debugLog("ERREUR acquisition verrou", ['error' => $e->getMessage()]);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur lors de l\'acquisition du verrou: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Fonction pour libérer le verrou
$releaseLock = function() use ($pdo, $lockName, &$lockAcquired) {
    if ($lockAcquired) {
        try {
            $stmtRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
            $stmtRelease->execute([':lock_name' => $lockName]);
            debugLog("Verrou MySQL libéré");
            $lockAcquired = false;
        } catch (Throwable $e) {
            debugLog("ERREUR libération verrou", ['error' => $e->getMessage()]);
        }
    }
};

// Vérifier le mode force (debug) - support GET et POST
$force = (isset($_GET['force']) && $_GET['force'] === '1') || (isset($_POST['force']) && $_POST['force'] === '1');
$forced = false;

$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$lastTimestamp = $last ? strtotime((string)$last) : 0;
$elapsed = time() - $lastTimestamp;
$due  = $elapsed >= $INTERVAL;
$nextDueInSec = $due ? 0 : ($INTERVAL - $elapsed);

// Si force=1, ignorer le check not_due mais conserver le lock et l'auth
if (!$due && !$force) {
  $releaseLock();
  echo json_encode([
    'ok' => false, 
    'ran' => false,
    'reason' => 'not_due', 
    'last_run' => $last,
    'last_run_at' => $last ? date('Y-m-d H:i:s', $lastTimestamp) : null,
    'next_due_in_sec' => $nextDueInSec,
    'forced' => false,
    'message' => "Import IONOS non dû (dernier: $last, interval: {$INTERVAL}s, écoulé: {$elapsed}s)"
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Si force=1 et not_due, on force l'exécution (bypass "due" mais garde auth + lock)
if (!$due && $force) {
  $forced = true;
  debugLog("Mode FORCE activé - import IONOS exécuté même si not_due", [
    'last_run' => $last,
    'interval' => $INTERVAL,
    'elapsed' => $elapsed,
    'next_due_in_sec' => $nextDueInSec
  ]);
}

// Mettre à jour app_kv même en mode force pour éviter les imports trop fréquents
$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

// Exécuter le script d'import
$php = PHP_BINARY ?: 'php';
$scriptPath = __DIR__ . '/import_ancien_http.php';
if (!is_file($scriptPath)) {
  echo json_encode([
    'ran' => false,
    'error' => 'Script import_ancien_http.php introuvable',
    'path' => $scriptPath
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$cmd = escapeshellcmd($php) . ' ' . escapeshellarg($scriptPath);
$desc = [
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];

// Passer les variables d'environnement
$env = $_ENV + $_SERVER;

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
  $write = null;
  $except = null;
  
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

// Préparer le message d'erreur si nécessaire
$errorMsg = null;
if ($timeoutReached) {
  $errorMsg = "TIMEOUT: Le processus d'import IONOS a dépassé la limite de {$TIMEOUT_SEC} secondes";
} elseif (!$success && !empty($err)) {
  $errorMsg = trim($err);
} elseif ($code !== 0 && $code !== null) {
  $errorMsg = "Le processus s'est terminé avec le code d'erreur: $code";
}

// Parser la sortie pour extraire les statistiques (si disponibles)
$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = [];

if (preg_match('/(\d+) insérés/', $out, $m)) {
    $inserted = (int)$m[1];
}
if (preg_match('/(\d+) mis à jour/', $out, $m)) {
    $updated = (int)$m[1];
}
if (preg_match('/(\d+) ignorés/', $out, $m)) {
    $skipped = (int)$m[1];
}

// Extraire les erreurs depuis stderr
if (!empty($err)) {
    $errors[] = trim($err);
}

// Calculer next_due_in_sec pour la réponse
$stmtNext = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmtNext->execute([$key]);
$nextLast = $stmtNext->fetchColumn();
$nextLastTimestamp = $nextLast ? strtotime((string)$nextLast) : 0;
$nextElapsed = time() - $nextLastTimestamp;
$nextDueInSec = ($INTERVAL - $nextElapsed) > 0 ? ($INTERVAL - $nextElapsed) : 0;

// Réponse JSON structurée (identique au format SFTP)
$response = [
  'ok'           => $success,
  'ran'          => true,
  'forced'       => $forced,
  'reason'        => $success ? 'started' : ($timeoutReached ? 'timeout' : 'script_error'),
  'inserted'     => $inserted,
  'updated'      => $updated,
  'skipped'      => $skipped,
  'errors'       => $errors,
  'stdout'       => trim($out),
  'stderr'       => trim($err),
  'last_run'     => date('Y-m-d H:i:s'),
  'last_run_at'  => date('Y-m-d H:i:s'),
  'next_due_in_sec' => $nextDueInSec,
  'code'         => $code,
  'timeout'      => $timeoutReached,
  'error'        => $errorMsg,
  'duration_ms'  => (time() - $startTime) * 1000,
  'message'      => $success 
    ? "Import IONOS exécuté avec succès (inséré: $inserted, mis à jour: $updated, ignoré: $skipped)"
    : ($errorMsg ?: "Import IONOS échoué (code: $code)")
];

if ($errorMsg) {
    $response['where'] = 'script_execution';
    $response['details'] = [
        'code' => $code,
        'stdout_length' => strlen($out),
        'stderr_length' => strlen($err)
    ];
}

// Logging dans import_run avec type ionos
try {
    $logMsg = json_encode([
        'type' => 'ionos',
        'detail' => $response['message'],
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'success' => $success,
        'error' => $errorMsg,
        'duration_ms' => $response['duration_ms']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    $stmtLog = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), ?, ?, ?, ?)
    ");
    $stmtLog->execute([
        $inserted + $updated,
        $skipped,
        $success ? 1 : 0,
        $logMsg
    ]);
} catch (Throwable $e) {
    debugLog("ERREUR lors du logging dans import_run", ['error' => $e->getMessage()]);
    // Ne pas bloquer la réponse si le logging échoue
}

// Libérer le verrou dans TOUS les cas
try {
    if (isset($releaseLock) && is_callable($releaseLock)) {
        $releaseLock();
    }
} catch (Throwable $e) {
    debugLog("ERREUR lors de la libération finale du verrou", ['error' => $e->getMessage()]);
}

debugLog("=== FIN run_import_web_if_due.php (IONOS) ===", [
  'success' => $success,
  'code' => $code,
  'timeout' => $timeoutReached,
  'error' => $errorMsg,
  'inserted' => $inserted,
  'updated' => $updated,
  'skipped' => $skipped,
  'duration_sec' => time() - $startTime
]);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

