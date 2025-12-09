<?php
/**
 * API endpoint pour créer un paiement
 * 
 * POST /API/facturation_payment_create.php
 * 
 * Paramètres (JSON ou POST):
 * - client_id (int, requis): ID du client
 * - facture_id (int, optionnel): ID de la facture liée
 * - montant (float, requis): Montant du paiement
 * - date_paiement (string, requis): Date au format Y-m-d
 * - mode_paiement (string, requis): 'virement', 'cb', 'cheque', 'especes', 'autre'
 * - reference (string, optionnel): Référence du paiement
 * - commentaire (string, optionnel): Commentaire
 * - send_receipt (bool, optionnel): Générer et envoyer un reçu
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": {
 *     "id": 1,
 *     "message": "Paiement créé avec succès"
 *   }
 * }
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/db.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Lire les données JSON ou POST
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Si pas de JSON, utiliser POST
if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'Données invalides'], 400);
}

// Vérification CSRF
requireCsrfToken($data['csrf_token'] ?? null);

// Validation des données
$clientId = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$factureId = isset($data['facture_id']) ? (int)$data['facture_id'] : null;
$montant = isset($data['montant']) ? (float)$data['montant'] : 0;
$datePaiement = trim($data['date_paiement'] ?? '');
$modePaiement = trim($data['mode_paiement'] ?? '');
$reference = trim($data['reference'] ?? '');
$commentaire = trim($data['commentaire'] ?? '');
$sendReceipt = isset($data['send_receipt']) ? (bool)$data['send_receipt'] : false;
$userId = $_SESSION['user_id'] ?? null;

$errors = [];
if ($clientId <= 0) {
    $errors[] = 'ID client invalide';
}
if ($montant <= 0) {
    $errors[] = 'Montant invalide';
}
if (empty($datePaiement)) {
    $errors[] = 'Date de paiement requise';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePaiement)) {
    $errors[] = 'Format de date invalide (attendu: Y-m-d)';
}
if (empty($modePaiement)) {
    $errors[] = 'Mode de paiement requis';
} elseif (!in_array($modePaiement, ['virement', 'cb', 'cheque', 'especes', 'autre'], true)) {
    $errors[] = 'Mode de paiement invalide';
}

if (!empty($errors)) {
    jsonResponse(['ok' => false, 'error' => implode(', ', $errors)], 400);
}

// Vérifier que le client existe
try {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    if (!$stmt->fetch()) {
        jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
    }
    
    // Si une facture est spécifiée, vérifier qu'elle existe et appartient au client
    if ($factureId !== null && $factureId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM factures WHERE id = :id AND id_client = :client_id");
        $stmt->execute([':id' => $factureId, ':client_id' => $clientId]);
        if (!$stmt->fetch()) {
            jsonResponse(['ok' => false, 'error' => 'Facture introuvable ou n\'appartient pas au client'], 404);
        }
    }
    
    // Insérer le paiement
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        INSERT INTO paiements (
            id_facture, id_client, montant, date_paiement, mode_paiement,
            reference, commentaire, statut, created_by
        ) VALUES (
            :facture_id, :client_id, :montant, :date_paiement, :mode_paiement,
            :reference, :commentaire, 'en_cours', :created_by
        )
    ");
    
    $stmt->execute([
        ':facture_id' => $factureId > 0 ? $factureId : null,
        ':client_id' => $clientId,
        ':montant' => $montant,
        ':date_paiement' => $datePaiement,
        ':mode_paiement' => $modePaiement,
        ':reference' => $reference !== '' ? $reference : null,
        ':commentaire' => $commentaire !== '' ? $commentaire : null,
        ':created_by' => $userId
    ]);
    
    $paiementId = $pdo->lastInsertId();
    
    $pdo->commit();
    
    jsonResponse([
        'ok' => true,
        'data' => [
            'id' => $paiementId,
            'message' => 'Paiement créé avec succès'
        ]
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('facturation_payment_create.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('facturation_payment_create.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

