-- Migration SQL : Ajout d'index pour optimiser les performances
-- À exécuter manuellement ou via un script de migration
-- Ces index améliorent les performances sans modifier le schéma ni les données

-- Index sur colonnes de recherche fréquentes (clients)
ALTER TABLE `clients` 
ADD INDEX `idx_clients_email` (`email`),
ADD INDEX `idx_clients_raison_sociale` (`raison_sociale`(100)),
ADD INDEX `idx_clients_ville` (`ville`),
ADD INDEX `idx_clients_code_postal` (`code_postal`);

-- Index composite pour recherches combinées (clients)
ALTER TABLE `clients`
ADD INDEX `idx_clients_raison_ville` (`raison_sociale`(50), `ville`);

-- Index sur colonnes de recherche (utilisateurs)
ALTER TABLE `utilisateurs`
ADD INDEX `idx_utilisateurs_email` (`Email`),
ADD INDEX `idx_utilisateurs_nom_prenom` (`nom`, `prenom`);

-- Index composites pour requêtes fréquentes (sav)
ALTER TABLE `sav`
ADD INDEX `idx_sav_date_statut` (`date_ouverture`, `statut`),
ADD INDEX `idx_sav_technicien_statut` (`id_technicien`, `statut`);

-- Index composites pour requêtes fréquentes (livraisons)
ALTER TABLE `livraisons`
ADD INDEX `idx_livraisons_date_statut` (`date_prevue`, `statut`),
ADD INDEX `idx_livraisons_livreur_statut` (`id_livreur`, `statut`);

-- Index composites pour requêtes fréquentes (historique)
ALTER TABLE `historique`
ADD INDEX `idx_historique_user_date` (`user_id`, `date_action` DESC),
ADD INDEX `idx_historique_date_action` (`date_action` DESC);

-- Index sur colonnes de recherche (sav, livraisons)
ALTER TABLE `sav`
ADD INDEX `idx_sav_reference` (`reference`);

ALTER TABLE `livraisons`
ADD INDEX `idx_livraisons_reference` (`reference`);

-- Index pour optimiser les recherches de compteurs
ALTER TABLE `compteur_relevee`
ADD INDEX `idx_compteur_mac_ts_desc` (`mac_norm`, `Timestamp` DESC);

ALTER TABLE `compteur_relevee_ancien`
ADD INDEX `idx_compteur_ancien_mac_ts_desc` (`mac_norm`, `Timestamp` DESC);

-- Note: Les index FULLTEXT nécessitent MySQL 5.6+ / MariaDB 10.0+
-- Décommenter si la version est compatible :
-- ALTER TABLE `clients` ADD FULLTEXT INDEX `ft_clients_search` (`raison_sociale`, `nom_dirigeant`, `prenom_dirigeant`, `adresse`, `ville`);

