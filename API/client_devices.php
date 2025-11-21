<?php
// /api/client_devices.php - Retourne les derniers relevés des photocopieurs d'un client
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID client manquant']);
    exit;
}

try {
    // Récupérer les photocopieurs du client avec leurs derniers relevés
    $sql = "
        WITH v_compteur_last AS (
            SELECT r.*,
                   ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
            FROM compteur_relevee r
        ),
        v_last AS (
            SELECT *, TIMESTAMPDIFF(HOUR, `Timestamp`, NOW()) AS age_hours
            FROM v_compteur_last WHERE rn = 1
        )
        SELECT
            pc.mac_norm,
            COALESCE(pc.SerialNumber, v.SerialNumber) AS SerialNumber,
            COALESCE(pc.MacAddress, v.MacAddress) AS MacAddress,
            v.Model,
            v.Nom,
            v.`Timestamp` AS last_ts,
            v.age_hours AS last_age_hours,
            v.TonerBlack,
            v.TonerCyan,
            v.TonerMagenta,
            v.TonerYellow,
            v.TotalBW,
            v.TotalColor,
            v.TotalPages,
            v.Status,
            v.IpAddress
        FROM photocopieurs_clients pc
        LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
        WHERE pc.id_client = :client_id
        ORDER BY COALESCE(pc.SerialNumber, pc.mac_norm) ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($devices ?: [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
    
} catch (PDOException $e) {
    error_log('client_devices.php SQL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de base de données']);
}

