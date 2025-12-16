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

// Exécuter le script directement en capturant sa sortie
// On va utiliser un buffer de sortie et simuler les paramètres

// Sauvegarder l'état actuel de GET/POST
$originalGet = $_GET;
$originalPost = $_POST;

try {
    // Simuler les paramètres pour le script
    if ($force) {
        $_GET['force'] = '1';
        $_POST['force'] = '1';
    } else {
        unset($_GET['force'], $_POST['force']);
    }
    
    // Capturer la sortie du script
    ob_start();
    
    // Inclure le script (il va générer du JSON)
    include $scriptPath;
    
    $response = ob_get_clean();
    
    // Restaurer GET/POST
    $_GET = $originalGet;
    $_POST = $originalPost;
    
} catch (Throwable $e) {
    // Restaurer GET/POST en cas d'erreur
    $_GET = $originalGet;
    $_POST = $originalPost;
    
    ob_end_clean();
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur lors de l\'exécution du script',
        'type' => $type,
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}

// Parser la réponse JSON
$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Si ce n'est pas du JSON valide, retourner la réponse brute avec un warning
    jsonResponse([
        'ok' => false,
        'error' => 'Réponse invalide du script d\'import',
        'type' => $type,
        'json_error' => json_last_error_msg(),
        'raw_response' => substr($response, 0, 500) // Limiter la taille
    ], 500);
}

// Retourner la réponse du script
jsonResponse($data, 200);

