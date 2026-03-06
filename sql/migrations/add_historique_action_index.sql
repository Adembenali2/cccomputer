-- Migration: Index sur action pour optimiser les filtres par catégorie
-- À exécuter si l'index n'existe pas déjà

-- Vérifier d'abord si l'index existe (MySQL 8+)
-- ALTER TABLE historique ADD INDEX idx_historique_action (action);

-- Pour MySQL 5.7 / MariaDB, exécuter directement (ignorer si l'index existe déjà)
ALTER TABLE `historique` ADD INDEX `idx_historique_action` (`action`);
