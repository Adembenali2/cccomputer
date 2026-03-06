<?php
// API/private_messages_send.php
// Envoi d'un message privé à un utilisateur

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();
$currentUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
}

$csrfToken = $data['csrf_token'] ?? '';
try {
    requireCsrfToken($csrfToken);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

$receiverId = !empty($data['id_receiver']) ? (int)$data['id_receiver'] : 0;
$message = isset($data['message']) ? trim($data['message']) : '';
$imagePath = isset($data['image_path']) && $data['image_path'] !== null ? trim($data['image_path']) : '';

if ($receiverId <= 0 || $receiverId === $currentUserId) {
    jsonResponse(['ok' => false, 'error' => 'Destinataire invalide'], 400);
}

if (empty($message) && empty($imagePath)) {
    jsonResponse(['ok' => false, 'error' => 'Le message ou une image doit être présent'], 400);
}

if (!empty($message) && strlen($message) > 5000) {
    jsonResponse(['ok' => false, 'error' => 'Message trop long (max 5000 caractères)'], 400);
}

// Vérifier que le destinataire existe et est actif
$check = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = ? AND statut = 'actif' LIMIT 1");
$check->execute([$receiverId]);
if (!$check->fetch()) {
    jsonResponse(['ok' => false, 'error' => 'Destinataire introuvable ou inactif'], 404);
}

// Valider le chemin image si présent
if (!empty($imagePath)) {
    if (!preg_match('/^\/uploads\/chatroom\/[a-zA-Z0-9_\-\.]+$/', $imagePath)) {
        jsonResponse(['ok' => false, 'error' => 'Chemin d\'image invalide'], 400);
    }
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $baseDir = $docRoot !== '' ? $docRoot : dirname(__DIR__);
    $fullPath = $baseDir . $imagePath;
    $uploadDirReal = realpath($baseDir . '/uploads/chatroom');
    if (!$uploadDirReal || !is_dir($uploadDirReal)) {
        jsonResponse(['ok' => false, 'error' => 'Répertoire d\'upload introuvable'], 500);
    }
    $realPath = realpath($fullPath);
    if (!$realPath || !is_file($realPath) || strpos($realPath, $uploadDirReal) !== 0) {
        jsonResponse(['ok' => false, 'error' => 'Image introuvable ou invalide'], 400);
    }
}

try {
    $messageToInsert = !empty($message) ? $message : '';
    $imageToInsert = !empty($imagePath) ? $imagePath : null;

    $stmt = $pdo->prepare("
        INSERT INTO private_messages (id_sender, id_receiver, message, image_path, date_envoi)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$currentUserId, $receiverId, $messageToInsert, $imageToInsert]);
    $messageId = (int)$pdo->lastInsertId();

    if ($messageId <= 0) {
        throw new Exception('Impossible de récupérer l\'ID du message');
    }

    $config = require __DIR__ . '/../config/app.php';
    $mysqlTz = $config['mysql_timezone'] ?? 'UTC';

    $hasDeliveredRead = false;
    try {
        $checkCol = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_messages'
            AND COLUMN_NAME IN ('delivered_at','read_at')
        ");
        $checkCol->execute();
        $hasDeliveredRead = (int)$checkCol->fetch(PDO::FETCH_ASSOC)['cnt'] >= 2;
    } catch (PDOException $e) {
        // ignore
    }

    $selectCols = $hasDeliveredRead
        ? 'm.id, m.id_sender, m.id_receiver, m.message, m.image_path, m.date_envoi, m.delivered_at, m.read_at, u.nom, u.prenom, u.Emploi'
        : 'm.id, m.id_sender, m.id_receiver, m.message, m.image_path, m.date_envoi, u.nom, u.prenom, u.Emploi';

    $stmt = $pdo->prepare("
        SELECT {$selectCols}
        FROM private_messages m
        INNER JOIN utilisateurs u ON u.id = m.id_sender
        WHERE m.id = ?
    ");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $responseMessage = [
        'id' => (int)$row['id'],
        'id_sender' => (int)$row['id_sender'],
        'id_receiver' => (int)$row['id_receiver'],
        'message' => $row['message'],
        'image_path' => $row['image_path'],
        'date_envoi' => formatDatetimeForJson($row['date_envoi'] ?? '', $mysqlTz),
        'user_nom' => $row['nom'],
        'user_prenom' => $row['prenom'],
        'user_emploi' => $row['Emploi'],
        'is_me' => true,
    ];
    if ($hasDeliveredRead) {
        $responseMessage['delivered_at'] = null;
        $responseMessage['read_at'] = null;
        $responseMessage['lu'] = 0;
    }

    jsonResponse(['ok' => true, 'message' => $responseMessage]);
} catch (PDOException $e) {
    error_log('private_messages_send.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
