<?php
// API pour récupérer les livraisons d'un client (pour dashboard)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($clientId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID client invalide'], 400);
}

try {
    $sql = "
        SELECT 
            l.id,
            l.reference,
            l.adresse_livraison,
            l.objet,
            l.date_prevue,
            l.date_reelle,
            l.statut,
            l.commentaire,
            l.id_livreur,
            u.nom AS livreur_nom,
            u.prenom AS livreur_prenom,
            l.created_at,
            l.updated_at
        FROM livraisons l
        LEFT JOIN utilisateurs u ON u.id = l.id_livreur
        WHERE l.id_client = :client_id
        ORDER BY l.date_prevue DESC, l.id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(['ok' => true, 'livraisons' => $livraisons]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_deliveries.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_deliveries.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

