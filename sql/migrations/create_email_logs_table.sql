-- Migration : Table email_logs pour traçabilité des envois d'emails
-- Date : 2025-01-XX
-- Auteur : Auto (Cursor AI)

DROP TABLE IF EXISTS `email_logs`;

CREATE TABLE `email_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `facture_id` INT NULL COMMENT 'ID de la facture liée (peut être NULL pour autres types)',
    `type_email` ENUM('facture', 'paiement', 'autre') NOT NULL DEFAULT 'facture' COMMENT 'Type d\'email envoyé',
    `destinataire` VARCHAR(255) NOT NULL COMMENT 'Adresse email du destinataire',
    `sujet` VARCHAR(255) NOT NULL COMMENT 'Sujet de l\'email',
    `statut` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' COMMENT 'Statut de l\'envoi',
    `message_id` VARCHAR(255) NULL COMMENT 'Message ID retourné par le serveur SMTP (pour traçabilité)',
    `error_message` TEXT NULL COMMENT 'Message d\'erreur en cas d\'échec',
    `sent_at` DATETIME NULL COMMENT 'Date et heure d\'envoi effectif',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de création de l\'entrée',
    PRIMARY KEY (`id`),
    KEY `idx_email_logs_facture` (`facture_id`),
    KEY `idx_email_logs_statut` (`statut`),
    KEY `idx_email_logs_created_at` (`created_at`),
    KEY `idx_email_logs_destinataire` (`destinataire`),
    CONSTRAINT `fk_email_logs_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Journal des envois d\'emails (factures, paiements, etc.)';

