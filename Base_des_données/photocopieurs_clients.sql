CREATE TABLE `photocopieurs_clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_client` int DEFAULT NULL,
  `SerialNumber` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `MacAddress` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `mac_norm` char(12) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (replace(upper(`MacAddress`),_utf8mb4':',_utf8mb4'')) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_serial` (`SerialNumber`),
  UNIQUE KEY `u_mac` (`mac_norm`),
  KEY `idx_pc_client` (`id_client`),
  CONSTRAINT `fk_pc_client__clients_id` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci