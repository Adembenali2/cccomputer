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

    $hasLu = false;
    $hasDeliveredRead = false;
    try {
        $checkCol = $pdo->prepare("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_messages'
            AND COLUMN_NAME IN ('lu','delivered_at','read_at')
        ");
        $checkCol->execute();
        $cols = $checkCol->fetchAll(PDO::FETCH_COLUMN);
        $hasLu = in_array('lu', $cols);
        $hasDeliveredRead = in_array('delivered_at', $cols) && in_array('read_at', $cols);
    } catch (PDOException $e) {
        // ignore
    }

    $extraCols = [];
    if ($hasLu) $extraCols[] = 'm.lu';
    if ($hasDeliveredRead) $extraCols[] = 'm.delivered_at';
    if ($hasDeliveredRead) $extraCols[] = 'm.read_at';
    $selectCols = 'm.id, m.id_sender, m.id_receiver, m.message, m.image_path, m.date_envoi' . (empty($extraCols) ? '' : ', ' . implode(', ', $extraCols)) . ', u.nom, u.prenom, u.Emploi';

    if ($sinceId > 0) {
        $stmt = $pdo->prepare("
            SELECT {$selectCols}
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
            SELECT {$selectCols}
            FROM private_messages m
            INNER JOIN utilisateurs u ON u.id = m.id_sender
            WHERE ((m.id_sender = ? AND m.id_receiver = ?) OR (m.id_sender = ? AND m.id_receiver = ?))
            ORDER BY m.date_envoi DESC
            LIMIT ?
        ");
        $stmt->execute([$currentUserId, $otherUserId, $otherUserId, $currentUserId, $limit]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Marquer comme lu/delivered/read les messages reçus par l'utilisateur actuel
    if ($hasLu && !empty($rows)) {
        $idsToMark = [];
        foreach ($rows as $r) {
            if ((int)$r['id_receiver'] === $currentUserId) {
                $idsToMark[] = (int)$r['id'];
            }
        }
        if (!empty($idsToMark)) {
            $placeholders = implode(',', array_fill(0, count($idsToMark), '?'));
            $setParts = ['lu = 1'];
            if ($hasDeliveredRead) {
                $setParts[] = 'delivered_at = COALESCE(delivered_at, NOW())';
                $setParts[] = 'read_at = COALESCE(read_at, NOW())';
            }
            $updateStmt = $pdo->prepare("
                UPDATE private_messages SET " . implode(', ', $setParts) . "
                WHERE id IN ($placeholders) AND id_receiver = ?
            ");
            $updateStmt->execute(array_merge($idsToMark, [$currentUserId]));
        }
    }
    if ($sinceId <= 0) {
        $rows = array_reverse($rows);
    }

    $messages = [];
    foreach ($rows as $r) {
        $msg = [
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
        if ($hasLu && isset($r['lu'])) {
            $msg['lu'] = (int)$r['lu'];
        }
        if ($hasDeliveredRead) {
            $msg['delivered_at'] = !empty($r['delivered_at']) ? formatDatetimeForJson($r['delivered_at'], $mysqlTz) : null;
            $msg['read_at'] = !empty($r['read_at']) ? formatDatetimeForJson($r['read_at'], $mysqlTz) : null;
        }
        $messages[] = $msg;
    }

    jsonResponse(['ok' => true, 'messages' => $messages]);
} catch (PDOException $e) {
    error_log('private_messages_get.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}
