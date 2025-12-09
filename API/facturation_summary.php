<?php
/**
 * API endpoint pour récupérer le résumé de facturation d'un client
 * 
 * GET /API/facturation_summary.php
 * 
 * Paramètres:
 * - client_id (int, requis): ID du client
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": {
 *     "total_a_facturer": 1245.30,
 *     "montant_non_paye": 820.00,
 *     "montant_paye": 425.30,
 *     "consommation_pages": {
 *       "nb": 10200,
 *       "color": 2100,
 *       "total": 12300
 *     },
 *     "facture_en_cours": {
 *       "numero": "2025-12",
 *       "statut": "brouillon",
 *       "montant_ttc": 845.20,
 *       "periode": {
 *         "debut": "2024-11-20",
 *         "fin": "2024-12-20"
 *       }
 *     }
 *   }
 * }
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/db.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

try {
    // Récupérer les paramètres
    $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    
    // Validation
    if (empty($clientId) || $clientId <= 0) {
        jsonResponse([
            'ok' => false,
            'error' => 'client_id est requis'
        ], 400);
    }
    
    // Calculer la période de facturation en cours (20 du mois précédent → 20 du mois courant)
    $now = new DateTime();
    $currentDay = (int)$now->format('d');
    $currentMonth = (int)$now->format('m');
    $currentYear = (int)$now->format('Y');
    
    $periodStartMonth = $currentMonth - 1;
    $periodStartYear = $currentYear;
    if ($periodStartMonth < 1) {
        $periodStartMonth = 12;
        $periodStartYear--;
    }
    
    $periodStart = new DateTime("$periodStartYear-$periodStartMonth-20");
    $periodEnd = new DateTime("$currentYear-$currentMonth-20");
    
    // Récupérer le total des factures non payées
    $sql = "
        SELECT 
            COALESCE(SUM(montant_ttc), 0) as total_factures
        FROM factures
        WHERE id_client = :client_id
          AND statut IN ('brouillon', 'envoyee', 'en_retard')
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $totalFactures = (float)$stmt->fetchColumn();
    
    // Récupérer le total des paiements
    $sql = "
        SELECT 
            COALESCE(SUM(montant), 0) as total_paiements
        FROM paiements
        WHERE id_client = :client_id
          AND statut = 'recu'
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $totalPaiements = (float)$stmt->fetchColumn();
    
    // Calculer le montant non payé (factures - paiements)
    $montantNonPaye = max(0, $totalFactures - $totalPaiements);
    
    // Récupérer la consommation de la période en cours
    // (Utiliser le service de consommation)
    require_once __DIR__ . '/../vendor/autoload.php';
    use App\Services\BillingService;
    use App\Repositories\ClientRepository;
    use App\Repositories\CompteurRepository;
    use App\Services\ConsumptionService;
    
    $clientRepository = new ClientRepository($pdo);
    $compteurRepository = new CompteurRepository($pdo);
    $consumptionService = new ConsumptionService($compteurRepository);
    $billingService = new BillingService($pdo, $clientRepository, $compteurRepository, $consumptionService);
    
    $invoiceData = $billingService->getConsumptionInvoiceData($clientId, $periodStart, $periodEnd);
    $consommationPages = $invoiceData['total'] ?? ['nb' => 0, 'color' => 0, 'total' => 0];
    
    // Récupérer la facture en cours (brouillon pour cette période)
    $sql = "
        SELECT 
            id, numero, statut, montant_ttc, date_debut_periode, date_fin_periode
        FROM factures
        WHERE id_client = :client_id
          AND statut = 'brouillon'
          AND date_debut_periode = :period_start
          AND date_fin_periode = :period_end
        ORDER BY created_at DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':client_id' => $clientId,
        ':period_start' => $periodStart->format('Y-m-d'),
        ':period_end' => $periodEnd->format('Y-m-d')
    ]);
    $factureEnCours = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Formater la réponse
    $data = [
        'total_a_facturer' => $totalFactures,
        'montant_non_paye' => $montantNonPaye,
        'montant_paye' => $totalPaiements,
        'consommation_pages' => $consommationPages,
        'facture_en_cours' => $factureEnCours ? [
            'id' => (int)$factureEnCours['id'],
            'numero' => $factureEnCours['numero'],
            'statut' => $factureEnCours['statut'],
            'montant_ttc' => (float)$factureEnCours['montant_ttc'],
            'periode' => [
                'debut' => $factureEnCours['date_debut_periode'],
                'fin' => $factureEnCours['date_fin_periode']
            ]
        ] : null
    ];
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_summary.php error: ' . $e->getMessage());
    error_log('facturation_summary.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_summary.php trace: ' . $e->getTraceAsString());
    
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

