DROP TABLE IF EXISTS stock_mouvements;
DROP TABLE IF EXISTS stock;

CREATE TABLE stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(100) NOT NULL UNIQUE,
  designation VARCHAR(255) NOT NULL,
  categorie ENUM('papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre') NOT NULL,
  marque VARCHAR(100) DEFAULT NULL,
  modele_compatible VARCHAR(255) DEFAULT NULL,
  quantite INT NOT NULL DEFAULT 0,
  quantite_min INT NOT NULL DEFAULT 5,
  prix_unitaire_ht DECIMAL(10,2) DEFAULT 0.00,
  unite ENUM('unite','carton','rame') NOT NULL DEFAULT 'unite',
  contenance INT DEFAULT NULL COMMENT 'Ex: 2500 pour carton papier A4',
  numero_serie VARCHAR(100) DEFAULT NULL,
  adresse_mac VARCHAR(17) DEFAULT NULL,
  cpu VARCHAR(100) DEFAULT NULL,
  ram VARCHAR(50) DEFAULT NULL,
  stockage VARCHAR(100) DEFAULT NULL,
  couleur_toner VARCHAR(20) DEFAULT NULL,
  rendement_pages INT DEFAULT NULL,
  taille_ecran VARCHAR(20) DEFAULT NULL,
  resolution VARCHAR(50) DEFAULT NULL,
  grammage VARCHAR(20) DEFAULT NULL,
  etat ENUM('neuf','bon','use','hs') NOT NULL DEFAULT 'neuf',
  emplacement VARCHAR(100) DEFAULT NULL,
  fournisseur VARCHAR(150) DEFAULT NULL,
  date_achat DATE DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_mouvements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_id INT NOT NULL,
  type_mouvement ENUM('entree','sortie','ajustement','livraison') NOT NULL,
  quantite INT NOT NULL,
  quantite_avant INT NOT NULL,
  quantite_apres INT NOT NULL,
  motif VARCHAR(255) DEFAULT NULL,
  reference_doc VARCHAR(100) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stock_id) REFERENCES stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
