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
