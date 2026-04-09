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
$sql = "SELECT sm.*, u.nom
        FROM stock_mouvements sm
        LEFT JOIN utilisateurs u ON sm.created_by = u.id
        WHERE sm.stock_id = ?
        ORDER BY sm.created_at DESC
        LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute([$stockId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

jsonResponse($items, 200);
