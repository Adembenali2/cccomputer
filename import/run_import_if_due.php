<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Activer les logs d'erreur
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Ne pas afficher dans la réponse JSON
ini_set('log_errors', '1');

$projectRoot = dirname(__DIR__); // ← racine du projet (pas besoin de remonter plus)

// Fonction de debug - centralisée dans includes/debug_helpers.php
require_once __DIR__ . '/../includes/debug_helpers.php';
// Wrapper pour préserver la compatibilité avec les appels existants
if (!function_exists('debugLog')) {
    function debugLog(string $message, array $context = []): void {
        \debugLog($message, $context, 'run_import_if_due', false);
    }
}

debugLog("=== DÉBUT run_import_if_due.php ===", ['project_root' => $projectRoot]);

try {
    require_once $projectRoot . '/includes/auth.php';
    debugLog("includes/auth.php chargé");
} catch (Throwable $e) {
    debugLog("ERREUR chargement auth.php", ['error' => $e->getMessage()]);
    echo json_encode(['ran' => false, 'error' => 'Erreur chargement auth.php: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
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

$INTERVAL = (int)(getenv('SFTP_IMPORT_INTERVAL_SEC') ?: 20);
$key      = 'sftp_last_run';

// ---------- VERROU ANTI-PARALLÉLISME avec GET_LOCK ----------
$lockName = 'import_compteur_sftp';
$lockAcquired = false;
try {
    // Tentative d'acquisition du verrou (timeout 0 = échec immédiat si verrouillé)
    $stmtLock = $pdo->query("SELECT GET_LOCK('$lockName', 0) as lock_result");
    $lockResult = $stmtLock->fetch(PDO::FETCH_ASSOC);
    $lockAcquired = (int)($lockResult['lock_result'] ?? 0) === 1;
    
    if (!$lockAcquired) {
        debugLog("Verrou non acquis - import déjà en cours");
        echo json_encode([
            'ok' => false,
            'reason' => 'locked',
            'message' => 'Un import est déjà en cours (verrou MySQL actif)'
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

// Fonction pour libérer le verrou (utilisée dans finally)
$releaseLock = function() use ($pdo, $lockName, &$lockAcquired) {
    if ($lockAcquired) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')");
            debugLog("Verrou MySQL libéré");
            $lockAcquired = false;
        } catch (Throwable $e) {
            debugLog("ERREUR libération verrou", ['error' => $e->getMessage()]);
        }
    }
};

// Log informatif (non bloquant) sur les imports récents
try {
    $stmtRunning = $pdo->prepare("
        SELECT ran_at, imported 
        FROM import_run 
        WHERE ran_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        AND msg LIKE '%\"source\":\"SFTP\"%'
        ORDER BY ran_at DESC 
        LIMIT 1
    ");
    $stmtRunning->execute();
    $recent = $stmtRunning->fetch(PDO::FETCH_ASSOC);
    if ($recent) {
        debugLog("Import récent détecté (informatif)", ['ran_at' => $recent['ran_at'], 'imported' => $recent['imported']]);
    }
} catch (Throwable $e) {
    // Log informatif uniquement, pas d'impact sur l'exécution
    debugLog("Note: Impossible de vérifier les imports récents", ['error' => $e->getMessage()]);
}

// Vérifier le mode force (debug) - support GET et POST
$force = (isset($_GET['force']) && $_GET['force'] === '1') || (isset($_POST['force']) && $_POST['force'] === '1');
$forced = false;

$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

// Si force=1, ignorer le check not_due mais conserver le lock
if (!$due && !$force) {
  $releaseLock();
  echo json_encode([
    'ok' => false, 
    'reason' => 'not_due', 
    'last_run' => $last,
    'forced' => false
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Si force=1 et not_due, on force l'exécution
if (!$due && $force) {
  $forced = true;
  debugLog("Mode FORCE activé - import exécuté même si not_due", [
    'last_run' => $last,
    'interval' => $INTERVAL,
    'elapsed' => $last ? (time() - strtotime((string)$last)) : 0
  ]);
}

// Mettre à jour app_kv même en mode force pour éviter les imports trop fréquents
$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

// Récupérer limit depuis POST ou GET (support POST pour compatibilité)
$limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

$php = PHP_BINARY ?: 'php';
debugLog("PHP binary", ['path' => $php, 'exists' => file_exists($php)]);

// CORRECTION : Le fichier upload_compteur.php se trouve dans API/scripts/, pas dans import/
$scriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
debugLog("Vérification du script", ['path' => $scriptPath, 'exists' => is_file($scriptPath), 'readable' => is_readable($scriptPath)]);

if (!is_file($scriptPath)) {
  $error = 'Script upload_compteur.php introuvable';
  debugLog("ERREUR", ['error' => $error, 'path' => $scriptPath]);
  echo json_encode([
    'ran' => false,
    'error' => $error,
    'path' => $scriptPath
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$cmd = escapeshellcmd($php) . ' ' . escapeshellarg($scriptPath);
$desc = [
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];

// passe le batch au worker SFTP
$env = $_ENV + $_SERVER + ['SFTP_BATCH_LIMIT' => (string)$limit];

// Timeout maximum : 60 secondes pour éviter les blocages
$TIMEOUT_SEC = 60;
$startTime = time();

// Forcer le working directory au root du projet pour éviter les problèmes de chemins relatifs
$cwd = $projectRoot;
if (!is_dir($cwd)) {
    debugLog("ERREUR: projectRoot n'est pas un répertoire valide", ['path' => $cwd]);
    $cwd = dirname(__DIR__); // Fallback vers le répertoire parent de import/
}

debugLog("Lancement du processus", [
    'cmd' => $cmd,
    'cwd' => $cwd,
    'limit' => $limit,
    'timeout' => $TIMEOUT_SEC,
    'project_root' => $projectRoot
]);

$proc = proc_open($cmd, $desc, $pipes, $cwd, $env);
$out = $err = '';
$code = null;
$timeoutReached = false;

if (is_resource($proc)) {
  debugLog("Processus créé avec succès");
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
      debugLog("TIMEOUT détecté", ['elapsed' => $elapsed, 'limit' => $TIMEOUT_SEC]);
      proc_terminate($proc, SIGTERM);
      // Attendre un peu pour que le processus se termine proprement
      sleep(2);
      if (proc_get_status($proc)['running']) {
        debugLog("Processus toujours actif, envoi SIGKILL");
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
    $changed = @stream_select($read, $write, $except, 1); // Timeout de 1 seconde
    
    if ($changed === false) {
      // Erreur sur stream_select, continuer quand même
      usleep(100000); // Attendre 100ms avant de réessayer
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
      // Aucune donnée disponible, attendre un peu
      usleep(100000); // 100ms
    }
  }
  
  // Lire les dernières données restantes
  $remainingOut = stream_get_contents($pipes[1]);
  $remainingErr = stream_get_contents($pipes[2]);
  if ($remainingOut !== false) $out .= $remainingOut;
  if ($remainingErr !== false) $err .= $remainingErr;
  
  // Fermer les pipes
  fclose($pipes[1]);
  fclose($pipes[2]);
  
  // Fermer le processus si pas déjà fait
  if (is_resource($proc)) {
    $code = proc_close($proc);
  }
} else {
  $err = 'Impossible de créer le processus';
  $code = -1;
  debugLog("ERREUR", ['error' => $err, 'cmd' => $cmd]);
}

// Vérifier si le processus a échoué
$success = ($code === 0 && !$timeoutReached);
if (!$success && empty($err) && !$timeoutReached) {
  $err = "Processus terminé avec le code de sortie: $code";
}

// Préparer le message d'erreur si nécessaire
$errorMsg = null;
if ($timeoutReached) {
  $errorMsg = "TIMEOUT: Le processus d'import SFTP a dépassé la limite de {$TIMEOUT_SEC} secondes";
} elseif (!$success && !empty($err)) {
  $errorMsg = trim($err);
} elseif ($code !== 0 && $code !== null) {
  $errorMsg = "Le processus s'est terminé avec le code d'erreur: $code";
}

// Parser la sortie pour extraire les statistiques
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

// Réponse JSON structurée
$response = [
  'ok'           => $success,
  'ran'          => true,
  'forced'       => $forced,
  'inserted'     => $inserted,
  'updated'      => $updated,
  'skipped'      => $skipped,
  'errors'       => $errors,
  'stdout'       => trim($out),
  'stderr'       => trim($err),
  'last_run'     => date('Y-m-d H:i:s'),
  'code'         => $code,
  'timeout'      => $timeoutReached,
  'error'        => $errorMsg,
  'duration_ms'  => (time() - $startTime) * 1000
];

if ($errorMsg) {
    $response['where'] = 'script_execution';
    $response['details'] = [
        'code' => $code,
        'stdout_length' => strlen($out),
        'stderr_length' => strlen($err)
    ];
}

// Libérer le verrou dans TOUS les cas (finally équivalent)
try {
    if (isset($releaseLock) && is_callable($releaseLock)) {
        $releaseLock();
    }
} catch (Throwable $e) {
    debugLog("ERREUR lors de la libération finale du verrou", ['error' => $e->getMessage()]);
}

debugLog("=== FIN run_import_if_due.php ===", [
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
