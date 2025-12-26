<?php
// API/chatroom_get_notifications.php
// Endpoint pour récupérer les notifications de chatroom (mentions et nouveaux messages)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    
    // Récupérer PDO
    $pdo = getPdoOrFail();

    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'chatroom_notifications'
        ");
        $checkTable->execute();
        $tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
    } catch (PDOException $e) {
        error_log('chatroom_get_notifications.php - Erreur vérification table: ' . $e->getMessage());
    }

    if (!$tableExists) {
        jsonResponse([
            'ok' => true,
            'count' => 0,
            'notifications' => []
        ]);
    }

    // Récupérer les notifications non lues
    $stmt = $pdo->prepare("
        SELECT 
            n.id,
            n.id_message,
            n.type,
            n.date_creation,
            m.message,
            m.id_user as message_user_id,
            u.nom as user_nom,
            u.prenom as user_prenom
        FROM chatroom_notifications n
        INNER JOIN chatroom_messages m ON m.id = n.id_message
        INNER JOIN utilisateurs u ON u.id = m.id_user
        WHERE n.id_user = :user_id
          AND n.lu = 0
        ORDER BY n.date_creation DESC
        LIMIT 50
    ");

    $stmt->execute([':user_id' => $currentUserId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compter le total
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM chatroom_notifications 
        WHERE id_user = :user_id AND lu = 0
    ");
    $countStmt->execute([':user_id' => $currentUserId]);
    $count = (int)$countStmt->fetchColumn();

    // Formater les notifications
    $formatted = array_map(function($notif) {
        return [
            'id' => (int)$notif['id'],
            'id_message' => (int)$notif['id_message'],
            'type' => $notif['type'],
            'date_creation' => $notif['date_creation'],
            'message_preview' => mb_substr($notif['message'], 0, 100),
            'user_nom' => $notif['user_nom'],
            'user_prenom' => $notif['user_prenom']
        ];
    }, $notifications);

    jsonResponse([
        'ok' => true,
        'count' => $count,
        'notifications' => $formatted
    ]);

} catch (PDOException $e) {
    error_log('chatroom_get_notifications.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
} catch (Exception $e) {
    error_log('chatroom_get_notifications.php - Erreur: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}

