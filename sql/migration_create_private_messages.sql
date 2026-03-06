-- Migration : Création de la table private_messages pour la messagerie privée utilisateur à utilisateur
-- Les messages expirent après 24 heures (gérés par script de purge)

CREATE TABLE IF NOT EXISTS `private_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_sender` int NOT NULL COMMENT 'Utilisateur expéditeur',
  `id_receiver` int NOT NULL COMMENT 'Utilisateur destinataire',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Contenu du message',
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Chemin relatif vers l''image (/uploads/chatroom/...)',
  `date_envoi` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date d''envoi',
  PRIMARY KEY (`id`),
  KEY `idx_sender_receiver` (`id_sender`, `id_receiver`),
  KEY `idx_receiver_sender` (`id_receiver`, `id_sender`),
  KEY `idx_date_envoi` (`date_envoi`),
  CONSTRAINT `fk_private_messages_sender` FOREIGN KEY (`id_sender`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_private_messages_receiver` FOREIGN KEY (`id_receiver`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
