<?php
/**
 * includes/messagerie_purge.php
 * Purge automatique des messages et images de messagerie après 24 heures.
 * Appelé au chargement de messagerie.php et par le script cron.
 *
 * Tables concernées :
 * - chatroom_messages (général)
 * - private_messages (privé)
 * - chatroom_notifications (cascade via FK quand message supprimé)
 *
 * Images : /uploads/chatroom/ (partagé par général et privé)
 */

if (!function_exists('purgeMessagerie24h')) {
    function purgeMessagerie24h(PDO $pdo): array {
        $stats = ['chatroom' => 0, 'private' => 0, 'images' => 0];
        $baseDir = dirname(__DIR__);

        // 1. Purge chatroom_messages (> 24h) + suppression images
        try {
            $checkTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_messages'");
            $checkTable->execute();
            if ($checkTable->rowCount() > 0) {
                $hasImagePath = false;
                $checkCol = $pdo->prepare("
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatroom_messages' AND COLUMN_NAME = 'image_path'
                ");
                $checkCol->execute();
                $hasImagePath = (int)$checkCol->fetchColumn() > 0;

                $cols = $hasImagePath ? 'id, image_path' : 'id';
                $stmt = $pdo->prepare("
                    SELECT {$cols} FROM chatroom_messages
                    WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmt->execute();
                $old = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $ids = [];
                foreach ($old as $msg) {
                    if ($hasImagePath && !empty($msg['image_path'])) {
                        $path = $baseDir . $msg['image_path'];
                        if (file_exists($path)) { @unlink($path); $stats['images']++; }
                    }
                    $ids[] = $msg['id'];
                }
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM chatroom_messages WHERE id IN ($placeholders)")->execute($ids);
                    $stats['chatroom'] = count($ids);
                }
            }
        } catch (PDOException $e) {
            error_log('purgeMessagerie24h chatroom: ' . $e->getMessage());
        }

        // 2. Purge private_messages (> 24h) + suppression images
        try {
            $checkTable = $pdo->prepare("SHOW TABLES LIKE 'private_messages'");
            $checkTable->execute();
            if ($checkTable->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT id, image_path FROM private_messages
                    WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stmt->execute();
                $old = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $ids = [];
                foreach ($old as $msg) {
                    if (!empty($msg['image_path'])) {
                        $path = $baseDir . $msg['image_path'];
                        if (file_exists($path)) { @unlink($path); $stats['images']++; }
                    }
                    $ids[] = $msg['id'];
                }
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM private_messages WHERE id IN ($placeholders)")->execute($ids);
                    $stats['private'] = count($ids);
                }
            }
        } catch (PDOException $e) {
            error_log('purgeMessagerie24h private: ' . $e->getMessage());
        }

        return $stats;
    }
}
