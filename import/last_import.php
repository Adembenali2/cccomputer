<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

$stmt = $pdo->prepare("SELECT id, ran_at, imported, skipped, ok, msg FROM import_run ORDER BY id DESC LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['has_run'=>false]); exit; }

$summary = null;
if (!empty($row['msg'])) {
    $decoded = json_decode((string)$row['msg'], true);
    if (is_array($decoded)) $summary = $decoded;
}
$recent = (time() - strtotime((string)$row['ran_at'])) < 180; // < 3 min
echo json_encode([
    'has_run'  => true,
    'id'       => (int)$row['id'],
    'ran_at'   => (string)$row['ran_at'],
    'imported' => (int)$row['imported'],
    'skipped'  => (int)$row['skipped'],
    'ok'       => (int)$row['ok'],
    'recent'   => $recent ? 1 : 0,
    'summary'  => $summary
]);
