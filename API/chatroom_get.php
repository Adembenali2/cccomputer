<?php
// API/chatroom_get.php
// Endpoint pour récupérer les messages de la chatroom globale

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    // Vérifier que la requête est en GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }

    // Récupérer l'ID utilisateur depuis la session
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
        exit;
    }

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
        echo json_encode([
            'ok' => true,
            'messages' => [],
            'has_more' => false
        ]);
        exit;
    }

    // Construire la requête selon les paramètres
    if ($sinceId > 0) {
        // Récupérer uniquement les nouveaux messages (depuis le dernier ID)
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
                m.date_envoi,
                m.mentions,
                m.type_lien,
                m.id_lien,
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

        // Récupérer les infos du lien si présent
        $lienInfo = null;
        if ($msg['type_lien'] && $msg['id_lien']) {
            try {
                if ($msg['type_lien'] === 'client') {
                    $lienStmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id = :id LIMIT 1");
                    $lienStmt->execute([':id' => $msg['id_lien']]);
                    $lienData = $lienStmt->fetch(PDO::FETCH_ASSOC);
                    if ($lienData) {
                        $lienInfo = [
                            'type' => 'client',
                            'id' => (int)$lienData['id'],
                            'label' => $lienData['raison_sociale']
                        ];
                    }
                } elseif ($msg['type_lien'] === 'livraison') {
                    $lienStmt = $pdo->prepare("SELECT id, reference FROM livraisons WHERE id = :id LIMIT 1");
                    $lienStmt->execute([':id' => $msg['id_lien']]);
                    $lienData = $lienStmt->fetch(PDO::FETCH_ASSOC);
                    if ($lienData) {
                        $lienInfo = [
                            'type' => 'livraison',
                            'id' => (int)$lienData['id'],
                            'label' => $lienData['reference']
                        ];
                    }
                } elseif ($msg['type_lien'] === 'sav') {
                    $lienStmt = $pdo->prepare("SELECT id, reference FROM sav WHERE id = :id LIMIT 1");
                    $lienStmt->execute([':id' => $msg['id_lien']]);
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
                error_log('chatroom_get.php - Erreur récupération lien: ' . $e->getMessage());
            }
        }

        $formattedMessages[] = [
            'id' => (int)$msg['id'],
            'id_user' => (int)$msg['id_user'],
            'message' => $msg['message'],
            'date_envoi' => $msg['date_envoi'],
            'user_nom' => $msg['nom'],
            'user_prenom' => $msg['prenom'],
            'user_emploi' => $msg['Emploi'],
            'is_me' => (int)$msg['id_user'] === $currentUserId,
            'mentions' => $mentionsArray,
            'lien' => $lienInfo
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

    echo json_encode([
        'ok' => true,
        'messages' => $formattedMessages,
        'has_more' => $hasMore,
        'count' => count($formattedMessages)
    ]);

} catch (PDOException $e) {
    error_log('chatroom_get.php - Erreur PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur lors de la récupération des messages']);
} catch (Exception $e) {
    error_log('chatroom_get.php - Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}

