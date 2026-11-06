-- Pinakes 0.9.0.

-- prestiti.warning_sent_for: the usable return day the due-soon e-mail announced.
-- A calendar save that changes that day without moving the stored date (the move
-- refused for capacity) re-arms the reminder, so the reader is told the new day once.
-- NULL for every existing loan: a loan already warned counts as warned for its stored
-- date. Additive and idempotent on MySQL and MariaDB.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'warning_sent_for');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE prestiti ADD COLUMN warning_sent_for date DEFAULT NULL AFTER warning_sent', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
