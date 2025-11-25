-- ============================================================
-- Ajout de la colonne barcode aux tables de catalogues
-- ============================================================
-- Ce script ajoute une colonne barcode unique à chaque table
-- de catalogue pour permettre le scanning de codes-barres

-- Table paper_catalog
ALTER TABLE `paper_catalog` 
ADD COLUMN `barcode` VARCHAR(50) NULL AFTER `poids`,
ADD UNIQUE KEY `uq_paper_barcode` (`barcode`);

-- Table toner_catalog
ALTER TABLE `toner_catalog` 
ADD COLUMN `barcode` VARCHAR(50) NULL AFTER `couleur`,
ADD UNIQUE KEY `uq_toner_barcode` (`barcode`);

-- Table lcd_catalog
ALTER TABLE `lcd_catalog` 
ADD COLUMN `barcode` VARCHAR(50) NULL AFTER `prix`,
ADD UNIQUE KEY `uq_lcd_barcode` (`barcode`);

-- Table pc_catalog
ALTER TABLE `pc_catalog` 
ADD COLUMN `barcode` VARCHAR(50) NULL AFTER `prix`,
ADD UNIQUE KEY `uq_pc_barcode` (`barcode`);

-- Note: Les colonnes sont NULL pour permettre la migration progressive
-- Les nouveaux produits auront automatiquement un barcode généré

