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
) ENGINE=InnoDB AUTO_INCREMENT=109458 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=220 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `import_run`;
CREATE TABLE `import_run` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ran_at` datetime NOT NULL,
  `imported` int NOT NULL,
  `skipped` int NOT NULL,
  `ok` tinyint(1) NOT NULL,
  `msg` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=782 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `ionos_cursor`;
CREATE TABLE `ionos_cursor` (
  `id` tinyint NOT NULL DEFAULT '1',
  `last_ts` datetime DEFAULT NULL,
  `last_mac` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lcd` (`marque`,`reference`)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `paper_catalog`;
CREATE TABLE `paper_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `marque` varchar(100) NOT NULL,
  `modele` varchar(100) NOT NULL,
  `poids` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paper` (`marque`,`modele`,`poids`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pc` (`reference`)
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sftp_jobs`;
CREATE TABLE `sftp_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `status` enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `error` text,
  `triggered_by` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `toner_catalog`;
CREATE TABLE `toner_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `marque` varchar(100) NOT NULL,
  `modele` varchar(100) NOT NULL,
  `couleur` varchar(20) NOT NULL,
  `qty_stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_toner` (`marque`,`modele`,`couleur`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email_unique` (`Email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP VIEW IF EXISTS `v_compteur_last`;
with `t` as (select `r`.`id` AS `id`,`r`.`Timestamp` AS `Timestamp`,`r`.`IpAddress` AS `IpAddress`,`r`.`Nom` AS `Nom`,`r`.`Model` AS `Model`,`r`.`SerialNumber` AS `SerialNumber`,`r`.`MacAddress` AS `MacAddress`,`r`.`Status` AS `Status`,`r`.`TonerBlack` AS `TonerBlack`,`r`.`TonerCyan` AS `TonerCyan`,`r`.`TonerMagenta` AS `TonerMagenta`,`r`.`TonerYellow` AS `TonerYellow`,`r`.`TotalPages` AS `TotalPages`,`r`.`FaxPages` AS `FaxPages`,`r`.`CopiedPages` AS `CopiedPages`,`r`.`PrintedPages` AS `PrintedPages`,`r`.`BWCopies` AS `BWCopies`,`r`.`ColorCopies` AS `ColorCopies`,`r`.`MonoCopies` AS `MonoCopies`,`r`.`BichromeCopies` AS `BichromeCopies`,`r`.`BWPrinted` AS `BWPrinted`,`r`.`BichromePrinted` AS `BichromePrinted`,`r`.`MonoPrinted` AS `MonoPrinted`,`r`.`ColorPrinted` AS `ColorPrinted`,`r`.`TotalColor` AS `TotalColor`,`r`.`TotalBW` AS `TotalBW`,`r`.`DateInsertion` AS `DateInsertion`,`r`.`mac_norm` AS `mac_norm`,row_number() OVER (PARTITION BY `r`.`mac_norm` ORDER BY `r`.`Timestamp` desc )  AS `rn` from `railway`.`compteur_relevee` `r`) select `t`.`id` AS `id`,`t`.`Timestamp` AS `Timestamp`,`t`.`IpAddress` AS `IpAddress`,`t`.`Nom` AS `Nom`,`t`.`Model` AS `Model`,`t`.`SerialNumber` AS `SerialNumber`,`t`.`MacAddress` AS `MacAddress`,`t`.`Status` AS `Status`,`t`.`TonerBlack` AS `TonerBlack`,`t`.`TonerCyan` AS `TonerCyan`,`t`.`TonerMagenta` AS `TonerMagenta`,`t`.`TonerYellow` AS `TonerYellow`,`t`.`TotalPages` AS `TotalPages`,`t`.`FaxPages` AS `FaxPages`,`t`.`CopiedPages` AS `CopiedPages`,`t`.`PrintedPages` AS `PrintedPages`,`t`.`BWCopies` AS `BWCopies`,`t`.`ColorCopies` AS `ColorCopies`,`t`.`MonoCopies` AS `MonoCopies`,`t`.`BichromeCopies` AS `BichromeCopies`,`t`.`BWPrinted` AS `BWPrinted`,`t`.`BichromePrinted` AS `BichromePrinted`,`t`.`MonoPrinted` AS `MonoPrinted`,`t`.`ColorPrinted` AS `ColorPrinted`,`t`.`TotalColor` AS `TotalColor`,`t`.`TotalBW` AS `TotalBW`,`t`.`DateInsertion` AS `DateInsertion`,`t`.`mac_norm` AS `mac_norm`,`t`.`rn` AS `rn` from `t` where (`t`.`rn` = 1);

DROP VIEW IF EXISTS `v_lcd_stock`;
select `l`.`id` AS `lcd_id`,`l`.`marque` AS `marque`,`l`.`reference` AS `reference`,`l`.`etat` AS `etat`,`l`.`modele` AS `modele`,`l`.`taille` AS `taille`,`l`.`resolution` AS `resolution`,`l`.`connectique` AS `connectique`,`l`.`prix` AS `prix`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`lcd_catalog` `l` left join `railway`.`lcd_moves` `m` on((`m`.`lcd_id` = `l`.`id`))) group by `l`.`id`,`l`.`marque`,`l`.`reference`,`l`.`etat`,`l`.`modele`,`l`.`taille`,`l`.`resolution`,`l`.`connectique`,`l`.`prix`;

DROP VIEW IF EXISTS `v_paper_stock`;
select `c`.`id` AS `paper_id`,`c`.`marque` AS `marque`,`c`.`modele` AS `modele`,`c`.`poids` AS `poids`,coalesce(sum(`m`.`qty_delta`),0) AS `qty_stock` from (`railway`.`paper_catalog` `c` left join `railway`.`paper_moves` `m` on((`m`.`paper_id` = `c`.`id`))) group by `c`.`id`,`c`.`marque`,`c`.`modele`,`c`.`poids`;

DROP VIEW IF EXISTS `v_photocopieurs_clients_last`;
select coalesce(`pc`.`mac_norm`,`railway`.`v`.`mac_norm`) AS `mac_norm`,`pc`.`SerialNumber` AS `SerialNumber`,`pc`.`MacAddress` AS `MacAddress`,`railway`.`v`.`Model` AS `Model`,`railway`.`v`.`Nom` AS `Nom`,`railway`.`v`.`Timestamp` AS `last_ts`,`railway`.`v`.`TonerBlack` AS `TonerBlack`,`railway`