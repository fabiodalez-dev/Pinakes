-- Migration script for Pinakes 0.4.6
-- Description: Add LibraryThing missing fields (dewey_wording, barcode, entry_date)
-- Date: 2025-02-02
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Uses prepared statements to check before creating

-- ============================================================
-- 1. ADD DEWEY_WORDING COLUMN (only if not exists)
-- ============================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'dewey_wording');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `dewey_wording` TEXT NULL COMMENT ''Dewey classification description (LibraryThing)'' AFTER `classificazione_dewey`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. ADD BARCODE COLUMN (only if not exists)
-- ============================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'barcode');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `barcode` VARCHAR(50) NULL COMMENT ''Physical barcode (LibraryThing)'' AFTER `bcid`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. ADD ENTRY_DATE COLUMN (only if not exists)
-- ============================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'entry_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `entry_date` DATE NULL COMMENT ''LibraryThing entry date'' AFTER `data_acquisizione`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. ADD INDEX FOR BARCODE (only if not exists)
-- ============================================================
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_barcode');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_barcode` (`barcode`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- End of migration
