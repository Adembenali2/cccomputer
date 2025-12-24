<?php
// API/chatroom_mark_notifications_read.php
// Endpoint pour marquer les notifications chatroom comme lues

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once __DIR__ . '/../includes/api_helpers.php';

try {
    initApi();
} catch (Throwable $e) {
    error_log('chatroom_mark_notifications_read.php - Erreur initApi: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

$pdo = getPdoOrFail();

try {
    requireApiAuth();
} catch (Throwable $e) {
    error_log('chatroom_mark_notifications_read.php - Erreur requireApiAuth: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'authentification'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$currentUserId = (int)$_SESSION['user_id'];

try {
    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_notifications'");
        $checkTable->execute();
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('chatroom_mark_notifications_read.php - Erreur vérification table: ' . $e->getMessage());
    }

    if (!$tableExists) {
        jsonResponse(['ok' => true, 'marked' => 0, 'message' => 'Table notifications n\'existe pas']);
    }

    // Récupérer les paramètres (optionnel : marquer une notification spécifique ou toutes)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
    
    $notificationId = isset($data['notification_id']) ? (int)$data['notification_id'] : null;
    $markAll = isset($data['mark_all']) && $data['mark_all'] === true;

    $markedCount = 0;

    if ($markAll) {
        // Marquer toutes les notifications non lues de l'utilisateur comme lues
        $stmt = $pdo->prepare("
            UPDATE chatroom_notifications 
            SET lu = 1 
            WHERE id_user = :user_id AND lu = 0
        ");
        $stmt->execute([':user_id' => $currentUserId]);
        $markedCount = $stmt->rowCount();
    } elseif ($notificationId > 0) {
        // Marquer une notification spécifique comme lue
        $stmt = $pdo->prepare("
            UPDATE chatroom_notifications 
            SET lu = 1 
            WHERE id = :id AND id_user = :user_id AND lu = 0
        ");
        $stmt->execute([
            ':id' => $notificationId,
            ':user_id' => $currentUserId
        ]);
        $markedCount = $stmt->rowCount();
    } else {
        // Par défaut : marquer toutes les notifications non lues
        $stmt = $pdo->prepare("
            UPDATE chatroom_notifications 
            SET lu = 1 
            WHERE id_user = :user_id AND lu = 0
        ");
        $stmt->execute([':user_id' => $currentUserId]);
        $markedCount = $stmt->rowCount();
    }

    jsonResponse([
        'ok' => true,
        'marked' => $markedCount,
        'message' => $markedCount > 0 ? "{$markedCount} notification(s) marquée(s) comme lue(s)" : 'Aucune notification à marquer'
    ]);

} catch (PDOException $e) {
    $errorInfo = $e->errorInfo ?? [];
    error_log('chatroom_mark_notifications_read.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données',
        'debug' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'sql_state' => $errorInfo[0] ?? null
        ]
    ], 500);
} catch (Throwable $e) {
    error_log('chatroom_mark_notifications_read.php - Erreur: ' . $e->getMessage());
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur'
    ], 500);
}


