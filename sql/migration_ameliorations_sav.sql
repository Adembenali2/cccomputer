-- Migration : Améliorations supplémentaires pour le système SAV
-- Ces améliorations sont optionnelles mais recommandées

-- 1. Ajouter un lien vers le photocopieur concerné (si applicable)
ALTER TABLE `sav` 
ADD COLUMN `mac_norm` char(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
AFTER `id_client`,
ADD INDEX `idx_sav_mac_norm` (`mac_norm`);

-- 2. Ajouter un champ pour la date d'intervention prévue
ALTER TABLE `sav` 
ADD COLUMN `date_intervention_prevue` date NULL DEFAULT NULL 
AFTER `date_ouverture`,
ADD INDEX `idx_sav_date_intervention` (`date_intervention_prevue`);

-- 3. Ajouter un champ pour le temps d'intervention estimé (en heures)
ALTER TABLE `sav` 
ADD COLUMN `temps_intervention_estime` decimal(4,2) NULL DEFAULT NULL 
COMMENT 'Temps estimé en heures' 
AFTER `date_intervention_prevue`;

-- 4. Ajouter un champ pour le temps d'intervention réel (en heures)
ALTER TABLE `sav` 
ADD COLUMN `temps_intervention_reel` decimal(4,2) NULL DEFAULT NULL 
COMMENT 'Temps réel en heures' 
AFTER `temps_intervention_estime`;

-- 5. Ajouter un champ pour le coût de l'intervention
ALTER TABLE `sav` 
ADD COLUMN `cout_intervention` decimal(10,2) NULL DEFAULT NULL 
COMMENT 'Coût de l\'intervention en euros' 
AFTER `temps_intervention_reel`;

-- 6. Ajouter un champ pour les pièces utilisées (référence vers le stock)
-- On peut créer une table de liaison pour gérer plusieurs pièces par SAV
CREATE TABLE IF NOT EXISTS `sav_pieces_utilisees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_sav` int NOT NULL,
  `product_type` enum('papier','toner','lcd','pc') NOT NULL,
  `product_id` int NOT NULL,
  `quantite` int NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sav_pieces_sav` (`id_sav`),
  KEY `idx_sav_pieces_product` (`product_type`, `product_id`),
  CONSTRAINT `fk_sav_pieces_sav` FOREIGN KEY (`id_sav`) REFERENCES `sav` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Ajouter un champ pour les notes techniques (réservé aux techniciens)
ALTER TABLE `sav` 
ADD COLUMN `notes_techniques` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
COMMENT 'Notes techniques réservées aux techniciens' 
AFTER `commentaire`;

-- 8. Ajouter un champ pour la satisfaction client (après résolution)
ALTER TABLE `sav` 
ADD COLUMN `satisfaction_client` tinyint NULL DEFAULT NULL 
COMMENT 'Note de satisfaction de 1 à 5' 
AFTER `date_fermeture`,
ADD COLUMN `commentaire_client` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
COMMENT 'Commentaire du client sur l\'intervention' 
AFTER `satisfaction_client`;

-- Note: Ces améliorations sont optionnelles
-- Vous pouvez exécuter uniquement les parties qui vous intéressent


