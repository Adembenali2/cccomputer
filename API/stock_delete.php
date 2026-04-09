<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès refusé'], 403);
}

requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}
$id = (int)($data['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(['ok' => false, 'error' => 'id invalide'], 400);
}

$pdo = getPdoOrFail();
$stmt = $pdo->prepare("DELETE FROM stock WHERE id = :id");
$stmt->execute([':id' => $id]);
enregistrerAction($pdo, currentUserId(), 'stock_supprime', "Stock #{$id} supprimé");
jsonResponse(['ok' => true]);

