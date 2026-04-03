<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Chargé relation clients']);

$pdo = getPdoOrFail();
require_once __DIR__ . '/../includes/parametres.php';
if (!isModuleEnabled($pdo, 'opportunites')) {
    jsonResponse(['ok' => false, 'error' => 'Module désactivé'], 403);
}

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (class_exists(\App\Services\ProductTier::class)
    && !\App\Services\ProductTier::canUseFeature($pdo, 'module_opportunites')) {
    jsonResponse(['ok' => false, 'error' => 'Fonction non disponible sur cette offre'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'client_id requis'], 400);
}

try {
    $st = $pdo->prepare("
        SELECT * FROM commercial_opportunites
        WHERE id_client = ? AND statut IN ('nouveau','vu')
        ORDER BY created_at DESC
    ");
    $st->execute([$clientId]);
    jsonResponse(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('opportunites_client_list: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
