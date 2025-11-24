-- Migration : Création de la table user_permissions pour le système ACL
-- Date : 2024

DROP TABLE IF EXISTS `user_permissions`;

CREATE TABLE `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `page` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = autorisé, 0 = interdit',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_page` (`user_id`, `page`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_page` (`page`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Pages disponibles par défaut
-- Les permissions sont optionnelles : si aucune permission n'existe pour un utilisateur/page,
-- on utilise les rôles par défaut (système de fallback)

