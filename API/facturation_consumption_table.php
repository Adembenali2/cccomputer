<?php
/**
 * API endpoint pour récupérer les données de consommation pour le tableau
 * 
 * GET /API/facturation_consumption_table.php
 * 
 * Paramètres:
 * - client_id (int, optionnel): ID du client (null pour tous les clients)
 * - months (int, optionnel): Nombre de mois à afficher (défaut: 3)
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": [
 *     {
 *       "id": 1,
 *       "nom": "HP LaserJet Pro",
 *       "modele": "M404dn",
 *       "macAddress": "AB:CD:EF:12:34:56",
 *       "consommations": [
 *         {
 *           "mois": "2025-01",
 *           "periode": "20/01 → 20/02",
 *           "pagesNB": 8750,
 *           "pagesCouleur": 0,
 *           "totalPages": 8750
 *         },
 *         ...
 *       ]
 *     },
 *     ...
 *   ]
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
    $months = !empty($_GET['months']) ? (int)$_GET['months'] : 3;
    
    // Validation
    if ($months < 1 || $months > 12) {
        $months = 3;
    }
    
    // Initialiser les services
    $clientRepository = new ClientRepository($pdo);
    $compteurRepository = new CompteurRepository($pdo);
    $consumptionService = new ConsumptionService($compteurRepository);
    $billingService = new BillingService($pdo, $clientRepository, $compteurRepository, $consumptionService);
    
    // Récupérer les données
    $data = $billingService->getConsumptionTableData($clientId, $months);
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_consumption_table.php error: ' . $e->getMessage());
    error_log('facturation_consumption_table.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_consumption_table.php trace: ' . $e->getTraceAsString());
    
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

