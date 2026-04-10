<?php
// API pour récupérer le nombre de messages privés non lus (pour le badge dans le header)
// Utilise la table private_messages (messagerie 1-à-1 actuelle)
// Compatible avec l'ancienne table messagerie si private_messages n'existe pas

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => true, 'count' => 0]);
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo = getPdo();
} catch (RuntimeException $e) {
    error_log('messagerie_get_unread_count.php: getPdo() failed - ' . $e->getMessage());
    jsonResponse(['ok' => true, 'count' => 0]);
}

try {
    $count = 0;

    // 1. Compter les messages privés non lus (private_messages)
    $hasPrivateLu = false;
    try {
        $checkCol = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_messages' AND COLUMN_NAME = 'lu'
        ");
        $checkCol->execute();
        $hasPrivateLu = (int)$checkCol->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    } catch (PDOException $e) {
        // ignore
    }

    if ($hasPrivateLu) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM private_messages
            WHERE id_receiver = :user_id AND lu = 0
        ");
        $stmt->execute([':user_id' => $userId]);
        $count += (int)$stmt->fetchColumn();
    }

    // 2. Ancienne messagerie (compatibilité) - si private_messages n'a pas de lu, ou en plus
    $checkOld = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messagerie'
    ");
    $checkOld->execute();
    if ((int)$checkOld->fetch(PDO::FETCH_ASSOC)['cnt'] > 0 && !$hasPrivateLu) {
        try {
            $stmt1 = $pdo->prepare("
                SELECT COUNT(*) FROM messagerie
                WHERE id_destinataire = :user_id AND lu = 0 AND supprime_destinataire = 0
            ");
            $stmt1->execute([':user_id' => $userId]);
            $count += (int)$stmt1->fetchColumn();
        } catch (PDOException $e) {
            // ignore
        }
    }

    jsonResponse(['ok' => true, 'count' => $count]);
} catch (PDOException $e) {
    error_log('messagerie_get_unread_count.php: ' . $e->getMessage());
    jsonResponse(['ok' => true, 'count' => 0]);
} catch (Throwable $e) {
    error_log('messagerie_get_unread_count.php: ' . $e->getMessage());
    jsonResponse(['ok' => true, 'count' => 0]);
}
