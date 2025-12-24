-- ============================================
-- Migration : Ajout de la colonne image_path à chatroom_messages
-- Date : 2024
-- Description : Ajoute la colonne image_path pour permettre l'upload d'images dans les messages
-- ============================================

-- Vérifier si la colonne existe déjà, sinon l'ajouter
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'chatroom_messages' 
    AND COLUMN_NAME = 'image_path'
);

-- Ajouter la colonne image_path si elle n'existe pas
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE `chatroom_messages` 
     ADD COLUMN `image_path` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
     COMMENT ''Chemin relatif vers l''image uploadée (ex: /uploads/chatroom/filename.jpg)'' 
     AFTER `mentions`',
    'SELECT ''La colonne image_path existe déjà.'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Alternative simple (si la vérification ne fonctionne pas, utilisez cette version) :
-- ALTER TABLE `chatroom_messages` 
-- ADD COLUMN `image_path` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
-- COMMENT 'Chemin relatif vers l''image uploadée (ex: /uploads/chatroom/filename.jpg)' 
-- AFTER `mentions`;

