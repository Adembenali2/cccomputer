<?php
// API/private_messages_list_users.php
// Liste des utilisateurs pour la messagerie privée (exclut l'utilisateur connecté)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();
$currentUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $query = trim($_GET['q'] ?? '');
    $limit = max(1, min((int)($_GET['limit'] ?? 100), 200));

    if (empty($query)) {
        $stmt = $pdo->prepare("
            SELECT id, nom, prenom, Emploi
            FROM utilisateurs
            WHERE statut = 'actif' AND id != ?
            ORDER BY nom ASC, prenom ASC
            LIMIT ?
        ");
        $stmt->execute([$currentUserId, $limit]);
    } else {
        $search = $query . '%';
        $stmt = $pdo->prepare("
            SELECT id, nom, prenom, Emploi
            FROM utilisateurs
            WHERE statut = 'actif' AND id != ?
            AND (nom LIKE ? OR prenom LIKE ? OR CONCAT(prenom, ' ', nom) LIKE ? OR CONCAT(nom, ' ', prenom) LIKE ?)
            ORDER BY nom ASC, prenom ASC
            LIMIT ?
        ");
        $stmt->execute([$currentUserId, $search, $search, $search, $search, $limit]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $users = [];
    foreach ($rows as $r) {
        $users[] = [
            'id' => (int)$r['id'],
            'nom' => $r['nom'],
            'prenom' => $r['prenom'],
            'display_name' => trim($r['prenom'] . ' ' . $r['nom']),
            'emploi' => $r['Emploi'],
        ];
    }

    jsonResponse(['ok' => true, 'users' => $users]);
} catch (PDOException $e) {
    error_log('private_messages_list_users.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
