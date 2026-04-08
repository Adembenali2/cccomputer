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

$statut = $_GET['statut'] ?? '';
$validStatuts = ['nouveau', 'vu', 'converti', 'ignore', ''];
if (!in_array($statut, $validStatuts, true)) {
    $statut = '';
}

try {
    $sql = "
        SELECT o.*, c.raison_sociale, c.numero_client
        FROM commercial_opportunites o
        INNER JOIN clients c ON c.id = o.id_client
    ";
    $params = [];
    if ($statut !== '') {
        $sql .= ' WHERE o.statut = ?';
        $params[] = $statut;
    }
    $sql .= ' ORDER BY FIELD(o.statut, \'nouveau\', \'vu\', \'converti\', \'ignore\'), o.updated_at DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['ok' => true, 'items' => $rows]);
} catch (Throwable $e) {
    error_log('opportunites_list: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
