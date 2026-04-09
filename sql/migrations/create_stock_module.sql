-- [Fonctionnalité Stock] Tables principales du module stock
CREATE TABLE IF NOT EXISTS stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(100) NOT NULL UNIQUE,
  designation VARCHAR(255) NOT NULL,
  categorie ENUM('toner_noir','toner_cyan','toner_magenta','toner_jaune','papier','piece_detachee','consommable','autre') NOT NULL,
  marque VARCHAR(100),
  modele_compatible VARCHAR(255),
  quantite INT NOT NULL DEFAULT 0,
  quantite_min INT NOT NULL DEFAULT 5,
  prix_unitaire_ht DECIMAL(10,2) DEFAULT 0,
  emplacement VARCHAR(100),
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_mouvements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_id INT NOT NULL,
  type_mouvement ENUM('entree','sortie','ajustement','livraison') NOT NULL,
  quantite INT NOT NULL,
  quantite_avant INT NOT NULL,
  quantite_apres INT NOT NULL,
  motif VARCHAR(255),
  reference_doc VARCHAR(100),
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stock_id) REFERENCES stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- [Fonctionnalité Stock] Évolution schéma catégories + unité/contenance (idempotent)
SET @sql := "ALTER TABLE stock MODIFY COLUMN categorie ENUM('papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre') NOT NULL";
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock'
    AND COLUMN_NAME = 'unite'
);
SET @sql := IF(
  @col_exists = 0,
  "ALTER TABLE stock ADD COLUMN unite ENUM('unite','carton','rame') NOT NULL DEFAULT 'unite'",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock'
    AND COLUMN_NAME = 'contenance'
);
SET @sql := IF(
  @col_exists = 0,
  "ALTER TABLE stock ADD COLUMN contenance INT DEFAULT NULL COMMENT 'Ex: 2500 pour un carton de papier A4'",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

