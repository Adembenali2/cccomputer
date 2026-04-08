<?php
declare(strict_types=1);

// [Fonctionnalité B]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$idSav = (int)($_GET['id_sav'] ?? 0);
if ($idSav <= 0) {
    jsonResponse(['success' => false, 'error' => 'id_sav invalide'], 400);
}

try {
    $pdo = getPdo();
    $stmt = $pdo->prepare("
      SELECT sp.*,
        CASE sp.product_type
          WHEN 'toner' THEN t.marque
          WHEN 'papier' THEN p.marque
          WHEN 'lcd' THEN l.marque
          WHEN 'pc' THEN pc.marque
        END as marque,
        CASE sp.product_type
          WHEN 'toner' THEN t.modele
          WHEN 'papier' THEN p.modele
          WHEN 'lcd' THEN l.modele
          WHEN 'pc' THEN pc.modele
        END as modele
      FROM sav_pieces_utilisees sp
      LEFT JOIN toner_catalog t ON sp.product_type='toner' AND t.id=sp.product_id
      LEFT JOIN paper_catalog p ON sp.product_type='papier' AND p.id=sp.product_id
      LEFT JOIN lcd_catalog l ON sp.product_type='lcd' AND l.id=sp.product_id
      LEFT JOIN pc_catalog pc ON sp.product_type='pc' AND pc.id=sp.product_id
      WHERE sp.id_sav = ?
      ORDER BY sp.id DESC
    ");
    $stmt->execute([$idSav]);
    $pieces = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jsonResponse(['success' => true, 'pieces' => $pieces]);
} catch (Throwable $e) {
    error_log('pieces_get.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
