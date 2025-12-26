<?php
declare(strict_types=1);
/**
 * API pour envoyer une facture par email (renvoi manuel)
 * 
 * Délègue entièrement à InvoiceEmailService qui gère :
 * - Génération/régénération PDF (Railway filesystem éphémère)
 * - Envoi SMTP via MailerService
 * - Email HTML + texte + PDF
 * - Claim atomique email_envoye (0 → 2 → 1)
 * - Table email_logs
 * - Gestion des stuck (>15 minutes)
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\InvoiceEmailService;

initApi();
requireApiAuth();

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Récupération des données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !is_array($data)) {
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
    }
    
    if (empty($data['facture_id'])) {
        jsonResponse(['ok' => false, 'error' => 'facture_id requis'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    
    if ($factureId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'facture_id invalide'], 400);
    }
    
    // Charger la configuration
    $config = require __DIR__ . '/../config/app.php';
    
    // Instancier InvoiceEmailService
    $invoiceEmailService = new InvoiceEmailService($pdo, $config);
    
    // Envoyer la facture (force=true pour renvoi manuel)
    // InvoiceEmailService gère tout : PDF, SMTP, logs, claim atomique
    $result = $invoiceEmailService->sendInvoiceAfterGeneration($factureId, true);
    
    if ($result['success']) {
        jsonResponse([
            'ok' => true,
            'message' => $result['message'],
            'facture_id' => $factureId,
            'log_id' => $result['log_id'],
            'message_id' => $result['message_id'],
            'email' => $result['email'] ?? null
        ]);
    } else {
        // Erreur gérée par InvoiceEmailService
        $httpCode = 500;
        
        // Messages d'erreur spécifiques avec codes HTTP appropriés
        if (strpos($result['message'], 'déjà envoyée') !== false) {
            $httpCode = 409; // Conflict
        } elseif (strpos($result['message'], 'déjà en cours') !== false) {
            $httpCode = 409; // Conflict
        } elseif (strpos($result['message'], 'introuvable') !== false) {
            $httpCode = 404; // Not Found
        } elseif (strpos($result['message'], 'invalide') !== false) {
            $httpCode = 400; // Bad Request
        }
        
        jsonResponse([
            'ok' => false,
            'error' => $result['message'],
            'facture_id' => $factureId,
            'log_id' => $result['log_id'] ?? null
        ], $httpCode);
    }
    
} catch (PDOException $e) {
    error_log('[factures_envoyer_email] PDOException: ' . $e->getMessage());
    error_log('[factures_envoyer_email] Stack trace: ' . $e->getTraceAsString());
    
    $errorMessage = 'Erreur de base de données';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $errorMessage .= ': ' . $e->getMessage();
    }
    
    jsonResponse(['ok' => false, 'error' => $errorMessage], 500);
    
} catch (Throwable $e) {
    error_log('[factures_envoyer_email] Exception: ' . $e->getMessage());
    error_log('[factures_envoyer_email] Stack trace: ' . $e->getTraceAsString());
    
    $errorMessage = 'Erreur inattendue lors de l\'envoi de l\'email';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $errorMessage .= ': ' . $e->getMessage();
    }
    
    jsonResponse(['ok' => false, 'error' => $errorMessage], 500);
}
