SET @has_notes := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'notes_internes'
);
SET @sql_notes := IF(
  @has_notes = 0,
  "ALTER TABLE clients ADD COLUMN notes_internes TEXT DEFAULT NULL AFTER statut",
  "SELECT 1"
);
PREPARE stmt_notes FROM @sql_notes;
EXECUTE stmt_notes;
DEALLOCATE PREPARE stmt_notes;
