<?php
/**
 * API pour modifier une facture
 * Permet de modifier : date_facture, statut, type
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
    $stmt = $pdo->prepare("SELECT id, numero FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    $updates = [];
    $params = [':id' => $factureId];
    
    // Date de facture
    if (!empty($data['date_facture'])) {
        $date = trim($data['date_facture']);
        $ts = strtotime($date);
        if ($ts === false) {
            jsonResponse(['ok' => false, 'error' => 'Date invalide'], 400);
        }
        $updates[] = "date_facture = :date_facture";
        $params[':date_facture'] = date('Y-m-d', $ts);
    }
    
    // Statut
    if (isset($data['statut'])) {
        $statutsValides = ['brouillon', 'envoyee', 'payee', 'en_retard', 'annulee'];
        if (!in_array($data['statut'], $statutsValides, true)) {
            jsonResponse(['ok' => false, 'error' => 'Statut invalide'], 400);
        }
        $updates[] = "statut = :statut";
        $params[':statut'] = $data['statut'];
    }
    
    // Type
    if (isset($data['type'])) {
        $typesValides = ['Consommation', 'Achat', 'Service'];
        if (!in_array($data['type'], $typesValides, true)) {
            jsonResponse(['ok' => false, 'error' => 'Type invalide'], 400);
        }
        $updates[] = "type = :type";
        $params[':type'] = $data['type'];
    }
    
    if (empty($updates)) {
        jsonResponse(['ok' => false, 'error' => 'Aucune modification fournie'], 400);
    }
    
    $sql = "UPDATE factures SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    enregistrerAction($pdo, currentUserId(), 'facture_modifiee', "Facture {$facture['numero']} modifiée (ID: {$factureId})");
    
    jsonResponse([
        'ok' => true,
        'message' => 'Facture modifiée avec succès',
        'facture_id' => $factureId
    ]);
    
} catch (PDOException $e) {
    error_log('factures_modifier.php SQL: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_modifier.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
