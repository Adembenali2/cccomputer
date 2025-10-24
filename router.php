<?php
// router.php — routeur pour le serveur PHP builtin

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$path = __DIR__ . $uri;

// 1) Si le fichier demandé existe (css/js/images…), on le sert tel quel
if ($uri !== '/' && is_file($path)) {
    return false;
}

// 2) Sinon, on tombe toujours sur index.php (front controller)
require __DIR__ . '/index.php';
