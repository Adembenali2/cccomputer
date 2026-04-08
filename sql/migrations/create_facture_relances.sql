CREATE TABLE IF NOT EXISTS facture_relances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_facture INT NOT NULL,
  numero_relance TINYINT NOT NULL DEFAULT 1 COMMENT '1=premiere relance, 2=deuxieme, 3=mise en demeure',
  date_relance DATETIME DEFAULT CURRENT_TIMESTAMP,
  envoye_par INT DEFAULT NULL COMMENT 'NULL=automatique, sinon id utilisateur',
  email_destinataire VARCHAR(255),
  statut ENUM('envoye','echec') DEFAULT 'envoye',
  FOREIGN KEY (id_facture) REFERENCES factures(id) ON DELETE CASCADE,
  INDEX idx_relance_facture (id_facture),
  INDEX idx_relance_date (date_relance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE factures
  ADD COLUMN IF NOT EXISTS nb_relances TINYINT DEFAULT 0 COMMENT 'Nombre de relances envoyees';

ALTER TABLE factures
  ADD COLUMN IF NOT EXISTS date_derniere_relance DATETIME DEFAULT NULL;
