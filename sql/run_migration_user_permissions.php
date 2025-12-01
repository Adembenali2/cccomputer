<?php
/**
 * Script pour exécuter la migration user_permissions
 * Usage: php sql/run_migration_user_permissions.php
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Migration: Création de la table user_permissions ===\n\n";

try {
    // Vérifier si la table existe déjà
    $check = $pdo->prepare("SHOW TABLES LIKE 'user_permissions'");
    $check->execute();
    if ($check->rowCount() > 0) {
        echo "✓ La table 'user_permissions' existe déjà.\n";
        exit(0);
    }
    
    echo "1. Création de la table user_permissions...\n";
    $pdo->exec("
        CREATE TABLE `user_permissions` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `page` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
          `allowed` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = autorisé, 0 = interdit',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_user_page` (`user_id`, `page`),
          KEY `idx_user_id` (`user_id`),
          KEY `idx_page` (`page`),
          CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "   ✓ Table créée avec succès.\n\n";
    
    echo "=== Migration terminée avec succès ! ===\n";
    echo "\nNote: Les permissions sont optionnelles. Si aucune permission n'existe pour un utilisateur/page,\n";
    echo "le système utilisera les rôles par défaut (système de fallback).\n";
    
} catch (PDOException $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

