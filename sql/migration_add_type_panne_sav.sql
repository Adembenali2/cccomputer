-- Migration : Ajout du champ type_panne dans la table SAV
-- Types de panne : logiciel, materiel, piece_rechangeable

-- Ajouter la colonne type_panne
ALTER TABLE `sav` 
ADD COLUMN `type_panne` enum('logiciel','materiel','piece_rechangeable') 
CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
AFTER `priorite`;

-- Ajouter un index pour améliorer les performances des filtres
CREATE INDEX IF NOT EXISTS `idx_sav_type_panne` ON `sav`(`type_panne`);

-- Mettre à jour les index existants si nécessaire
-- (Les index existants restent valides)


