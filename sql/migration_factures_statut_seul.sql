-- ============================================================
-- MIGRATION : Mise à jour statut factures uniquement
-- À utiliser si vous avez DÉJÀ les tables et des données
-- Ajoute : en_attente, en_cours à l'enum statut
-- ============================================================

ALTER TABLE `factures` 
MODIFY COLUMN `statut` enum('brouillon','en_attente','envoyee','en_cours','en_retard','payee','annulee') 
CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente';

UPDATE `factures` SET `statut` = 'en_attente' WHERE `statut` = 'brouillon';
