<?php
/**
 * Cron: Met à jour les statuts des factures selon la date
 * À exécuter quotidiennement (ex: 0 1 * * * = 1h du matin)
 * 
 * Logique:
 * - payee: facture entièrement payée (inchangé)
 * - annulee: facture annulée (inchangé)
 * - Autres: en_attente (avant le 25), en_cours (le 25), en_retard (après le 25)
 */

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

$token = $_GET['token'] ?? $_ENV['CRON_SECRET_TOKEN'] ?? '';
$expected = $_ENV['CRON_SECRET_TOKEN'] ?? getenv('CRON_SECRET_TOKEN') ?: '';
if ($expected !== '' && !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    $pdo = getPdo();
    $service = new \App\Services\FactureStatutService($pdo);
    $updated = $service->updateStatutsFromDate();
    echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (Throwable $e) {
    error_log('[update_facture_statuts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
