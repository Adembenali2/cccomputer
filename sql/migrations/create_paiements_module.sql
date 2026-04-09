-- [Fonctionnalité Paiements]
CREATE TABLE IF NOT EXISTS paiements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  facture_id INT NULL,
  montant DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','prelevement','carte') NOT NULL,
  date_paiement DATE NOT NULL,
  reference VARCHAR(255),
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compatibilité avec les schémas existants
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'paiements'
    AND COLUMN_NAME = 'facture_id'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE paiements ADD COLUMN facture_id INT NULL", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures'
    AND COLUMN_NAME = 'montant_paye'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE factures ADD COLUMN montant_paye DECIMAL(10,2) NOT NULL DEFAULT 0", "SELECT 1");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
