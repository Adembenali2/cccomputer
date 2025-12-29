<?php
declare(strict_types=1);
/**
 * API pour envoyer plusieurs factures par email en masse
 * 
 * Envoie chaque facture à son client respectif automatiquement
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
    
    if (empty($data['facture_ids']) || !is_array($data['facture_ids'])) {
        jsonResponse(['ok' => false, 'error' => 'facture_ids requis (tableau)'], 400);
    }
    
    $factureIds = array_map('intval', $data['facture_ids']);
    $factureIds = array_filter($factureIds, function($id) {
        return $id > 0;
    });
    
    if (empty($factureIds)) {
        jsonResponse(['ok' => false, 'error' => 'Aucune facture valide sélectionnée'], 400);
    }
    
    // Limiter à 100 factures par requête pour éviter les timeouts
    if (count($factureIds) > 100) {
        jsonResponse(['ok' => false, 'error' => 'Maximum 100 factures par envoi'], 400);
    }
    
    // Charger la configuration
    $config = require __DIR__ . '/../config/app.php';
    
    // Instancier InvoiceEmailService
    $invoiceEmailService = new InvoiceEmailService($pdo, $config);
    
    $results = [];
    $total = count($factureIds);
    $success = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($factureIds as $factureId) {
        try {
            // Envoyer la facture (force=true pour renvoi manuel)
            $result = $invoiceEmailService->sendInvoiceAfterGeneration($factureId, true);
            
            if ($result['success']) {
                $success++;
                $results[] = [
                    'facture_id' => $factureId,
                    'success' => true,
                    'message' => $result['message'],
                    'log_id' => $result['log_id'] ?? null,
                    'message_id' => $result['message_id'] ?? null,
                    'email' => $result['email'] ?? null
                ];
            } else {
                $failed++;
                $results[] = [
                    'facture_id' => $factureId,
                    'success' => false,
                    'error' => $result['message'],
                    'log_id' => $result['log_id'] ?? null
                ];
            }
        } catch (Throwable $e) {
            $failed++;
            error_log("[factures_envoyer_masse] Erreur pour facture #{$factureId}: " . $e->getMessage());
            $results[] = [
                'facture_id' => $factureId,
                'success' => false,
                'error' => 'Erreur: ' . $e->getMessage()
            ];
        }
        
        // Petit délai entre chaque envoi pour éviter la surcharge
        usleep(100000); // 0.1 seconde
    }
    
    jsonResponse([
        'ok' => true,
        'total' => $total,
        'success' => $success,
        'failed' => $failed,
        'skipped' => $skipped,
        'results' => $results
    ]);
    
} catch (PDOException $e) {
    error_log('[factures_envoyer_masse] PDOException: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
    
} catch (Throwable $e) {
    error_log('[factures_envoyer_masse] Exception: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

