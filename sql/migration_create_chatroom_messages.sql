-- Migration : Création de la table chatroom_messages pour la chatroom globale
-- Date : 2024
-- Description : Table pour stocker les messages de la chatroom globale (type groupe WhatsApp/Messenger)
-- Avec support des mentions (@username) et liens vers clients/SAVs/livraisons

DROP TABLE IF EXISTS `chatroom_messages`;

CREATE TABLE `chatroom_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL COMMENT 'ID de l''utilisateur qui a envoyé le message',
  `message` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Contenu du message',
  `date_envoi` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date et heure d''envoi du message',
  `mentions` text COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'JSON array des IDs utilisateurs mentionnés (@username)',
  `type_lien` enum('client','livraison','sav') DEFAULT NULL COMMENT 'Type de lien associé',
  `id_lien` int DEFAULT NULL COMMENT 'ID du client/livraison/SAV lié',
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_date_envoi` (`date_envoi`),
  KEY `idx_type_lien` (`type_lien`, `id_lien`),
  CONSTRAINT `fk_chatroom_messages_user` FOREIGN KEY (`id_user`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Index composite pour optimiser les requêtes de récupération des messages récents
CREATE INDEX `idx_date_envoi_desc` ON `chatroom_messages` (`date_envoi` DESC);

-- Table pour stocker les notifications de chatroom (pour les mentions)
DROP TABLE IF EXISTS `chatroom_notifications`;

CREATE TABLE `chatroom_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL COMMENT 'Utilisateur qui reçoit la notification',
  `id_message` int NOT NULL COMMENT 'Message qui a déclenché la notification',
  `type` enum('mention','message') NOT NULL DEFAULT 'message' COMMENT 'Type de notification',
  `lu` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = non lu, 1 = lu',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_id_message` (`id_message`),
  KEY `idx_lu` (`lu`),
  KEY `idx_date_creation` (`date_creation`),
  CONSTRAINT `fk_chatroom_notif_user` FOREIGN KEY (`id_user`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chatroom_notif_message` FOREIGN KEY (`id_message`) REFERENCES `chatroom_messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

