-- Migration : Suppression des colonnes de liens (type_lien, id_lien) de chatroom_messages
-- Date : 2024
-- Description : Supprime les colonnes type_lien et id_lien qui ne sont plus utilisées

ALTER TABLE `chatroom_messages` 
  DROP COLUMN IF EXISTS `type_lien`,
  DROP COLUMN IF EXISTS `id_lien`,
  DROP INDEX IF EXISTS `idx_type_lien`;






