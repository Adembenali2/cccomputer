<?php
// API/chatroom_send.php
// Endpoint pour envoyer un message dans la chatroom globale

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Buffer de sortie pour capturer toute sortie accidentelle
ob_start();

// Gestionnaire d'erreur fatale (doit être défini AVANT tout require)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log('chatroom_send.php FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Erreur fatale PHP',
            'debug' => [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

require_once __DIR__ . '/../includes/api_helpers.php';

// Gestionnaire d'exception global pour capturer toutes les exceptions non capturées
set_exception_handler(function($exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('chatroom_send.php UNCAUGHT EXCEPTION: ' . $exception->getMessage() . ' | Trace: ' . $exception->getTraceAsString());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Exception non capturée',
        'debug' => [
            'message' => $exception->getMessage(),
            'type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString())
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

try {
    initApi();
} catch (Throwable $e) {
    $errorInfo = [];
    if ($e instanceof PDOException && isset($e->errorInfo)) {
        $errorInfo = [
            'sql_state' => $e->errorInfo[0] ?? null,
            'driver_code' => $e->errorInfo[1] ?? null,
            'driver_message' => $e->errorInfo[2] ?? null
        ];
    }
    
    error_log('chatroom_send.php - Erreur initApi: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur d\'initialisation',
        'debug' => array_merge([
            'message' => $e->getMessage(),
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ], $errorInfo)
    ], 500);
}

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

try {
    requireApiAuth();
} catch (Throwable $e) {
    error_log('chatroom_send.php - Erreur requireApiAuth: ' . $e->getMessage());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur d\'authentification',
        'debug' => $e->getMessage()
    ], 500);
}

// Vérifier que la requête est en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Récupérer l'ID utilisateur depuis la session
$userId = (int)$_SESSION['user_id'];

// Récupérer les données JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
}

// Vérifier le token CSRF (depuis les données JSON)
$csrfToken = $data['csrf_token'] ?? '';
try {
    requireCsrfToken($csrfToken);
} catch (Throwable $e) {
    error_log('chatroom_send.php - Erreur CSRF: ' . $e->getMessage());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur de vérification CSRF',
        'debug' => $e->getMessage()
    ], 403);
}

