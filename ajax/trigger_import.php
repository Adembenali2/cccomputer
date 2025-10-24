<?php
// /ajax/trigger_import.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

try {
    $pdo->exec("INSERT INTO sftp_jobs(status) VALUES('pending')");
    $id = $pdo->lastInsertId();
    echo json_encode(['ok' => true, 'job_id' => (int)$id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
