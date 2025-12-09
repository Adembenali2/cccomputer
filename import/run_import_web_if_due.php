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

