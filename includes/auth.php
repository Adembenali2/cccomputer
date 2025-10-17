<?php
// /includes/auth.php (VERSION SÉCURISÉE)

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';

// Démarrage sécurisé de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification que l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /cccomputer/public/login.php');
    exit;
}

// Récupération sécurisée des infos utilisateur depuis la session
$user_id     = (int)($_SESSION['user_id'] ?? 0);
$user_email  = $_SESSION['user_email'] ?? '';
$user_nom    = $_SESSION['user_nom'] ?? '';
$user_prenom = $_SESSION['user_prenom'] ?? '';
$emploi      = $_SESSION['emploi'] ?? '';

// Regénérer l’ID de session toutes les 15 minutes pour plus de sécurité
if (!isset($_SESSION['last_regenerate'])) {
    $_SESSION['last_regenerate'] = time();
} elseif (time() - $_SESSION['last_regenerate'] > 900) { // 900 sec = 15 min
    session_regenerate_id(true);
    $_SESSION['last_regenerate'] = time();
}

// CSRF token : généré si inexistant
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Vérifier que l'utilisateur existe encore dans la base
$stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $user_id]);
if (!$stmt->fetch()) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: /cccomputer/public/login.php');
    exit;
}

// ✅ Toutes les pages qui incluent ce fichier auront maintenant :
// $user_id, $user_email, $user_nom, $user_prenom, $emploi, $_SESSION['csrf_token']
?>
