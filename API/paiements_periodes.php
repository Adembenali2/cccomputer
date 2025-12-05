<?php
// API pour récupérer les consommations et dettes par période 20→20 pour tous les clients
// Utilise les services pour calculer correctement
require_once __DIR__ . '/../includes/api_helpers.php';

// Charger les dépendances
require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\CompteurRepository;
use App\Repositories\ClientRepository;
use App\Services\ConsumptionService;
use App\Services\DebtService;
use DateTime;

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

// Paramètres : mois et année (optionnels, par défaut mois courant)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

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
    error_log('paiements_periodes.php - Erreur date début: ' . $e->getMessage());
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
    
    // Récupérer les clients avec leurs photocopieurs
    $clientsWithPhotos = $clientRepository->findAllWithPhotocopieurs();
    
    // Filtrer par client si demandé
    if ($clientId > 0) {
        $clientsWithPhotos = array_filter($clientsWithPhotos, function($data) use ($clientId) {
            return $data['client']->id === $clientId;
        });
    }
    
    // Calculer les consommations et dettes pour chaque client
    $periodes = [];
    
    foreach ($clientsWithPhotos as $clientData) {
        $client = $clientData['client'];
        $photocopieurs = $clientData['photocopieurs'];
        
        $clientId = $client->id;
        
        // Agréger la consommation de tous les photocopieurs du client
        $totalBw = 0;
        $totalColor = 0;
        $photocopieursDetails = [];
        
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
                continue;
            }
            
            $bwConsumption = $consumption['bw'] ?? 0;
            $colorConsumption = $consumption['color'] ?? 0;
            
            $totalBw += $bwConsumption;
            $totalColor += $colorConsumption;
            
            $photocopieursDetails[] = [
                'mac_norm' => $macNorm,
                'mac_address' => $photo['mac_address'] ?? '',
                'serial' => $photo['serial'] ?? '',
                'model' => $photo['model'] ?? 'Inconnu',
                'consumption_bw' => $bwConsumption,
                'consumption_color' => $colorConsumption
            ];
        }
        
        // Calculer la dette totale pour le client
        $debtData = $debtService->calculateDebt($totalBw, $totalColor);
        
        // TODO: Récupérer le statut de paiement depuis la table factures/paiements si elle existe
        // Pour l'instant, on retourne un statut par défaut
        $statutPaiement = 'non_paye'; // 'paye', 'non_paye', 'partiellement_paye'
        $montantPaye = 0;
        $factureId = null;
        $factureUrl = null;
        
        $periodes[] = [
            'client_id' => $clientId,
            'numero_client' => $client->numeroClient,
            'raison_sociale' => $client->raisonSociale,
            'period_start' => $dateDebut->format('Y-m-d'),
            'period_end' => $dateFin->format('Y-m-d'),
            'period_label' => $dateDebut->format('d/m/Y') . ' → ' . $dateFin->format('d/m/Y'),
            'consumption_bw' => $totalBw,
            'consumption_color' => $totalColor,
            'debt' => $debtData['debt'],
            'bw_amount' => $debtData['bw_amount'],
            'color_amount' => $debtData['color_amount'],
            'statut_paiement' => $statutPaiement,
            'montant_paye' => $montantPaye,
            'montant_restant' => round($debtData['debt'] - $montantPaye, 2),
            'facture_id' => $factureId,
            'facture_url' => $factureUrl,
            'photocopieurs' => $photocopieursDetails
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'periodes' => $periodes,
        'period' => [
            'month' => $month,
            'year' => $year,
            'date_debut' => $dateDebut->format('Y-m-d'),
            'date_fin' => $dateFin->format('Y-m-d'),
            'label' => $dateDebut->format('d/m/Y') . ' → ' . $dateFin->format('d/m/Y')
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_periodes.php PDO error: ' . $e->getMessage());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage()
        ] : null
    ], 500);
} catch (Throwable $e) {
    error_log('paiements_periodes.php error: ' . $e->getMessage());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage()
        ] : null
    ], 500);
}




