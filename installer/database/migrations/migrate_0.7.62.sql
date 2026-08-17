-- Migration 0.7.62
-- Issue #360: recall (sollecito) tracking for overdue loans.
-- recall_count / last_recall_at let the automatic recall scheduler repeat the
-- reminder at the configured interval (loans.recall_interval_days) up to the
-- configured cap (loans.recall_max_count); manual recalls use the same columns.
-- NotificationService::addNotificationColumns() self-heals these at runtime too,
-- so pre-migration installs keep working; this makes the schema explicit.
-- Idempotent for both the web updater and the standalone migration runner.

SET @column_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'recall_count'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `recall_count` INT NOT NULL DEFAULT 0 COMMENT ''#360: how many recall (sollecito) emails went out for this loan'' AFTER `overdue_notification_sent`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'last_recall_at'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `last_recall_at` DATETIME NULL DEFAULT NULL COMMENT ''#360: when the last recall (sollecito) email went out'' AFTER `recall_count`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
