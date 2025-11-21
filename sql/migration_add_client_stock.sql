-- Migration : Ajout de la table client_stock et des champs produit dans livraisons
-- À exécuter sur les bases de données existantes

-- 1. Ajouter les colonnes produit à la table livraisons (si elles n'existent pas)
-- Note: MySQL ne supporte pas IF NOT EXISTS avec ADD COLUMN, donc on utilise une procédure

DELIMITER $$

DROP PROCEDURE IF EXISTS add_livraisons_product_columns$$

CREATE PROCEDURE add_livraisons_product_columns()
BEGIN
    DECLARE column_exists INT DEFAULT 0;
    
    -- Vérifier et ajouter product_type
    SELECT COUNT(*) INTO column_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'livraisons'
      AND COLUMN_NAME = 'product_type';
    
    IF column_exists = 0 THEN
        ALTER TABLE `livraisons` 
        ADD COLUMN `product_type` enum('papier','toner','lcd','pc','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `commentaire`;
    END IF;
    
    -- Vérifier et ajouter product_id
    SELECT COUNT(*) INTO column_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'livraisons'
      AND COLUMN_NAME = 'product_id';
    
    IF column_exists = 0 THEN
        ALTER TABLE `livraisons` 
        ADD COLUMN `product_id` int DEFAULT NULL AFTER `product_type`;
    END IF;
    
    -- Vérifier et ajouter product_qty
    SELECT COUNT(*) INTO column_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'livraisons'
      AND COLUMN_NAME = 'product_qty';
    
    IF column_exists = 0 THEN
        ALTER TABLE `livraisons` 
        ADD COLUMN `product_qty` int DEFAULT NULL AFTER `product_id`;
    END IF;
END$$

DELIMITER ;

-- Exécuter la procédure
CALL add_livraisons_product_columns();

-- Supprimer la procédure après utilisation
DROP PROCEDURE IF EXISTS add_livraisons_product_columns;

-- 2. Créer la table client_stock
DROP TABLE IF EXISTS `client_stock`;
CREATE TABLE `client_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int NOT NULL,
  `product_type` enum('papier','toner','lcd','pc') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `product_id` int NOT NULL,
  `qty_stock` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_stock` (`id_client`,`product_type`,`product_id`),
  KEY `idx_client_stock_client` (`id_client`),
  KEY `idx_client_stock_product` (`product_type`,`product_id`),
  CONSTRAINT `fk_client_stock_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

