<?php
/**
 * API endpoint pour récupérer les données de consommation pour le graphique
 * 
 * GET /API/facturation_consumption_chart.php
 * 
 * Paramètres:
 * - client_id (int, optionnel): ID du client (null pour tous les clients)
 * - granularity (string): 'year' ou 'month'
 * - year (int): Année
 * - month (int, optionnel): Mois (0-11) si granularity = 'month'
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": {
 *     "labels": ["Jan 2025", "Fév 2025", ...],
 *     "nbData": [1000, 1200, ...],
 *     "colorData": [200, 250, ...],
 *     "totalData": [1200, 1450, ...]
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
    $granularity = trim($_GET['granularity'] ?? 'month');
    $year = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : null;
    
    // Validation
    if (!in_array($granularity, ['year', 'month'], true)) {
        $granularity = 'month';
    }
    
    $periodParams = ['year' => $year];
    if ($granularity === 'month' && $month !== null) {
        $periodParams['month'] = $month;
    }
    
    // Initialiser les services
    $clientRepository = new ClientRepository($pdo);
    $compteurRepository = new CompteurRepository($pdo);
    $consumptionService = new ConsumptionService($compteurRepository);
    $billingService = new BillingService($pdo, $clientRepository, $compteurRepository, $consumptionService);
    
    // Récupérer les données
    $data = $billingService->getConsumptionChartData($clientId, $granularity, $periodParams);
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    // Log l'erreur complète pour le débogage
    error_log('facturation_consumption_chart.php error: ' . $e->getMessage());
    error_log('facturation_consumption_chart.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_consumption_chart.php trace: ' . $e->getTraceAsString());
    
    // S'assurer qu'aucune sortie n'a été envoyée avant
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // Retourner une réponse JSON même en cas d'erreur
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur: ' . $e->getMessage(),
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e),
            'trace' => $e->getTraceAsString()
        ] : null
    ], 500);
}

