-- Migration: Ajouter en_attente et en_cours au statut des factures
-- Logique: en_attente (générée), envoyee (envoyée), en_cours (le 25 du mois), en_retard (après le 25), payee (payée)

ALTER TABLE `factures` 
MODIFY COLUMN `statut` enum('brouillon','en_attente','envoyee','en_cours','en_retard','payee','annulee') 
COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente';
