<?php
/**
 * scripts/private_messages_cleanup.php
 * Purge des messages privés et images après 24 heures.
 *
 * @deprecated Utiliser scripts/messagerie_cleanup.php qui purge général + privé + images.
 * Ce script reste compatible : il appelle la purge centralisée (général + privé).
 *
 * Cron : 0 * * * * php /chemin/vers/scripts/private_messages_cleanup.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/messagerie_purge.php';

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    error_log('private_messages_cleanup.php - Erreur PDO: ' . $e->getMessage());
    echo "Erreur connexion: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $stats = purgeMessagerie24h($pdo);
    $total = $stats['chatroom'] + $stats['private'];
    if ($total > 0 || $stats['images'] > 0) {
        echo "Purge terminée: {$stats['chatroom']} message(s) général, {$stats['private']} message(s) privé, {$stats['images']} image(s) supprimée(s).\n";
    }
    exit(0);
} catch (Throwable $e) {
    error_log('private_messages_cleanup.php - Erreur: ' . $e->getMessage());
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
