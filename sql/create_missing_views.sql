-- Vues manquantes pour le système de stock
-- À exécuter dans la base de données pour compléter le schéma

-- Vue pour les toners en stock (utilise la table toner_moves qui existe)
DROP VIEW IF EXISTS `v_toner_stock`;
CREATE VIEW `v_toner_stock` AS
SELECT 
    t.id AS toner_id,
    t.marque,
    t.modele,
    t.couleur,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM toner_catalog t
LEFT JOIN toner_moves m ON m.toner_id = t.id
GROUP BY t.id, t.marque, t.modele, t.couleur;

-- Table pc_moves manquante - à créer avant la vue v_pc_stock
-- Cette table est nécessaire pour suivre les mouvements de stock des PC
DROP TABLE IF EXISTS `pc_moves`;
CREATE TABLE `pc_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pc_id` int NOT NULL,
  `qty_delta` int NOT NULL,
  `reason` enum('ajustement','achat','retour','correction') NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pc_time` (`pc_id`,`created_at`),
  CONSTRAINT `fk_pc_moves_catalog` FOREIGN KEY (`pc_id`) REFERENCES `pc_catalog` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Vue pour les PC en stock (après création de pc_moves)
DROP VIEW IF EXISTS `v_pc_stock`;
CREATE VIEW `v_pc_stock` AS
SELECT 
    p.id AS pc_id,
    p.etat,
    p.reference,
    p.marque,
    p.modele,
    p.cpu,
    p.ram,
    p.stockage,
    p.os,
    p.gpu,
    p.reseau,
    p.ports,
    p.prix,
    COALESCE(SUM(m.qty_delta), 0) AS qty_stock
FROM pc_catalog p
LEFT JOIN pc_moves m ON m.pc_id = p.id
GROUP BY p.id, p.etat, p.reference, p.marque, p.modele, p.cpu, p.ram, 
         p.stockage, p.os, p.gpu, p.reseau, p.ports, p.prix;

-- Note: Les vues existantes (v_compteur_last, v_lcd_stock, v_paper_stock) 
-- dans railway_cccomputer_base_des_données.sql référencent 'railway.compteur_relevee'
-- Si votre base de données n'est pas nommée 'railway', il faudra corriger ces vues

