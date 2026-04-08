-- Table pour stocker les coordonnées géocodées des clients
-- Cela permet d'éviter de géocoder à chaque chargement de la page

CREATE TABLE IF NOT EXISTS `client_geocode` (
  `id_client` int NOT NULL,
  `address_hash` varchar(64) NOT NULL COMMENT 'Hash MD5 de l''adresse géocodée pour détecter les changements',
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `display_name` varchar(500) DEFAULT NULL COMMENT 'Nom d''affichage retourné par le géocodeur',
  `geocoded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_client`),
  KEY `idx_address_hash` (`address_hash`),
  CONSTRAINT `fk_client_geocode_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

