<?php
// API/chatroom_upload_image.php
// Endpoint pour uploader une image dans la chatroom

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$currentUserId = (int)$_SESSION['user_id'];

try {

    // Vérifier qu'un fichier a été uploadé
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['ok' => false, 'error' => 'Aucun fichier uploadé ou erreur d\'upload'], 400);
    }

    $file = $_FILES['image'];

    // Vérifier que c'est bien un fichier uploadé
    if (!is_uploaded_file($file['tmp_name'])) {
        jsonResponse(['ok' => false, 'error' => 'Fichier invalide'], 400);
    }

    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        jsonResponse(['ok' => false, 'error' => 'Type de fichier non autorisé. Formats acceptés: JPEG, PNG, GIF, WebP'], 400);
    }

    // Vérifier la taille (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        jsonResponse(['ok' => false, 'error' => 'Fichier trop volumineux (max 5MB)'], 400);
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
        jsonResponse(['ok' => false, 'error' => 'Extension de fichier non autorisée'], 400);
    }

    // Nom de fichier: timestamp_userid_randomhash.extension
    $filename = date('Ymd_His') . '_' . $currentUserId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;

    // Déplacer le fichier
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'enregistrement du fichier'], 500);
    }

    // Définir les permissions
    @chmod($filepath, 0644);

    // Chemin relatif pour l'URL
    $relativePath = '/uploads/chatroom/' . $filename;

    jsonResponse([
        'ok' => true,
        'image_path' => $relativePath,
        'filename' => $filename
    ]);

} catch (PDOException $e) {
    error_log('chatroom_upload_image.php - Erreur PDO: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
} catch (Exception $e) {
    error_log('chatroom_upload_image.php - Erreur: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}



