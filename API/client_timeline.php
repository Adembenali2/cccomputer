<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
if ($clientId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID client invalide'], 400);
}

$pdo = getPdoOrFail();

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    jsonResponse(['ok' => false, 'error' => 'Autoload indisponible'], 500);
}
require_once __DIR__ . '/../vendor/autoload.php';

try {
    $svc = new \App\Services\ClientTimelineService($pdo);
    $events = $svc->fetchEvents($clientId, $limit);
    jsonResponse(['ok' => true, 'events' => $events]);
} catch (Throwable $e) {
    error_log('client_timeline: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
