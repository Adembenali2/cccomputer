<?php
/**
 * API pour vérifier le nombre de photocopieurs d'un client
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

if (!$clientId) {
    jsonResponse(['ok' => false, 'error' => 'ID client manquant ou invalide'], 400);
}

try {
    $pdo = getPdo();
    
    // Compter les photocopieurs du client
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM photocopieurs_clients WHERE id_client = :client_id");
    $stmt->execute([':client_id' => $clientId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $nbPhotocopieurs = (int)($result['nb'] ?? 0);
    
    // Récupérer aussi les détails des photocopieurs
    $stmt = $pdo->prepare("
        SELECT 
            pc.id,
            pc.SerialNumber,
            pc.MacAddress,
            pc.mac_norm,
            COALESCE(latest.Nom, 'Inconnu') as nom,
            COALESCE(latest.Model, 'Inconnu') as modele
        FROM photocopieurs_clients pc
        LEFT JOIN (
            SELECT 
                mac_norm,
                Nom,
                Model,
                ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) latest ON latest.mac_norm = pc.mac_norm AND latest.rn = 1
        WHERE pc.id_client = :client_id
        ORDER BY pc.id
    ");
    $stmt->execute([':client_id' => $clientId]);
    $photocopieurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'ok' => true,
        'nb_photocopieurs' => $nbPhotocopieurs,
        'photocopieurs' => $photocopieurs
    ]);
    
} catch (Exception $e) {
    error_log('Erreur factures_check_photocopieurs: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors de la vérification des photocopieurs'], 500);
}
?>
