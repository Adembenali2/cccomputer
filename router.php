<?php
// router.php – routes pour le serveur PHP intégré
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$full = __DIR__ . $path;

// Si le fichier statique existe, laisse le serveur le servir
if ($path !== '/' && is_file($full)) {
    return false;
}

// Si c'est une requête vers /API/, vérifier si le fichier existe dans API/
if (strpos($path, '/API/') === 0) {
    $apiFile = __DIR__ . $path;
    if (is_file($apiFile)) {
        // Le fichier existe, laisser le serveur le servir
        return false;
    }
    // Le fichier n'existe pas
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Endpoint API introuvable']);
    exit;
}

// Page d'accueil -> index.php
require __DIR__ . '/index.php';
