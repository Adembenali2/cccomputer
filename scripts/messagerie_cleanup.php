<?php
/**
 * scripts/messagerie_cleanup.php
 * Purge automatique des messages et images de messagerie après 24 heures.
 * Général (chatroom_messages) + Privé (private_messages) + images.
 *
 * À exécuter via cron toutes les heures :
 * 0 * * * * php /chemin/vers/scripts/messagerie_cleanup.php
 *
 * IMPORTANT : C'est le SEUL point d'exécution de la purge (plus de purge au chargement de page).
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/messagerie_purge.php';

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    error_log('messagerie_cleanup.php - Erreur PDO: ' . $e->getMessage());
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
    error_log('messagerie_cleanup.php - Erreur: ' . $e->getMessage());
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
