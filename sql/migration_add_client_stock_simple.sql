-- Migration simplifiée : Ajout de la table client_stock et des champs produit dans livraisons
-- Version simple sans procédure stockée (pour MySQL/MariaDB qui ne supportent pas IF NOT EXISTS avec ADD COLUMN)
-- À exécuter manuellement en vérifiant d'abord si les colonnes existent

-- 1. Ajouter les colonnes produit à la table livraisons
-- ATTENTION: Exécutez ces commandes UNE PAR UNE et ignorez l'erreur si la colonne existe déjà

ALTER TABLE `livraisons` 
ADD COLUMN `product_type` enum('papier','toner','lcd','pc','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `commentaire`;

ALTER TABLE `livraisons` 
ADD COLUMN `product_id` int DEFAULT NULL AFTER `product_type`;

ALTER TABLE `livraisons` 
ADD COLUMN `product_qty` int DEFAULT NULL AFTER `product_id`;

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

