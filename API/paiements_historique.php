<?php
/**
 * API pour récupérer l'historique de tous les paiements
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    $pdo = getPdo();
    
    // Récupérer tous les paiements avec les informations de facture et client
    $sql = "
        SELECT 
            p.id,
            p.id_facture,
            p.id_client,
            p.montant,
            p.date_paiement,
            p.mode_paiement,
            p.reference,
            p.commentaire,
            p.statut,
            p.recu_path,
            p.created_at,
            p.updated_at,
            f.numero as facture_numero,
            f.date_facture as facture_date,
            f.montant_ttc as facture_montant_ttc,
            c.raison_sociale as client_nom,
            c.numero_client as client_code,
            u.nom as created_by_nom,
            u.prenom as created_by_prenom
        FROM paiements p
        LEFT JOIN factures f ON p.id_facture = f.id
        LEFT JOIN clients c ON p.id_client = c.id
        LEFT JOIN utilisateurs u ON p.created_by = u.id
        ORDER BY p.date_paiement DESC, p.created_at DESC
    ";
    
    $stmt = $pdo->query($sql);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données pour le frontend
    $formatted = [];
    foreach ($paiements as $paiement) {
        // Formater la date de paiement
        $datePaiement = $paiement['date_paiement'] ? date('d/m/Y', strtotime($paiement['date_paiement'])) : '';
        
        // Labels pour les modes de paiement
        $modeLabels = [
            'cb' => 'Carte bancaire',
            'cheque' => 'Chèque',
            'virement' => 'Virement',
            'especes' => 'Espèce',
            'autre' => 'Autre paiement'
        ];
        $modeLabel = $modeLabels[$paiement['mode_paiement']] ?? $paiement['mode_paiement'];
        
        // Labels pour les statuts
        $statutLabels = [
            'en_cours' => 'En cours',
            'recu' => 'Reçu',
            'refuse' => 'Refusé',
            'annule' => 'Annulé'
        ];
        $statutLabel = $statutLabels[$paiement['statut']] ?? $paiement['statut'];
        
        // Couleurs pour les statuts
        $statutColors = [
            'en_cours' => '#6b7280',
            'recu' => '#10b981',
            'refuse' => '#ef4444',
            'annule' => '#9ca3af'
        ];
        $statutColor = $statutColors[$paiement['statut']] ?? '#6b7280';
        
        $formatted[] = [
            'id' => (int)$paiement['id'],
            'id_facture' => $paiement['id_facture'] ? (int)$paiement['id_facture'] : null,
            'id_client' => (int)$paiement['id_client'],
            'montant' => (float)$paiement['montant'],
            'date_paiement' => $paiement['date_paiement'],
            'date_paiement_formatted' => $datePaiement,
            'mode_paiement' => $paiement['mode_paiement'],
            'mode_paiement_label' => $modeLabel,
            'reference' => $paiement['reference'],
            'commentaire' => $paiement['commentaire'],
            'statut' => $paiement['statut'],
            'statut_label' => $statutLabel,
            'statut_color' => $statutColor,
            'recu_path' => $paiement['recu_path'],
            'created_at' => $paiement['created_at'],
            'facture_numero' => $paiement['facture_numero'],
            'facture_date' => $paiement['facture_date'],
            'facture_date_formatted' => $paiement['facture_date'] ? date('d/m/Y', strtotime($paiement['facture_date'])) : '',
            'facture_montant_ttc' => $paiement['facture_montant_ttc'] ? (float)$paiement['facture_montant_ttc'] : null,
            'client_nom' => $paiement['client_nom'],
            'client_code' => $paiement['client_code'],
            'created_by_nom' => $paiement['created_by_nom'],
            'created_by_prenom' => $paiement['created_by_prenom'],
            'created_by_full' => trim(($paiement['created_by_prenom'] ?? '') . ' ' . ($paiement['created_by_nom'] ?? ''))
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'paiements' => $formatted,
        'total' => count($formatted)
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_historique.php SQL error: ' . $e->getMessage());
    // Si la table n'existe pas, retourner une liste vide
    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown table") !== false) {
        jsonResponse(['ok' => true, 'paiements' => [], 'total' => 0]);
    }
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_historique.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

