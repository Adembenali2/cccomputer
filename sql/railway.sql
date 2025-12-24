/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

DROP TABLE IF EXISTS `app_kv`;
CREATE TABLE `app_kv` (
  `k` varchar(64) NOT NULL,
  `v` text,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `chatroom_messages`;
CREATE TABLE `chatroom_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL COMMENT 'ID de l''utilisateur qui a envoyé le message',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Contenu du message',
  `date_envoi` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date et heure d''envoi du message',
  `mentions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'JSON array des IDs utilisateurs mentionnés (@username)',
  `type_lien` enum('client','livraison','sav') COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Type de lien associé',
  `id_lien` int DEFAULT NULL COMMENT 'ID du client/livraison/SAV lié',
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_date_envoi` (`date_envoi`),
  KEY `idx_type_lien` (`type_lien`,`id_lien`),
  KEY `idx_date_envoi_desc` (`date_envoi` DESC),
  CONSTRAINT `fk_chatroom_messages_user` FOREIGN KEY (`id_user`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `chatroom_notifications`;
CREATE TABLE `chatroom_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL COMMENT 'Utilisateur qui reçoit la notification',
  `id_message` int NOT NULL COMMENT 'Message qui a déclenché la notification',
  `type` enum('mention','message') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'message' COMMENT 'Type de notification',
  `lu` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = non lu, 1 = lu',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_id_user` (`id_user`),
  KEY `idx_id_message` (`id_message`),
  KEY `idx_lu` (`lu`),
  KEY `idx_date_creation` (`date_creation`),
  CONSTRAINT `fk_chatroom_notif_message` FOREIGN KEY (`id_message`) REFERENCES `chatroom_messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chatroom_notif_user` FOREIGN KEY (`id_user`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `client_stock`;
CREATE TABLE `client_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `product_type` enum('papier','toner','lcd','pc') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `product_id` int NOT NULL,
  `qty_stock` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_stock` (`id_client`,`product_type`,`product_id`),
  KEY `idx_client_stock_client` (`id_client`),
  KEY `idx_client_stock_product` (`product_type`,`product_id`),
  CONSTRAINT `fk_client_stock_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `client_geocode`;
CREATE TABLE `client_geocode` (
  `id_client` int NOT NULL,
  `address_hash` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Hash MD5 de l''adresse géocodée',
  `lat` decimal(10,8) DEFAULT NULL COMMENT 'Latitude',
  `lng` decimal(11,8) DEFAULT NULL COMMENT 'Longitude',
  `display_name` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Nom d''affichage retourné par le géocodage',
  `geocoded_at` datetime DEFAULT NULL COMMENT 'Date du premier géocodage',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date de dernière mise à jour',
  PRIMARY KEY (`id_client`),
  KEY `idx_address_hash` (`address_hash`),
  CONSTRAINT `fk_client_geocode_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id` int NOT NULL,
  `numero_client` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `raison_sociale` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `adresse` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `code_postal` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `ville` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `adresse_livraison` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `livraison_identique` tinyint(1) DEFAULT '0',
  `siret` varchar(14) COLLATE utf8mb4_general_ci NOT NULL,
  `numero_tva` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `depot_mode` enum('espece','cheque','virement','paiement_carte') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'espece',
  `nom_dirigeant` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prenom_dirigeant` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `telephone1` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `telephone2` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `parrain` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `offre` enum('packbronze','packargent') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'packbronze',
  `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_dajout` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pdf1` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pdf2` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pdf3` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pdf4` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pdf5` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pdfcontrat` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `iban` varchar(34) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_numero` (`numero_client`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `compteur_relevee`;
CREATE TABLE `compteur_relevee` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Timestamp` datetime DEFAULT NULL,
  `IpAddress` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Nom` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Model` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `SerialNumber` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `MacAddress` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Status` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `TonerBlack` int DEFAULT NULL,
  `TonerCyan` int DEFAULT NULL,
  `TonerMagenta` int DEFAULT NULL,
  `TonerYellow` int DEFAULT NULL,
  `TotalPages` int DEFAULT NULL,
  `FaxPages` int DEFAULT NULL,
  `CopiedPages` int DEFAULT NULL,
  `PrintedPages` int DEFAULT NULL,
  `BWCopies` int DEFAULT NULL,
  `ColorCopies` int DEFAULT NULL,
  `MonoCopies` int DEFAULT NULL,
  `BichromeCopies` int DEFAULT NULL,
  `BWPrinted` int DEFAULT NULL,
  `BichromePrinted` int DEFAULT NULL,
  `MonoPrinted` int DEFAULT NULL,
  `ColorPrinted` int DEFAULT NULL,
  `TotalColor` int DEFAULT NULL,
  `TotalBW` int DEFAULT NULL,
  `DateInsertion` datetime DEFAULT NULL,
  `mac_norm` char(12) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (replace(upper(`MacAddress`),_utf8mb4':',_utf8mb4'')) STORED,
  PRIMARY KEY (`id`),
  KEY `ix_compteur_date` (`Timestamp`),
  KEY `ix_compteur_mac_ts` (`mac_norm`,`Timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=109978 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `compteur_relevee_ancien`;
CREATE TABLE `compteur_relevee_ancien` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Timestamp` datetime DEFAULT NULL,
  `IpAddress` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `SerialNumber` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `MacAddress` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `TonerBlack` int DEFAULT NULL,
  `TonerCyan` int DEFAULT NULL,
  `TonerMagenta` int DEFAULT NULL,
  `TonerYellow` int DEFAULT NULL,
  `TotalPages` int DEFAULT NULL,
  `FaxPages` int DEFAULT NULL,
  `CopiedPages` int DEFAULT NULL,
  `PrintedPages` int DEFAULT NULL,
  `BWCopies` int DEFAULT NULL,
  `ColorCopies` int DEFAULT NULL,
  `MonoCopies` int DEFAULT NULL,
  `BichromeCopies` int DEFAULT NULL,
  `BWPrinted` int DEFAULT NULL,
  `BichromePrinted` int DEFAULT NULL,
  `MonoPrinted` int DEFAULT NULL,
  `ColorPrinted` int DEFAULT NULL,
  `TotalColor` int DEFAULT NULL,
  `TotalBW` int DEFAULT NULL,
  `DateInsertion` datetime DEFAULT NULL,
  `mac_norm` char(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (replace(upper(`MacAddress`),_utf8mb4':',_utf8mb4'')) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mac_ts_ancien` (`mac_norm`,`Timestamp`),
  KEY `ix_compteur_date` (`Timestamp`),
  KEY `ix_compteur_mac_ts` (`mac_norm`,`Timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=149430 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `historique`;
CREATE TABLE `historique` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `details` text COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_action` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_date_action` (`date_action`)
) ENGINE=InnoDB AUTO_INCREMENT=555 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `import_run`;
CREATE TABLE `import_run` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ran_at` datetime NOT NULL,
  `imported` int NOT NULL,
  `skipped` int NOT NULL,
  `ok` tinyint(1) NOT NULL,
  `msg` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2891 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `lcd_catalog`;
CREATE TABLE `lcd_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `marque` varchar(100) NOT NULL,
  `reference` varchar(100) NOT NULL,
  `etat` char(1) NOT NULL DEFAULT 'A',
  `modele` varchar(100) NOT NULL,
  `taille` tinyint unsigned NOT NULL,
  `resolution` varchar(20) NOT NULL,
  `connectique` varchar(100) NOT NULL,
  `prix` decimal(10,2) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lcd` (`marque`,`reference`),
  UNIQUE KEY `uq_lcd_barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `lcd_moves`;
CREATE TABLE `lcd_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lcd_id` int NOT NULL,
  `qty_delta` int NOT NULL,
  `reason` enum('ajustement','achat','retour','correction') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lcd_time` (`lcd_id`,`created_at`),
  CONSTRAINT `fk_lcd_moves_catalog` FOREIGN KEY (`lcd_id`) REFERENCES `lcd_catalog` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `livraisons`;
CREATE TABLE `livraisons` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int DEFAULT NULL,
  `id_livreur` int DEFAULT NULL,
  `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `adresse_livraison` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_prevue` date NOT NULL,
  `date_reelle` date DEFAULT NULL,
  `statut` enum('planifiee','en_cours','livree','annulee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'planifiee',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `product_type` enum('papier','toner','lcd','pc','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `product_qty` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_livraisons_reference` (`reference`),
  KEY `idx_livraisons_client` (`id_client`),
  KEY `idx_livraisons_livreur` (`id_livreur`),
  KEY `idx_livraisons_date_prevue` (`date_prevue`),
  KEY `idx_livraisons_date_reelle` (`date_reelle`),
  KEY `idx_livraisons_statut` (`statut`),
  CONSTRAINT `fk_livraisons_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_livraisons_livreur` FOREIGN KEY (`id_livreur`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `messagerie`;
CREATE TABLE `messagerie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_expediteur` int NOT NULL,
  `id_destinataire` int DEFAULT NULL,
  `sujet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_lien` enum('client','livraison','sav') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_lien` int DEFAULT NULL,
  `id_message_parent` int DEFAULT NULL,
  `type_reponse` enum('text','emoji') COLLATE utf8mb4_general_ci DEFAULT 'text',
  `emoji_code` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lu` tinyint(1) NOT NULL DEFAULT '0',
  `date_envoi` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_lecture` datetime DEFAULT NULL,
  `supprime_expediteur` tinyint(1) NOT NULL DEFAULT '0',
  `supprime_destinataire` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_expediteur` (`id_expediteur`),
  KEY `idx_destinataire` (`id_destinataire`),
  KEY `idx_lu` (`lu`),
  KEY `idx_date_envoi` (`date_envoi`),
  KEY `idx_type_lien` (`type_lien`,`id_lien`),
  KEY `idx_destinataire_lu` (`id_destinataire`,`lu`,`date_envoi`),
  KEY `idx_expediteur_supprime` (`id_expediteur`,`supprime_expediteur`,`date_envoi`),
  KEY `idx_message_parent` (`id_message_parent`),
  CONSTRAINT `fk_messagerie_destinataire` FOREIGN KEY (`id_destinataire`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messagerie_expediteur` FOREIGN KEY (`id_expediteur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messagerie_parent` FOREIGN KEY (`id_message_parent`) REFERENCES `messagerie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `messagerie_lectures`;
CREATE TABLE `messagerie_lectures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_message` int NOT NULL,
  `id_utilisateur` int NOT NULL,
  `date_lecture` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_user` (`id_message`,`id_utilisateur`),
  KEY `idx_utilisateur` (`id_utilisateur`),
  KEY `idx_message` (`id_message`),
  CONSTRAINT `fk_lecture_message` FOREIGN KEY (`id_message`) REFERENCES `messagerie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lecture_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `paper_catalog`;
CREATE TABLE `paper_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `marque` varchar(100) NOT NULL,
  `modele` varchar(100) NOT NULL,
  `poids` varchar(20) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paper` (`marque`,`modele`,`poids`),
  UNIQUE KEY `uq_paper_barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `paper_moves`;
CREATE TABLE `paper_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `paper_id` int NOT NULL,
  `qty_delta` int NOT NULL,
  `reason` enum('ajustement','achat','retour','correction') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_paper_time` (`paper_id`,`created_at`),
  CONSTRAINT `fk_paper_moves_catalog` FOREIGN KEY (`paper_id`) REFERENCES `paper_catalog` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `pc_catalog`;
CREATE TABLE `pc_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `etat` char(1) NOT NULL DEFAULT 'A',
  `reference` varchar(100) NOT NULL,
  `marque` varchar(100) NOT NULL,
  `modele` varchar(100) NOT NULL,
  `cpu` varchar(100) NOT NULL,
  `ram` varchar(50) NOT NULL,
  `stockage` varchar(100) NOT NULL,
  `os` varchar(100) NOT NULL,
  `gpu` varchar(100) DEFAULT NULL,
  `reseau` varchar(100) DEFAULT NULL,
  `ports` varchar(255) DEFAULT NULL,
  `prix` decimal(10,2) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pc` (`reference`),
  UNIQUE KEY `uq_pc_barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `pc_moves`;
CREATE TABLE `pc_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pc_id` int NOT NULL,
  `qty_delta` int NOT NULL,
  `reason` enum('ajustement','achat','retour','correction') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pc_time` (`pc_id`,`created_at`),
  CONSTRAINT `fk_pc_moves_catalog` FOREIGN KEY (`pc_id`) REFERENCES `pc_catalog` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `photocopieurs_clients`;
CREATE TABLE `photocopieurs_clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int DEFAULT NULL,
  `SerialNumber` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `MacAddress` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mac_norm` char(12) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (replace(upper(`MacAddress`),_utf8mb4':',_utf8mb4'')) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_serial` (`SerialNumber`),
  UNIQUE KEY `u_mac` (`mac_norm`),
  KEY `idx_pc_client` (`id_client`),
  CONSTRAINT `fk_pc_client__clients_id` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sav`;
CREATE TABLE `sav` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int DEFAULT NULL,
  `mac_norm` char(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `id_technicien` int DEFAULT NULL,
  `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_ouverture` date NOT NULL,
  `date_intervention_prevue` date DEFAULT NULL,
  `temps_intervention_estime` decimal(4,2) DEFAULT NULL COMMENT 'Temps estimé en heures',
  `temps_intervention_reel` decimal(4,2) DEFAULT NULL COMMENT 'Temps réel en heures',
  `cout_intervention` decimal(10,2) DEFAULT NULL COMMENT 'Coût de l''intervention en euros',
  `date_fermeture` date DEFAULT NULL,
  `satisfaction_client` tinyint DEFAULT NULL COMMENT 'Note de satisfaction de 1 à 5',
  `commentaire_client` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Commentaire du client sur l''intervention',
  `statut` enum('ouvert','en_cours','resolu','annule') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ouvert',
  `priorite` enum('basse','normale','haute','urgente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'normale',
  `type_panne` enum('logiciel','materiel','piece_rechangeable') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `notes_techniques` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Notes techniques réservées aux techniciens',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sav_reference` (`reference`),
  KEY `idx_sav_client` (`id_client`),
  KEY `idx_sav_technicien` (`id_technicien`),
  KEY `idx_sav_date_ouverture` (`date_ouverture`),
  KEY `idx_sav_date_fermeture` (`date_fermeture`),
  KEY `idx_sav_statut` (`statut`),
  KEY `idx_sav_priorite` (`priorite`),
  KEY `idx_sav_mac_norm` (`mac_norm`),
  KEY `idx_sav_date_intervention` (`date_intervention_prevue`),
  CONSTRAINT `fk_sav_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sav_technicien` FOREIGN KEY (`id_technicien`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sav_pieces_utilisees`;
CREATE TABLE `sav_pieces_utilisees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_sav` int NOT NULL,
  `product_type` enum('papier','toner','lcd','pc') COLLATE utf8mb4_general_ci NOT NULL,
  `product_id` int NOT NULL,
  `quantite` int NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sav_pieces_sav` (`id_sav`),
  KEY `idx_sav_pieces_product` (`product_type`,`product_id`),
  CONSTRAINT `fk_sav_pieces_sav` FOREIGN KEY (`id_sav`) REFERENCES `sav` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `toner_catalog`;
CREATE TABLE `toner_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `marque` varchar(100) NOT NULL,
  `modele` varchar(100) NOT NULL,
  `couleur` varchar(20) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_toner` (`marque`,`modele`,`couleur`),
  UNIQUE KEY `uq_toner_barcode` (`barcode`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `toner_moves`;
CREATE TABLE `toner_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `toner_id` int NOT NULL,
  `qty_delta` int NOT NULL,
  `reason` enum('ajustement','achat','retour','correction') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_toner_time` (`toner_id`,`created_at`),
  CONSTRAINT `fk_toner_moves_catalog` FOREIGN KEY (`toner_id`) REFERENCES `toner_catalog` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `page` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = autorisé, 0 = interdit',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_page` (`user_id`,`page`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_page` (`page`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `factures`;
CREATE TABLE `factures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `numero` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `date_facture` date NOT NULL,
  `date_debut_periode` date DEFAULT NULL COMMENT 'Date de début de période de consommation (20 du mois)',
  `date_fin_periode` date DEFAULT NULL COMMENT 'Date de fin de période de consommation (20 du mois suivant)',
  `type` enum('Consommation','Achat','Service') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Consommation',
  `montant_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tva` decimal(10,2) NOT NULL DEFAULT '0.00',
  `montant_ttc` decimal(10,2) NOT NULL DEFAULT '0.00',
  `statut` enum('brouillon','envoyee','payee','en_retard','annulee') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
  `pdf_genere` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_envoye` tinyint(1) NOT NULL DEFAULT '0',
  `date_envoi_email` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur qui a créé la facture',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_factures_numero` (`numero`),
  KEY `idx_factures_client` (`id_client`),
  KEY `idx_factures_date` (`date_facture`),
  KEY `idx_factures_statut` (`statut`),
  KEY `idx_factures_created_by` (`created_by`),
  CONSTRAINT `fk_factures_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_factures_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `facture_lignes`;
CREATE TABLE `facture_lignes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_facture` int NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('N&B','Couleur','Service','Produit') COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` decimal(10,2) NOT NULL DEFAULT '1.00',
  `prix_unitaire_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ordre` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_facture_lignes_facture` (`id_facture`),
  CONSTRAINT `fk_facture_lignes_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `paiements`;
CREATE TABLE `paiements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_facture` int DEFAULT NULL COMMENT 'ID de la facture liée (peut être NULL pour paiement sans facture)',
  `id_client` int NOT NULL COMMENT 'ID du client',
  `montant` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `mode_paiement` enum('virement','cb','cheque','especes','autre') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'virement',
  `reference` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Référence du paiement (ex: VIR-2025-001)',
  `commentaire` text COLLATE utf8mb4_general_ci,
  `statut` enum('en_cours','recu','refuse','annule') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_cours',
  `recu_genere` tinyint(1) NOT NULL DEFAULT '0',
  `recu_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_envoye` tinyint(1) NOT NULL DEFAULT '0',
  `date_envoi_email` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur qui a créé le paiement',
  PRIMARY KEY (`id`),
  KEY `idx_paiements_facture` (`id_facture`),
  KEY `idx_paiements_client` (`id_client`),
  KEY `idx_paiements_date` (`date_paiement`),
  KEY `idx_paiements_statut` (`statut`),
  KEY `idx_paiements_created_by` (`created_by`),
  CONSTRAINT `fk_paiements_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_paiements_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_paiements_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE `utilisateurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `telephone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Emploi` enum('Chargé relation clients','Livreur','Technicien','Secrétaire','Dirigeant','Admin') COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('actif','inactif') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'actif',
  `date_debut` date NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_activity` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email_unique` (`Email`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP VIEW IF EXISTS `v_compteur_last`;
with `t` as (select `r`.`id` AS `id`,`r`.`Timestamp` AS `Timestamp`,`r`.`IpAddress` AS `IpAddress`,`r`.`Nom` AS `Nom`,`r`.`Model` AS `Model`,`r`.`SerialNumber` AS `SerialNumber`,`r`.`MacAddress` AS `MacAddress`,`r`.`Status` AS `Status`,`r`.`TonerBlack` AS `TonerBlack`,`r`.`TonerCyan` AS `TonerCyan`,`r`.`TonerMagenta` AS `TonerMagenta`,`r`.`TonerYellow` AS `TonerYellow`,`r`.`TotalPages` AS `TotalPages`,`r`.`FaxPages` AS `FaxPages`,`r`.`CopiedPages` AS `CopiedPages`,`r`.`PrintedPages` AS `PrintedPages`,`r`.`BWCopies` AS `BWCopies`,`r`.`ColorCopies` AS `ColorCopies`,`r`.`MonoCopies` AS `MonoCopies`,`r`.`BichromeCopies` AS `BichromeCopies`,`r`.`BWPrinted` AS `BWPrinted`,`r`.`BichromePrinted` AS `BichromePrinted`,`r`.`MonoPrinted` AS `MonoPrinted`,`r`.`ColorPrinted` AS `ColorPrinted`,`r`.`TotalColor` AS `TotalColor`,`r`.`TotalBW` AS `TotalBW`,`r`.`DateInsertion` AS `DateInsertion`,`r`.`mac_norm` AS `mac_norm`,row_number() OVER (PARTITION BY `r`.`mac_norm` ORDER BY `r`.`Timestamp` desc )  AS `rn` from `railway`.`compteur_relevee` `r`) select `t`.`id` AS `id`,`t`.`Timestamp` AS `Timestamp`,`t`.`IpAddress` AS `IpAddress`,`t`.`Nom` AS `Nom`,`t`.`Model` AS `Model`,`t`.`SerialNumber` AS `SerialNumber`,`t`.`MacAddress` AS `MacAddress`,`t`.`Status` AS `Status`,`t`.`TonerBlack` AS `TonerBlack`,`t`.`TonerCyan` AS `TonerCyan`,`t`.`TonerMagenta` AS `TonerMagenta`,`t`.`TonerYellow` AS `TonerYellow`,`t`.`TotalPages` AS `TotalPages`,`t`.`FaxPages` AS `FaxPages`,`t`.`CopiedPages` AS `CopiedPages`,`t`.`PrintedPages` AS `PrintedPages`,`t`.`BWCopies` AS `BWCopies`,`t`.`ColorCopies` AS `ColorCopies`,`t`.`MonoCopies` AS `MonoCopies`,`t`.`BichromeCopies` AS `BichromeCopies`,`t`.`BWPrinted` AS `BWPrinted`,`t`.`BichromePrinted` AS `BichromePrinted`,`t`.`MonoPrinted` AS `MonoPrinted`,`t`.`ColorPrinted` AS `ColorPrinted`,`t`.`TotalColor` AS `TotalColor`,`t`.`TotalBW` AS `TotalBW`,`t`.`DateInsertion` AS `DateInsertion`,`t`.`mac_norm` AS `mac_norm`,`t`.`rn` AS `rn` from `t` where (`t`.`rn` = 1);

DROP VIEW IF EXISTS `v_lcd_stock`;
select `l`.`id` AS `lcd_id`,`l`.`marque` AS `marque`,`l`.`reference` AS `reference`,`l`.`etat` AS `etat`,`l`.`modele` AS `modele`,`l`.`taille` AS `taille`,`l`.`resolution` AS `resolution`,`l`.`connectique` AS `connectique`,`l`.`prix` AS `prix`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`lcd_catalog` `l` left join `railway`.`lcd_moves` `m` on((`m`.`lcd_id` = `l`.`id`))) group by `l`.`id`,`l`.`marque`,`l`.`reference`,`l`.`etat`,`l`.`modele`,`l`.`taille`,`l`.`resolution`,`l`.`connectique`,`l`.`prix`;

DROP VIEW IF EXISTS `v_paper_stock`;
select `c`.`id` AS `paper_id`,`c`.`marque` AS `marque`,`c`.`modele` AS `modele`,`c`.`poids` AS `poids`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`paper_catalog` `c` left join `railway`.`paper_moves` `m` on((`m`.`paper_id` = `c`.`id`))) group by `c`.`id`,`c`.`marque`,`c`.`modele`,`c`.`poids`;

DROP VIEW IF EXISTS `v_pc_stock`;
select `p`.`id` AS `pc_id`,`p`.`etat` AS `etat`,`p`.`reference` AS `reference`,`p`.`marque` AS `marque`,`p`.`modele` AS `modele`,`p`.`cpu` AS `cpu`,`p`.`ram` AS `ram`,`p`.`stockage` AS `stockage`,`p`.`os` AS `os`,`p`.`gpu` AS `gpu`,`p`.`reseau` AS `reseau`,`p`.`ports` AS `ports`,`p`.`prix` AS `prix`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`pc_catalog` `p` left join `railway`.`pc_moves` `m` on((`m`.`pc_id` = `p`.`id`))) group by `p`.`id`,`p`.`etat`,`p`.`reference`,`p`.`marque`,`p`.`modele`,`p`.`cpu`,`p`.`ram`,`p`.`stockage`,`p`.`os`,`p`.`gpu`,`p`.`reseau`,`p`.`ports`,`p`.`prix`;

DROP VIEW IF EXISTS `v_photocopieurs_clients_last`;
with `v_compteur_last` as (select `r`.`id` AS `id`,`r`.`Timestamp` AS `Timestamp`,`r`.`IpAddress` AS `IpAddress`,`r`.`Nom` AS `Nom`,`r`.`Model` AS `Model`,`r`.`SerialNumber` AS `SerialNumber`,`r`.`MacAddress` AS `MacAddress`,`r`.`Status` AS `Status`,`r`.`TonerBlack` AS `TonerBlack`,`r`.`TonerCyan` AS `TonerCyan`,`r`.`TonerMagenta` AS `TonerMagenta`,`r`.`TonerYellow` AS `TonerYellow`,`r`.`TotalPages` AS `TotalPages`,`r`.`FaxPages` AS `FaxPages`,`r`.`CopiedPages` AS `CopiedPages`,`r`.`PrintedPages` AS `PrintedPages`,`r`.`BWCopies` AS `BWCopies`,`r`.`ColorCopies` AS `ColorCopies`,`r`.`MonoCopies` AS `MonoCopies`,`r`.`BichromeCopies` AS `BichromeCopies`,`r`.`BWPrinted` AS `BWPrinted`,`r`.`BichromePrinted` AS `BichromePrinted`,`r`.`MonoPrinted` AS `MonoPrinted`,`r`.`ColorPrinted` AS `ColorPrinted`,`r`.`TotalColor` AS `TotalColor`,`r`.`TotalBW` AS `TotalBW`,`r`.`DateInsertion` AS `DateInsertion`,`r`.`mac_norm` AS `mac_norm`,row_number() OVER (PARTITION BY `r`.`mac_norm` ORDER BY `r`.`Timestamp` desc )  AS `rn` from `railway`.`compteur_relevee` `r` where ((`r`.`mac_norm` is not null) and (`r`.`mac_norm` <> ''))), `v_last` as (select `v_compteur_last`.`id` AS `id`,`v_compteur_last`.`Timestamp` AS `Timestamp`,`v_compteur_last`.`IpAddress` AS `IpAddress`,`v_compteur_last`.`Nom` AS `Nom`,`v_compteur_last`.`Model` AS `Model`,`v_compteur_last`.`SerialNumber` AS `SerialNumber`,`v_compteur_last`.`MacAddress` AS `MacAddress`,`v_compteur_last`.`Status` AS `Status`,`v_compteur_last`.`TonerBlack` AS `TonerBlack`,`v_compteur_last`.`TonerCyan` AS `TonerCyan`,`v_compteur_last`.`TonerMagenta` AS `TonerMagenta`,`v_compteur_last`.`TonerYellow` AS `TonerYellow`,`v_compteur_last`.`TotalPages` AS `TotalPages`,`v_compteur_last`.`FaxPages` AS `FaxPages`,`v_compteur_last`.`CopiedPages` AS `CopiedPages`,`v_compteur_last`.`PrintedPages` AS `PrintedPages`,`v_compteur_last`.`BWCopies` AS `BWCopies`,`v_compteur_last`.`ColorCopies` AS `ColorCopies`,`v_compteur_last`.`MonoCopies` AS `MonoCopies`,`v_compteur_last`.`BichromeCopies` AS `BichromeCopies`,`v_compteur_last`.`BWPrinted` AS `BWPrinted`,`v_compteur_last`.`BichromePrinted` AS `BichromePrinted`,`v_compteur_last`.`MonoPrinted` AS `MonoPrinted`,`v_compteur_last`.`ColorPrinted` AS `ColorPrinted`,`v_compteur_last`.`TotalColor` AS `TotalColor`,`v_compteur_last`.`TotalBW` AS `TotalBW`,`v_compteur_last`.`DateInsertion` AS `DateInsertion`,`v_compteur_last`.`mac_norm` AS `mac_norm`,`v_compteur_last`.`rn` AS `rn` from `v_compteur_last` where (`v_compteur_last`.`rn` = 1)) select coalesce(`pc`.`mac_norm`,`v`.`mac_norm`) AS `mac_norm`,`pc`.`id_client` AS `client_id`,`pc`.`SerialNumber` AS `SerialNumber`,`pc`.`MacAddress` AS `MacAddress`,`v`.`Model` AS `Model`,`v`.`Nom` AS `Nom`,`v`.`Timestamp` AS `last_ts`,`v`.`TonerBlack` AS `TonerBlack`,`v`.`TonerCyan` AS `TonerCyan`,`v`.`TonerMagenta` AS `TonerMagenta`,`v`.`TonerYellow` AS `TonerYellow`,`v`.`TotalBW` AS `TotalBW`,`v`.`TotalColor` AS `TotalColor`,`v`.`TotalPages` AS `TotalPages`,`v`.`Status` AS `Status`,`v`.`IpAddress` AS `IpAddress` from (`railway`.`photocopieurs_clients` `pc` left join `v_last` `v` on((`v`.`mac_norm` = `pc`.`mac_norm`))) where (`pc`.`id_client` is not null);

DROP VIEW IF EXISTS `v_toner_stock`;
select `t`.`id` AS `toner_id`,`t`.`marque` AS `marque`,`t`.`modele` AS `modele`,`t`.`couleur` AS `couleur`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`toner_catalog` `t` left join `railway`.`toner_moves` `m` on((`m`.`toner_id` = `t`.`id`))) group by `t`.`id`,`t`.`marque`,`t`.`modele`,`t`.`couleur`;



/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;