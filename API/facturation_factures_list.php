<?php
/**
 * API endpoint pour récupérer la liste des factures d'un client
 * 
 * GET /API/facturation_factures_list.php
 * 
 * Paramètres:
 * - client_id (int, requis): ID du client
 * - limit (int, optionnel): Nombre de factures à retourner (défaut: 50)
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": [
 *     {
 *       "id": 1,
 *       "numero": "2025-001",
 *       "date_facture": "2025-01-15",
 *       "date_debut_periode": "2025-01-01",
 *       "date_fin_periode": "2025-01-31",
 *       "type": "Consommation",
 *       "montant_ttc": 845.20,
 *       "statut": "brouillon",
 *       "pdf_genere": false
 *     },
 *     ...
 *   ]
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
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 50;
    
    // Validation
    if (empty($clientId) || $clientId <= 0) {
        jsonResponse([
            'ok' => false,
            'error' => 'client_id est requis'
        ], 400);
    }
    
    if ($limit < 1 || $limit > 200) {
        $limit = 50;
    }
    
    // Récupérer les factures
    $sql = "
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.date_debut_periode,
            f.date_fin_periode,
            f.type,
            f.montant_ht,
            f.tva,
            f.montant_ttc,
            f.statut,
            f.pdf_genere,
            f.created_at
        FROM factures f
        WHERE f.id_client = :client_id
        ORDER BY f.date_facture DESC, f.created_at DESC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les résultats
    $data = [];
    foreach ($factures as $facture) {
        $periode = null;
        if ($facture['date_debut_periode'] && $facture['date_fin_periode']) {
            $periode = [
                'debut' => $facture['date_debut_periode'],
                'fin' => $facture['date_fin_periode']
            ];
        }
        
        $data[] = [
            'id' => (int)$facture['id'],
            'numero' => $facture['numero'],
            'date' => $facture['date_facture'],
            'periode' => $periode,
            'type' => $facture['type'],
            'montantHT' => (float)$facture['montant_ht'],
            'tva' => (float)$facture['tva'],
            'montantTTC' => (float)$facture['montant_ttc'],
            'statut' => $facture['statut'],
            'pdfGenere' => (bool)$facture['pdf_genere']
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_factures_list.php error: ' . $e->getMessage());
    error_log('facturation_factures_list.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_factures_list.php trace: ' . $e->getTraceAsString());
    
    // S'assurer qu'aucune sortie n'a été envoyée avant
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur: ' . $e->getMessage(),
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ] : null
    ], 500);
}

