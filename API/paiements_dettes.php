<?php
// API pour calculer les dettes mensuelles des clients
// Période comptable : du 20 du mois au 20 du mois suivant
// NOUVELLE VERSION : Utilise les services (ConsumptionService, DebtService) pour calculer correctement
require_once __DIR__ . '/../includes/api_helpers.php';

// Charger les dépendances
require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\CompteurRepository;
use App\Repositories\ClientRepository;
use App\Services\ConsumptionService;
use App\Services\DebtService;
use App\Models\Client;
use DateTime;

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

// Paramètres : mois et année (optionnels, par défaut mois courant)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validation
if ($month < 1 || $month > 12) {
    $month = (int)date('m');
}
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

// Calculer la période comptable : du 20 du mois au 20 du mois suivant
try {
    $dateDebut = new DateTime("$year-$month-20 00:00:00");
} catch (Exception $e) {
    error_log('paiements_dettes.php - Erreur date début: ' . $e->getMessage());
    $dateDebut = new DateTime();
    $dateDebut->setDate((int)date('Y'), (int)date('m'), 20);
    $dateDebut->setTime(0, 0, 0);
}

$dateFin = clone $dateDebut;
$dateFin->modify('+1 month'); // 20 du mois suivant

try {
    // Initialiser les services
    $compteurRepository = new CompteurRepository($pdo);
    $clientRepository = new ClientRepository($pdo);
    $consumptionService = new ConsumptionService($compteurRepository);
    $debtService = new DebtService($consumptionService);
    
    // Récupérer tous les clients avec leurs photocopieurs
    $clientsWithPhotos = $clientRepository->findAllWithPhotocopieurs();
    
    // Calculer les dettes pour chaque client
    $dettes = [];
    
    foreach ($clientsWithPhotos as $clientData) {
        $client = $clientData['client'];
        $photocopieurs = $clientData['photocopieurs'];
        
        $clientId = $client->id;
        
        // Initialiser le client dans le tableau des dettes
        if (!isset($dettes[$clientId])) {
            $dettes[$clientId] = [
                'client_id' => $clientId,
                'numero_client' => $client->numeroClient,
                'raison_sociale' => $client->raisonSociale,
                'photocopieurs' => [],
                'total_bw' => 0,
                'total_color' => 0,
                'total_debt' => 0
            ];
        }
        
        // Pour chaque photocopieur du client, calculer la consommation et la dette
        foreach ($photocopieurs as $photo) {
            $macNorm = trim($photo['mac_norm'] ?? '');
            
            if (empty($macNorm)) {
                continue;
            }
            
            // Calculer la consommation pour cette période (20→20)
            $consumption = $consumptionService->calculateConsumptionForPeriod(
                $macNorm,
                $dateDebut,
                $dateFin
            );
            
            if (!$consumption) {
                // Pas de relevés pour cette période
                continue;
            }
            
            $bwConsumption = $consumption['bw'] ?? 0;
            $colorConsumption = $consumption['color'] ?? 0;
            
            // Calculer la dette pour cette consommation
            $debtData = $debtService->calculateDebt($bwConsumption, $colorConsumption);
            
            // Récupérer les compteurs de début et fin
            $startCounter = $consumption['start_counter'] ?? null;
            $endCounter = $consumption['end_counter'] ?? null;
            
            // Ajouter le photocopieur au client
            $dettes[$clientId]['photocopieurs'][] = [
                'mac_norm' => $macNorm,
                'mac_address' => $photo['mac_address'] ?? '',
                'serial' => $photo['serial'] ?? '',
                'model' => $photo['model'] ?? 'Inconnu',
                'compteur_debut_bw' => $startCounter ? $startCounter->totalBw : 0,
                'compteur_debut_color' => $startCounter ? $startCounter->totalColor : 0,
                'compteur_fin_bw' => $endCounter ? $endCounter->totalBw : 0,
                'compteur_fin_color' => $endCounter ? $endCounter->totalColor : 0,
                'consumption_bw' => $bwConsumption,
                'consumption_color' => $colorConsumption,
                'debt' => $debtData['debt'],
                'bw_amount' => $debtData['bw_amount'],
                'color_amount' => $debtData['color_amount']
            ];
            
            // Ajouter au total du client
            $dettes[$clientId]['total_bw'] += $bwConsumption;
            $dettes[$clientId]['total_color'] += $colorConsumption;
            $dettes[$clientId]['total_debt'] += $debtData['debt'];
        }
        
        // Calculer la dette totale pour le client (agrégée de tous ses photocopieurs)
        $totalDebtData = $debtService->calculateDebt(
            $dettes[$clientId]['total_bw'],
            $dettes[$clientId]['total_color']
        );
        
        $dettes[$clientId]['total_debt'] = round($totalDebtData['debt'], 2);
        $dettes[$clientId]['total_bw_amount'] = round($totalDebtData['bw_amount'], 2);
        $dettes[$clientId]['total_color_amount'] = round($totalDebtData['color_amount'], 2);
    }
    
    // Convertir en tableau indexé
    $dettesArray = array_values($dettes);
    
    jsonResponse([
        'ok' => true,
        'dettes' => $dettesArray,
        'period' => [
            'month' => $month,
            'year' => $year,
            'date_debut' => $dateDebut->format('Y-m-d'),
            'date_fin' => $dateFin->format('Y-m-d'),
            'label' => $dateDebut->format('d/m/Y') . ' → ' . $dateFin->format('d/m/Y')
        ],
        'tarifs' => [
            'bw_base' => 0.05,
            'bw_threshold' => 1000,
            'color_base' => 0.09
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_dettes.php PDO error: ' . $e->getMessage());
    error_log('paiements_dettes.php SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    error_log('paiements_dettes.php Error Code: ' . ($e->errorInfo[1] ?? 'N/A'));
    error_log('paiements_dettes.php Error Message: ' . ($e->errorInfo[2] ?? 'N/A'));
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'sql_state' => $e->errorInfo[0] ?? null,
            'code' => $e->errorInfo[1] ?? null,
            'error_info' => $e->errorInfo[2] ?? null
        ] : null
    ], 500);
} catch (Throwable $e) {
    error_log('paiements_dettes.php error: ' . $e->getMessage());
    error_log('paiements_dettes.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ] : null
    ], 500);
}
