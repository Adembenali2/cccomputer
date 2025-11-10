<?php
// import/run_ionos_if_due.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/**
 * Déclenche l’ETL IONOS (API/SCRIPTS/ionos_to_compteur.php)
 * - rate-limit (app_kv) par défaut 20s
 * - crée/maj un job dans sftp_jobs
 * - passe JOB_ID et IONOS_BATCH_LIMIT au worker
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php'; // $pdo Railway

// tables de suivi (si besoin)
$pdo->exec("CREATE TABLE IF NOT EXISTS app_kv (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->exec("CREATE TABLE IF NOT EXISTS sftp_jobs (
  id INT NOT NULL AUTO_INCREMENT,
  status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  summary JSON DEFAULT NULL,
  error TEXT,
  triggered_by INT DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->exec("CREATE TABLE IF NOT EXISTS ionos_cursor (
  id TINYINT NOT NULL DEFAULT 1,
  last_ts DATETIME DEFAULT NULL,
  last_mac CHAR(12) DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// rate-limit 20s (ou env)
$INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 20);
$key = 'ionos_last_run';
$last = $pdo->query("SELECT v FROM app_kv WHERE k='{$key}'")->fetchColumn();
$due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;
if (!$due) {
  echo json_encode(['ran'=>false,'reason'=>'not_due','last_run'=>$last]);
  exit;
}

// crée un job 'running'
$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);
$pdo->prepare("INSERT INTO sftp_jobs(status, started_at) VALUES('running', NOW())")->execute();
$jobId = (int)$pdo->lastInsertId();

// batch size (par défaut 10)
$limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

// lance le worker
$php    = PHP_BINARY ?: 'php';
$script = $projectRoot . '/API/SCRIPTS/ionos_to_compteur.php';
$cmd    = escapeshellcmd($php) . ' ' . escapeshellarg($script);
$env    = $_ENV + $_SERVER + ['JOB_ID' => (string)$jobId, 'IONOS_BATCH_LIMIT' => (string)$limit];

$desc = [1=>['pipe','w'], 2=>['pipe','w']];
$proc = proc_open($cmd, $desc, $pipes, $projectRoot, $env);

$out = $err = ''; $code = null;
if (is_resource($proc)) {
  $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code = proc_close($proc);
}

$status = ($code === 0) ? 'done' : 'failed';
$sum = json_encode(['stdout'=>trim($out)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$upd = $pdo->prepare("UPDATE sftp_jobs SET status=:st, finished_at=NOW(), summary=:su, error=:er WHERE id=:id");
$upd->execute([':st'=>$status, ':su'=>$sum, ':er'=>trim($err) ?: null, ':id'=>$jobId]);

echo json_encode([
  'ran'=>true,
  'job_id'=>$jobId,
  'code'=>$code,
  'stdout'=>trim($out),
  'stderr'=>trim($err),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
