<?php
/**
 * scripts/chatroom_cleanup.php
 * Script de nettoyage automatique des messages de chatroom (suppression après 24h)
 * À exécuter via cron toutes les heures : 0 * * * * php /chemin/vers/scripts/chatroom_cleanup.php
 */

require_once __DIR__ . '/../includes/db.php';

try {
    // Vérifier que la table existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'chatroom_messages'");
    if ($checkTable->rowCount() === 0) {
        echo "Table chatroom_messages n'existe pas.\n";
        exit(0);
    }

    // Supprimer les messages de plus de 24h
    $stmt = $pdo->prepare("
        SELECT id, image_path 
        FROM chatroom_messages 
        WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $oldMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deletedCount = 0;
    $deletedImages = 0;

    foreach ($oldMessages as $msg) {
        // Supprimer l'image associée si elle existe
        if (!empty($msg['image_path'])) {
            $imagePath = dirname(__DIR__) . $msg['image_path'];
            if (file_exists($imagePath)) {
                @unlink($imagePath);
                $deletedImages++;
            }
        }

        // Supprimer le message
        $deleteStmt = $pdo->prepare("DELETE FROM chatroom_messages WHERE id = :id");
        $deleteStmt->execute([':id' => $msg['id']]);
        $deletedCount++;
    }

    // Supprimer aussi les notifications associées aux messages supprimés
    // (elles seront supprimées automatiquement par CASCADE, mais on peut aussi les nettoyer manuellement)
    $pdo->exec("
        DELETE FROM chatroom_notifications 
        WHERE id_message NOT IN (SELECT id FROM chatroom_messages)
    ");

    echo "Nettoyage terminé: {$deletedCount} message(s) supprimé(s), {$deletedImages} image(s) supprimée(s).\n";

} catch (PDOException $e) {
    error_log('chatroom_cleanup.php - Erreur PDO: ' . $e->getMessage());
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    error_log('chatroom_cleanup.php - Erreur: ' . $e->getMessage());
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

