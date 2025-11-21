-- Script de vérification et ajout des colonnes nécessaires pour la messagerie
-- Ce script vérifie et ajoute les colonnes manquantes pour la suppression et les réponses

DELIMITER //

CREATE PROCEDURE VerifyMessagerieColumns()
BEGIN
    -- Vérifier et ajouter supprime_expediteur si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'supprime_expediteur') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `supprime_expediteur` TINYINT(1) NOT NULL DEFAULT 0 AFTER `date_lecture`;
    END IF;

    -- Vérifier et ajouter supprime_destinataire si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'supprime_destinataire') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `supprime_destinataire` TINYINT(1) NOT NULL DEFAULT 0 AFTER `supprime_expediteur`;
    END IF;

    -- Vérifier et ajouter id_message_parent si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'id_message_parent') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `id_message_parent` INT DEFAULT NULL AFTER `id_lien`;
        
        -- Ajouter l'index si la colonne vient d'être créée
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'messagerie'
                       AND INDEX_NAME = 'idx_message_parent') THEN
            ALTER TABLE `messagerie`
            ADD INDEX `idx_message_parent` (`id_message_parent`);
        END IF;
        
        -- Ajouter la contrainte de clé étrangère si elle n'existe pas
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'messagerie'
                       AND CONSTRAINT_NAME = 'fk_messagerie_parent') THEN
            ALTER TABLE `messagerie`
            ADD CONSTRAINT `fk_messagerie_parent` FOREIGN KEY (`id_message_parent`) 
            REFERENCES `messagerie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
        END IF;
    END IF;

    -- Vérifier et ajouter type_reponse si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'type_reponse') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `type_reponse` ENUM('text', 'emoji') DEFAULT 'text' AFTER `id_message_parent`;
    END IF;

    -- Vérifier et ajouter emoji_code si elle n'existe pas
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'messagerie'
                   AND COLUMN_NAME = 'emoji_code') THEN
        ALTER TABLE `messagerie`
        ADD COLUMN `emoji_code` VARCHAR(10) DEFAULT NULL AFTER `type_reponse`;
    END IF;
END //

DELIMITER ;

-- Exécuter la procédure
CALL VerifyMessagerieColumns();

-- Supprimer la procédure
DROP PROCEDURE VerifyMessagerieColumns;

