<?php
/**
 * API pour mettre à jour le statut de paiement d'une facture
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Récupération des données
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id']) || empty($data['statut'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    $statut = trim($data['statut']);
    
    // Valider le statut
    $statutsValides = ['brouillon', 'envoyee', 'payee', 'en_retard', 'annulee'];
    if (!in_array($statut, $statutsValides, true)) {
        jsonResponse(['ok' => false, 'error' => 'Statut invalide'], 400);
    }
    
    // Vérifier que la facture existe
    $stmt = $pdo->prepare("SELECT id, numero FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    // Récupérer l'ancien statut et email_envoye avant mise à jour
    $stmt = $pdo->prepare("SELECT statut, email_envoye FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldStatut = $oldData['statut'] ?? null;
    $emailEnvoye = (bool)($oldData['email_envoye'] ?? false);
    
    // Mettre à jour le statut
    $stmt = $pdo->prepare("UPDATE factures SET statut = :statut WHERE id = :id");
    $stmt->execute([
        ':statut' => $statut,
        ':id' => $factureId
    ]);
    
    // Envoi automatique si statut passe à 'envoyee' et email pas encore envoyé
    $emailSent = false;
    $emailError = null;
    if ($statut === 'envoyee' && !$emailEnvoye) {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            $config = require __DIR__ . '/../config/app.php';
            $invoiceEmailService = new \App\Services\InvoiceEmailService($pdo, $config);
            
            // Forcer l'envoi même si AUTO_SEND_INVOICES=false (car admin a validé)
            $result = $invoiceEmailService->sendInvoiceAfterGeneration($factureId, true);
            if ($result['success']) {
                $emailSent = true;
                error_log("[factures_update_statut] ✅ Facture #{$factureId} envoyée automatiquement après validation");
            } else {
                $emailError = $result['message'];
                error_log("[factures_update_statut] ⚠️ Échec envoi automatique facture #{$factureId}: " . $emailError);
            }
        } catch (Throwable $e) {
            // Ne pas bloquer la mise à jour de statut si l'envoi échoue
            $emailError = $e->getMessage();
            error_log("[factures_update_statut] ❌ Erreur envoi automatique (non bloquant): " . $emailError);
        }
    }
    
    $response = [
        'ok' => true,
        'message' => 'Statut mis à jour avec succès',
        'facture_id' => $factureId,
        'statut' => $statut
    ];
    
    if ($emailSent) {
        $response['email_sent'] = true;
        $response['message'] = 'Statut mis à jour et facture envoyée par email';
    } elseif ($emailError) {
        $response['email_sent'] = false;
        $response['email_error'] = $emailError;
    }
    
    jsonResponse($response);
    
} catch (PDOException $e) {
    error_log('factures_update_statut.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_update_statut.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

