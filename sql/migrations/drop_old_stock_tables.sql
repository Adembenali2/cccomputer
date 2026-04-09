-- Suppression des anciennes tables stock (avant refonte v2)
-- Vérifie d'abord quelles tables existent avec :
-- SHOW TABLES LIKE '%stock%';
-- Puis supprime celles qui ne sont PAS : stock, stock_mouvements

-- Tables potentiellement à supprimer selon l'ancien schéma :
DROP TABLE IF EXISTS toner_stock;
DROP TABLE IF EXISTS paper_stock;
DROP TABLE IF EXISTS pc_stock;
DROP TABLE IF EXISTS lcd_stock;
DROP TABLE IF EXISTS toner_catalog;
DROP TABLE IF EXISTS paper_catalog;
DROP TABLE IF EXISTS pc_catalog;
DROP TABLE IF EXISTS lcd_catalog;
DROP TABLE IF EXISTS v_toner_stock;
DROP TABLE IF EXISTS v_paper_stock;
DROP TABLE IF EXISTS v_pc_stock;
DROP TABLE IF EXISTS v_lcd_stock;
