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

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `parametres_app` (
          `cle` VARCHAR(80) NOT NULL PRIMARY KEY,
          `valeur` VARCHAR(255) NOT NULL DEFAULT '',
          `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $pdo->exec("INSERT IGNORE INTO `parametres_app` (`cle`, `valeur`) VALUES ('auto_send_emails', '0')");
    echo "Migration terminée: table parametres_app créée.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "Migration déjà appliquée (table existante).\n";
    } else {
        throw $e;
    }
}
