<?php
// /ajax/run_ionos_if_due.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Table KV pour stocker le dernier run
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_kv (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) { /* ignore */ }

$INTERVAL_SEC = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 120); // 2 min par défaut
$key = 'ionos_last_run';

// lire dernier run
$last = null;
try {
    $s = $pdo->prepare("SELECT v FROM app_kv WHERE k=?");
    $s->execute([$key]);
    $last = $s->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$now = time();
$lastTs = $last ? strtotime((string)$last) : 0;
$due = ($now - $lastTs) >= $INTERVAL_SEC;

if (!$due) {
    echo json_encode(['ran' => false, 'reason' => 'not_due', 'last_run' => $last]);
    exit;
}

// mettre à jour immédiatement (verrou optimiste)
try {
    $pdo->prepare("REPLACE INTO app_kv (k, v) VALUES (?, NOW())")->execute([$key]);
} catch (Throwable $e) { /* ignore */ }

// lancer le script CLI
$php  = PHP_BINARY ?: 'php';
$cmd  = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/../cli/import_ionos_http.php');
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes, __DIR__ . '/..');

$out = $err = '';
if (is_resource($proc)) {
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
}

echo json_encode([
    'ran'      => true,
    'stdout'   => trim($out),
    'stderr'   => trim($err),
    'last_run' => date('Y-m-d H:i:s'),
]);
