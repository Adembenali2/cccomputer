<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';

$type = $_GET['type'] ?? $_POST['type'] ?? 'sftp';
$php  = PHP_BINARY ?: 'php';
$script = ($type==='ionos') ? __DIR__.'/import_ionos_http.php' : __DIR__.'/upload_compteur.php';

$cmd = escapeshellcmd($php).' '.escapeshellarg($script);
$desc=[1=>['pipe','w'],2=>['pipe','w']];
$proc=proc_open($cmd,$desc,$pipes,__DIR__);
$out=$err='';
if(is_resource($proc)){ $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($proc); }
echo json_encode(['ok'=>1,'type'=>$type,'stdout'=>trim($out),'stderr'=>trim($err)]);
