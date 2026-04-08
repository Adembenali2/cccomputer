-- Migration : Ajout des colonnes lu, delivered_at, read_at à private_messages
-- Pour les notifications et statuts reçu/lu des messages privés
-- Exécuter : mysql -u user -p database < migration_private_messages_read_status.sql

-- Colonne lu (non lu par défaut pour les anciens messages)
ALTER TABLE `private_messages` ADD COLUMN `lu` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = non lu, 1 = lu (par le destinataire)';

-- Colonne delivered_at
ALTER TABLE `private_messages` ADD COLUMN `delivered_at` DATETIME NULL DEFAULT NULL COMMENT 'Quand le destinataire a récupéré le message';

-- Colonne read_at
ALTER TABLE `private_messages` ADD COLUMN `read_at` DATETIME NULL DEFAULT NULL COMMENT 'Quand le destinataire a ouvert la conversation';

-- Index pour le comptage des non lus (ignorer si existe)
-- CREATE INDEX idx_private_messages_receiver_lu ON private_messages (id_receiver, lu);
