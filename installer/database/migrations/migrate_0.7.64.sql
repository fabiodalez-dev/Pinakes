-- Migration 0.7.64
-- Durable claim/retry state for the ready-for-pickup notification.
--
-- The marker is written BEFORE any ALTER TABLE (which implicitly commits in
-- MySQL). Existing rows receive 1 through the ADD COLUMN default itself; the
-- default is then changed to 0 for future rows. Deliberately no UPDATE touches
-- prestiti here: older circulation triggers validate every UPDATE and can
-- reject unrelated backfill writes on damaged/overdue legacy rows.

SET @pickup_sent_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'pickup_notification_sent'
);
SET @pickup_token_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'pickup_notification_claim_token'
);
SET @pickup_attempt_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'pickup_notification_last_attempt_at'
);

INSERT INTO system_settings (category, setting_key, setting_value, description)
SELECT
    'migrations',
    'pickup_notification_backfill_0_7_64',
    IF(@pickup_sent_exists = 0, 'pending', 'done'),
    'Resumable state for the 0.7.64 pickup notification schema'
WHERE NOT EXISTS (
    SELECT 1
      FROM system_settings
     WHERE category = 'migrations'
       AND setting_key = 'pickup_notification_backfill_0_7_64'
);

SET @sql = IF(
    @pickup_sent_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `pickup_notification_sent` TINYINT(1) DEFAULT 1 COMMENT ''claim/retry flag for the ready-for-pickup email''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @pickup_token_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `pickup_notification_claim_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''owner token for an in-flight pickup email claim''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @pickup_attempt_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `pickup_notification_last_attempt_at` DATETIME NULL DEFAULT NULL COMMENT ''fair retry ordering for pickup email attempts''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @pickup_backfill_marker = (
    SELECT setting_value
      FROM system_settings
     WHERE category = 'migrations'
       AND setting_key = 'pickup_notification_backfill_0_7_64'
     LIMIT 1
);
SET @sql = IF(
    @pickup_backfill_marker = 'pending'
        OR @pickup_backfill_marker REGEXP '^pending:[0-9]+$',
    'ALTER TABLE `prestiti` MODIFY COLUMN `pickup_notification_sent` TINYINT(1) DEFAULT 0 COMMENT ''claim/retry flag for the ready-for-pickup email''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE system_settings
   SET setting_value = 'done'
 WHERE category = 'migrations'
   AND setting_key = 'pickup_notification_backfill_0_7_64'
   AND setting_value = @pickup_backfill_marker
   AND (
       @pickup_backfill_marker = 'pending'
       OR @pickup_backfill_marker REGEXP '^pending:[0-9]+$'
   );
