<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant']);

$pdo = getPdoOrFail();
require_once __DIR__ . '/../includes/parametres.php';
if (!isModuleEnabled($pdo, 'factures_recurrentes')) {
    jsonResponse(['ok' => false, 'error' => 'Module désactivé'], 403);
}

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (class_exists(\App\Services\ProductTier::class)
    && !\App\Services\ProductTier::canUseFeature($pdo, 'module_factures_recurrentes')) {
    jsonResponse(['ok' => false, 'error' => 'Fonction non disponible sur cette offre'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $rows = $pdo->query("
        SELECT fr.*, c.raison_sociale, c.numero_client
        FROM factures_recurrentes fr
        INNER JOIN clients c ON c.id = fr.id_client
        ORDER BY fr.actif DESC, fr.prochaine_echeance ASC, fr.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['ok' => true, 'items' => $rows]);
} catch (Throwable $e) {
    error_log('factures_recurrentes_list: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
