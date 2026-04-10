-- Ajoute le statut `partielle` si manquant (idempotent)
SET @enum_def := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'factures'
    AND COLUMN_NAME = 'statut'
  LIMIT 1
);

SET @has_partielle := IF(@enum_def LIKE "%'partielle'%", 1, 0);
SET @sql := IF(
  @has_partielle = 0,
  "ALTER TABLE factures MODIFY COLUMN statut ENUM('brouillon','en_attente','envoyee','en_cours','en_retard','partielle','payee','annulee') NOT NULL DEFAULT 'en_attente'",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
