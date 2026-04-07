CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  type ENUM('sav_assigne','livraison_planifiee','facture_impayee','paiement_recu','sav_urgent') NOT NULL,
  titre VARCHAR(150) NOT NULL,
  message TEXT,
  id_lien INT NULL COMMENT 'ID de la ressource liée (sav, livraison, etc.)',
  type_lien ENUM('sav','livraison','facture','paiement') NULL,
  lu TINYINT(1) DEFAULT 0,
  date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES utilisateurs(id) ON DELETE CASCADE,
  INDEX idx_notif_user_lu (id_user, lu),
  INDEX idx_notif_date (date_creation)
) ENGINE=InnoDB;
