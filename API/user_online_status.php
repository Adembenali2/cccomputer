<?php
// API/user_online_status.php
// Vérifie si un utilisateur est en ligne (last_activity dans les 5 dernières minutes)

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => true, 'online' => false]);
}

try {
    requireApiAuth();
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($userId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'user_id invalide'], 400);
}

$pdo = getPdoOrFail();

try {
    $stmt = $pdo->prepare("
        SELECT 1 FROM utilisateurs
        WHERE id = ? AND statut = 'actif'
        AND (
            (last_activity IS NOT NULL AND last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
            OR (last_activity IS NULL AND date_modification >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        )
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $online = $stmt->fetch() !== false;

    jsonResponse(['ok' => true, 'online' => $online]);
} catch (PDOException $e) {
    error_log('user_online_status.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
