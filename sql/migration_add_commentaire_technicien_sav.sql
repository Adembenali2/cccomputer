-- Migration pour ajouter le champ commentaire_technicien à la table sav
-- Si notes_techniques existe déjà, cette migration ne fait rien
-- Sinon, elle crée notes_techniques pour stocker les commentaires des techniciens

DELIMITER //
CREATE PROCEDURE AddCommentaireTechnicienToSav()
BEGIN
    -- Vérifier si la colonne notes_techniques existe déjà
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'sav'
                   AND COLUMN_NAME = 'notes_techniques') THEN
        ALTER TABLE `sav`
        ADD COLUMN `notes_techniques` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
        COMMENT 'Commentaires et notes techniques du technicien' 
        AFTER `commentaire`;
    END IF;
END //
DELIMITER ;

CALL AddCommentaireTechnicienToSav();
DROP PROCEDURE AddCommentaireTechnicienToSav;

