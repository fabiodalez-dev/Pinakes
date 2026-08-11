-- Migration 0.7.58
-- Issue #338: allow administrators to mark a series as complete.
-- Idempotent for both the web updater and the standalone migration runner.

SET @column_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'collane'
       AND COLUMN_NAME = 'is_completa'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE `collane` ADD COLUMN `is_completa` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Admin flag: all expected volumes are present in the catalogue'' AFTER `ordine_ciclo`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
