<?php
// API pour géocoder une adresse (convertir adresse -> lat/lng) via Nominatim (OpenStreetMap)
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
    error_log('maps_geocode.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$address = trim($_GET['address'] ?? '');

if (empty($address)) {
    jsonResponse(['ok' => false, 'error' => 'Adresse manquante'], 400);
}

// Utiliser Nominatim (OpenStreetMap) pour géocoder
// IMPORTANT: Respecter la politique d'utilisation (max 1 requête/seconde, User-Agent requis)
$encodedAddress = urlencode($address);
$nominatimUrl = "https://nominatim.openstreetmap.org/search?q={$encodedAddress}&format=json&limit=1&addressdetails=1";

$ch = curl_init($nominatimUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'CCComputer Route Planner', // Requis par Nominatim
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
    error_log('maps_geocode.php curl error: ' . $curlError);
    jsonResponse(['ok' => false, 'error' => 'Erreur de géocodage'], 500);
}

if ($httpCode !== 200) {
    error_log('maps_geocode.php HTTP error: ' . $httpCode);
    jsonResponse(['ok' => false, 'error' => 'Service de géocodage indisponible'], 503);
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data)) {
    jsonResponse(['ok' => false, 'error' => 'Adresse non trouvée'], 404);
}

$result = $data[0];
jsonResponse([
    'ok' => true,
    'lat' => (float)$result['lat'],
    'lng' => (float)$result['lon'],
    'display_name' => $result['display_name'] ?? $address
]);

