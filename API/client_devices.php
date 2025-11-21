<?php
// /api/client_devices.php - Retourne les derniers relevés des photocopieurs d'un client

// Désactiver toute sortie d'erreur HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Définir le header JSON en premier
header('Content-Type: application/json; charset=utf-8');

// Gestion de la session pour les API (sans redirection HTML)
try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('client_devices.php require error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur d\'initialisation']);
    exit;
}

// Vérifier l'authentification sans redirection HTML
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Vérifier que la connexion existe
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Connexion base de données manquante']);
    exit;
}

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID client manquant']);
    exit;
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
        // Formatage de la date pour faciliter l'affichage côté client
        if (!empty($device['last_ts'])) {
            // Garder la date telle quelle, le JS s'en occupera
        }
        
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
    
    echo json_encode($devices ?: [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK);
    
} catch (PDOException $e) {
    error_log('client_devices.php SQL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('client_devices.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur inattendue']);
}

