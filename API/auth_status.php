<?php
// API/auth_status.php
// Endpoint léger pour vérifier le statut d'authentification
// Permet d'éviter le polling inutile si l'utilisateur n'est pas authentifié

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

// Retourner simplement le statut d'authentification
jsonResponse([
    'ok' => true,
    'authenticated' => !empty($_SESSION['user_id']),
    'user_id' => !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
]);

