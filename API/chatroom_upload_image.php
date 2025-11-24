<?php
// API/chatroom_upload_image.php
// Endpoint pour uploader une image dans la chatroom

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
        exit;
    }

    // Vérifier qu'un fichier a été uploadé
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Aucun fichier uploadé ou erreur d\'upload']);
        exit;
    }

    $file = $_FILES['image'];

    // Vérifier que c'est bien un fichier uploadé
    if (!is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fichier invalide']);
        exit;
    }

    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Type de fichier non autorisé. Formats acceptés: JPEG, PNG, GIF, WebP']);
        exit;
    }

    // Vérifier la taille (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 5MB)']);
        exit;
    }

    // Créer le répertoire d'upload si nécessaire
    $uploadDir = dirname(__DIR__) . '/uploads/chatroom';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // Générer un nom de fichier sécurisé
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Extension de fichier non autorisée']);
        exit;
    }

    // Nom de fichier: timestamp_userid_randomhash.extension
    $filename = date('Ymd_His') . '_' . $currentUserId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;

    // Déplacer le fichier
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors de l\'enregistrement du fichier']);
        exit;
    }

    // Définir les permissions
    @chmod($filepath, 0644);

    // Chemin relatif pour l'URL
    $relativePath = '/uploads/chatroom/' . $filename;

    echo json_encode([
        'ok' => true,
        'image_path' => $relativePath,
        'filename' => $filename
    ]);

} catch (PDOException $e) {
    error_log('chatroom_upload_image.php - Erreur PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
} catch (Exception $e) {
    error_log('chatroom_upload_image.php - Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}

