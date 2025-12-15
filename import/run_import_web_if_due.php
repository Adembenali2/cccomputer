<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/db.php';

/**
 * Script de déclenchement de l'import WEB_COMPTEUR
 * Similaire à run_import_if_due.php mais pour l'import depuis test_compteur.php
 * 
 * FONCTIONNEMENT :
 * - Exécution automatique toutes les 2 minutes (120 secondes) depuis le dashboard
 * - Importe 100 lignes maximum par exécution
 * - Utilise un système anti-bouclage via app_kv pour éviter les exécutions trop fréquentes
 * - Les doublons sont évités via la contrainte UNIQUE (mac_norm, Timestamp)
 * 
 * EXÉCUTION :
 * - Appelé automatiquement depuis le dashboard toutes les 2 minutes
 * - Peut être appelé manuellement via POST/GET
 * - Peut être planifié via CRON
 */

// ---------- Table KV pour anti-bouclage ----------
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_kv (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Intervalle minimum entre deux exécutions (en secondes)
// Par défaut 120 secondes (2 minutes) - comme demandé
$INTERVAL = (int)(getenv('WEB_IMPORT_INTERVAL_SEC') ?: 120);
$key      = 'web_compteur_last_run';

// Vérifier si on peut exécuter
$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

if (!$due) {
  echo json_encode([
    'ran' => false, 
    'reason' => 'not_due', 
    'last_run' => $last,
    'interval' => $INTERVAL
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Marquer comme exécuté maintenant
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

echo json_encode([
  'ran'      => true,
  'stdout'   => trim($out),
  'stderr'   => trim($err),
  'last_run' => date('Y-m-d H:i:s'),
  'code'     => $code,
  'success'  => $success,
  'timeout'  => $timeoutReached
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

