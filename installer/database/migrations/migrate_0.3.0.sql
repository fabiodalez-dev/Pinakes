-- Migration script for Pinakes 0.3.0
-- Renames classificazione_dowey -> classificazione_dewey (typo fix)
-- Idempotent: no-op if the old column no longer exists (fresh install or already migrated)

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'libri'
      AND COLUMN_NAME  = 'classificazione_dowey'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE libri CHANGE COLUMN classificazione_dowey classificazione_dewey VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
