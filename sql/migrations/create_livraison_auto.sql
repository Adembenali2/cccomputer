CREATE TABLE IF NOT EXISTS livraison_auto_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_client INT NOT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 0,
  papier_actif TINYINT(1) NOT NULL DEFAULT 0,
  papier_product_id INT DEFAULT NULL COMMENT 'ID dans paper_catalog',
  papier_seuil INT NOT NULL DEFAULT 5 COMMENT 'Seuil en ramettes',
  papier_qte_livraison INT NOT NULL DEFAULT 10 COMMENT 'Quantite a livrer en ramettes',
  papier_qte_auto TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=calcule auto selon conso, 0=manuel',
  toner_actif TINYINT(1) NOT NULL DEFAULT 0,
  toner_seuil_pct INT NOT NULL DEFAULT 10 COMMENT 'Seuil en pourcentage (ex: 10 pour 10%)',
  derniere_verification DATETIME DEFAULT NULL,
  derniere_livraison_creee DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_config_client (id_client),
  FOREIGN KEY (id_client) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS livraison_auto_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_client INT NOT NULL,
  type ENUM('papier','toner_black','toner_cyan','toner_magenta','toner_yellow') NOT NULL,
  declencheur VARCHAR(255) COMMENT 'Ex: stock=3 ramettes < seuil=5',
  id_livraison_creee INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_client (id_client),
  INDEX idx_log_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
