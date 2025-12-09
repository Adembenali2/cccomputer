<?php
/**
 * API endpoint pour récupérer le détail d'une facture
 * 
 * GET /API/facturation_facture_detail.php
 * 
 * Paramètres:
 * - facture_id (int, requis): ID de la facture
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": {
 *     "id": 1,
 *     "numero": "2025-001",
 *     "date_facture": "2025-01-15",
 *     "periode": { "debut": "2025-01-01", "fin": "2025-01-31" },
 *     "type": "Consommation",
 *     "montant_ht": 704.33,
 *     "tva": 140.87,
 *     "montant_ttc": 845.20,
 *     "statut": "brouillon",
 *     "client": {
 *       "nom": "Entreprise ABC",
 *       "adresse": "123 Rue Example",
 *       "email": "contact@entreprise-abc.fr"
 *     },
 *     "lignes": [
 *       {
 *         "description": "Pages N&B",
 *         "type": "N&B",
 *         "quantite": 8450,
 *         "prix_unitaire_ht": 0.05,
 *         "total_ht": 422.50
 *       },
 *       ...
 *     ]
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
    $factureId = !empty($_GET['facture_id']) ? (int)$_GET['facture_id'] : null;
    
    // Validation
    if (empty($factureId) || $factureId <= 0) {
        jsonResponse([
            'ok' => false,
            'error' => 'facture_id est requis'
        ], 400);
    }
    
    // Récupérer la facture avec les informations client
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
            c.raison_sociale,
            c.adresse,
            c.code_postal,
            c.ville,
            c.email
        FROM factures f
        INNER JOIN clients c ON c.id = f.id_client
        WHERE f.id = :facture_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':facture_id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse([
            'ok' => false,
            'error' => 'Facture introuvable'
        ], 404);
    }
    
    // Récupérer les lignes de la facture
    $sqlLignes = "
        SELECT 
            id,
            description,
            type,
            quantite,
            prix_unitaire_ht,
            total_ht,
            ordre
        FROM facture_lignes
        WHERE id_facture = :facture_id
        ORDER BY ordre ASC, id ASC
    ";
    
    $stmtLignes = $pdo->prepare($sqlLignes);
    $stmtLignes->execute([':facture_id' => $factureId]);
    $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données
    $periode = null;
    if ($facture['date_debut_periode'] && $facture['date_fin_periode']) {
        $periode = [
            'debut' => $facture['date_debut_periode'],
            'fin' => $facture['date_fin_periode']
        ];
    }
    
    $adresseComplete = trim($facture['adresse'] . ' ' . $facture['code_postal'] . ' ' . $facture['ville']);
    
    $data = [
        'id' => (int)$facture['id'],
        'numero' => $facture['numero'],
        'date' => $facture['date_facture'],
        'periode' => $periode,
        'type' => $facture['type'],
        'montantHT' => (float)$facture['montant_ht'],
        'tva' => (float)$facture['tva'],
        'montantTTC' => (float)$facture['montant_ttc'],
        'statut' => $facture['statut'],
        'pdfGenere' => (bool)$facture['pdf_genere'],
        'client' => [
            'nom' => $facture['raison_sociale'],
            'adresse' => $adresseComplete,
            'email' => $facture['email']
        ],
        'lignes' => array_map(function($ligne) {
            return [
                'description' => $ligne['description'],
                'type' => $ligne['type'],
                'quantite' => (float)$ligne['quantite'],
                'prixUnitaire' => (float)$ligne['prix_unitaire_ht'],
                'total' => (float)$ligne['total_ht']
            ];
        }, $lignes)
    ];
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_facture_detail.php error: ' . $e->getMessage());
    error_log('facturation_facture_detail.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_facture_detail.php trace: ' . $e->getTraceAsString());
    
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

