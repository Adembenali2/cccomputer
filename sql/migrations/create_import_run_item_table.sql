-- Migration: Création de la table import_run_item pour logs détaillés par fichier
-- Table optionnelle mais recommandée pour un meilleur suivi des imports SFTP

CREATE TABLE IF NOT EXISTS `import_run_item` (
    `id` int NOT NULL AUTO_INCREMENT,
    `run_id` int NOT NULL COMMENT 'Référence vers import_run.id',
    `filename` varchar(255) NOT NULL COMMENT 'Nom du fichier traité',
    `status` enum('success','error','skipped') NOT NULL DEFAULT 'error' COMMENT 'Statut du traitement',
    `inserted_rows` int NOT NULL DEFAULT 0 COMMENT 'Nombre de lignes insérées (0 ou 1)',
    `error` text COMMENT 'Message d''erreur si status=error',
    `duration_ms` decimal(10,2) DEFAULT NULL COMMENT 'Durée du traitement en millisecondes',
    `processed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date/heure de traitement',
    PRIMARY KEY (`id`),
    KEY `idx_run_id` (`run_id`),
    KEY `idx_status` (`status`),
    KEY `idx_processed_at` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Logs détaillés par fichier pour les imports SFTP';

