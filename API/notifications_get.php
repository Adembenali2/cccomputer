<?php
/**
 * GET — Notifications non lues de l'utilisateur connecté.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/NotificationService.php';

initApi();
requireApiAuth();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$list = NotificationService::getUnread($userId);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    [
        'success' => true,
        'notifications' => $list,
        'count' => count($list),
    ],
    JSON_UNESCAPED_UNICODE
);
