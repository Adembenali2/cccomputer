CREATE TABLE IF NOT EXISTS client_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_client INT NOT NULL,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  poste VARCHAR(100) DEFAULT NULL COMMENT 'Fonction/poste dans la société',
  telephone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  est_principal TINYINT(1) DEFAULT 0,
  date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_client) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_contacts_client (id_client)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
