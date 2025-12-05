<?php
/**
 * API endpoint pour récupérer les données d'une facture de consommation
 * 
 * GET /API/facturation_invoice.php
 * 
 * Paramètres:
 * - client_id (int, requis): ID du client
 * - period_start (string, requis): Date de début de période au format Y-m-d (20 du mois)
 * - period_end (string, requis): Date de fin de période au format Y-m-d (20 du mois suivant)
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": {
 *     "client": {...},
 *     "period": {
 *       "start": "2025-01-20",
 *       "end": "2025-02-20",
 *       "label": "20/01 → 20/02"
 *     },
 *     "lignes": [
 *       {
 *         "photocopieur": {
 *           "nom": "HP LaserJet Pro",
 *           "modele": "M404dn",
 *           "mac": "AB:CD:EF:12:34:56"
 *         },
 *         "nb": 8750,
 *         "color": 0,
 *         "total": 8750
 *       },
 *       ...
 *     ],
 *     "total": {
 *       "nb": 8750,
 *       "color": 0,
 *       "total": 8750
 *     }
 *   }
 * }
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\BillingService;
use App\Repositories\ClientRepository;
use App\Repositories\CompteurRepository;
use App\Services\ConsumptionService;

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

try {
    // Récupérer les paramètres
    $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $periodStartStr = trim($_GET['period_start'] ?? '');
    $periodEndStr = trim($_GET['period_end'] ?? '');
    
    // Validation
    if (empty($clientId)) {
        jsonResponse([
            'ok' => false,
            'error' => 'client_id est requis'
        ], 400);
    }
    
    if (empty($periodStartStr) || empty($periodEndStr)) {
        jsonResponse([
            'ok' => false,
            'error' => 'period_start et period_end sont requis'
        ], 400);
    }
    
    try {
        $periodStart = new DateTime($periodStartStr);
        $periodEnd = new DateTime($periodEndStr);
    } catch (Exception $e) {
        jsonResponse([
            'ok' => false,
            'error' => 'Format de date invalide. Format attendu: Y-m-d'
        ], 400);
    }
    
    // Initialiser les services
    $clientRepository = new ClientRepository($pdo);
    $compteurRepository = new CompteurRepository($pdo);
    $consumptionService = new ConsumptionService($compteurRepository);
    $billingService = new BillingService($pdo, $clientRepository, $compteurRepository, $consumptionService);
    
    // Récupérer les données
    $data = $billingService->getConsumptionInvoiceData($clientId, $periodStart, $periodEnd);
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_invoice.php error: ' . $e->getMessage());
    error_log('facturation_invoice.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_invoice.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ] : null
    ], 500);
}

