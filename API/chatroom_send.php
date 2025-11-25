<?php
// API/chatroom_send.php
// Endpoint pour envoyer un message dans la chatroom globale

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
    error_log('chatroom_send.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier que la requête est en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Récupérer l'ID utilisateur depuis la session
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Récupérer les données JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
}

// Vérifier le token CSRF
$csrfToken = $data['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
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
        error_log('chatroom_send.php - Erreur vérification table: ' . $e->getMessage());
    }

    if (!$tableExists) {
        jsonResponse(['ok' => false, 'error' => 'Table chatroom_messages non trouvée. Veuillez exécuter la migration SQL.'], 500);
    }

    // Préparer les mentions en JSON
    $mentionsJson = !empty($mentions) ? json_encode($mentions) : null;

    // Insérer le message
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
    error_log('chatroom_send.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur lors de l\'envoi du message'], 500);
} catch (Exception $e) {
    error_log('chatroom_send.php - Erreur: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}

