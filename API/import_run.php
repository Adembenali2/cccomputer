<?php
declare(strict_types=1);
/**
 * API/import_run.php
 * Endpoint POST pour lancer un import spécifique (SFTP ou IONOS)
 * 
 * Paramètres :
 * - type: sftp|ionos (requis)
 * - force: 0|1 (optionnel, défaut 0)
 */

require_once __DIR__ . '/../includes/api_helpers.php';

// Initialiser l'API (session, DB, headers)
initApi();

// Vérifier l'authentification
requireApiAuth();

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée. Utilisez POST.'], 405);
}

// Récupérer les paramètres
$type = $_POST['type'] ?? $_GET['type'] ?? '';
$force = (isset($_POST['force']) && $_POST['force'] === '1') || (isset($_GET['force']) && $_GET['force'] === '1');

// Valider le type
if (!in_array($type, ['sftp', 'ionos'], true)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Type invalide. Utilisez "sftp" ou "ionos".',
        'received' => $type
    ], 400);
}

// Déterminer le script à exécuter
$projectRoot = dirname(__DIR__);
$scriptPath = null;

if ($type === 'sftp') {
    $scriptPath = $projectRoot . '/import/run_import_if_due.php';
} else {
    $scriptPath = $projectRoot . '/import/run_import_web_if_due.php';
}

// Vérifier que le script existe
if (!is_file($scriptPath)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Script d\'import introuvable',
        'type' => $type,
        'path' => $scriptPath
    ], 500);
}

// Construire l'URL avec les paramètres
$url = '/' . ltrim(str_replace($projectRoot, '', $scriptPath), '/');
if ($force) {
    $url .= '?force=1';
}

// Utiliser curl pour appeler le script (ou file_get_contents avec contexte)
// Mais comme c'est un script PHP, on peut aussi l'inclure directement
// Cependant, pour éviter les problèmes de session/headers, on va utiliser un appel HTTP interne

// Option 1 : Utiliser file_get_contents avec contexte HTTP
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$fullUrl = $baseUrl . $url;

// Créer un contexte HTTP avec les cookies de session
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Cookie: ' . session_name() . '=' . session_id(),
            'Content-Type: application/x-www-form-urlencoded'
        ],
        'timeout' => 120 // 2 minutes max
    ]
]);

// Appeler le script
$response = @file_get_contents($fullUrl, false, $context);

// Si file_get_contents échoue, essayer d'exécuter directement le script
if ($response === false) {
    // Alternative : exécuter le script directement via include
    // Mais cela peut causer des problèmes de headers/session
    // On va plutôt utiliser un buffer de sortie
    
    ob_start();
    try {
        // Simuler les paramètres GET/POST
        if ($force) {
            $_GET['force'] = '1';
            $_POST['force'] = '1';
        }
        
        // Inclure le script
        include $scriptPath;
        
        $response = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        jsonResponse([
            'ok' => false,
            'error' => 'Erreur lors de l\'exécution du script',
            'type' => $type,
            'exception' => $e->getMessage()
        ], 500);
    }
}

// Parser la réponse JSON
$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Si ce n'est pas du JSON valide, retourner la réponse brute avec un warning
    jsonResponse([
        'ok' => false,
        'error' => 'Réponse invalide du script d\'import',
        'type' => $type,
        'raw_response' => substr($response, 0, 500) // Limiter la taille
    ], 500);
}

// Retourner la réponse du script
jsonResponse($data, 200);

