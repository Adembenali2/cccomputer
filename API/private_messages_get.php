<?php
// API/private_messages_get.php
// Récupère les messages privés entre l'utilisateur connecté et un autre utilisateur

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();
$currentUserId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$otherUserId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
if ($otherUserId <= 0 || $otherUserId === $currentUserId) {
    jsonResponse(['ok' => false, 'error' => 'Destinataire invalide'], 400);
}

try {
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
    $limit = max(1, min((int)($_GET['limit'] ?? 100), 200));

    $config = require __DIR__ . '/../config/app.php';
    $mysqlTz = $config['mysql_timezone'] ?? 'UTC';

    if ($sinceId > 0) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.id_sender, m.id_receiver, m.message, m.image_path, m.date_envoi,
                   u.nom, u.prenom, u.Emploi
            FROM private_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_sender
            WHERE m.id > ?
            AND ((m.id_sender = ? AND m.id_receiver = ?) OR (m.id_sender = ? AND m.id_receiver = ?))
            ORDER BY m.date_envoi ASC
            LIMIT ?
        ");
        $stmt->execute([$sinceId, $currentUserId, $otherUserId, $otherUserId, $currentUserId, $limit]);
    } else {
        $stmt = $pdo->prepare("
            SELECT m.id, m.id_sender, m.id_receiver, m.message, m.image_path, m.date_envoi,
                   u.nom, u.prenom, u.Emploi
            FROM private_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_sender
            WHERE ((m.id_sender = ? AND m.id_receiver = ?) OR (m.id_sender = ? AND m.id_receiver = ?))
            ORDER BY m.date_envoi DESC
            LIMIT ?
        ");
        $stmt->execute([$currentUserId, $otherUserId, $otherUserId, $currentUserId, $limit]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($sinceId <= 0) {
        $rows = array_reverse($rows);
    }

    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id' => (int)$r['id'],
            'id_sender' => (int)$r['id_sender'],
            'id_receiver' => (int)$r['id_receiver'],
            'message' => $r['message'],
            'image_path' => $r['image_path'],
            'date_envoi' => formatDatetimeForJson($r['date_envoi'] ?? '', $mysqlTz),
            'user_nom' => $r['nom'],
            'user_prenom' => $r['prenom'],
            'user_emploi' => $r['Emploi'],
            'is_me' => (int)$r['id_sender'] === $currentUserId,
        ];
    }

    jsonResponse(['ok' => true, 'messages' => $messages]);
} catch (PDOException $e) {
    error_log('private_messages_get.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
