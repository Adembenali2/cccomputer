<?php
// /worker.php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/cli/upload_compteur.php';

while (true) {
  try {
    // Commencer une transaction et verrouiller 1 job pending
    $pdo->beginTransaction();
    $stmt = $pdo->query("
      SELECT id FROM sftp_jobs
      WHERE status='pending'
      ORDER BY id ASC
      LIMIT 1
      FOR UPDATE
    ");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
      $pdo->commit();
      sleep(5);
      continue;
    }

    $pdo->prepare("UPDATE sftp_jobs SET status='running', started_at=NOW() WHERE id=?")
        ->execute([$job['id']]);
    $pdo->commit();

    // Exécuter l'import
    $cfg = [
      'host' => getenv('SFTP_HOST') ?: '',
      'user' => getenv('SFTP_USER') ?: '',
      'pass' => getenv('SFTP_PASS') ?: '',
      'path' => getenv('SFTP_PATH') ?: '/',
      'port' => (int)(getenv('SFTP_PORT') ?: 22),
      'local_dir' => getenv('SFTP_LOCAL_DIR') ?: null,
    ];
    $result = importCounters($pdo, $cfg);

    $stmt = $pdo->prepare("UPDATE sftp_jobs SET status='done', finished_at=NOW(), summary=:s WHERE id=:id");
    $stmt->execute([':s' => json_encode($result, JSON_UNESCAPED_UNICODE), ':id' => $job['id']]);

  } catch (Throwable $e) {
    if (!empty($job['id'])) {
      $stmt = $pdo->prepare("UPDATE sftp_jobs SET status='failed', finished_at=NOW(), error=:e WHERE id=:id");
      $stmt->execute([':e' => $e->getMessage(), ':id' => $job['id']]);
    }
    sleep(3);
  }
}
