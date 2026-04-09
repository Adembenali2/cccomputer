-- ============================================================
-- MODULE STOCK v2 — CCComputer
-- ============================================================

DROP TABLE IF EXISTS stock_mouvements;
DROP TABLE IF EXISTS stock;

-- ------------------------------------------------------------
-- Table principale : un article = une ligne
-- ------------------------------------------------------------
CREATE TABLE stock (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  reference       VARCHAR(100)  NOT NULL UNIQUE      COMMENT 'Ex: PAP-20260409-0001',
  designation     VARCHAR(255)  NOT NULL,
  categorie       ENUM(
                    'papier',
                    'toner_noir',
                    'toner_cyan',
                    'toner_magenta',
                    'toner_jaune',
                    'pc',
                    'ecran_lcd',
                    'imprimante'
                  ) NOT NULL,

  -- Stock
  quantite        INT           NOT NULL DEFAULT 0,
  quantite_min    INT           NOT NULL DEFAULT 5    COMMENT 'Seuil alerte',
  unite           ENUM('unite','carton') NOT NULL DEFAULT 'unite',
  contenance      INT           DEFAULT NULL           COMMENT '2500 pour carton papier',

  -- Infos communes
  marque          VARCHAR(100)  DEFAULT NULL,
  modele          VARCHAR(150)  DEFAULT NULL,
  fournisseur     VARCHAR(150)  DEFAULT NULL,
  prix_unitaire_ht DECIMAL(10,2) DEFAULT 0.00,
  etat            ENUM('neuf','bon','use','hs') NOT NULL DEFAULT 'neuf',
  emplacement     VARCHAR(100)  DEFAULT NULL,
  date_achat      DATE          DEFAULT NULL,
  notes           TEXT          DEFAULT NULL,

  -- Champs PC
  numero_serie    VARCHAR(100)  DEFAULT NULL,
  adresse_mac     VARCHAR(17)   DEFAULT NULL,
  cpu             VARCHAR(100)  DEFAULT NULL,
  ram             VARCHAR(50)   DEFAULT NULL,
  stockage        VARCHAR(100)  DEFAULT NULL,

  -- Champs Toner
  couleur_toner   VARCHAR(20)   DEFAULT NULL,
  rendement_pages INT           DEFAULT NULL           COMMENT 'Nb pages estimé',

  -- Champs LCD
  taille_ecran    VARCHAR(20)   DEFAULT NULL           COMMENT 'Ex: 24 pouces',
  resolution      VARCHAR(50)   DEFAULT NULL           COMMENT 'Ex: 1920x1080',

  -- Champs Papier
  grammage        VARCHAR(20)   DEFAULT NULL           COMMENT 'Ex: 80g/m²',
  format_papier   VARCHAR(10)   DEFAULT NULL           COMMENT 'Ex: A4',

  -- QR Code
  qr_code         VARCHAR(255)  DEFAULT NULL           COMMENT 'Référence utilisée pour QR',

  -- Méta
  actif           TINYINT(1)    NOT NULL DEFAULT 1,
  created_by      INT           DEFAULT NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table mouvements : traçabilité entrées/sorties
-- ------------------------------------------------------------
CREATE TABLE stock_mouvements (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  stock_id        INT           NOT NULL,
  type_mouvement  ENUM('entree','sortie','ajustement','livraison') NOT NULL,
  quantite        INT           NOT NULL,
  quantite_avant  INT           NOT NULL,
  quantite_apres  INT           NOT NULL,
  motif           VARCHAR(255)  DEFAULT NULL,
  reference_doc   VARCHAR(100)  DEFAULT NULL           COMMENT 'N° livraison ou facture',
  created_by      INT           DEFAULT NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stock_id) REFERENCES stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
