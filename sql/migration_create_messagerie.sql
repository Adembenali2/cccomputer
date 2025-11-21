-- Migration pour créer la table de messagerie interne
-- Permet aux utilisateurs de communiquer entre eux et de lier des messages à des clients, livraisons ou SAV

CREATE TABLE IF NOT EXISTS `messagerie` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_expediteur` INT NOT NULL COMMENT 'ID de l''utilisateur qui envoie le message',
  `id_destinataire` INT DEFAULT NULL COMMENT 'NULL = message à tous, sinon ID utilisateur spécifique',
  `sujet` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_lien` ENUM('client', 'livraison', 'sav', NULL) DEFAULT NULL COMMENT 'Type d''élément lié',
  `id_lien` INT DEFAULT NULL COMMENT 'ID de l''élément lié (client, livraison ou SAV)',
  `lu` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = non lu, 1 = lu',
  `date_envoi` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_lecture` DATETIME DEFAULT NULL COMMENT 'Date de première lecture',
  `supprime_expediteur` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Message supprimé par l''expéditeur',
  `supprime_destinataire` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Message supprimé par le destinataire',
  PRIMARY KEY (`id`),
  KEY `idx_expediteur` (`id_expediteur`),
  KEY `idx_destinataire` (`id_destinataire`),
  KEY `idx_lu` (`lu`),
  KEY `idx_date_envoi` (`date_envoi`),
  KEY `idx_type_lien` (`type_lien`, `id_lien`),
  CONSTRAINT `fk_messagerie_expediteur` FOREIGN KEY (`id_expediteur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messagerie_destinataire` FOREIGN KEY (`id_destinataire`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Index pour améliorer les performances des requêtes fréquentes
ALTER TABLE `messagerie` ADD INDEX `idx_destinataire_lu` (`id_destinataire`, `lu`, `date_envoi`);
ALTER TABLE `messagerie` ADD INDEX `idx_expediteur_supprime` (`id_expediteur`, `supprime_expediteur`, `date_envoi`);

-- Table pour gérer les lectures des messages "à tous" (un message peut être lu par plusieurs utilisateurs)
CREATE TABLE IF NOT EXISTS `messagerie_lectures` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `id_message` INT NOT NULL,
  `id_utilisateur` INT NOT NULL,
  `date_lecture` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_user` (`id_message`, `id_utilisateur`),
  KEY `idx_utilisateur` (`id_utilisateur`),
  KEY `idx_message` (`id_message`),
  CONSTRAINT `fk_lecture_message` FOREIGN KEY (`id_message`) REFERENCES `messagerie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lecture_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

