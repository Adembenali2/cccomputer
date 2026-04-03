<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$pdo = getPdoOrFail();

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    jsonResponse(['ok' => false, 'error' => 'Autoload indisponible'], 500);
}
require_once __DIR__ . '/../vendor/autoload.php';

try {
    $svc = new \App\Services\OperationsDashboardService($pdo);
    $summary = $svc->getSummary();
    jsonResponse(['ok' => true, 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('dashboard_ops_summary: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
