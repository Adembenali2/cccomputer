<?php
declare(strict_types=1);

// [Livraison Auto]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}
requireCsrfToken();
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant'], true)) {
    jsonResponse(['success' => false, 'error' => 'Accès refusé'], 403);
}

try {
    $results = require __DIR__ . '/../../scripts/livraison_auto_check.php';
    $createdCount = is_array($results['created'] ?? null) ? count($results['created']) : 0;
    jsonResponse(['success' => true, 'results' => $results, 'message' => $createdCount . ' livraisons créées']);
} catch (Throwable $e) {
    error_log('auto_run.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
