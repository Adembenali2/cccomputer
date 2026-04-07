<?php
// includes/auth.php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/helpers.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// La session est démarrée par session_config.php

// Utilisateur connecté ?
if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

// Variables utiles
$user_id     = (int)($_SESSION['user_id'] ?? 0);

// [Fonctionnalité D] Session révoquée (ligne supprimée depuis le profil) : déconnexion si la table est utilisée
try {
    $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?');
    $stmtCnt->execute([$user_id]);
    $nSess = (int)$stmtCnt->fetchColumn();
    if ($nSess > 0) {
        $stmtCur = $pdo->prepare(
            'SELECT 1 FROM user_sessions WHERE session_token = ? AND user_id = ? LIMIT 1'
        );
        $stmtCur->execute([session_id(), $user_id]);
        if (!$stmtCur->fetch()) {
            $_SESSION['login_error'] = 'Cette session a été fermée depuis un autre appareil.';
            unset(
                $_SESSION['user_id'],
                $_SESSION['user_email'],
                $_SESSION['user_nom'],
                $_SESSION['user_prenom'],
                $_SESSION['emploi'],
                $_SESSION['last_regenerate'],
                $_SESSION['last_activity_update'],
                $_SESSION['last_db_activity_sync'],
                $_SESSION['user_status_check_time'],
                $_SESSION['user_check_time'],
                $_SESSION['last_login_at']
            );
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_regenerate_id(true);
            header('Location: /public/login.php', true, 302);
            exit;
        }
    }
} catch (PDOException $e) {
    /* table absente */
}
$user_email  = $_SESSION['user_email'] ?? '';
$user_nom    = $_SESSION['user_nom'] ?? '';
$user_prenom = $_SESSION['user_prenom'] ?? '';
$emploi      = $_SESSION['emploi'] ?? '';

// Regénération régulière
if (!isset($_SESSION['last_regenerate'])) {
    $_SESSION['last_regenerate'] = time();
} elseif (time() - $_SESSION['last_regenerate'] > 900) {
    // [Fonctionnalité D] Garder la ligne user_sessions alignée sur le nouveau session_id
    $oldSid = session_id();
    session_regenerate_id(true);
    $newSid = session_id();
    try {
        $stmtRg = $pdo->prepare(
            'UPDATE user_sessions SET session_token = ?, last_activity = NOW() WHERE session_token = ? AND user_id = ?'
        );
        $stmtRg->execute([$newSid, $oldSid, $user_id]);
    } catch (PDOException $e) {
        /* table absente ou ligne manquante */
    }
    $_SESSION['last_regenerate'] = time();
}

// CSRF si manquant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [Fonctionnalité B] Déconnexion automatique après 30 minutes d'inactivité
$inactivityLimit = 1800; // 30 minutes en secondes
$lastActivity = $_SESSION['last_activity_update'] ?? time();
if (time() - $lastActivity > $inactivityLimit) {
    $_SESSION['login_error'] = 'Vous avez été déconnecté après 30 minutes d\'inactivité.';
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_email'],
        $_SESSION['user_nom'],
        $_SESSION['user_prenom'],
        $_SESSION['emploi'],
        $_SESSION['last_regenerate'],
        $_SESSION['last_activity_update'],
        $_SESSION['last_db_activity_sync'],
        $_SESSION['user_status_check_time'],
        $_SESSION['user_check_time'],
        $_SESSION['last_login_at']
    );
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_regenerate_id(true);
    header('Location: /public/login.php', true, 302);
    exit;
}

// [Fonctionnalité C/D] Mise à jour last_activity (utilisateurs) toutes les 30s — clé session dédiée
$lastDbActivitySync = $_SESSION['last_db_activity_sync'] ?? 0;
if (time() - $lastDbActivitySync > 30) {
    try {
        $stmt = $pdo->prepare('UPDATE utilisateurs SET last_activity = NOW() WHERE id = :id');
        $stmt->execute([':id' => $user_id]);
        $_SESSION['last_db_activity_sync'] = time();
    } catch (PDOException $e) {
        error_log('Warning: last_activity update failed (field may not exist): ' . $e->getMessage());
    }
    try {
        $stmtUpdSess = $pdo->prepare('UPDATE user_sessions SET last_activity = NOW() WHERE session_token = ?');
        $stmtUpdSess->execute([session_id()]);
    } catch (PDOException $e) {
        /* ignorer */
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
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_email'],
            $_SESSION['user_nom'],
            $_SESSION['user_prenom'],
            $_SESSION['emploi'],
            $_SESSION['last_regenerate'],
            $_SESSION['last_activity_update'],
            $_SESSION['last_db_activity_sync'],
            $_SESSION['user_status_check_time'],
            $_SESSION['user_check_time'],
            $_SESSION['last_login_at']
        );
        
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

// [Fonctionnalité B] Chaque requête authentifiée réinitialise le minuteur d’inactivité
$_SESSION['last_activity_update'] = time();
