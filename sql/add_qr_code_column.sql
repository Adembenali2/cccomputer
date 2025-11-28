-- ============================================================
-- Ajout de la colonne qr_code_path aux tables de catalogues
-- ============================================================
-- Cette colonne stockera le chemin vers l'image du QR Code généré

-- Table paper_catalog
ALTER TABLE `paper_catalog` 
ADD COLUMN `qr_code_path` VARCHAR(255) NULL AFTER `barcode`;

-- Table toner_catalog
ALTER TABLE `toner_catalog` 
ADD COLUMN `qr_code_path` VARCHAR(255) NULL AFTER `barcode`;

-- Table lcd_catalog
ALTER TABLE `lcd_catalog` 
ADD COLUMN `qr_code_path` VARCHAR(255) NULL AFTER `barcode`;

-- Table pc_catalog
ALTER TABLE `pc_catalog` 
ADD COLUMN `qr_code_path` VARCHAR(255) NULL AFTER `barcode`;

-- Note: Les colonnes sont NULL pour permettre la migration progressive
-- Les nouveaux produits auront automatiquement un QR Code généré



