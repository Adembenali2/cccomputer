<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Chargé relation clients']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfForApi();

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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

$id = (int)($data['id'] ?? 0);
$statut = (string)($data['statut'] ?? '');
$allowed = ['nouveau', 'vu', 'converti', 'ignore'];

if ($id <= 0 || !in_array($statut, $allowed, true)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $st = $pdo->prepare('UPDATE commercial_opportunites SET statut = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$statut, $id]);
    if ($st->rowCount() === 0) {
        jsonResponse(['ok' => false, 'error' => 'Opportunité introuvable'], 404);
    }
    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    error_log('opportunites_statut: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur mise à jour'], 500);
}
