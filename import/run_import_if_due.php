<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__); // ← racine du projet (pas besoin de remonter plus)
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
// CORRECTION : Le fichier upload_compteur.php se trouve dans API/scripts/, pas dans import/
$scriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
if (!is_file($scriptPath)) {
  echo json_encode([
    'ran' => false,
    'error' => 'Script upload_compteur.php introuvable',
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

$proc = proc_open($cmd, $desc, $pipes, $projectRoot, $env);
$out = $err = '';
$code = null;

if (is_resource($proc)) {
  $out  = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err  = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code = proc_close($proc);
} else {
  $err = 'Impossible de créer le processus';
  $code = -1;
}

// Vérifier si le processus a échoué
$success = ($code === 0 || $code === null);
if (!$success && empty($err)) {
  $err = "Processus terminé avec le code de sortie: $code";
}

echo json_encode([
  'ran'      => true,
  'stdout'   => trim($out),
  'stderr'   => trim($err),
  'last_run' => date('Y-m-d H:i:s'),
  'code'     => $code,
  'success'  => $success
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
