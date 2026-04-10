CREATE TABLE IF NOT EXISTS historique (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'client',
    'facture',
    'paiement',
    'sav',
    'livraison',
    'stock',
    'photocopieur',
    'utilisateur',
    'connexion'
  ) NOT NULL,
  action VARCHAR(100) NOT NULL COMMENT 'Ex: creation, modification, suppression, envoi, paiement_recu',
  label VARCHAR(255) NOT NULL COMMENT 'Texte affiche dans la timeline',
  detail TEXT DEFAULT NULL COMMENT 'Details supplementaires',
  ref_id INT DEFAULT NULL COMMENT 'ID de l entite concernee',
  ref_url VARCHAR(255) DEFAULT NULL COMMENT 'URL vers la page concernee',
  user_id INT DEFAULT NULL COMMENT 'Utilisateur qui a fait l action',
  user_nom VARCHAR(150) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_created (created_at),
  INDEX idx_user (user_id),
  UNIQUE KEY uq_historique_retro (type, action, ref_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
