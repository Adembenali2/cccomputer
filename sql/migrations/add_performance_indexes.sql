-- Index pour les jointures fréquentes
CREATE INDEX IF NOT EXISTS idx_factures_id_client ON factures(id_client);
CREATE INDEX IF NOT EXISTS idx_factures_statut ON factures(statut);
CREATE INDEX IF NOT EXISTS idx_factures_date ON factures(date_facture);
CREATE INDEX IF NOT EXISTS idx_paiements_id_client ON paiements(id_client);
CREATE INDEX IF NOT EXISTS idx_paiements_id_facture ON paiements(id_facture);
CREATE INDEX IF NOT EXISTS idx_paiements_date ON paiements(date_paiement);
CREATE INDEX IF NOT EXISTS idx_sav_id_client ON sav(id_client);
CREATE INDEX IF NOT EXISTS idx_sav_statut ON sav(statut);
CREATE INDEX IF NOT EXISTS idx_sav_id_technicien ON sav(id_technicien);
CREATE INDEX IF NOT EXISTS idx_livraisons_id_client ON livraisons(id_client);
CREATE INDEX IF NOT EXISTS idx_livraisons_statut ON livraisons(statut);
CREATE INDEX IF NOT EXISTS idx_livraisons_id_livreur ON livraisons(id_livreur);
CREATE INDEX IF NOT EXISTS idx_historique_user_id ON historique(user_id);
CREATE INDEX IF NOT EXISTS idx_historique_date ON historique(date_action);
CREATE INDEX IF NOT EXISTS idx_compteur_relevee_mac ON compteur_relevee(MacAddress);
CREATE INDEX IF NOT EXISTS idx_compteur_relevee_ts ON compteur_relevee(Timestamp);
CREATE INDEX IF NOT EXISTS idx_messagerie_destinataire ON messagerie(id_destinataire);
CREATE INDEX IF NOT EXISTS idx_messagerie_lu ON messagerie(id_destinataire, lu);
CREATE INDEX IF NOT EXISTS idx_email_logs_facture ON email_logs(facture_id);
CREATE INDEX IF NOT EXISTS idx_email_logs_statut ON email_logs(statut);

-- Index composé pour les requêtes dashboard fréquentes
CREATE INDEX IF NOT EXISTS idx_factures_client_statut ON factures(id_client, statut);
CREATE INDEX IF NOT EXISTS idx_sav_client_statut ON sav(id_client, statut);
