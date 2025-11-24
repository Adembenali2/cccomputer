<?php
// /api/client_devices.php - Retourne les derniers relevés des photocopieurs d'un client

// Activer le buffer de sortie pour capturer toute sortie accidentelle
ob_start();

// Désactiver toute sortie d'erreur HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

// Définir le header JSON en premier (avant toute sortie)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Fonction helper pour nettoyer le buffer et renvoyer du JSON
function jsonResponse($data, $statusCode = 200) {
    ob_clean();
    http_response_code($statusCode);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK);
    exit;
}

// Gestion de la session pour les API (sans redirection HTML)
try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('client_devices.php require error: ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier l'authentification sans redirection HTML
if (empty($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Non authentifié'], 401);
}

// Vérifier que la connexion existe
if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['error' => 'Connexion base de données manquante'], 500);
}

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
    jsonResponse(['error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('client_devices.php error: ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur inattendue'], 500);
}
