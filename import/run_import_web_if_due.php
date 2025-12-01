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
 * Utilise un système anti-bouclage via app_kv pour éviter les exécutions trop fréquentes
 * 
 * EXÉCUTION :
 * - Peut être appelé manuellement via POST/GET
 * - Peut être planifié via CRON
 * - Peut être appelé depuis le dashboard (JavaScript)
 */

// ---------- Table KV pour anti-bouclage ----------
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_kv (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Intervalle minimum entre deux exécutions (en secondes)
$INTERVAL = (int)(getenv('WEB_IMPORT_INTERVAL_SEC') ?: 120); // Par défaut 2 minutes
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
$cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/import_ancien_http.php');
$desc = [
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];

// Passer les variables d'environnement
$env = $_ENV + $_SERVER;

$proc = proc_open($cmd, $desc, $pipes, __DIR__, $env);
$out = $err = '';
$code = null;

if (is_resource($proc)) {
  $out  = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err  = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code = proc_close($proc);
}

echo json_encode([
  'ran'      => true,
  'stdout'   => trim($out),
  'stderr'   => trim($err),
  'last_run' => date('Y-m-d H:i:s'),
  'code'     => $code
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

