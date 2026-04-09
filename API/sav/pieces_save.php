<?php
declare(strict_types=1);

// [Fonctionnalité B]
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$idSav = (int)($_POST['id_sav'] ?? 0);
$productType = (string)($_POST['product_type'] ?? '');
$productId = (int)($_POST['product_id'] ?? 0);
$quantite = (int)($_POST['quantite'] ?? 0);

if ($idSav <= 0 || !in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true) || $productId <= 0 || $quantite <= 0) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getPdo();
    $catWhere = [
        'papier' => "categorie='papier'",
        'toner' => "categorie IN ('toner_noir','toner_cyan','toner_magenta','toner_jaune')",
        'lcd' => "categorie='ecran_lcd'",
        'pc' => "categorie='pc'",
    ][$productType];
    $check = $pdo->prepare("SELECT id FROM stock WHERE id = ? AND actif = 1 AND {$catWhere} LIMIT 1");
    $check->execute([$productId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        jsonResponse(['success' => false, 'error' => 'Produit introuvable'], 404);
    }

    $stmt = $pdo->prepare('INSERT INTO sav_pieces_utilisees (id_sav, product_type, product_id, quantite) VALUES (?, ?, ?, ?)');
    $stmt->execute([$idSav, $productType, $productId, $quantite]);
    $newId = (int)$pdo->lastInsertId();
    enregistrerAction($pdo, currentUserId(), 'sav_piece_ajoutee', "SAV #{$idSav} : pièce {$productType} #{$productId} x{$quantite}");
    jsonResponse(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    error_log('pieces_save.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
