<?php
// API pour supprimer un message (marquer comme supprimé)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
} catch (Throwable $e) {
    error_log('messagerie_delete.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
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
    // Vérifier que le message existe et que l'utilisateur a le droit de le supprimer
    $check = $pdo->prepare("
        SELECT id, id_expediteur, id_destinataire, sujet, id_message_parent
        FROM messagerie 
        WHERE id = :id
        LIMIT 1
    ");
    $check->execute([':id' => $messageId]);
    $message = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$message) {
        jsonResponse(['ok' => false, 'error' => 'Message introuvable'], 404);
    }
    
    $isExpediteur = (int)$message['id_expediteur'] === $userId;
    $isDestinataire = ($message['id_destinataire'] === null || (int)$message['id_destinataire'] === $userId);
    
    if (!$isExpediteur && !$isDestinataire) {
        jsonResponse(['ok' => false, 'error' => 'Vous n\'avez pas le droit de supprimer ce message'], 403);
    }
    
    // Marquer comme supprimé
    if ($isExpediteur) {
        $update = $pdo->prepare("
            UPDATE messagerie 
            SET supprime_expediteur = 1
            WHERE id = :id
        ");
        $update->execute([':id' => $messageId]);
        $whoDeleted = 'expéditeur';
    } else {
        // Pour les messages "à tous", utiliser la table de lectures si elle existe
        if ($message['id_destinataire'] === null) {
            try {
                $deleteRead = $pdo->prepare("
                    DELETE FROM messagerie_lectures 
                    WHERE id_message = :id_message AND id_utilisateur = :user_id
                ");
                $deleteRead->execute([':id_message' => $messageId, ':user_id' => $userId]);
            } catch (PDOException $e) {
                // Si la table n'existe pas, on continue
            }
        }
        $update = $pdo->prepare("
            UPDATE messagerie 
            SET supprime_destinataire = 1
            WHERE id = :id
        ");
        $update->execute([':id' => $messageId]);
        $whoDeleted = 'destinataire';
    }
    
    // Enregistrer dans l'historique
    try {
        $isReply = !empty($message['id_message_parent']);
        $messageType = $isReply ? 'réponse' : 'message';
        $details = sprintf(
            'Suppression d\'un %s (ID %d) : "%s" - Supprimé par %s',
            $messageType,
            $messageId,
            mb_substr($message['sujet'] ?? '', 0, 50),
            $whoDeleted
        );
        enregistrerAction($pdo, $userId, 'message_supprime', $details);
    } catch (Throwable $e) {
        error_log('messagerie_delete.php log error: ' . $e->getMessage());
    }
    
    jsonResponse(['ok' => true, 'message' => 'Message supprimé avec succès']);
    
} catch (PDOException $e) {
    error_log('messagerie_delete.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_delete.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

