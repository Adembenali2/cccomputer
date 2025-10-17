<?php
// /source/connexion/logout.php (VERSION SÉCURISÉE)

// 1️⃣ Inclure configurateur de session et dépendances
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/historique.php';

session_start();

// 2️⃣ Journaliser la déconnexion avant destruction
if (isset($_SESSION['user_id']) && isset($pdo) && function_exists('enregistrerAction')) {
    try {
        enregistrerAction(
            $pdo,
            (int)$_SESSION['user_id'],
            'deconnexion',
            'Déconnexion manuelle via le bouton'
        );
    } catch (Throwable $e) {
        // Ignorer les erreurs pour ne pas bloquer la déconnexion
    }
}

// 3️⃣ Purge complète de la session
$_SESSION = [];

// 4️⃣ Supprimer le cookie de session
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 5️⃣ Détruire la session côté serveur
session_destroy();

// 6️⃣ Redirection vers la page de login
header('Location: ../public/login.php');
exit;
