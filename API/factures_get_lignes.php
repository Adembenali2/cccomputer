<?php
/**
 * API pour récupérer les lignes d'une facture (Achat, Service)
 * GET ?facture_id=123
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$factureId = isset($_GET['facture_id']) ? (int)$_GET['facture_id'] : 0;
if ($factureId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'facture_id requis et invalide'], 400);
}

try {
    $pdo = getPdo();
    
    $stmt = $pdo->prepare("SELECT id, type, numero FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    $stmt = $pdo->prepare("
        SELECT id, description, type, quantite, prix_unitaire_ht, total_ht, ordre
        FROM facture_lignes
        WHERE id_facture = :id
        ORDER BY ordre ASC
    ");
    $stmt->execute([':id' => $factureId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'ok' => true,
        'facture_id' => $factureId,
        'type' => $facture['type'],
        'lignes' => $lignes
    ]);
    
} catch (PDOException $e) {
    error_log('factures_get_lignes.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_get_lignes.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
