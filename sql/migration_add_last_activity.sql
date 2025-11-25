-- Migration : Ajout du champ last_activity pour le suivi des utilisateurs en ligne
-- Date : 2024

ALTER TABLE `utilisateurs` 
ADD COLUMN `last_activity` datetime NULL DEFAULT NULL AFTER `date_modification`;

-- Créer un index pour améliorer les performances des requêtes de comptage
CREATE INDEX `idx_last_activity` ON `utilisateurs` (`last_activity`);

-- Initialiser avec date_modification pour les utilisateurs existants
UPDATE `utilisateurs` 
SET `last_activity` = `date_modification` 
WHERE `last_activity` IS NULL;




