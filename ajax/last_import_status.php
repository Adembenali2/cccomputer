<?php
// /ajax/last_import_status.php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$row = $pdo->query("
  SELECT id, status, created_at, started_at, finished_at, summary, error
  FROM sftp_jobs
  ORDER BY id DESC
  LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'id' => isset($row['id']) ? (int)$row['id'] : null,
  'status' => $row['status'] ?? null,
  'finished_at' => $row['finished_at'] ?? null,
  'summary' => isset($row['summary']) ? json_decode($row['summary'], true) : null,
  'error' => $row['error'] ?? null,
]);
