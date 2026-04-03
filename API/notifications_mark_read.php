<?php
/**
 * POST — Marquer une notification comme lue ou tout marquer lu.
 * Corps JSON ou form : id_notification (int) ou all=true
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/NotificationService.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$idNotif = isset($body['id_notification']) ? (int) $body['id_notification'] : (int) ($_POST['id_notification'] ?? 0);
$all = false;
if (array_key_exists('all', $body)) {
    $v = $body['all'];
    $all = $v === true || $v === 1 || $v === '1' || $v === 'true';
} else {
    $p = $_POST['all'] ?? '';
    $all = $p === '1' || $p === 'true' || $p === true;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($all) {
    NotificationService::markAllRead($userId);
} elseif ($idNotif > 0) {
    NotificationService::markRead($idNotif, $userId);
} else {
    jsonResponse(['success' => false, 'error' => 'id_notification ou all requis'], 400);
}

jsonResponse(['success' => true]);
