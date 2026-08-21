-- Migration 0.7.64
-- Circulation review follow-up: claim/retry tracking for the "ready for
-- pickup" email. Without a persisted flag an SMTP failure lost the notice
-- forever and checkExpiredPickups() then cancelled the loan at the deadline
-- without the user ever learning the book was waiting.
-- NotificationService::addNotificationColumns() and
-- MaintenanceService::ensurePickupNotificationColumn() self-heal this at
-- runtime too, so pre-migration installs keep working; this makes the schema
-- explicit. Idempotent for both the web updater and the migration runner.

SET @column_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'prestiti'
       AND COLUMN_NAME = 'pickup_notification_sent'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE `prestiti` ADD COLUMN `pickup_notification_sent` TINYINT(1) DEFAULT 0 COMMENT ''claim/retry flag for the ready-for-pickup email (see NotificationService::sendPickupReadyNotification)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
