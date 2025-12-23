<?php
/**
 * API pour récupérer la liste de toutes les factures
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    $pdo = getPdo();
    
    // Récupérer toutes les factures avec les informations du client
    $sql = "
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.type,
            f.montant_ht,
            f.tva,
            f.montant_ttc,
            f.statut,
            f.pdf_path,
            f.created_at,
            c.id as client_id,
            c.raison_sociale as client_nom,
            c.numero_client as client_code
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        ORDER BY f.date_facture DESC, f.created_at DESC
    ";
    
    $stmt = $pdo->query($sql);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données pour le frontend
    $formatted = [];
    foreach ($factures as $facture) {
        $formatted[] = [
            'id' => (int)$facture['id'],
            'numero' => $facture['numero'],
            'date_facture' => $facture['date_facture'],
            'date_facture_formatted' => date('d/m/Y', strtotime($facture['date_facture'])),
            'type' => $facture['type'],
            'montant_ht' => (float)$facture['montant_ht'],
            'tva' => (float)$facture['tva'],
            'montant_ttc' => (float)$facture['montant_ttc'],
            'statut' => $facture['statut'],
            'pdf_path' => $facture['pdf_path'],
            'client_id' => (int)$facture['client_id'],
            'client_nom' => $facture['client_nom'] ?? 'Client inconnu',
            'client_code' => $facture['client_code'] ?? '',
            'created_at' => $facture['created_at']
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'factures' => $formatted,
        'total' => count($formatted)
    ]);
    
} catch (PDOException $e) {
    error_log('factures_liste.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_liste.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

