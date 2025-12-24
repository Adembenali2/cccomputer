<?php
// API pour géocoder un client et stocker les coordonnées en base
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('maps_geocode_client.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$clientId = (int)($_GET['client_id'] ?? 0);
$address = trim($_GET['address'] ?? '');

if (!$clientId || empty($address)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres manquants'], 400);
}

// Nettoyer l'adresse : supprimer les emails, tabulations et autres caractères indésirables
// Garder uniquement l'adresse postale
$address = preg_replace('/\s+/', ' ', $address); // Remplacer tous les espaces multiples par un seul espace
$address = preg_replace('/\t+/', ' ', $address); // Remplacer les tabulations par des espaces
$address = preg_replace('/[^\w\s\-\.,\(\)]/u', '', $address); // Supprimer les caractères spéciaux sauf ceux utiles pour les adresses
$address = trim($address);

// Supprimer les emails si présents (format: texte@domaine.ext)
$address = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '', $address);
$address = trim($address);

if (empty($address)) {
    jsonResponse(['ok' => false, 'error' => 'Adresse invalide après nettoyage'], 400);
}

// Vérifier le cache (24h de validité)
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cacheKey = 'geocode_' . md5($address);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';

$cachedCoords = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['ok']) && $cached['ok']) {
        $cachedCoords = $cached;
    }
}

// Si pas de cache, géocoder via Nominatim
if (!$cachedCoords) {
    $encodedAddress = urlencode($address);
    $nominatimUrl = "https://nominatim.openstreetmap.org/search?q={$encodedAddress}&format=json&limit=1&addressdetails=1";

    $ch = curl_init($nominatimUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'CCComputer Route Planner',
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept-Language: fr-FR,fr;q=0.9'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('maps_geocode_client.php curl error: ' . $curlError);
        jsonResponse(['ok' => false, 'error' => 'Erreur de géocodage'], 500);
    }

    if ($httpCode !== 200) {
        error_log('maps_geocode_client.php HTTP error: ' . $httpCode);
        jsonResponse(['ok' => false, 'error' => 'Service de géocodage indisponible'], 503);
    }

    $data = json_decode($response, true);

    // Si aucun résultat ou résultat invalide, retourner success:false avec HTTP 200
    if (!is_array($data) || empty($data)) {
        jsonResponse([
            'success' => false,
            'reason' => 'ADDRESS_NOT_FOUND',
            'client_id' => $clientId
        ], 200);
    }

    $result = $data[0];
    
    // Vérifier si le résultat a un statut d'erreur
    if (isset($result['error']) || (isset($result['status']) && $result['status'] !== 'OK')) {
        jsonResponse([
            'success' => false,
            'reason' => 'ADDRESS_NOT_FOUND',
            'client_id' => $clientId
        ], 200);
    }
    
    $cachedCoords = [
        'ok' => true,
        'lat' => (float)$result['lat'],
        'lng' => (float)$result['lon'],
        'display_name' => $result['display_name'] ?? $address
    ];

    // Sauvegarder dans le cache
    @file_put_contents($cacheFile, json_encode($cachedCoords));
}

// Vérifier si la table client_geocode existe, sinon la créer
try {
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'client_geocode'
    ");
    $checkTable->execute();
    $tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
    
    if (!$tableExists) {
        // Créer la table si elle n'existe pas
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS `client_geocode` (
              `id_client` int NOT NULL,
              `address_hash` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Hash MD5 de l''adresse géocodée',
              `lat` decimal(10,8) DEFAULT NULL COMMENT 'Latitude',
              `lng` decimal(11,8) DEFAULT NULL COMMENT 'Longitude',
              `display_name` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Nom d''affichage retourné par le géocodage',
              `geocoded_at` datetime DEFAULT NULL COMMENT 'Date du premier géocodage',
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date de dernière mise à jour',
              PRIMARY KEY (`id_client`),
              KEY `idx_address_hash` (`address_hash'),
              CONSTRAINT `fk_client_geocode_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        $pdo->exec($createTableSql);
        error_log('maps_geocode_client.php: Table client_geocode créée automatiquement');
    }
} catch (PDOException $e) {
    error_log('maps_geocode_client.php: Error checking/creating table: ' . $e->getMessage());
    // Continuer quand même, on essaiera d'insérer
}

// Stocker les coordonnées en base de données
$addressHash = md5($address);
try {
    $sql = "
        INSERT INTO client_geocode (id_client, address_hash, lat, lng, display_name, geocoded_at, updated_at)
        VALUES (:id_client, :address_hash, :lat, :lng, :display_name, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            address_hash = VALUES(address_hash),
            lat = VALUES(lat),
            lng = VALUES(lng),
            display_name = VALUES(display_name),
            updated_at = NOW()
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_client' => $clientId,
        ':address_hash' => $addressHash,
        ':lat' => $cachedCoords['lat'],
        ':lng' => $cachedCoords['lng'],
        ':display_name' => $cachedCoords['display_name']
    ]);
    
    jsonResponse([
        'success' => true,
        'ok' => true, // Compatibilité avec l'ancien format
        'lat' => $cachedCoords['lat'],
        'lng' => $cachedCoords['lng'],
        'client_id' => $clientId,
        'display_name' => $cachedCoords['display_name']
    ]);
    
} catch (PDOException $e) {
    error_log('maps_geocode_client.php SQL error: ' . $e->getMessage());
    // Si l'erreur est due à la table qui n'existe pas, on retourne quand même les coordonnées
    // mais sans les sauvegarder
    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Table") !== false) {
        error_log('maps_geocode_client.php: Table client_geocode n\'existe pas, retour des coordonnées sans sauvegarde');
        jsonResponse([
            'success' => true,
            'ok' => true,
            'lat' => $cachedCoords['lat'],
            'lng' => $cachedCoords['lng'],
            'client_id' => $clientId,
            'display_name' => $cachedCoords['display_name'],
            'warning' => 'Coordonnées retournées mais non sauvegardées (table manquante)'
        ]);
    } else {
        jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
    }
}

