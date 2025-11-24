<?php
// API/chatroom_send.php
// Endpoint pour envoyer un message dans la chatroom globale

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    // Vérifier que la requête est en POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }

    // Récupérer les données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Données JSON invalides']);
        exit;
    }

    // Vérifier le token CSRF
    if (!verifyCsrfToken($data['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide']);
        exit;
    }

    // Récupérer l'ID utilisateur depuis la session
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
        exit;
    }

    // Valider le message
    $message = trim($data['message'] ?? '');
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le message ne peut pas être vide']);
        exit;
    }

    // Limiter la longueur du message (5000 caractères max)
    if (strlen($message) > 5000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le message est trop long (max 5000 caractères)']);
        exit;
    }

    // Récupérer les mentions (@username)
    $mentions = [];
    if (!empty($data['mentions']) && is_array($data['mentions'])) {
        $mentions = array_filter(array_map('intval', $data['mentions']));
    }

    // Récupérer le lien (client/SAV/livraison)
    $typeLien = null;
    $idLien = null;
    if (!empty($data['type_lien']) && in_array($data['type_lien'], ['client', 'livraison', 'sav'], true)) {
        $typeLien = $data['type_lien'];
        $idLien = isset($data['id_lien']) ? (int)$data['id_lien'] : null;
        if ($idLien <= 0) {
            $idLien = null;
            $typeLien = null;
        }
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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Table chatroom_messages non trouvée. Veuillez exécuter la migration SQL.']);
        exit;
    }

    // Préparer les mentions en JSON
    $mentionsJson = !empty($mentions) ? json_encode($mentions) : null;

    // Insérer le message
    $stmt = $pdo->prepare("
        INSERT INTO chatroom_messages (id_user, message, date_envoi, mentions, type_lien, id_lien)
        VALUES (:id_user, :message, NOW(), :mentions, :type_lien, :id_lien)
    ");

    $stmt->execute([
        ':id_user' => $userId,
        ':message' => $message,
        ':mentions' => $mentionsJson,
        ':type_lien' => $typeLien,
        ':id_lien' => $idLien
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
            m.date_envoi,
            m.mentions,
            m.type_lien,
            m.id_lien,
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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors de la récupération du message']);
        exit;
    }

    // Récupérer les infos du lien si présent
    $lienInfo = null;
    if ($messageData['type_lien'] && $messageData['id_lien']) {
        try {
            if ($messageData['type_lien'] === 'client') {
                $lienStmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id = :id LIMIT 1");
                $lienStmt->execute([':id' => $messageData['id_lien']]);
                $lienData = $lienStmt->fetch(PDO::FETCH_ASSOC);
                if ($lienData) {
                    $lienInfo = [
                        'type' => 'client',
                        'id' => (int)$lienData['id'],
                        'label' => $lienData['raison_sociale']
                    ];
                }
            } elseif ($messageData['type_lien'] === 'livraison') {
                $lienStmt = $pdo->prepare("SELECT id, reference FROM livraisons WHERE id = :id LIMIT 1");
                $lienStmt->execute([':id' => $messageData['id_lien']]);
                $lienData = $lienStmt->fetch(PDO::FETCH_ASSOC);
                if ($lienData) {
                    $lienInfo = [
                        'type' => 'livraison',
                        'id' => (int)$lienData['id'],
                        'label' => $lienData['reference']
                    ];
                }
            } elseif ($messageData['type_lien'] === 'sav') {
                $lienStmt = $pdo->prepare("SELECT id, reference FROM sav WHERE id = :id LIMIT 1");
                $lienStmt->execute([':id' => $messageData['id_lien']]);
                $lienData = $lienStmt->fetch(PDO::FETCH_ASSOC);
                if ($lienData) {
                    $lienInfo = [
                        'type' => 'sav',
                        'id' => (int)$lienData['id'],
                        'label' => $lienData['reference']
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log('chatroom_send.php - Erreur récupération lien: ' . $e->getMessage());
        }
    }

    // Parser les mentions
    $mentionsArray = [];
    if (!empty($messageData['mentions'])) {
        $mentionsArray = json_decode($messageData['mentions'], true) ?: [];
    }

    // Formater la réponse
    echo json_encode([
        'ok' => true,
        'message' => [
            'id' => (int)$messageData['id'],
            'id_user' => (int)$messageData['id_user'],
            'message' => $messageData['message'],
            'date_envoi' => $messageData['date_envoi'],
            'user_nom' => $messageData['nom'],
            'user_prenom' => $messageData['prenom'],
            'user_emploi' => $messageData['Emploi'],
            'mentions' => $mentionsArray,
            'lien' => $lienInfo
        ]
    ]);

} catch (PDOException $e) {
    error_log('chatroom_send.php - Erreur PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur lors de l\'envoi du message']);
} catch (Exception $e) {
    error_log('chatroom_send.php - Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}

