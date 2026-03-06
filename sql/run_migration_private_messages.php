<?php
/**
 * Exécute la migration pour créer la table private_messages
 * Accès : php sql/run_migration_private_messages.php
 */
require_once __DIR__ . '/../includes/helpers.php';

echo "Migration : création table private_messages\n";

try {
    $pdo = getPdo();
    $sql = file_get_contents(__DIR__ . '/migration_create_private_messages.sql');
    $pdo->exec($sql);
    echo "OK - Table private_messages créée.\n";
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
