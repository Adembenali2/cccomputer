<?php
// /includes/session_config.php

// On s'assure que ce code n'est exécuté qu'une seule fois.
if (defined('SESSION_CONFIG_LOADED')) {
    return;
}
define('SESSION_CONFIG_LOADED', true);

// Calculer si la connexion est sécurisée (HTTPS)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// On définit les paramètres du cookie pour qu'il soit valide sur TOUT le site.
// Adapte 'path' si ton app n'est pas sous /cccomputer/ (mettre '/' sinon).
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/cccomputer/', // <-- adapte si nécessaire (ex: '/' si site à la racine)
    'domain'   => '',            // mettre le domaine si besoin (ex: '.mondomaine.tld')
    'secure'   => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// On démarre la session uniquement si elle n'est pas déjà active.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
