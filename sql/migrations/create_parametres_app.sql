-- Table des paramètres applicatifs (clé-valeur)
-- Utilisée pour les réglages modifiables depuis l'interface (Profil > Paramètres)

CREATE TABLE IF NOT EXISTS `parametres_app` (
  `cle` VARCHAR(80) NOT NULL PRIMARY KEY,
  `valeur` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Valeurs par défaut (1=activé, 0=désactivé)
INSERT IGNORE INTO `parametres_app` (`cle`, `valeur`) VALUES
  ('auto_send_emails', '0'),
  ('module_dashboard', '1'), ('module_agenda', '1'), ('module_historique', '1'),
  ('module_clients', '1'), ('module_paiements', '1'), ('module_messagerie', '1'),
  ('module_sav', '1'), ('module_livraison', '1'), ('module_stock', '1'),
  ('module_photocopieurs', '1'), ('module_maps', '1'), ('module_profil', '1'),
  ('module_commercial', '1'), ('module_import_sftp', '1'), ('module_import_ionos', '1');
