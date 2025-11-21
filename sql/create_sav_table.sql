-- Création de la table SAV (Service Après-Vente)
-- Structure similaire à livraisons mais adaptée pour les interventions SAV

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

