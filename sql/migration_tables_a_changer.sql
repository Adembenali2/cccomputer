-- ============================================================
-- Tables à modifier / créer (factures + facture_lignes)
-- Copier-coller dans votre base de données
-- ============================================================

-- 1. Factures : ajouter en_attente et en_cours au statut
ALTER TABLE `factures` 
MODIFY COLUMN `statut` enum('brouillon','en_attente','envoyee','en_cours','en_retard','payee','annulee') 
CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente';

UPDATE `factures` SET `statut` = 'en_attente' WHERE `statut` = 'brouillon';

-- 2. Facture_lignes : créer si manquante
CREATE TABLE IF NOT EXISTS `facture_lignes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_facture` int NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('N&B','Couleur','Service','Produit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` decimal(10,2) NOT NULL DEFAULT '1.00',
  `prix_unitaire_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ordre` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_facture_lignes_facture` (`id_facture`),
  CONSTRAINT `fk_facture_lignes_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
