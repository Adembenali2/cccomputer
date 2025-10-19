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
 */
function requireAdmin() {
    return authorize_roles(['Administrateur']);
}

/**
 * Accès réservé aux commerciaux et administrateurs
 */
function requireCommercial() {
    return authorize_roles(['Commercial', 'Administrateur']);
}
?>
