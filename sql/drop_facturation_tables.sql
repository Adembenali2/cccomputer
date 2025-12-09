-- SQL script to drop facturation (billing) module tables
-- This script removes the old billing module database tables
-- Execute this script to clean up the database before rebuilding the module

-- Drop tables in reverse order of dependencies (child tables first)
DROP TABLE IF EXISTS `paiements`;
DROP TABLE IF EXISTS `facture_lignes`;
DROP TABLE IF EXISTS `factures`;

-- Note: The tables `compteur_relevee` and `compteur_relevee_ancien` are NOT dropped
-- as they are used by other modules (consumption tracking, etc.)

