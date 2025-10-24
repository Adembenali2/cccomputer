<?php
// /ajax/run_import_if_due.php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

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
    PRIMARY KEY (`id`),
    KEY `ix_status_created` (`status`,`created_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// throttle 2 minutes basé sur import_run (dernier run)
$last = $pdo->query("SELECT ran_at FROM import_run ORDER BY id DESC LIMIT 1")->fetchColumn();
$now = new DateTimeImmutable('now');
if ($last) {
  $lastdt = new DateTimeImmutable($last);
  if ($now->getTimestamp() - $lastdt->getTimestamp() < 120) {
    echo json_encode(['ok'=>true,'skipped'=>true,'message'=>'Import récent (<2min)']);
    exit;
  }
}

// Créer un job (info) + le marquer running
$pdo->exec("INSERT INTO sftp_jobs(status) VALUES('pending')");
$jobId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE sftp_jobs SET status='running', started_at=NOW() WHERE id=?")->execute([$jobId]);

// Lancer l'import en arrière-plan
$script = realpath(__DIR__ . '/../cli/upload_compteur.php');
if (!$script || !is_file($script)) {
  echo json_encode(['ok'=>false,'message'=>"Script introuvable"]);
  exit;
}
$cmd = 'php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
@exec($cmd);

echo json_encode(['ok'=>true,'job_id'=>$jobId,'message'=>'Import lancé en arrière-plan']);
