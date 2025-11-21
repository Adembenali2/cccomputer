-- Script d'optimisation : Création d'index pour améliorer les performances
-- À exécuter sur la base de données pour améliorer les temps de réponse

-- Index sur utilisateurs (utilisé fréquemment dans les requêtes)
CREATE INDEX IF NOT EXISTS idx_utilisateurs_emploi_statut ON utilisateurs(Emploi, statut);
CREATE INDEX IF NOT EXISTS idx_utilisateurs_email ON utilisateurs(Email);
CREATE INDEX IF NOT EXISTS idx_utilisateurs_nom_prenom ON utilisateurs(nom, prenom);

-- Index sur clients (recherche et tri fréquents)
CREATE INDEX IF NOT EXISTS idx_clients_raison_sociale ON clients(raison_sociale);
CREATE INDEX IF NOT EXISTS idx_clients_numero_client ON clients(numero_client);
CREATE INDEX IF NOT EXISTS idx_clients_email ON clients(email);

-- Index sur photocopieurs_clients (jointures fréquentes)
CREATE INDEX IF NOT EXISTS idx_photocopieurs_clients_mac ON photocopieurs_clients(mac_norm);
CREATE INDEX IF NOT EXISTS idx_photocopieurs_clients_client ON photocopieurs_clients(id_client);
CREATE INDEX IF NOT EXISTS idx_photocopieurs_clients_client_mac ON photocopieurs_clients(id_client, mac_norm);

-- Index sur compteur_relevee (requêtes avec ROW_NUMBER et tri par timestamp)
CREATE INDEX IF NOT EXISTS idx_compteur_relevee_mac_timestamp ON compteur_relevee(mac_norm, `Timestamp` DESC);
CREATE INDEX IF NOT EXISTS idx_compteur_relevee_timestamp ON compteur_relevee(`Timestamp` DESC);
CREATE INDEX IF NOT EXISTS idx_compteur_relevee_serial ON compteur_relevee(SerialNumber);

-- Index sur livraisons (filtres par statut et client)
CREATE INDEX IF NOT EXISTS idx_livraisons_statut ON livraisons(statut);
CREATE INDEX IF NOT EXISTS idx_livraisons_client ON livraisons(id_client);
CREATE INDEX IF NOT EXISTS idx_livraisons_livreur ON livraisons(id_livreur);
CREATE INDEX IF NOT EXISTS idx_livraisons_date_prevue ON livraisons(date_prevue);
CREATE INDEX IF NOT EXISTS idx_livraisons_reference ON livraisons(reference);

-- Index sur sav (filtres par statut, priorité et client)
CREATE INDEX IF NOT EXISTS idx_sav_statut ON sav(statut);
CREATE INDEX IF NOT EXISTS idx_sav_priorite ON sav(priorite);
CREATE INDEX IF NOT EXISTS idx_sav_client ON sav(id_client);
CREATE INDEX IF NOT EXISTS idx_sav_technicien ON sav(id_technicien);
CREATE INDEX IF NOT EXISTS idx_sav_date_ouverture ON sav(date_ouverture);
CREATE INDEX IF NOT EXISTS idx_sav_reference ON sav(reference);

-- Index sur historique (requêtes de comptage et tri par date)
CREATE INDEX IF NOT EXISTS idx_historique_date_action ON historique(date_action);
CREATE INDEX IF NOT EXISTS idx_historique_user_id ON historique(user_id);
CREATE INDEX IF NOT EXISTS idx_historique_action ON historique(action);

-- Index sur les tables de stock (moves)
CREATE INDEX IF NOT EXISTS idx_paper_moves_paper_id ON paper_moves(paper_id);
CREATE INDEX IF NOT EXISTS idx_toner_moves_toner_id ON toner_moves(toner_id);
CREATE INDEX IF NOT EXISTS idx_lcd_moves_lcd_id ON lcd_moves(lcd_id);
CREATE INDEX IF NOT EXISTS idx_pc_moves_pc_id ON pc_moves(pc_id);

-- Index sur les tables de catalogue (recherche)
CREATE INDEX IF NOT EXISTS idx_paper_catalog_marque_modele ON paper_catalog(marque, modele);
CREATE INDEX IF NOT EXISTS idx_toner_catalog_marque_modele ON toner_catalog(marque, modele);
CREATE INDEX IF NOT EXISTS idx_lcd_catalog_marque_modele ON lcd_catalog(marque, modele);
CREATE INDEX IF NOT EXISTS idx_pc_catalog_marque_modele ON pc_catalog(marque, modele);

-- Note: Ces index peuvent ralentir légèrement les INSERT/UPDATE/DELETE
-- mais améliorent significativement les performances des SELECT
-- À ajuster selon les besoins spécifiques de l'application

