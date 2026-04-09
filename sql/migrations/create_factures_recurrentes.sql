CREATE TABLE IF NOT EXISTS factures_recurrentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_client INT NOT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  type ENUM('Consommation','Achat','Service') NOT NULL DEFAULT 'Consommation',
  jour_generation TINYINT NOT NULL DEFAULT 1 COMMENT 'Jour du mois de generation (1-28)',
  mois_dernier_envoi VARCHAR(7) DEFAULT NULL COMMENT 'Format YYYY-MM',
  description_manuelle TEXT DEFAULT NULL COMMENT 'Pour type Achat/Service',
  montant_fixe DECIMAL(10,2) DEFAULT NULL COMMENT 'Montant fixe HT pour Achat/Service',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_recurrent_client (id_client),
  FOREIGN KEY (id_client) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compatibilité schémas anciens: ajoute les colonnes manquantes
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'actif'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'type'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN type ENUM('Consommation','Achat','Service') NOT NULL DEFAULT 'Consommation'", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'jour_generation'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN jour_generation TINYINT NOT NULL DEFAULT 1 COMMENT 'Jour du mois de generation (1-28)'", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'mois_dernier_envoi'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN mois_dernier_envoi VARCHAR(7) DEFAULT NULL COMMENT 'Format YYYY-MM'", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'description_manuelle'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN description_manuelle TEXT DEFAULT NULL COMMENT 'Pour type Achat/Service'", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'montant_fixe'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN montant_fixe DECIMAL(10,2) DEFAULT NULL COMMENT 'Montant fixe HT pour Achat/Service'", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'created_at'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'updated_at'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures_recurrentes ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Si ancien champ type_facture existe, on rapatrie vers type quand possible
SET @has_type_facture := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'type_facture'
);
SET @has_type := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures_recurrentes'
    AND COLUMN_NAME = 'type'
);
SET @sql := IF(
  @has_type_facture = 1 AND @has_type = 1,
  "UPDATE factures_recurrentes SET type = CASE WHEN type_facture IN ('Consommation','Achat','Service') THEN type_facture ELSE type END WHERE (type IS NULL OR type = '')",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
