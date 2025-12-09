<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Activer les logs d'erreur
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Ne pas afficher dans la réponse JSON
ini_set('log_errors', '1');

$projectRoot = dirname(__DIR__); // ← racine du projet (pas besoin de remonter plus)

// Fonction de debug
function debugLog(string $message, array $context = []): void {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logMsg = "[$timestamp] [run_import_if_due] $message$contextStr\n";
    error_log($logMsg);
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

$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

if (!$due) {
  echo json_encode(['ran' => false, 'reason' => 'not_due', 'last_run' => $last], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

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

debugLog("Lancement du processus", [
    'cmd' => $cmd,
    'cwd' => $projectRoot,
    'limit' => $limit,
    'timeout' => $TIMEOUT_SEC
]);

$proc = proc_open($cmd, $desc, $pipes, $projectRoot, $env);
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

$response = [
  'ran'         => true,
  'stdout'      => trim($out),
  'stderr'      => trim($err),
  'last_run'    => date('Y-m-d H:i:s'),
  'code'        => $code,
  'success'     => $success,
  'timeout'     => $timeoutReached,
  'error'       => $errorMsg,
  'duration_sec' => time() - $startTime
];

debugLog("=== FIN run_import_if_due.php ===", [
  'success' => $success,
  'code' => $code,
  'timeout' => $timeoutReached,
  'error' => $errorMsg,
  'stdout_length' => strlen($out),
  'stderr_length' => strlen($err)
]);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
