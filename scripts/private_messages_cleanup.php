<?php
/**
 * scripts/private_messages_cleanup.php
 * Purge des messages privés et images après 24 heures.
 * À exécuter via cron toutes les heures : 0 * * * * php /chemin/vers/scripts/private_messages_cleanup.php
 */

require_once __DIR__ . '/../includes/helpers.php';

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    error_log('private_messages_cleanup.php - Erreur PDO: ' . $e->getMessage());
    echo "Erreur connexion: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $checkTable = $pdo->prepare("SHOW TABLES LIKE 'private_messages'");
    $checkTable->execute();
    if ($checkTable->rowCount() === 0) {
        echo "Table private_messages n'existe pas.\n";
        exit(0);
    }

    $stmt = $pdo->prepare("
        SELECT id, image_path 
        FROM private_messages 
        WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $oldMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deletedCount = 0;
    $deletedImages = 0;

    foreach ($oldMessages as $msg) {
        if (!empty($msg['image_path'])) {
            $imagePath = dirname(__DIR__) . $msg['image_path'];
            if (file_exists($imagePath)) {
                @unlink($imagePath);
                $deletedImages++;
            }
        }
        $deleteStmt = $pdo->prepare("DELETE FROM private_messages WHERE id = ?");
        $deleteStmt->execute([$msg['id']]);
        $deletedCount++;
    }

    if ($deletedCount > 0) {
        echo "Purge terminée: {$deletedCount} message(s) supprimé(s), {$deletedImages} image(s) supprimée(s).\n";
    }
    exit(0);
} catch (PDOException $e) {
    error_log('private_messages_cleanup.php - Erreur: ' . $e->getMessage());
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
