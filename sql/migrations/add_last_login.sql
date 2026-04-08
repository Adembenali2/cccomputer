-- [Fonctionnalité C] Horodatage de la dernière connexion
-- Idempotent sur MySQL 5.7+ / 8.x et MariaDB (sans ADD COLUMN IF NOT EXISTS, absent avant MySQL 8.0.29)

SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'utilisateurs'
    AND COLUMN_NAME = 'last_login_at'
);
SET @sql := IF(
  @exists = 0,
  'ALTER TABLE utilisateurs ADD COLUMN last_login_at DATETIME DEFAULT NULL',
  'SELECT ''last_login_at déjà présent'' AS migration_skip'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
