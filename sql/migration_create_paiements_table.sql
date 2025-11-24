-- Migration: Création de la table paiements pour l'historique des paiements
-- Date: 2024

CREATE TABLE IF NOT EXISTS `paiements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `type_paiement` enum('especes','cheque','virement') NOT NULL,
  `date_paiement` date NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `justificatif_upload` varchar(500) DEFAULT NULL COMMENT 'Chemin du justificatif uploadé par l''utilisateur',
  `justificatif_pdf` varchar(500) DEFAULT NULL COMMENT 'Chemin du justificatif PDF généré automatiquement',
  `numero_justificatif` varchar(50) DEFAULT NULL COMMENT 'Numéro unique du justificatif',
  `user_id` int(11) DEFAULT NULL COMMENT 'Utilisateur qui a enregistré le paiement',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_date_paiement` (`date_paiement`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_paiements_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_paiements_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

