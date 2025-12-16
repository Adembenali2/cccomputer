<?php
declare(strict_types=1);
/**
 * API/import_status.php
 * Endpoint GET pour récupérer le statut des deux imports (SFTP et IONOS)
 */

require_once __DIR__ . '/../includes/api_helpers.php';

// Initialiser l'API (session, DB, headers)
initApi();

// Vérifier l'authentification
requireApiAuth();

// Récupérer PDO
$pdo = getPdoOrFail();

// S'assurer que app_kv existe
$pdo->exec("
  CREATE TABLE IF NOT EXISTS app_kv (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// S'assurer que import_run existe
$pdo->exec("
  CREATE TABLE IF NOT EXISTS import_run (
    id INT NOT NULL AUTO_INCREMENT,
    ran_at DATETIME NOT NULL,
    imported INT NOT NULL,
    skipped INT NOT NULL,
    ok TINYINT(1) NOT NULL,
    msg TEXT,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$serverTime = date('Y-m-d H:i:s');

// Configuration des intervalles
$sftpInterval = (int)(getenv('SFTP_IMPORT_INTERVAL_SEC') ?: 20);
$ionosInterval = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 60);

// Fonction pour récupérer le statut d'un import
function getImportStatus(PDO $pdo, string $type, int $interval): array {
    $key = $type === 'sftp' ? 'sftp_last_run' : 'ionos_last_run';
    
    // Récupérer le dernier run depuis app_kv
    $stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
    $stmt->execute([$key]);
    $lastRunKv = $stmt->fetchColumn();
    $lastRunKvTimestamp = $lastRunKv ? strtotime((string)$lastRunKv) : 0;
    
    // Récupérer le dernier run depuis import_run (avec type dans msg)
    $typePattern = $type === 'sftp' ? 'sftp' : 'ionos';
    $stmt = $pdo->prepare("
        SELECT ran_at, imported, skipped, ok, msg
        FROM import_run
        WHERE msg LIKE :pattern
        ORDER BY ran_at DESC
        LIMIT 1
    ");
    $stmt->execute([':pattern' => '%"type":"' . $typePattern . '"%']);
    $lastRunDb = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculer les temps
    $now = time();
    $elapsed = $lastRunKvTimestamp > 0 ? ($now - $lastRunKvTimestamp) : 999999;
    $due = $elapsed >= $interval;
    $nextDueInSec = $due ? 0 : ($interval - $elapsed);
    
    // Extraire le message du dernier run
    $lastMessage = null;
    if ($lastRunDb && !empty($lastRunDb['msg'])) {
        $msgData = json_decode($lastRunDb['msg'], true);
        if (is_array($msgData)) {
            $lastMessage = $msgData['detail'] ?? $msgData['message'] ?? $lastRunDb['msg'];
        } else {
            $lastMessage = $lastRunDb['msg'];
        }
    }
    
    return [
        'interval' => $interval,
        'last_run_kv' => $lastRunKv ? date('Y-m-d H:i:s', $lastRunKvTimestamp) : null,
        'last_run_db' => $lastRunDb ? $lastRunDb['ran_at'] : null,
        'last_message' => $lastMessage,
        'ok' => $lastRunDb ? (bool)$lastRunDb['ok'] : null,
        'imported' => $lastRunDb ? (int)$lastRunDb['imported'] : null,
        'skipped' => $lastRunDb ? (int)$lastRunDb['skipped'] : null,
        'elapsed_sec' => $elapsed,
        'due' => $due,
        'next_due_in_sec' => $nextDueInSec
    ];
}

// Récupérer les statuts
$sftpStatus = getImportStatus($pdo, 'sftp', $sftpInterval);
$ionosStatus = getImportStatus($pdo, 'ionos', $ionosInterval);

// Réponse JSON
jsonResponse([
    'ok' => true,
    'server_time' => $serverTime,
    'sftp' => $sftpStatus,
    'ionos' => $ionosStatus
], 200);

