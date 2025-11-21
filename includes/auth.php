<?php
// includes/auth.php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';

// La session est démarrée par session_config.php

// Utilisateur connecté ?
if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

// Variables utiles
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_email  = $_SESSION['user_email'] ?? '';
$user_nom    = $_SESSION['user_nom'] ?? '';
$user_prenom = $_SESSION['user_prenom'] ?? '';
$emploi      = $_SESSION['emploi'] ?? '';

// Regénération régulière
if (!isset($_SESSION['last_regenerate'])) {
    $_SESSION['last_regenerate'] = time();
} elseif (time() - $_SESSION['last_regenerate'] > 900) {
    session_regenerate_id(true);
    $_SESSION['last_regenerate'] = time();
}

// CSRF si manquant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Optionnel: vérifier que l'utilisateur existe toujours (avec cache pour éviter requêtes répétées)
// On vérifie seulement toutes les 5 minutes pour améliorer les performances
$lastCheck = $_SESSION['user_check_time'] ?? 0;
if (time() - $lastCheck > 300) { // 5 minutes
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user_id]);
    if (!$stmt->fetch()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: /public/login.php');
        exit;
    }
    $_SESSION['user_check_time'] = time();
}
