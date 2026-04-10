<?php
// API/chatroom_get_notifications.php
// Endpoint pour récupérer les notifications de chatroom (mentions et nouveaux messages)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

// Pour ce endpoint spécifique, on retourne 0 au lieu d'une erreur si non authentifié
// pour ne pas bloquer le header et éviter le spam dans les logs Railway (comportement similaire à messagerie_get_unread_count.php)
if (empty($_SESSION['user_id'])) {
    jsonResponse([
        'ok' => true,
        'count' => 0,
        'notifications' => []
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
    }

    // Vérifier que l'utilisateur est authentifié
    $currentUserId = (int)$_SESSION['user_id'];
    if ($currentUserId <= 0) {
        // Ne devrait jamais arriver car on a déjà vérifié au-dessus, mais sécurité
        jsonResponse([
            'ok' => true,
            'count' => 0,
            'notifications' => []
        ]);
    }
    
    // Récupérer PDO (gérer gracieusement les erreurs pour ce endpoint de polling)
    try {
        $pdo = getPdo();
    } catch (RuntimeException $e) {
        error_log('chatroom_get_notifications.php: getPdo() failed - ' . $e->getMessage());
        jsonResponse([
            'ok' => true,
            'count' => 0,
            'notifications' => []
        ]);
    }

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
    // Retourner 0 au lieu d'une erreur pour éviter de bloquer le header et polluer les logs Railway
    jsonResponse([
        'ok' => true,
        'count' => 0,
        'notifications' => []
    ]);
} catch (Throwable $e) {
    error_log('chatroom_get_notifications.php - Erreur: ' . $e->getMessage());
    // Retourner 0 au lieu d'une erreur pour éviter de bloquer le header et polluer les logs Railway
    jsonResponse([
        'ok' => true,
        'count' => 0,
        'notifications' => []
    ]);
}

