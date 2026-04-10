-- Pack business : relances, facturation récurrente, opportunités, paramètres produit
-- MySQL / MariaDB — exécuter sur la base applicative existante

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `facture_relances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `facture_id` int NOT NULL,
  `niveau` tinyint NOT NULL COMMENT '1=douce 2=ferme 3=finale',
  `destinataire` varchar(255) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facture_relance_niveau` (`facture_id`,`niveau`),
  KEY `idx_facture_relances_facture` (`facture_id`),
  CONSTRAINT `fk_facture_relances_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `factures_recurrentes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `description_ligne` varchar(255) NOT NULL,
  `montant_ht` decimal(10,2) NOT NULL,
  `tva_pct` decimal(5,2) NOT NULL DEFAULT 20.00,
  `type_facture` enum('Consommation','Achat','Service') NOT NULL DEFAULT 'Service',
  `ligne_type` enum('N&B','Couleur','Service','Produit') NOT NULL DEFAULT 'Service',
  `frequence` enum('mensuel','trimestriel','annuel') NOT NULL DEFAULT 'mensuel',
  `jour_mois` tinyint unsigned NOT NULL DEFAULT 1,
  `prochaine_echeance` date NOT NULL,
  `derniere_facture_id` int DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fr_echeance_actif` (`prochaine_echeance`,`actif`),
  KEY `idx_fr_client` (`id_client`),
  CONSTRAINT `fk_fr_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_fr_derniere_facture` FOREIGN KEY (`derniere_facture_id`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fr_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `commercial_opportunites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `rule_code` varchar(64) NOT NULL,
  `titre` varchar(255) NOT NULL,
  `detail` text,
  `statut` enum('nouveau','vu','converti','ignore') NOT NULL DEFAULT 'nouveau',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opp_client_rule` (`id_client`,`rule_code`),
  KEY `idx_opp_statut` (`statut`),
  CONSTRAINT `fk_opp_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO `parametres_app` (`cle`, `valeur`) VALUES
  ('product_tier', 'pro'),
  ('module_relances_auto', '1'),
  ('module_factures_recurrentes', '1'),
  ('module_dashboard_business', '1'),
  ('module_opportunites', '1'),
  ('relance_jours_1', '7'),
  ('relance_jours_2', '14'),
  ('relance_jours_3', '30');
