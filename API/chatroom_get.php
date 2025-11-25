<?php
// API/chatroom_get.php
// Endpoint pour récupérer les messages de la chatroom globale

// Mode debug temporaire - activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0); // On garde à 0 pour ne pas polluer la sortie, mais on log tout
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/api_helpers.php';

try {
    initApi();
} catch (Throwable $e) {
    error_log('chatroom_get.php - Erreur initApi: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur d\'initialisation',
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}

try {
    $pdo = requirePdoConnection();
} catch (Throwable $e) {
    error_log('chatroom_get.php - Erreur requirePdoConnection: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur de connexion à la base de données',
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}

try {
    requireApiAuth();
} catch (Throwable $e) {
    error_log('chatroom_get.php - Erreur requireApiAuth: ' . $e->getMessage());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur d\'authentification',
        'debug' => $e->getMessage()
    ], 500);
}

// Vérifier que la requête est en GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Récupérer l'ID utilisateur depuis la session
$currentUserId = (int)$_SESSION['user_id'];

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
        error_log('chatroom_get.php - Erreur vérification table: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
        // On continue même si la vérification échoue, on essaiera la requête quand même
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

    try {
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('chatroom_get.php - Erreur exécution requête: ' . $e->getMessage() . ' | Code: ' . $e->getCode() . ' | SQL State: ' . $e->errorInfo[0]);
        throw $e; // Re-lancer pour être capturé par le catch global
    }

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
        try {
            $oldestId = min(array_column($messages, 'id'));
            $checkMore = $pdo->prepare("SELECT COUNT(*) FROM chatroom_messages WHERE id < :oldest_id");
            $checkMore->execute([':oldest_id' => $oldestId]);
            $hasMore = (int)$checkMore->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log('chatroom_get.php - Erreur vérification has_more: ' . $e->getMessage());
            // On continue même si cette vérification échoue
        }
    }

    jsonResponse([
        'ok' => true,
        'messages' => $formattedMessages,
        'has_more' => $hasMore,
        'count' => count($formattedMessages)
    ]);

} catch (PDOException $e) {
    $errorInfo = $e->errorInfo ?? [];
    error_log('chatroom_get.php - Erreur PDO: ' . $e->getMessage() . ' | Code: ' . $e->getCode() . ' | SQL State: ' . ($errorInfo[0] ?? 'N/A') . ' | Driver Code: ' . ($errorInfo[1] ?? 'N/A'));
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur base de données lors de la récupération des messages',
        'debug' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'sql_state' => $errorInfo[0] ?? null,
            'driver_code' => $errorInfo[1] ?? null,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], 500);
} catch (Throwable $e) {
    error_log('chatroom_get.php - Erreur: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur serveur',
        'debug' => [
            'message' => $e->getMessage(),
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], 500);
}

