<?php
// Proxy pour les requêtes OSRM (évite les problèmes CORS)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    // Autoriser CORS pour le domaine de production
    header('Access-Control-Allow-Origin: https://cccomputer-production.up.railway.app');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Gérer les requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function jsonResponse(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: https://cccomputer-production.up.railway.app');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

// Vérifier que le paramètre coords est présent
$coords = trim($_GET['coords'] ?? '');

if (empty($coords)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètre coords manquant'], 400);
}

// Valider le format des coordonnées (doit contenir des points séparés par des points-virgules)
if (!preg_match('/^-?\d+\.?\d*,-?\d+\.?\d*(;-?\d+\.?\d*,-?\d+\.?\d*)*$/', $coords)) {
    jsonResponse(['ok' => false, 'error' => 'Format de coordonnées invalide'], 400);
}

// Construire l'URL OSRM
$osrmUrl = "https://router.project-osrm.org/route/v1/driving/{$coords}?overview=full&geometries=geojson&steps=true";

// Appeler OSRM via cURL
$ch = curl_init($osrmUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'CCComputer Route Planner',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log('osrm_route.php curl error: ' . $curlError);
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion au service de routage'], 500);
}

if ($httpCode !== 200) {
    error_log('osrm_route.php HTTP error: ' . $httpCode . ' for URL: ' . $osrmUrl);
    jsonResponse(['ok' => false, 'error' => 'Service de routage indisponible (code: ' . $httpCode . ')'], 503);
}

// Parser la réponse JSON
$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('osrm_route.php JSON decode error: ' . json_last_error_msg());
    jsonResponse(['ok' => false, 'error' => 'Réponse invalide du service de routage'], 500);
}

// Retourner la réponse OSRM brute
echo $response;
exit;

