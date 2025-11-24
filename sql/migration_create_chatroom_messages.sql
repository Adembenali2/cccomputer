-- Migration : Création de la table chatroom_messages pour la chatroom globale
-- Date : 2024
-- Description : Table pour stocker les messages de la chatroom globale (type groupe WhatsApp/Messenger)

DROP TABLE IF EXISTS `chatroom_messages`;

CREATE TABLE `chatroom_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL COMMENT 'ID de l''utilisateur qui a envoyé le message',
  `message` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Contenu du message',
  `date_envoi` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date et heure d''envoi du message',
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_date_envoi` (`date_envoi`),
  CONSTRAINT `fk_chatroom_messages_user` FOREIGN KEY (`id_user`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Index composite pour optimiser les requêtes de récupération des messages récents
CREATE INDEX `idx_date_envoi_desc` ON `chatroom_messages` (`date_envoi` DESC);

