<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/db.php';

/**
 * Table KV pour anti-bouclage
 */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_kv (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$INTERVAL = 120; // 2 minutes en secondes
$key      = 'ancien_last_run';

$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

if (!$due) {
  echo json_encode(['ran' => false, 'reason' => 'not_due', 'last_run' => $last], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

$php = PHP_BINARY ?: 'php';
$cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/../API/upload_compteur_ancien/import_compteurs.php');
$desc = [
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];

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



