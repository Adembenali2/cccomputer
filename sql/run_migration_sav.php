<?php
/**
 * Script de migration pour créer la table SAV
 * À exécuter une seule fois pour créer la table dans la base de données
 */

require_once __DIR__ . '/../includes/db.php';

try {
    // Vérifier si la table existe déjà
    $check = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'sav'
    ");
    $check->execute();
    
    if ($check->fetchColumn() > 0) {
        echo "La table 'sav' existe déjà.\n";
        exit(0);
    }
    
    // Créer la table SAV
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sav` (
          `id` int NOT NULL AUTO_INCREMENT,
          `id_client` int DEFAULT NULL,
          `id_technicien` int DEFAULT NULL,
          `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `date_ouverture` date NOT NULL,
          `date_fermeture` date DEFAULT NULL,
          `statut` enum('ouvert','en_cours','resolu','annule') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ouvert',
          `priorite` enum('basse','normale','haute','urgente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'normale',
          `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_sav_reference` (`reference`),
          KEY `idx_sav_client` (`id_client`),
          KEY `idx_sav_technicien` (`id_technicien`),
          KEY `idx_sav_date_ouverture` (`date_ouverture`),
          KEY `idx_sav_date_fermeture` (`date_fermeture`),
          KEY `idx_sav_statut` (`statut`),
          KEY `idx_sav_priorite` (`priorite`),
          CONSTRAINT `fk_sav_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
          CONSTRAINT `fk_sav_technicien` FOREIGN KEY (`id_technicien`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
    echo "✅ Table 'sav' créée avec succès !\n";
    
} catch (PDOException $e) {
    echo "❌ Erreur lors de la création de la table 'sav': " . $e->getMessage() . "\n";
    exit(1);
}

