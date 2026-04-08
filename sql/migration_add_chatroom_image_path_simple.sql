-- ============================================
-- Migration SIMPLE : Ajout de la colonne image_path à chatroom_messages
-- À exécuter dans phpMyAdmin ou votre client MySQL
-- ============================================

-- Ajouter la colonne image_path à la table chatroom_messages
ALTER TABLE `chatroom_messages` 
ADD COLUMN `image_path` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
COMMENT 'Chemin relatif vers l''image uploadée (ex: /uploads/chatroom/filename.jpg)' 
AFTER `mentions`;



