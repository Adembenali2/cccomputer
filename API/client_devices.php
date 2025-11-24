<?php
// /api/client_devices.php - Retourne les derniers relevés des photocopieurs d'un client
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($clientId <= 0) {
    jsonResponse(['error' => 'ID client manquant'], 400);
}

try {
    // Récupérer les photocopieurs du client avec leurs derniers relevés
    // Utilisation de ROW_NUMBER() pour identifier le dernier relevé par mac_norm
    // En cas d'égalité sur Timestamp, on prend le plus grand ID (plus récent)
    $sql = "
        WITH v_compteur_last AS (
            SELECT r.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY r.mac_norm 
                       ORDER BY r.`Timestamp` DESC, r.id DESC
                   ) AS rn
            FROM compteur_relevee r
            WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
        ),
        v_last AS (
            SELECT 
                *,
                TIMESTAMPDIFF(HOUR, `Timestamp`, NOW()) AS age_hours
            FROM v_compteur_last 
            WHERE rn = 1
        )
        SELECT
            pc.id,
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
    
    // Normaliser les données pour éviter les valeurs NULL problématiques et formater les dates
    $devices = array_map(function($device) {
        // S'assurer que les valeurs numériques sont correctement typées
        $numericFields = ['TonerBlack', 'TonerCyan', 'TonerMagenta', 'TonerYellow', 
                          'TotalBW', 'TotalColor', 'TotalPages', 'last_age_hours'];
        foreach ($numericFields as $field) {
            if (isset($device[$field])) {
                $device[$field] = $device[$field] === null ? null : (int)$device[$field];
            }
        }
        
        return $device;
    }, $devices);
    
    jsonResponse($devices ?: []);
    
} catch (PDOException $e) {
    error_log('client_devices.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('client_devices.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
