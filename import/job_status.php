<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

$last = $pdo->query("SELECT id, ran_at, imported, skipped, ok, msg FROM import_run ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$kv   = $pdo->query("SELECT k,v FROM app_kv WHERE k IN ('sftp_last_run','ionos_last_run')")->fetchAll(PDO::FETCH_KEY_PAIR);
$rows = [];
foreach ($last as $r) {
  $r['summary'] = null;
  if (!empty($r['msg'])) { $d = json_decode((string)$r['msg'], true); if (is_array($d)) $r['summary'] = $d; }
  $rows[] = $r;
}
echo json_encode(['runs'=>$rows, 'last_sftp'=>$kv['sftp_last_run']??null, 'last_ionos'=>$kv['ionos_last_run']??null]);
