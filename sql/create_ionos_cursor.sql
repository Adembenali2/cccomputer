-- Table pour stocker le curseur de l'import IONOS
-- Permet de reprendre l'import là où il s'est arrêté et d'éviter les doublons

CREATE TABLE IF NOT EXISTS `ionos_cursor` (
  `id` tinyint NOT NULL DEFAULT 1,
  `last_ts` datetime DEFAULT NULL COMMENT 'Dernier Timestamp importé',
  `last_mac` char(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Dernier mac_norm importé',
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_single_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Initialiser avec une ligne si absente
INSERT IGNORE INTO `ionos_cursor` (`id`, `last_ts`, `last_mac`) VALUES (1, NULL, NULL);

