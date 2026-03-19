<?php
/**
 * Migration: Créer la table parametres_app pour les réglages (ex: envoi auto emails)
 * À exécuter une seule fois
 */

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

$pdo = getPdo();

$defaults = [
    ['auto_send_emails', '0'],
    ['module_dashboard', '1'], ['module_agenda', '1'], ['module_historique', '1'],
    ['module_clients', '1'], ['module_paiements', '1'], ['module_messagerie', '1'],
    ['module_sav', '1'], ['module_livraison', '1'], ['module_stock', '1'],
    ['module_photocopieurs', '1'], ['module_maps', '1'], ['module_profil', '1'],
    ['module_commercial', '1'], ['module_import_sftp', '1'], ['module_import_ionos', '1'],
];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `parametres_app` (
          `cle` VARCHAR(80) NOT NULL PRIMARY KEY,
          `valeur` VARCHAR(255) NOT NULL DEFAULT '',
          `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $stmt = $pdo->prepare("INSERT IGNORE INTO `parametres_app` (`cle`, `valeur`) VALUES (?, ?)");
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }
    echo "Migration terminée: table parametres_app créée.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "Migration déjà appliquée (table existante).\n";
    } else {
        throw $e;
    }
}
