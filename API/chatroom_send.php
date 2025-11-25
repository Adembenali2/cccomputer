<?php
// API/chatroom_send.php
// Endpoint pour envoyer un message dans la chatroom globale

// Mode debug temporaire - activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0); // On garde à 0 pour ne pas polluer la sortie, mais on log tout
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/api_helpers.php';

try {
    initApi();
} catch (Throwable $e) {
    error_log('chatroom_send.php - Erreur initApi: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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
    error_log('chatroom_send.php - Erreur requirePdoConnection: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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

    // Valider le message (peut être vide si une image est envoyée)
    $message = trim($data['message'] ?? '');
    $imagePath = $data['image_path'] ?? null;

    // Le message ou l'image doit être présent
    if (empty($message) && empty($imagePath)) {
        jsonResponse(['ok' => false, 'error' => 'Le message ou une image doit être fourni'], 400);
    }

    // Limiter la longueur du message (5000 caractères max)
    if (strlen($message) > 5000) {
        jsonResponse(['ok' => false, 'error' => 'Le message est trop long (max 5000 caractères)'], 400);
    }

    // Récupérer les mentions (@username)
    $mentions = [];
    if (!empty($data['mentions']) && is_array($data['mentions'])) {
        $mentions = array_filter(array_map('intval', $data['mentions']));
    }

    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'chatroom_messages'");
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

    // Insérer le message
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chatroom_messages (id_user, message, image_path, date_envoi, mentions)
            VALUES (:id_user, :message, :image_path, NOW(), :mentions)
        ");

        $stmt->execute([
            ':id_user' => $userId,
            ':message' => $message,
            ':image_path' => $imagePath,
            ':mentions' => $mentionsJson
        ]);

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
            $checkNotifTable = $pdo->query("SHOW TABLES LIKE 'chatroom_notifications'");
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
        $checkNotifTable = $pdo->query("SHOW TABLES LIKE 'chatroom_notifications'");
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
    jsonResponse([
        'ok' => true,
        'message' => [
            'id' => (int)$messageData['id'],
            'id_user' => (int)$messageData['id_user'],
            'message' => $messageData['message'],
            'image_path' => $messageData['image_path'],
            'date_envoi' => $messageData['date_envoi'],
            'user_nom' => $messageData['nom'],
            'user_prenom' => $messageData['prenom'],
            'user_emploi' => $messageData['Emploi'],
            'is_me' => (int)$messageData['id_user'] === $userId,
            'mentions' => $mentionsArray
        ]
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

