<?php
// /includes/auth_role.php (VERSION SÉCURISÉE REDIRECTION)

// Inclure le fichier auth pour la session
require_once __DIR__ . '/auth.php'; // Vérifie que la session est démarrée

/**
 * Vérifie si l'utilisateur a l'un des rôles autorisés.
 * Si non, redirige vers la page de redirection avec un code 302.
 *
 * @param array $allowed_roles Liste des rôles autorisés
 */
function authorize_roles(array $allowed_roles) {
    // Vérification que l'emploi est bien chargé depuis la session
    global $emploi;

    // Si l'emploi est vide ou non valide, rediriger
    if (empty($emploi) || !in_array($emploi, $allowed_roles, true)) {
        // Redirection vers la page d'accès interdit
        header('Location: /redirection/acces_interdit.php', true, 302);
        exit;
    }
}

/**
 * Accès réservé aux administrateurs
 * Note: Utilise 'Admin' (valeur exacte de la base de données ENUM)
 */
function requireAdmin() {
    return authorize_roles(['Admin']);
}

/**
 * Accès réservé aux chargés relation clients et administrateurs
 * Note: 'Chargé relation clients' est la valeur exacte dans la base, pas 'Commercial'
 */
function requireCommercial() {
    return authorize_roles(['Chargé relation clients', 'Admin']);
}

/**
 * Vérifie si l'utilisateur a la permission d'accéder à une page spécifique.
 * Utilise le système ACL (user_permissions) si une permission existe,
 * sinon utilise le système de rôles par défaut (fallback).
 *
 * @param string $page Nom de la page (ex: 'dashboard', 'historique', 'maps')
 * @param array $allowed_roles Liste des rôles autorisés par défaut (fallback)
 * @return bool True si l'utilisateur a accès, false sinon
 */
function checkPagePermission(string $page, array $allowed_roles = []): bool {
    // Récupérer PDO via la fonction centralisée
    if (!function_exists('getPdo')) {
        require_once __DIR__ . '/helpers.php';
    }
    try {
        $pdo = getPdo();
    } catch (RuntimeException $e) {
        // Si PDO n'est pas disponible, refuser l'accès
        error_log('checkPagePermission: Impossible de récupérer PDO: ' . $e->getMessage());
        return false;
    }
    
    // Récupérer les informations de l'utilisateur depuis la session
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $emploi = $_SESSION['emploi'] ?? '';
    
    // Si pas d'utilisateur, refuser
    if (empty($user_id)) {
        return false;
    }
    
    try {
        // Vérifier si une permission explicite existe pour cet utilisateur et cette page
        $stmt = $pdo->prepare("SELECT allowed FROM user_permissions WHERE user_id = ? AND page = ? LIMIT 1");
        $stmt->execute([$user_id, $page]);
        $permission = $stmt->fetchColumn();
        
        // Si une permission existe, l'utiliser
        if ($permission !== false) {
            return (int)$permission === 1;
        }
        
        // Sinon, utiliser le système de rôles par défaut (fallback)
        if (!empty($allowed_roles)) {
            return in_array($emploi, $allowed_roles, true);
        }
        
        // Si aucun rôle par défaut n'est spécifié et aucune permission n'existe, autoriser par défaut
        // (pour éviter de bloquer l'accès si le système ACL n'est pas encore configuré)
        return true;
    } catch (PDOException $e) {
        // Si la table n'existe pas encore (migration pas appliquée), utiliser les rôles par défaut
        error_log('Warning: user_permissions table may not exist: ' . $e->getMessage());
        if (!empty($allowed_roles)) {
            return in_array($emploi, $allowed_roles, true);
        }
        return true;
    }
}

/**
 * Vérifie l'accès à une page avec le système ACL.
 * Si l'utilisateur n'a pas accès, redirige vers la page d'accès interdit.
 *
 * @param string $page Nom de la page (ex: 'dashboard', 'historique', 'maps')
 * @param array $allowed_roles Liste des rôles autorisés par défaut (fallback)
 */
function authorize_page(string $page, array $allowed_roles = []): void {
    if (!checkPagePermission($page, $allowed_roles)) {
        header('Location: /redirection/acces_interdit.php', true, 302);
        exit;
    }
}
?>
