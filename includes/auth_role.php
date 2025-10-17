<?php
// /includes/auth_role.php (VERSION SÉCURISÉE REDIRECTION)

// Inclure auth pour la session
require_once __DIR__ . '/auth.php';

/**
 * Vérifie si l'utilisateur a un des rôles autorisés.
 * Si non, redirige vers la page de redirection.
 *
 * @param array $allowed_roles
 */
function authorize_roles(array $allowed_roles) {
    global $emploi;

    if (!in_array($emploi, $allowed_roles, true)) {
        // Redirection vers page d'accès refusé
        header('Location: /cccomputer/redirection/acces_interdit.php');
        exit;
    }
}

// Accès réservé aux administrateurs
function requireAdmin() {
    return authorize_roles(['Administrateur']);
}

// Accès réservé aux commerciaux + admins
function requireCommercial() {
    return authorize_roles(['Commercial', 'Administrateur']);
}
?>
