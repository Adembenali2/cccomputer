<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$stockId = (int)($_GET['stock_id'] ?? 0);
if ($stockId <= 0) {
    jsonResponse(['success' => false, 'message' => 'stock_id invalide'], 400);
}

$pdo = getPdoOrFail();
$stmt = $pdo->prepare("SELECT * FROM stock_mouvements WHERE stock_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$stockId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

jsonResponse($items);
