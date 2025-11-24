<?php
// API pour marquer un message comme lu
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// La fonction jsonResponse() est définie dans includes/api_helpers.php

try {
    // Inclure session_config.php EN PREMIER (il démarre la session si nécessaire)
    // session_config.php configure les paramètres de session AVANT de démarrer la session
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('messagerie_mark_read.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    error_log('messagerie_mark_read.php - Session user_id vide. Session ID: ' . session_id());
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

// Vérification CSRF
$csrfToken = $data['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

$messageId = (int)($data['message_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($messageId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID message invalide'], 400);
}

try {
    // Vérifier si la table messagerie existe
    // Note: SHOW TABLES LIKE ne supporte pas les paramètres liés, on utilise INFORMATION_SCHEMA
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = :table
    ");
    $checkTable->execute([':table' => 'messagerie']);
    if (((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) === 0) {
        jsonResponse(['ok' => false, 'error' => 'La table de messagerie n\'existe pas.'], 500);
    }
    
    // Vérifier que le message appartient bien au destinataire (ou message à tous)
    // Pour les messages directs, vérifier que lu = 0
    // Pour les messages "à tous", vérifier qu'il n'est pas déjà dans la table de lectures
    $check = $pdo->prepare("
        SELECT id, lu, id_destinataire, id_expediteur
        FROM messagerie 
        WHERE id = :id 
          AND (id_destinataire = :user_id OR (id_destinataire IS NULL AND id_expediteur != :user_id2))
        LIMIT 1
    ");
    $check->execute([':id' => $messageId, ':user_id' => $userId, ':user_id2' => $userId]);
    $message = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$message) {
        jsonResponse(['ok' => false, 'error' => 'Message introuvable'], 404);
    }
    
    // Pour les messages directs, vérifier qu'ils ne sont pas déjà lus
    if ($message['id_destinataire'] !== null && (int)$message['lu'] === 1) {
        jsonResponse(['ok' => false, 'error' => 'Message déjà lu'], 400);
    }
    
    // Pour les messages "à tous", vérifier qu'ils ne sont pas déjà dans la table de lectures
    if ($message['id_destinataire'] === null) {
        try {
            $checkLectures = $pdo->prepare("
                SELECT COUNT(*) as cnt 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = :table
            ");
            $checkLectures->execute([':table' => 'messagerie_lectures']);
            if (((int)$checkLectures->fetch(PDO::FETCH_ASSOC)['cnt']) > 0) {
                $checkRead = $pdo->prepare("
                    SELECT id FROM messagerie_lectures 
                    WHERE id_message = :id_message AND id_utilisateur = :user_id
                    LIMIT 1
                ");
                $checkRead->execute([':id_message' => $messageId, ':user_id' => $userId]);
                if ($checkRead->fetch()) {
                    jsonResponse(['ok' => false, 'error' => 'Message déjà lu'], 400);
                }
            }
        } catch (PDOException $e) {
            // Si la table n'existe pas, on continue
        }
    }
    
    // Gérer différemment les messages directs et les messages "à tous"
    if ($message['id_destinataire'] === null) {
        // Message "à tous" : utiliser la table de lectures
        try {
            // Vérifier si la table existe
            $checkLectures = $pdo->prepare("
                SELECT COUNT(*) as cnt 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = :table
            ");
            $checkLectures->execute([':table' => 'messagerie_lectures']);
            if (((int)$checkLectures->fetch(PDO::FETCH_ASSOC)['cnt']) > 0) {
                $insert = $pdo->prepare("
                    INSERT IGNORE INTO messagerie_lectures (id_message, id_utilisateur, date_lecture)
                    VALUES (:id_message, :user_id, NOW())
                ");
                $insert->execute([':id_message' => $messageId, ':user_id' => $userId]);
            } else {
                // Si la table n'existe pas, on met quand même à jour le champ lu (solution de secours)
                $update = $pdo->prepare("
                    UPDATE messagerie 
                    SET lu = 1, 
                        date_lecture = NOW()
                    WHERE id = :id
                ");
                $update->execute([':id' => $messageId]);
            }
        } catch (PDOException $e) {
            error_log('messagerie_mark_read.php - Erreur table lectures: ' . $e->getMessage());
            // Solution de secours : mettre à jour le champ lu
            $update = $pdo->prepare("
                UPDATE messagerie 
                SET lu = 1, 
                    date_lecture = NOW()
                WHERE id = :id
            ");
            $update->execute([':id' => $messageId]);
        }
    } else {
        // Message direct : mettre à jour le champ lu
        $update = $pdo->prepare("
            UPDATE messagerie 
            SET lu = 1, 
                date_lecture = NOW()
            WHERE id = :id 
              AND id_destinataire = :user_id
        ");
        $update->execute([':id' => $messageId, ':user_id' => $userId]);
    }
    
    jsonResponse(['ok' => true, 'message' => 'Message marqué comme lu']);
    
} catch (PDOException $e) {
    error_log('messagerie_mark_read.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_mark_read.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

