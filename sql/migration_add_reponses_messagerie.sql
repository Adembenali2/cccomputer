-- Migration pour ajouter le support des réponses dans la messagerie
-- Ajoute un champ pour lier une réponse à un message parent

DELIMITER //
CREATE PROCEDURE AddReponsesToMessagerie()
BEGIN
    -- Ajouter la colonne id_message_parent si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'id_message_parent') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `id_message_parent` INT DEFAULT NULL AFTER `id_lien`,
        ADD INDEX `idx_message_parent` (`id_message_parent`),
        ADD CONSTRAINT `fk_messagerie_parent` FOREIGN KEY (`id_message_parent`) REFERENCES `messagerie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
    END IF;
END //
DELIMITER ;

CALL AddReponsesToMessagerie();
DROP PROCEDURE AddReponsesToMessagerie;

