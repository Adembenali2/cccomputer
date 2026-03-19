<?php
/**
 * API pour supprimer une facture
 * Supprime la facture et ses lignes (CASCADE)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id'])) {
        jsonResponse(['ok' => false, 'error' => 'facture_id requis'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    if ($factureId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'facture_id invalide'], 400);
    }
    
    // Vérifier que la facture existe
    $stmt = $pdo->prepare("SELECT id, numero, id_client FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    // Supprimer d'abord les paiements liés à cette facture
    $stmt = $pdo->prepare("DELETE FROM paiements WHERE id_facture = :id");
    $stmt->execute([':id' => $factureId]);
    $nbPaiementsSupprimes = $stmt->rowCount();
    
    // Supprimer les lignes (CASCADE le fait automatiquement, mais on peut être explicite)
    $pdo->prepare("DELETE FROM facture_lignes WHERE id_facture = ?")->execute([$factureId]);
    
    // Supprimer la facture
    $stmt = $pdo->prepare("DELETE FROM factures WHERE id = :id");
    $stmt->execute([':id' => $factureId]);
    
    if ($stmt->rowCount() === 0) {
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de la suppression'], 500);
    }
    
    $details = "Facture {$facture['numero']} supprimée (ID: {$factureId})";
    if ($nbPaiementsSupprimes > 0) {
        $details .= " et {$nbPaiementsSupprimes} paiement(s) associé(s)";
    }
    enregistrerAction($pdo, currentUserId(), 'facture_supprimee', $details);
    
    jsonResponse([
        'ok' => true,
        'message' => 'Facture supprimée avec succès' . ($nbPaiementsSupprimes > 0 ? " ({$nbPaiementsSupprimes} paiement(s) supprimé(s))" : ''),
        'facture_id' => $factureId,
        'paiements_supprimes' => $nbPaiementsSupprimes
    ]);
    
} catch (PDOException $e) {
    error_log('factures_supprimer.php SQL: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_supprimer.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
