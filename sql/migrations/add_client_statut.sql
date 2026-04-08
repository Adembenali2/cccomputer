SET @has_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'statut'
);
SET @sql_col := IF(
  @has_col = 0,
  "ALTER TABLE clients ADD COLUMN statut ENUM('actif','archive') NOT NULL DEFAULT 'actif' AFTER email",
  "SELECT 1"
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

SET @has_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND INDEX_NAME = 'idx_clients_statut'
);
SET @sql_idx := IF(
  @has_idx = 0,
  "CREATE INDEX idx_clients_statut ON clients(statut)",
  "SELECT 1"
);
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
