<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Secrétaire']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input') ?: '{}';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

requireCsrfForApi((string)($data['csrf_token'] ?? ''));
$stockId = (int)($data['stock_id'] ?? 0);
if ($stockId <= 0) {
    jsonResponse(['success' => false, 'message' => 'stock_id invalide'], 400);
}

$pdo = getPdoOrFail();
$stmt = $pdo->prepare("UPDATE stock SET actif = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
$stmt->execute([$stockId]);

jsonResponse(['success' => true, 'message' => 'Article supprimé']);
