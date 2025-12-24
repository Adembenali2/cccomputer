<?php
// router.php – routes pour le serveur PHP intégré
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$full = __DIR__ . $path;

// Si le fichier statique existe, laisse le serveur le servir
if ($path !== '/' && is_file($full)) {
    return false;
}

// Si c'est une requête vers /API/, essayer de servir le fichier directement
// Sur Railway avec Caddy, on peut soit laisser Caddy gérer, soit inclure directement
if (strpos($path, '/API/') === 0 || strpos($path, '/api/') === 0) {
    // Normaliser le chemin vers /API/ (majuscules)
    $normalizedPath = str_replace(['/API/', '/api/'], '/API/', $path);
    $apiFile = __DIR__ . $normalizedPath;
    
    // Si le fichier existe, l'inclure directement
    if (is_file($apiFile)) {
        require $apiFile;
        exit;
    }
    
    // Si le fichier n'existe pas, laisser Caddy gérer (retourner false)
    // Caddy retournera une 404 standard
    return false;
}

// Page d'accueil -> index.php
require __DIR__ . '/index.php';
