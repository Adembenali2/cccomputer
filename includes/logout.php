<?php
// includes/logout.php (VERSION SÉCURISÉE, sans sortie)

// 1) Session & dépendances (session déjà démarrée dans session_config.php)
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/historique.php';

// 2) Note: Les connexions/déconnexions ne sont plus enregistrées dans l'historique

// 3) Purge de la session
$_SESSION = [];

// 4) Supprimer le cookie de session (forcer path "/")
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        '/',                        // IMPORTANT: path racine
        $p['domain'] ?: '',
        (bool)$p['secure'],
        (bool)$p['httponly']
    );
}

// 5) Détruire la session serveur
session_destroy();

// 6) Redirection propre vers la page de connexion
header('Location: /public/login.php', true, 302);
exit;
