-- Table des paramètres applicatifs (clé-valeur)
-- Utilisée pour les réglages modifiables depuis l'interface (ex: envoi automatique emails)

CREATE TABLE IF NOT EXISTS `parametres_app` (
  `cle` VARCHAR(80) NOT NULL PRIMARY KEY,
  `valeur` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Valeur par défaut : 0 = désactivé (l'utilisateur veut désactiver pour le moment)
INSERT IGNORE INTO `parametres_app` (`cle`, `valeur`) VALUES ('auto_send_emails', '0');
