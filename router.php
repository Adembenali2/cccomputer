<?php
// router.php – routes pour le serveur PHP intégré
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$full = __DIR__ . $path;

// Si le fichier statique existe, laisse le serveur le servir
if ($path !== '/' && is_file($full)) {
    return false;
}

// Page d’accueil -> index.php
require __DIR__ . '/index.php';
