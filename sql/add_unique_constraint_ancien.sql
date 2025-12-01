-- ============================================================================
-- Script SQL : Ajout de la contrainte UNIQUE sur (mac_norm, Timestamp)
-- pour la table compteur_relevee_ancien
-- ============================================================================
-- 
-- Ce script ajoute une contrainte UNIQUE sur le couple (mac_norm, Timestamp)
-- pour garantir qu'un même relevé (même MAC + même timestamp) ne peut être
-- présent qu'une seule fois dans la table.
--
-- IMPORTANT : 
-- - Si la contrainte existe déjà, le script échouera avec une erreur
--   (c'est normal, cela signifie que la contrainte est déjà en place)
-- - Si la table contient déjà des doublons, il faudra les nettoyer avant
--   d'exécuter ce script
--
-- UTILISATION :
--   mysql -u user -p database_name < sql/add_unique_constraint_ancien.sql
--   ou exécuter directement dans votre client MySQL
--
-- ============================================================================

-- Vérifier et supprimer les doublons éventuels avant d'ajouter la contrainte
-- (Conserve uniquement le premier enregistrement de chaque couple MAC+Timestamp)
-- 
-- ATTENTION : Décommentez cette section si vous avez des doublons à nettoyer
-- 
-- DELETE t1 FROM compteur_relevee_ancien t1
-- INNER JOIN compteur_relevee_ancien t2 
-- WHERE t1.id > t2.id 
--   AND t1.mac_norm = t2.mac_norm 
--   AND t1.Timestamp = t2.Timestamp;

-- Ajouter la contrainte UNIQUE
-- Si la contrainte existe déjà, cette commande échouera (c'est normal)
ALTER TABLE `compteur_relevee_ancien` 
ADD UNIQUE KEY `uniq_mac_ts_ancien` (`mac_norm`,`Timestamp`);

-- Vérification : Afficher les contraintes de la table
-- (pour confirmer que la contrainte a été ajoutée)
SHOW CREATE TABLE `compteur_relevee_ancien`;

