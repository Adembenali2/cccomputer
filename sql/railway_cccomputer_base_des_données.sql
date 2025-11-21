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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `client_stock`;
CREATE TABLE `client_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `product_type` enum('papier','toner','lcd','pc') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `product_id` int NOT NULL,
  `qty_stock` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_stock` (`id_client`,`product_type`,`product_id`),
  KEY `idx_client_stock_client` (`id_client`),
  KEY `idx_client_stock_product` (`product_type`,`product_id`),
  CONSTRAINT `fk_client_stock_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sav`;
CREATE TABLE `sav` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int DEFAULT NULL,
  `id_technicien` int DEFAULT NULL,
  `reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_ouverture` date NOT NULL,
  `date_fermeture` date DEFAULT NULL,
  `statut` enum('ouvert','en_cours','resolu','annule') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ouvert',
  `priorite` enum('basse','normale','haute','urgente') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'normale',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
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
  CONSTRAINT `fk_sav_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sav_technicien` FOREIGN KEY (`id_technicien`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
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
CREATE VIEW `v_compteur_last` AS
WITH t AS (
    SELECT 
        r.id, r.Timestamp, r.IpAddress, r.Nom, r.Model, r.SerialNumber, 
        r.MacAddress, r.Status, r.TonerBlack, r.TonerCyan, r.TonerMagenta, 
        r.TonerYellow, r.TotalPages, r.FaxPages, r.CopiedPages, r.PrintedPages, 
        r.BWCopies, r.ColorCopies, r.MonoCopies, r.BichromeCopies, r.BWPrinted, 
        r.BichromePrinted, r.MonoPrinted, r.ColorPrinted, r.TotalColor, 
        r.TotalBW, r.DateInsertion, r.mac_norm,
        ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.Timestamp DESC) AS rn
    FROM compteur_relevee r
)
SELECT * FROM t WHERE t.rn = 1;

DROP VIEW IF EXISTS `v_lcd_stock`;
CREATE VIEW `v_lcd_stock` AS
SELECT 
    l.id AS lcd_id,
    l.marque,
    l.reference,
    l.etat,
    l.modele,
    l.taille,
    l.resolution,
    l.connectique,
    l.prix,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM lcd_catalog l
LEFT JOIN lcd_moves m ON m.lcd_id = l.id
GROUP BY l.id, l.marque, l.reference, l.etat, l.modele, l.taille, 
         l.resolution, l.connectique, l.prix;

DROP VIEW IF EXISTS `v_paper_stock`;
CREATE VIEW `v_paper_stock` AS
SELECT 
    c.id AS paper_id,
    c.marque,
    c.modele,
    c.poids,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM paper_catalog c
LEFT JOIN paper_moves m ON m.paper_id = c.id
GROUP BY c.id, c.marque, c.modele, c.poids;

DROP VIEW IF EXISTS `v_photocopieurs_clients_last`;
CREATE VIEW `v_photocopieurs_clients_last` AS
WITH v_compteur_last AS (
    SELECT 
        r.*,
        ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
    FROM compteur_relevee r
    WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
),
v_last AS (
    SELECT * FROM v_compteur_last WHERE rn = 1
)
SELECT 
    COALESCE(pc.mac_norm, v.mac_norm) AS mac_norm,
    pc.id_client AS client_id,
    pc.SerialNumber,
    pc.MacAddress,
    v.Model,
    v.Nom,
    v.`Timestamp` AS last_ts,
    v.TonerBlack,
    v.TonerCyan,
    v.TonerMagenta,
    v.TonerYellow,
    v.TotalBW,
    v.TotalColor,
    v.TotalPages,
    v.Status,
    v.IpAddress
FROM photocopieurs_clients pc
LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
WHERE pc.id_client IS NOT NULL;

DROP VIEW IF EXISTS `v_toner_stock`;
CREATE VIEW `v_toner_stock` AS
SELECT 
    t.id AS toner_id,
    t.marque,
    t.modele,
    t.couleur,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM toner_catalog t
LEFT JOIN toner_moves m ON m.toner_id = t.id
GROUP BY t.id, t.marque, t.modele, t.couleur;

DROP VIEW IF EXISTS `v_pc_stock`;
CREATE VIEW `v_pc_stock` AS
SELECT 
    p.id AS pc_id,
    p.etat,
    p.reference,
    p.marque,
    p.modele,
    p.cpu,
    p.ram,
    p.stockage,
    p.os,
    p.gpu,
    p.reseau,
    p.ports,
    p.prix,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM pc_catalog p
LEFT JOIN pc_moves m ON m.pc_id = p.id
GROUP BY p.id, p.etat, p.reference, p.marque, p.modele, p.cpu, p.ram, 
         p.stockage, p.os, p.gpu, p.reseau, p.ports, p.prix;