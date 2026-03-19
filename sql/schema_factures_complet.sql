-- ============================================================
-- SCHÉMA COMPLET - Tables pour factures et paiements
-- À copier-coller dans votre base de données
-- Statut factures : brouillon, en_attente, envoyee, en_cours, en_retard, payee, annulee
--
-- ATTENTION : Ce script DROP les tables existantes (données perdues).
-- Si vous avez déjà des données, utilisez plutôt :
--   sql/migration_factures_statut_seul.sql (ALTER uniquement)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. CLIENTS (requis pour factures)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `client_geocode`;
DROP TABLE IF EXISTS `client_stock`;
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
  UNIQUE KEY `uq_clients_numero` (`numero_client`),
  KEY `idx_clients_email` (`email`),
  KEY `idx_clients_raison_sociale` (`raison_sociale`(100)),
  KEY `idx_clients_ville` (`ville`),
  KEY `idx_clients_code_postal` (`code_postal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 2. UTILISATEURS (requis pour factures, paiements)
-- ------------------------------------------------------------
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
  UNIQUE KEY `idx_email_unique` (`Email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 3. FACTURES (avec statuts: brouillon, en_attente, envoyee, en_cours, en_retard, payee, annulee)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `facture_lignes`;
DROP TABLE IF EXISTS `email_logs`;
DROP TABLE IF EXISTS `factures_envois_programmes`;
DROP TABLE IF EXISTS `paiements`;
DROP TABLE IF EXISTS `factures`;
CREATE TABLE `factures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `numero` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_facture` date NOT NULL,
  `date_debut_periode` date DEFAULT NULL COMMENT 'Date de début de période de consommation (20 du mois)',
  `date_fin_periode` date DEFAULT NULL COMMENT 'Date de fin de période de consommation (20 du mois suivant)',
  `type` enum('Consommation','Achat','Service') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Consommation',
  `montant_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tva` decimal(10,2) NOT NULL DEFAULT '0.00',
  `montant_ttc` decimal(10,2) NOT NULL DEFAULT '0.00',
  `statut` enum('brouillon','en_attente','envoyee','en_cours','en_retard','payee','annulee') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente',
  `pdf_genere` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
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

-- ------------------------------------------------------------
-- 4. FACTURE_LIGNES
-- ------------------------------------------------------------
CREATE TABLE `facture_lignes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_facture` int NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('N&B','Couleur','Service','Produit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` decimal(10,2) NOT NULL DEFAULT '1.00',
  `prix_unitaire_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ordre` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_facture_lignes_facture` (`id_facture`),
  CONSTRAINT `fk_facture_lignes_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 5. PAIEMENTS
-- ------------------------------------------------------------
CREATE TABLE `paiements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_facture` int DEFAULT NULL COMMENT 'ID de la facture liée',
  `id_client` int NOT NULL COMMENT 'ID du client',
  `montant` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `mode_paiement` enum('virement','cb','cheque','especes','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'virement',
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Référence du paiement',
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('en_cours','recu','refuse','annule') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_cours',
  `recu_genere` tinyint(1) NOT NULL DEFAULT '0',
  `recu_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
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
  CONSTRAINT `fk_paiements_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_paiements_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_paiements_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 6. HISTORIQUE
-- ------------------------------------------------------------
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
  KEY `idx_date_action` (`date_action`),
  CONSTRAINT `fk_historique_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 7. EMAIL_LOGS
-- ------------------------------------------------------------
CREATE TABLE `email_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `facture_id` int DEFAULT NULL COMMENT 'ID de la facture liée',
  `type_email` enum('facture','paiement','autre') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'facture',
  `destinataire` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `sujet` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `statut` enum('pending','sent','failed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `message_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_logs_facture` (`facture_id`),
  KEY `idx_email_logs_statut` (`statut`),
  CONSTRAINT `fk_email_logs_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 8. FACTURES_ENVOIS_PROGRAMMES
-- ------------------------------------------------------------
CREATE TABLE `factures_envois_programmes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_envoi` enum('une_facture','plusieurs_factures','toutes_selectionnees','tous_clients') NOT NULL DEFAULT 'une_facture',
  `facture_id` int DEFAULT NULL,
  `factures_json` text DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `email_destination` varchar(255) DEFAULT NULL,
  `use_client_email` tinyint(1) NOT NULL DEFAULT 1,
  `all_clients` tinyint(1) NOT NULL DEFAULT 0,
  `all_selected_factures` tinyint(1) NOT NULL DEFAULT 0,
  `sujet` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `date_envoi_programmee` datetime NOT NULL,
  `statut` enum('en_attente','envoye','annule','echoue') NOT NULL DEFAULT 'en_attente',
  `erreur_message` text DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fep_statut` (`statut`),
  KEY `idx_fep_date_envoi` (`date_envoi_programmee`),
  KEY `idx_fep_facture` (`facture_id`),
  CONSTRAINT `fk_fep_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fep_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fep_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 9. PHOTOCOPIEURS_CLIENTS (pour factures_check_photocopieurs)
-- ------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 10. COMPTEUR_RELEVEE (pour factures_check_photocopieurs)
-- ------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 11. COMPTEUR_RELEVEE_ANCIEN (optionnel, pour factures_check_photocopieurs)
-- ------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
