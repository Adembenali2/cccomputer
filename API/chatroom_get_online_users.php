<?php
// API/chatroom_get_online_users.php
// Endpoint pour récupérer les utilisateurs actuellement en ligne (présence via last_activity)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

// Retourner liste vide si non authentifié (évite de bloquer l'UI)
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => true, 'users' => [], 'count' => 0]);
}

try {
    requireApiAuth();
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$pdo = getPdoOrFail();
$currentUserId = (int)$_SESSION['user_id'];

// Mise à jour last_activity pour maintenir l'utilisateur "en ligne" pendant qu'il consulte la messagerie
$lastUpdate = $_SESSION['last_activity_update'] ?? 0;
if (time() - $lastUpdate > 30) {
    try {
        $upd = $pdo->prepare("UPDATE utilisateurs SET last_activity = NOW() WHERE id = ?");
        $upd->execute([$currentUserId]);
        $_SESSION['last_activity_update'] = time();
    } catch (PDOException $e) {
        // Ignorer si colonne absente
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    // Utilisateurs en ligne : last_activity dans les 5 dernières minutes
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, Emploi
        FROM utilisateurs
        WHERE statut = 'actif'
        AND (
            (last_activity IS NOT NULL AND last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
            OR (last_activity IS NULL AND date_modification >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        )
        ORDER BY nom ASC, prenom ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = [];
    foreach ($rows as $row) {
        $users[] = [
            'id' => (int)$row['id'],
            'nom' => $row['nom'],
            'prenom' => $row['prenom'],
            'display_name' => trim($row['prenom'] . ' ' . $row['nom']),
            'emploi' => $row['Emploi'],
            'is_me' => (int)$row['id'] === $currentUserId,
        ];
    }

    jsonResponse([
        'ok' => true,
        'users' => $users,
        'count' => count($users),
    ]);
} catch (PDOException $e) {
    error_log('chatroom_get_online_users.php: ' . $e->getMessage());
    jsonResponse(['ok' => true, 'users' => [], 'count' => 0]);
} catch (Throwable $e) {
    error_log('chatroom_get_online_users.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
