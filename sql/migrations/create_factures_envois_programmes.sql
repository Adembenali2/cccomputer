-- Table des envois de factures programmés
-- UTF-8 sans BOM

CREATE TABLE IF NOT EXISTS `factures_envois_programmes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_envoi` enum('une_facture','plusieurs_factures','toutes_selectionnees','tous_clients') NOT NULL DEFAULT 'une_facture' COMMENT 'Type de programmation',
  `facture_id` int DEFAULT NULL COMMENT 'ID facture unique (si type une_facture)',
  `factures_json` text DEFAULT NULL COMMENT 'JSON array des IDs factures (si plusieurs)',
  `client_id` int DEFAULT NULL COMMENT 'ID client (optionnel)',
  `email_destination` varchar(255) DEFAULT NULL COMMENT 'Email manuel si saisi',
  `use_client_email` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=utiliser email client, 0=email manuel',
  `all_clients` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=envoyer à tous les clients concernés',
  `all_selected_factures` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=toutes les factures sélectionnées',
  `sujet` varchar(255) DEFAULT NULL COMMENT 'Objet email personnalisé',
  `message` text DEFAULT NULL COMMENT 'Message email personnalisé',
  `date_envoi_programmee` datetime NOT NULL COMMENT 'Date/heure prévue d''envoi',
  `statut` enum('en_attente','envoye','annule','echoue') NOT NULL DEFAULT 'en_attente',
  `erreur_message` text DEFAULT NULL COMMENT 'Message d''erreur si échec',
  `created_by` int DEFAULT NULL COMMENT 'ID utilisateur créateur',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL COMMENT 'Date/heure envoi effectif',
  PRIMARY KEY (`id`),
  KEY `idx_fep_statut` (`statut`),
  KEY `idx_fep_date_envoi` (`date_envoi_programmee`),
  KEY `idx_fep_created_by` (`created_by`),
  KEY `idx_fep_facture` (`facture_id`),
  CONSTRAINT `fk_fep_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fep_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fep_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Envois de factures programmés';
