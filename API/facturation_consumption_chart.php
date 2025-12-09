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

// Charger l'autoloader Composer AVANT les use statements
// Le chemin est relatif depuis API/ vers la racine du projet
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // Essayer un chemin alternatif si nécessaire
    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
}

if (file_exists($autoloadPath)) {
    // Charger l'autoloader Composer
    require_once $autoloadPath;
} else {
    // Fallback: charger les classes manuellement si l'autoloader n'existe pas
    // Charger d'abord les modèles (dépendances)
    require_once __DIR__ . '/../app/Models/Client.php';
    require_once __DIR__ . '/../app/Models/Releve.php';
    // Puis les repositories
    require_once __DIR__ . '/../app/Repositories/ClientRepository.php';
    require_once __DIR__ . '/../app/Repositories/CompteurRepository.php';
    // Puis les services
    require_once __DIR__ . '/../app/Services/ConsumptionService.php';
    require_once __DIR__ . '/../app/Services/BillingService.php';
}

// Vérifier que les classes sont disponibles après chargement de l'autoloader
// Si elles n'existent pas, les charger manuellement (fallback)
if (!class_exists('App\Repositories\ClientRepository', true)) {
    // Si l'autoloader n'a pas pu charger la classe, charger manuellement
    if (!class_exists('App\Models\Client', false)) {
        require_once __DIR__ . '/../app/Models/Client.php';
    }
    require_once __DIR__ . '/../app/Repositories/ClientRepository.php';
}
if (!class_exists('App\Repositories\CompteurRepository', true)) {
    if (!class_exists('App\Models\Releve', false)) {
        require_once __DIR__ . '/../app/Models/Releve.php';
    }
    require_once __DIR__ . '/../app/Repositories/CompteurRepository.php';
}
if (!class_exists('App\Services\ConsumptionService', true)) {
    require_once __DIR__ . '/../app/Services/ConsumptionService.php';
}
if (!class_exists('App\Services\BillingService', true)) {
    require_once __DIR__ . '/../app/Services/BillingService.php';
}

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