try {

    // Valider le message
    $message = trim($data['message'] ?? '');
    $imagePath = trim($data['image_path'] ?? '');

    // Le message ou l'image doit être présent
    if (empty($message) && empty($imagePath)) {
        jsonResponse(['ok' => false, 'error' => 'Le message ou une image doit être présent'], 400);
    }

    // Limiter la longueur du message (5000 caractères max)
    if (!empty($message) && strlen($message) > 5000) {
        jsonResponse(['ok' => false, 'error' => 'Le message est trop long (max 5000 caractères)'], 400);
    }
    
    // Valider le chemin de l'image si présent
    if (!empty($imagePath) && !preg_match('/^\/uploads\/chatroom\/[a-zA-Z0-9_\-\.]+$/', $imagePath)) {
        jsonResponse(['ok' => false, 'error' => 'Chemin d\'image invalide'], 400);
    }

    // Récupérer les mentions (@username)
    $mentions = [];
    if (!empty($data['mentions']) && is_array($data['mentions'])) {
        $mentions = array_filter(array_map('intval', $data['mentions']));
    }

    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_messages'");
        $checkTable->execute();
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('chatroom_send.php - Erreur vérification table: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
        throw $e; // Re-lancer pour être capturé par le catch global
    }

    if (!$tableExists) {
        jsonResponse(['ok' => false, 'error' => 'Table chatroom_messages non trouvée. Veuillez exécuter la migration SQL.'], 500);
    }

    // Préparer les mentions en JSON
    $mentionsJson = !empty($mentions) ? json_encode($mentions) : null;

    // Vérifier si la colonne image_path existe
    $hasImagePath = false;
    try {
        $checkColumn = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'chatroom_messages' 
            AND COLUMN_NAME = 'image_path'
        ");
        $checkColumn->execute();
        $hasImagePath = (int)$checkColumn->fetchColumn() > 0;
    } catch (PDOException $e) {
        // Si la vérification échoue, on continue sans image_path
        error_log('chatroom_send.php - Erreur vérification colonne image_path: ' . $e->getMessage());
    }

    // Insérer le message
    try {
        if ($hasImagePath) {
            $stmt = $pdo->prepare("
                INSERT INTO chatroom_messages (id_user, message, date_envoi, mentions, image_path)
                VALUES (:id_user, :message, NOW(), :mentions, :image_path)
            ");
            $stmt->execute([
                ':id_user' => $userId,
                ':message' => $message ?: null,
                ':mentions' => $mentionsJson,
                ':image_path' => $imagePath ?: null
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO chatroom_messages (id_user, message, date_envoi, mentions)
                VALUES (:id_user, :message, NOW(), :mentions)
            ");
            $stmt->execute([
                ':id_user' => $userId,
                ':message' => $message ?: null,
                ':mentions' => $mentionsJson
            ]);
        }

        $messageId = (int)$pdo->lastInsertId();
        
        if ($messageId <= 0) {
            throw new Exception('Impossible de récupérer l\'ID du message inséré');
        }
    } catch (PDOException $e) {
        $errorInfo = $e->errorInfo ?? [];
        error_log('chatroom_send.php - Erreur insertion message: ' . $e->getMessage() . ' | Code: ' . $e->getCode() . ' | SQL State: ' . ($errorInfo[0] ?? 'N/A'));
        throw $e; // Re-lancer pour être capturé par le catch global
    }

    // Créer les notifications pour les utilisateurs mentionnés
    if (!empty($mentions)) {
        try {
            $notifTableExists = false;
            $checkNotifTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_notifications'");
            $checkNotifTable->execute();
            $notifTableExists = $checkNotifTable->rowCount() > 0;

            if ($notifTableExists) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO chatroom_notifications (id_user, id_message, type, lu, date_creation)
                    VALUES (:id_user, :id_message, 'mention', 0, NOW())
                ");
                foreach ($mentions as $mentionedUserId) {
                    if ($mentionedUserId != $userId) { // Ne pas se notifier soi-même
                        $notifStmt->execute([
                            ':id_user' => $mentionedUserId,
                            ':id_message' => $messageId
                        ]);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('chatroom_send.php - Erreur création notifications: ' . $e->getMessage());
            // On continue même si les notifications échouent
        }
    }

    // Créer une notification pour tous les utilisateurs (nouveau message dans la chatroom)
    // Sauf pour l'expéditeur et les utilisateurs déjà mentionnés
    try {
        $notifTableExists = false;
        $checkNotifTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_notifications'");
        $checkNotifTable->execute();
        $notifTableExists = $checkNotifTable->rowCount() > 0;

        if ($notifTableExists) {
            $excludeUsers = array_merge([$userId], $mentions);
            $placeholders = implode(',', array_fill(0, count($excludeUsers), '?'));
            
            $allUsersStmt = $pdo->prepare("
                SELECT id FROM utilisateurs 
                WHERE statut = 'actif' AND id NOT IN ($placeholders)
            ");
            $allUsersStmt->execute($excludeUsers);
            $allUsers = $allUsersStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($allUsers)) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO chatroom_notifications (id_user, id_message, type, lu, date_creation)
                    VALUES (:id_user, :id_message, 'message', 0, NOW())
                ");
                foreach ($allUsers as $notifyUserId) {
                    $notifStmt->execute([
                        ':id_user' => $notifyUserId,
                        ':id_message' => $messageId
                    ]);
                }
            }
        }
    } catch (PDOException $e) {
        error_log('chatroom_send.php - Erreur création notifications générales: ' . $e->getMessage());
        // On continue même si les notifications échouent
    }

    // Récupérer les informations complètes du message pour la réponse
    try {
        $selectColumns = $hasImagePath 
            ? 'm.id, m.id_user, m.message, m.date_envoi, m.mentions, m.image_path, u.nom, u.prenom, u.Emploi'
            : 'm.id, m.id_user, m.message, m.date_envoi, m.mentions, u.nom, u.prenom, u.Emploi';
        
        $stmt = $pdo->prepare("
            SELECT 
                {$selectColumns}
            FROM chatroom_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_user
            WHERE m.id = :id
        ");

        $stmt->execute([':id' => $messageId]);
        $messageData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errorInfo = $e->errorInfo ?? [];
        error_log('chatroom_send.php - Erreur récupération message: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
        throw $e; // Re-lancer pour être capturé par le catch global
    }

    if (!$messageData) {
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de la récupération du message'], 500);
    }

    // Parser les mentions
    $mentionsArray = [];
    if (!empty($messageData['mentions'])) {
        $mentionsArray = json_decode($messageData['mentions'], true) ?: [];
    }
    
    // Formater la réponse
    $responseMessage = [
        'id' => (int)$messageData['id'],
        'id_user' => (int)$messageData['id_user'],
        'message' => $messageData['message'],
        'date_envoi' => $messageData['date_envoi'],
        'user_nom' => $messageData['nom'],
        'user_prenom' => $messageData['prenom'],
        'user_emploi' => $messageData['Emploi'],
        'is_me' => (int)$messageData['id_user'] === $userId,
        'mentions' => $mentionsArray
    ];
    
    if ($hasImagePath && isset($messageData['image_path'])) {
        $responseMessage['image_path'] = $messageData['image_path'];
    }
    
    jsonResponse([
        'ok' => true,
        'message' => $responseMessage
    ]);

} catch (PDOException $e) {
    $errorInfo = $e->errorInfo ?? [];
    error_log('chatroom_send.php - Erreur PDO: ' . $e->getMessage() . ' | Code: ' . $e->getCode() . ' | SQL State: ' . ($errorInfo[0] ?? 'N/A') . ' | Driver Code: ' . ($errorInfo[1] ?? 'N/A'));
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur base de données lors de l\'envoi du message',
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
    error_log('chatroom_send.php - Erreur: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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

