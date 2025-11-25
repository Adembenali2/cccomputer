<?php
// API/chatroom_search_users.php
// Endpoint pour rechercher des utilisateurs pour les mentions (@username)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
$pdo = requirePdoConnection();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$currentUserId = (int)$_SESSION['user_id'];

try {

    $query = trim($_GET['q'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 10), 20);

    if (empty($query) || strlen($query) < 1) {
        jsonResponse(['ok' => true, 'users' => []]);
    }

    $searchTerm = '%' . $query . '%';
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nom,
            prenom,
            Email,
            Emploi
        FROM utilisateurs
        WHERE statut = 'actif'
          AND id != :current_user_id
          AND (
            nom LIKE :search
            OR prenom LIKE :search
            OR Email LIKE :search
            OR CONCAT(prenom, ' ', nom) LIKE :search
            OR CONCAT(nom, ' ', prenom) LIKE :search
          )
        ORDER BY nom ASC, prenom ASC
        LIMIT :limit
    ");

    $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
    $stmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($user) {
        return [
            'id' => (int)$user['id'],
            'nom' => $user['nom'],
            'prenom' => $user['prenom'],
            'email' => $user['Email'],
            'emploi' => $user['Emploi'],
            'display_name' => trim($user['prenom'] . ' ' . $user['nom'])
        ];
    }, $users);

    jsonResponse([
        'ok' => true,
        'users' => $formatted
    ]);

} catch (PDOException $e) {
    error_log('chatroom_search_users.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
} catch (Exception $e) {
    error_log('chatroom_search_users.php - Erreur: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}

