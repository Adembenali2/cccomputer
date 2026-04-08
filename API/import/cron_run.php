<?php
/**
 * API/import/cron_run.php
 * Endpoint GET pour déclencher les imports automatiquement (cron externe)
 *
 * Usage: GET /API/import/cron_run.php?token=SECRET
 *
 * À appeler toutes les minutes via:
 * - cron-job.org, cron-job.net
 * - curl dans crontab: * * * * * curl -s "https://votre-site.com/API/import/cron_run.php?token=SECRET"
 *
 * Variables: CRON_SECRET_TOKEN, SFTP_IMPORT_MAX_FILES (défaut: 10)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require dirname(__DIR__, 2) . '/config/app.php';
$token = $_GET['token'] ?? '';
$expectedToken = $config['import']['cron_secret_token'] ?? getenv('CRON_SECRET_TOKEN') ?? '';

if (empty($expectedToken)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'CRON_SECRET_TOKEN non configuré',
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Token invalide',
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$sftpScript = $projectRoot . '/scripts/import_sftp_cron.php';
$ionosScript = $projectRoot . '/scripts/import_ionos_cron.php';
$maxFiles = (string)($config['import']['sftp_max_files'] ?? 10);

putenv('SFTP_IMPORT_MAX_FILES=' . $maxFiles);

$result = [
    'ok' => true,
    'server_time' => date('Y-m-d H:i:s'),
    'sftp' => ['ok' => false, 'output' => ''],
    'ionos' => ['ok' => false, 'output' => ''],
];

// Import SFTP (10 fichiers)
if (file_exists($sftpScript)) {
    $output = [];
    $code = 0;
    exec('php ' . escapeshellarg($sftpScript) . ' 2>&1', $output, $code);
    $result['sftp'] = ['ok' => $code === 0, 'output' => implode("\n", $output)];
}

// Import IONOS
if (file_exists($ionosScript)) {
    $output = [];
    $code = 0;
    exec('php ' . escapeshellarg($ionosScript) . ' 2>&1', $output, $code);
    $result['ionos'] = ['ok' => $code === 0, 'output' => implode("\n", $output)];
}

$result['ok'] = $result['sftp']['ok'] && $result['ionos']['ok'];

echo json_encode($result, JSON_UNESCAPED_UNICODE);
