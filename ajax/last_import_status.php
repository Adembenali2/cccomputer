<?php
// /ajax/last_import_status.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

// Dernier run import_run
$row = $pdo->query("
  SELECT id, ran_at, imported, skipped, ok, msg
  FROM import_run
  ORDER BY id DESC
  LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode(['has_run'=>false]);
  return;
}

$msg = null;
if (!empty($row['msg'])) {
  $decoded = json_decode($row['msg'], true);
  if (json_last_error() === JSON_ERROR_NONE) $msg = $decoded;
}

$recent = false;
try {
  $dt = new DateTimeImmutable($row['ran_at']);
  $recent = (time() - $dt->getTimestamp()) < 120; // < 2 min
} catch (\Throwable $e) {}

echo json_encode([
  'has_run'  => true,
  'ran_at'   => $row['ran_at'],
  'ok'       => (int)$row['ok'],
  'imported' => (int)$row['imported'],
  'skipped'  => (int)$row['skipped'],
  'recent'   => $recent,
  'summary'  => $msg, // ex: {processed, errors, inserted, files:[...]}
]);
