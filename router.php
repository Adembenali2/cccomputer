<?php
// router.php – routes pour le serveur PHP intégré
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$full = __DIR__ . $path;

// Si le fichier statique existe, laisse le serveur le servir
if ($path !== '/' && is_file($full)) {
    return false;
}

// Si c'est une requête vers /API/, laisser passer (ne pas rediriger)
if (strpos($path, '/API/') === 0) {
    // Le fichier API devrait être géré directement par le serveur web
    // Si on arrive ici, le fichier n'existe pas
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Endpoint API introuvable']);
    exit;
}

// Page d'accueil -> index.php
require __DIR__ . '/index.php';
