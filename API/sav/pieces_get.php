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
      SELECT sp.*, s.marque, s.modele
      FROM sav_pieces_utilisees sp
      LEFT JOIN stock s ON s.id = sp.product_id
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
