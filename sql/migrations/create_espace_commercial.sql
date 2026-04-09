-- Role commercial + tables espace commercial

ALTER TABLE utilisateurs
MODIFY COLUMN role ENUM(
  'admin','dirigeant','secretaire',
  'commercial',
  'technicien','livreur','charge_relation_clients'
) NOT NULL DEFAULT 'commercial';

CREATE TABLE IF NOT EXISTS commercial_activites (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  client_id     INT NOT NULL,
  type          ENUM('appel','email','rdv','relance','devis','autre') NOT NULL,
  titre         VARCHAR(255) NOT NULL,
  description   TEXT,
  statut        ENUM('planifie','fait','annule') NOT NULL DEFAULT 'planifie',
  date_activite DATETIME NOT NULL,
  created_by    INT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commercial_objectifs (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  mois               VARCHAR(7) NOT NULL COMMENT 'Format YYYY-MM',
  objectif_ca        DECIMAL(12,2) NOT NULL DEFAULT 0,
  objectif_clients   INT NOT NULL DEFAULT 0,
  objectif_factures  INT NOT NULL DEFAULT 0,
  created_by         INT,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_mois (mois)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
