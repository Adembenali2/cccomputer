<?php
/**
 * API endpoint pour récupérer la liste des paiements d'un client
 * 
 * GET /API/facturation_payments_list.php
 * 
 * Paramètres:
 * - client_id (int, requis): ID du client
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": [
 *     {
 *       "id": 1,
 *       "facture_id": 1,
 *       "facture_numero": "2025-001",
 *       "montant": 250.00,
 *       "date_paiement": "2025-01-15",
 *       "mode_paiement": "virement",
 *       "reference": "VIR-2025-001",
 *       "commentaire": "Paiement partiel",
 *       "statut": "recu",
 *       "created_by_nom": "Admin",
 *       "created_by_prenom": "CCComputer"
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
    
    // Validation
    if (empty($clientId) || $clientId <= 0) {
        jsonResponse([
            'ok' => false,
            'error' => 'client_id est requis'
        ], 400);
    }
    
    // Récupérer les paiements
    $sql = "
        SELECT 
            p.id,
            p.id_facture,
            f.numero as facture_numero,
            p.montant,
            p.date_paiement,
            p.mode_paiement,
            p.reference,
            p.commentaire,
            p.statut,
            p.created_at,
            u.nom as created_by_nom,
            u.prenom as created_by_prenom
        FROM paiements p
        LEFT JOIN factures f ON f.id = p.id_facture
        LEFT JOIN utilisateurs u ON u.id = p.created_by
        WHERE p.id_client = :client_id
        ORDER BY p.date_paiement DESC, p.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les résultats
    $data = [];
    foreach ($paiements as $paiement) {
        $data[] = [
            'id' => (int)$paiement['id'],
            'facture_id' => $paiement['id_facture'] ? (int)$paiement['id_facture'] : null,
            'facture_numero' => $paiement['facture_numero'],
            'montant' => (float)$paiement['montant'],
            'date_paiement' => $paiement['date_paiement'],
            'mode_paiement' => $paiement['mode_paiement'],
            'reference' => $paiement['reference'],
            'commentaire' => $paiement['commentaire'],
            'statut' => $paiement['statut'],
            'created_at' => $paiement['created_at'],
            'created_by_nom' => $paiement['created_by_nom'],
            'created_by_prenom' => $paiement['created_by_prenom']
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_payments_list.php error: ' . $e->getMessage());
    error_log('facturation_payments_list.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_payments_list.php trace: ' . $e->getTraceAsString());
    
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

