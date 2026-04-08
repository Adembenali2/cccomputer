<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant']);

require_once __DIR__ . '/../includes/parametres.php';

$pdo = getPdoOrFail();
if (!isModuleEnabled($pdo, 'dashboard_business')) {
    jsonResponse(['ok' => false, 'error' => 'Module désactivé'], 403);
}

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (class_exists(\App\Services\ProductTier::class)
    && !\App\Services\ProductTier::canUseFeature($pdo, 'module_dashboard_business')) {
    jsonResponse(['ok' => false, 'error' => 'Fonction non disponible sur cette offre'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $svc = new \App\Services\BusinessDashboardService($pdo);
    $kpis = $svc->getKpis();
    jsonResponse(['ok' => true, 'kpis' => $kpis]);
} catch (Throwable $e) {
    error_log('business_dashboard_stats: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
