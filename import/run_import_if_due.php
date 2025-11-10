<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/../includes/auth.php';
require_once dirname(__DIR__) . '/../includes/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS app_kv (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$INTERVAL = (int)(getenv('SFTP_IMPORT_INTERVAL_SEC') ?: 20);
$key = 'sftp_last_run';

$last = $pdo->query("SELECT v FROM app_kv WHERE k='${key}'")->fetchColumn();
$due = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

if (!$due) { echo json_encode(['ran'=>false,'reason'=>'not_due','last_run'=>$last]); exit; }

$pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

$limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

$php = PHP_BINARY ?: 'php';
$cmd = escapeshellcmd($php).' '.escapeshellarg(__DIR__.'/upload_compteur.php');
$desc=[1=>['pipe','w'],2=>['pipe','w']];

// Passe le batch au worker SFTP
$env = $_ENV + $_SERVER + ['SFTP_BATCH_LIMIT' => (string)$limit];

$proc=proc_open($cmd,$desc,$pipes,__DIR__,$env);
$out=$err=''; $code=null;
if(is_resource($proc)){
  $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code=proc_close($proc);
}
echo json_encode(['ran'=>true,'stdout'=>trim($out),'stderr'=>trim($err),'last_run'=>date('Y-m-d H:i:s'),'code'=>$code]);
