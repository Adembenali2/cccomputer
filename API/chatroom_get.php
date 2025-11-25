<?php
// API/chatroom_get.php
// Endpoint pour récupérer les messages de la chatroom globale

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../includes/api_helpers.php';

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('chatroom_get.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier que la requête est en GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Récupérer l'ID utilisateur depuis la session
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

try {

    // Paramètres de pagination (optionnels)
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = max(1, min(500, $limit)); // Entre 1 et 500

    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'chatroom_messages'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('chatroom_get.php - Erreur vérification table: ' . $e->getMessage());
    }

    if (!$tableExists) {
        // Si la table n'existe pas, retourner un tableau vide
        jsonResponse([
            'ok' => true,
            'messages' => [],
            'has_more' => false
        ]);
    }

    // Construire la requête selon les paramètres
    if ($sinceId > 0) {
        // Récupérer uniquement les nouveaux messages (depuis le dernier ID)
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.id_user,
                m.message,
                m.image_path,
                m.date_envoi,
                m.mentions,
                u.nom,
                u.prenom,
                u.Emploi
            FROM chatroom_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_user
            WHERE m.id > :since_id
            ORDER BY m.date_envoi ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        // Récupérer les messages les plus récents
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.id_user,
                m.message,
                m.image_path,
                m.date_envoi,
                m.mentions,
                u.nom,
                u.prenom,
                u.Emploi
            FROM chatroom_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_user
            ORDER BY m.date_envoi DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }

    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si on récupère depuis un ID, on garde l'ordre chronologique
    // Sinon, on inverse pour avoir les plus anciens en premier
    if ($sinceId <= 0) {
        $messages = array_reverse($messages);
    }

    // Formater les messages
    $formattedMessages = [];
    foreach ($messages as $msg) {
        // Parser les mentions
        $mentionsArray = [];
        if (!empty($msg['mentions'])) {
            $mentionsArray = json_decode($msg['mentions'], true) ?: [];
        }

        $formattedMessages[] = [
            'id' => (int)$msg['id'],
            'id_user' => (int)$msg['id_user'],
            'message' => $msg['message'],
            'image_path' => $msg['image_path'],
            'date_envoi' => $msg['date_envoi'],
            'user_nom' => $msg['nom'],
            'user_prenom' => $msg['prenom'],
            'user_emploi' => $msg['Emploi'],
            'is_me' => (int)$msg['id_user'] === $currentUserId,
            'mentions' => $mentionsArray
        ];
    }

    // Vérifier s'il y a plus de messages
    $hasMore = false;
    if (count($messages) > 0) {
        $oldestId = min(array_column($messages, 'id'));
        $checkMore = $pdo->prepare("SELECT COUNT(*) FROM chatroom_messages WHERE id < :oldest_id");
        $checkMore->execute([':oldest_id' => $oldestId]);
        $hasMore = (int)$checkMore->fetchColumn() > 0;
    }

    jsonResponse([
        'ok' => true,
        'messages' => $formattedMessages,
        'has_more' => $hasMore,
        'count' => count($formattedMessages)
    ]);

} catch (PDOException $e) {
    error_log('chatroom_get.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur lors de la récupération des messages'], 500);
} catch (Exception $e) {
    error_log('chatroom_get.php - Erreur: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}

