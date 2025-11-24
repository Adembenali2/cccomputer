<?php
/**
 * API pour rechercher des utilisateurs (recherche intelligente en temps réel)
 * GET /API/profil_search_users.php?q=recherche
 */

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/auth_role.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/helpers.php';
} catch (Throwable $e) {
    error_log('profil_search_users.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Vérifier les permissions
$emploi = $_SESSION['emploi'] ?? '';
$allowedRoles = ['Admin', 'Dirigeant', 'Technicien', 'Livreur'];
if (empty($emploi) || !in_array($emploi, $allowedRoles, true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès non autorisé'], 403);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    // Récupérer et nettoyer la recherche
    $search = trim($_GET['q'] ?? '');
    $search = mb_substr(preg_replace('/\s+/', ' ', $search), 0, 120);
    
    // Construire la requête SQL avec LIKE 'saisie%' (commence par)
    $params = [];
    $where = [];
    
    if ($search !== '') {
        $searchPattern = $search . '%';
        
        // Recherche intelligente : nom OU email OU prénom commence par la saisie
        $where[] = "(LOWER(nom) LIKE LOWER(:search_nom) OR LOWER(prenom) LIKE LOWER(:search_prenom) OR LOWER(Email) LIKE LOWER(:search_email))";
        $params[':search_nom'] = $searchPattern;
        $params[':search_prenom'] = $searchPattern;
        $params[':search_email'] = $searchPattern;
    }
    
    $sql = "SELECT id, Email, nom, prenom, telephone, Emploi, statut, date_debut
            FROM utilisateurs";
    
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' OR ', $where);
    }
    
    $sql .= ' ORDER BY nom ASC, prenom ASC LIMIT 300';
    
    $users = safeFetchAll($pdo, $sql, $params, 'profil_search_users');
    
    // Formater les résultats pour le frontend
    $formatted = [];
    foreach ($users as $user) {
        $formatted[] = [
            'id' => (int)$user['id'],
            'nom' => $user['nom'],
            'prenom' => $user['prenom'],
            'full_name' => trim($user['nom'] . ' ' . $user['prenom']),
            'email' => $user['Email'],
            'telephone' => $user['telephone'] ?? '',
            'emploi' => $user['Emploi'],
            'statut' => $user['statut'],
            'date_debut' => $user['date_debut']
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'users' => $formatted,
        'count' => count($formatted)
    ]);
    
} catch (PDOException $e) {
    error_log('profil_search_users.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('profil_search_users.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

