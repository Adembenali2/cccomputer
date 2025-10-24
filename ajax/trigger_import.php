<?php
// /ajax/trigger_import.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

try {
    // 1) S'assurer que la table existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sftp_jobs` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `status` ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `started_at` DATETIME NULL,
          `finished_at` DATETIME NULL,
          `summary` JSON NULL,
          `error` TEXT NULL,
          `triggered_by` INT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 2) Créer un job "pending"
    $pdo->exec("INSERT INTO sftp_jobs(status) VALUES('pending')");
    $id = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'job_id' => $id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
