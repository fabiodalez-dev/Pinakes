-- Migration script for Pinakes 0.4.7
-- Description: Comprehensive LibraryThing fields migration (idempotent)
-- Date: 2025-02-02
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Uses prepared statements to check before creating

-- This migration consolidates all LibraryThing database schema requirements
-- and can be safely run multiple times

-- ============================================================
-- 1. REVIEW AND RATING FIELDS
-- ============================================================

-- review field
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'review');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `review` TEXT NULL COMMENT ''Book review (LibraryThing)'' AFTER `descrizione`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- rating field
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'rating');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `rating` TINYINT UNSIGNED NULL COMMENT ''Rating 1-5 (LibraryThing)'' AFTER `review`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- comment field
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'comment');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `comment` TEXT NULL COMMENT ''Public comment (LibraryThing)'' AFTER `rating`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- private_comment field
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'private_comment');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `private_comment` TEXT NULL COMMENT ''Private comment (LibraryThing)'' AFTER `comment`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. PHYSICAL DESCRIPTION
-- ============================================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'physical_description');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `physical_description` VARCHAR(255) NULL COMMENT ''Physical description (LibraryThing)'' AFTER `dimensioni`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. CLASSIFICATION FIELDS
-- ============================================================

-- dewey_wording
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'dewey_wording');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `dewey_wording` TEXT NULL COMMENT ''Dewey classification description (LibraryThing)'' AFTER `classificazione_dewey`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lccn
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lccn');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lccn` VARCHAR(50) NULL COMMENT ''Library of Congress Control Number (LibraryThing)'' AFTER `dewey_wording`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lc_classification
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lc_classification');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lc_classification` VARCHAR(100) NULL COMMENT ''LC Classification (LibraryThing)'' AFTER `lccn`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- other_call_number
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'other_call_number');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `other_call_number` VARCHAR(100) NULL COMMENT ''Other call number (LibraryThing)'' AFTER `lc_classification`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. DATE TRACKING FIELDS
-- ============================================================

-- entry_date
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'entry_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `entry_date` DATE NULL COMMENT ''LibraryThing entry date'' AFTER `data_acquisizione`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- date_started
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'date_started');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `date_started` DATE NULL COMMENT ''Date started reading (LibraryThing)'' AFTER `entry_date`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- date_read
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'date_read');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `date_read` DATE NULL COMMENT ''Date finished reading (LibraryThing)'' AFTER `date_started`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 5. CATALOG IDENTIFIERS
-- ============================================================

-- bcid
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'bcid');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `bcid` VARCHAR(50) NULL COMMENT ''BCID (LibraryThing)'' AFTER `ean`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- barcode
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'barcode');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `barcode` VARCHAR(50) NULL COMMENT ''Physical barcode (LibraryThing)'' AFTER `bcid`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- oclc
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'oclc');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `oclc` VARCHAR(50) NULL COMMENT ''OCLC number (LibraryThing)'' AFTER `barcode`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- work_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'work_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `work_id` VARCHAR(50) NULL COMMENT ''LibraryThing Work ID'' AFTER `oclc`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- issn
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'issn');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `issn` VARCHAR(20) NULL COMMENT ''ISSN for periodicals (LibraryThing)'' AFTER `isbn13`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 6. LANGUAGE FIELDS
-- ============================================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'original_languages');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `original_languages` VARCHAR(255) NULL COMMENT ''Original languages (LibraryThing)'' AFTER `lingua`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 7. ACQUISITION FIELDS
-- ============================================================

-- source
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'source');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `source` VARCHAR(255) NULL COMMENT ''Source/vendor (LibraryThing)'' AFTER `editore_id`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- from_where
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'from_where');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `from_where` VARCHAR(255) NULL COMMENT ''From where acquired (LibraryThing)'' AFTER `source`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 8. LENDING TRACKING FIELDS
-- ============================================================

-- lending_patron
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lending_patron');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lending_patron` VARCHAR(255) NULL COMMENT ''Current lending patron (LibraryThing)'' AFTER `from_where`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lending_status
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lending_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lending_status` VARCHAR(50) NULL COMMENT ''Lending status (LibraryThing)'' AFTER `lending_patron`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lending_start
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lending_start');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lending_start` DATE NULL COMMENT ''Lending start date (LibraryThing)'' AFTER `lending_status`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lending_end
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lending_end');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `lending_end` DATE NULL COMMENT ''Lending end date (LibraryThing)'' AFTER `lending_start`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 9. FINANCIAL AND CONDITION FIELDS
-- ============================================================

-- value
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'value');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `value` DECIMAL(10,2) NULL COMMENT ''Current value (LibraryThing)'' AFTER `prezzo`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- condition_lt
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'condition_lt');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `condition_lt` VARCHAR(100) NULL COMMENT ''Physical condition (LibraryThing)'' AFTER `value`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 10. VISIBILITY CONTROL (JSON/LONGTEXT)
-- ============================================================
-- JSON type is only available in MariaDB 10.2.7+ and MySQL 5.7.8+
-- For older versions, we use LONGTEXT as a fallback

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'lt_fields_visibility');

-- Detect if we're on MariaDB 10.0/10.1 or MySQL < 5.7.8
SET @is_old_mariadb = (@@version REGEXP '^(MariaDB )?10\\.(0|1)\\.' OR @@version REGEXP '^5\\.');
SET @col_type = IF(@is_old_mariadb, 'LONGTEXT', 'JSON');

-- Build ALTER statement with version-appropriate column type
SET @sql = IF(@col_exists = 0,
              CONCAT('ALTER TABLE `libri` ADD COLUMN `lt_fields_visibility` ', @col_type,
                     ' NULL COMMENT ''Frontend visibility preferences for LibraryThing fields'' AFTER `condition_lt`'),
              'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 11. INDEXES FOR PERFORMANCE
-- ============================================================

-- idx_lt_rating
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_rating');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_rating` (`rating`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_date_read
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_date_read');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_date_read` (`date_read`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_lending_status
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_lending_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_lending_status` (`lending_status`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_lccn
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_lccn');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_lccn` (`lccn`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_barcode
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_barcode');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_barcode` (`barcode`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_oclc
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_oclc');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_oclc` (`oclc`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_work_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_work_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_work_id` (`work_id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_lt_issn
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_issn');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_lt_issn` (`issn`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 12. CONSTRAINTS
-- ============================================================

-- Rating constraint: must be NULL or between 1-5
-- Note: MariaDB/MySQL might have issues with CHECK constraints before 8.0.16
-- This will silently fail on older versions but is safe
SET @constraint_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND CONSTRAINT_NAME = 'chk_lt_rating');
SET @sql = IF(@constraint_exists = 0 AND @@version NOT LIKE '%MariaDB%', 'ALTER TABLE `libri` ADD CONSTRAINT `chk_lt_rating` CHECK (`rating` IS NULL OR (`rating` >= 1 AND `rating` <= 5))', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- End of migration
