<?php
// includes/auth.php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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

// Mise à jour de last_activity pour le suivi des utilisateurs en ligne
// On met à jour toutes les 30 secondes pour éviter trop de requêtes
$lastActivityUpdate = $_SESSION['last_activity_update'] ?? 0;
if (time() - $lastActivityUpdate > 30) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET last_activity = NOW() WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $_SESSION['last_activity_update'] = time();
    } catch (PDOException $e) {
        // Si le champ n'existe pas encore, on ignore l'erreur (migration pas encore appliquée)
        error_log('Warning: last_activity update failed (field may not exist): ' . $e->getMessage());
    }
}

// Vérification du statut de l'utilisateur (plus fréquente pour déconnexion immédiate si inactif)
// On vérifie toutes les 30 secondes pour une réactivité rapide
$lastStatusCheck = $_SESSION['user_status_check_time'] ?? 0;
if (time() - $lastStatusCheck > 30) {
    $stmt = $pdo->prepare("SELECT id, statut FROM utilisateurs WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    $userCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si l'utilisateur n'existe plus ou est inactif, déconnexion immédiate
    if (!$userCheck || ($userCheck['statut'] ?? 'inactif') !== 'actif') {
        // Stocker le message d'erreur dans la session avant de la nettoyer
        $_SESSION['login_error'] = "Votre compte a été désactivé. Vous avez été déconnecté.";
        
        // Nettoyer toutes les données utilisateur de la session
        unset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_nom'], 
              $_SESSION['user_prenom'], $_SESSION['emploi'], $_SESSION['last_regenerate'],
              $_SESSION['last_activity_update'], $_SESSION['user_status_check_time'], 
              $_SESSION['user_check_time']);
        
        // Supprimer le cookie de session
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        
        // Régénérer l'ID de session pour sécurité
        session_regenerate_id(true);
        
        header('Location: /public/login.php', true, 302);
        exit;
    }
    
    $_SESSION['user_status_check_time'] = time();
}

// Optionnel: vérifier que l'utilisateur existe toujours (avec cache pour éviter requêtes répétées)
// On vérifie seulement toutes les 5 minutes pour améliorer les performances
$lastCheck = $_SESSION['user_check_time'] ?? 0;
if (time() - $lastCheck > 300) { // 5 minutes
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
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
