<?php
// includes/logout.php (VERSION SÉCURISÉE, sans sortie)

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/historique.php';

// Enregistrer la déconnexion AVANT de purge la session (pour avoir user_id)
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    try {
        $pdo = getPdo();
        enregistrerAction($pdo, $userId, 'deconnexion', 'Déconnexion');
    } catch (Throwable $e) {
        error_log('logout.php audit: ' . $e->getMessage());
    }
}

// Purge de la session
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
