<?php
/**
 * Migration : Ajout des colonnes lu, delivered_at, read_at à private_messages
 * Pour les notifications et statuts reçu/lu des messages privés
 * Exécuter : php sql/run_migration_private_messages_read_status.php
 */

require_once __DIR__ . '/../includes/helpers.php';

echo "Migration : colonnes lu, delivered_at, read_at sur private_messages\n";

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    echo "Erreur connexion: " . $e->getMessage() . "\n";
    exit(1);
}

$columnsToAdd = [
    'lu' => "ADD COLUMN `lu` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = non lu, 1 = lu (par le destinataire)'",
    'delivered_at' => "ADD COLUMN `delivered_at` DATETIME NULL DEFAULT NULL COMMENT 'Quand le destinataire a récupéré le message'",
    'read_at' => "ADD COLUMN `read_at` DATETIME NULL DEFAULT NULL COMMENT 'Quand le destinataire a ouvert la conversation'",
];

foreach ($columnsToAdd as $colName => $alterSql) {
    $check = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_messages' AND COLUMN_NAME = ?
    ");
    $check->execute([$colName]);
    if ((int)$check->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        echo "  Colonne $colName existe déjà.\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `private_messages` $alterSql");
    echo "  Colonne $colName ajoutée.\n";
}

echo "OK - Migration terminée.\n";
