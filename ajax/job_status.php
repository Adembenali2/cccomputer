<?php
// /ajax/job_status.php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['status' => 'unknown']); exit; }

$stmt = $pdo->prepare("SELECT status, summary, error FROM sftp_jobs WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  'status'  => $row['status'] ?? 'unknown',
  'summary' => isset($row['summary']) ? json_decode($row['summary'], true) : null,
  'error'   => $row['error'] ?? null,
]);
