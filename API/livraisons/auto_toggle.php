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

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}
$idClient = (int)($data['id_client'] ?? 0);
$actif = (int)($data['actif'] ?? 0) ? 1 : 0;
if ($idClient <= 0) {
    jsonResponse(['success' => false, 'error' => 'id_client invalide'], 400);
}

try {
    $pdo = getPdo();
    $upd = $pdo->prepare("UPDATE livraison_auto_config SET actif = ?, updated_at = NOW() WHERE id_client = ?");
    $upd->execute([$actif, $idClient]);
    if ($upd->rowCount() === 0) {
        $ins = $pdo->prepare("INSERT INTO livraison_auto_config (id_client, actif) VALUES (?, ?)");
        $ins->execute([$idClient, $actif]);
    }
    jsonResponse(['success' => true, 'actif' => $actif]);
} catch (Throwable $e) {
    error_log('auto_toggle.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
