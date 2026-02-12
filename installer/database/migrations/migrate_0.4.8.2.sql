-- Migration script for Pinakes 0.4.8.2
-- Description: Add illustratore field + expand lingua column + normalize language names to native
-- Date: 2026-02-11
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering

-- ============================================================
-- 1. ADD illustratore COLUMN TO libri (after traduttore)
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'illustratore'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE libri ADD COLUMN illustratore VARCHAR(255) DEFAULT NULL AFTER traduttore',
    'SELECT "Column illustratore already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. EXPAND lingua COLUMN FROM varchar(50) TO varchar(255)
-- ============================================================

SET @col_type = (
    SELECT CHARACTER_MAXIMUM_LENGTH
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'lingua'
);

SET @sql = IF(@col_type < 255,
    'ALTER TABLE libri MODIFY COLUMN lingua VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL',
    'SELECT "Column lingua already varchar(255)" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. ADD illustratore TO libri_autori ruolo ENUM
-- ============================================================

SET @has_illustratore = (
    SELECT LOCATE('illustratore', COLUMN_TYPE)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri_autori'
      AND COLUMN_NAME = 'ruolo'
);

SET @sql = IF(@has_illustratore = 0,
    'ALTER TABLE libri_autori MODIFY COLUMN ruolo ENUM(''principale'',''co-autore'',''traduttore'',''illustratore'') NOT NULL',
    'SELECT "Enum already includes illustratore" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. NORMALIZE LANGUAGE NAMES: Italian → Native
--    Convert legacy Italian language names to native names
--    (idempotent: only updates rows that still have Italian names)
-- ============================================================

UPDATE libri SET lingua = REPLACE(lingua, 'italiano', 'Italiano') WHERE BINARY lingua LIKE '%italiano%';
UPDATE libri SET lingua = REPLACE(lingua, 'inglese', 'English') WHERE BINARY lingua LIKE '%inglese%';
UPDATE libri SET lingua = REPLACE(lingua, 'francese', 'Français') WHERE BINARY lingua LIKE '%francese%';
UPDATE libri SET lingua = REPLACE(lingua, 'tedesco', 'Deutsch') WHERE BINARY lingua LIKE '%tedesco%';
UPDATE libri SET lingua = REPLACE(lingua, 'spagnolo', 'Español') WHERE BINARY lingua LIKE '%spagnolo%';
UPDATE libri SET lingua = REPLACE(lingua, 'portoghese', 'Português') WHERE BINARY lingua LIKE '%portoghese%';
UPDATE libri SET lingua = REPLACE(lingua, 'russo', 'Русский') WHERE BINARY lingua LIKE '%russo%';
UPDATE libri SET lingua = REPLACE(lingua, 'cinese', '中文') WHERE BINARY lingua LIKE '%cinese%';
UPDATE libri SET lingua = REPLACE(lingua, 'giapponese', '日本語') WHERE BINARY lingua LIKE '%giapponese%';
UPDATE libri SET lingua = REPLACE(lingua, 'arabo', 'العربية') WHERE BINARY lingua LIKE '%arabo%';
UPDATE libri SET lingua = REPLACE(lingua, 'olandese', 'Nederlands') WHERE BINARY lingua LIKE '%olandese%';
UPDATE libri SET lingua = REPLACE(lingua, 'svedese', 'Svenska') WHERE BINARY lingua LIKE '%svedese%';
UPDATE libri SET lingua = REPLACE(lingua, 'norvegese', 'Norsk') WHERE BINARY lingua LIKE '%norvegese%';
UPDATE libri SET lingua = REPLACE(lingua, 'danese', 'Dansk') WHERE BINARY lingua LIKE '%danese%';
UPDATE libri SET lingua = REPLACE(lingua, 'finlandese', 'Suomi') WHERE BINARY lingua LIKE '%finlandese%';
UPDATE libri SET lingua = REPLACE(lingua, 'polacco', 'Polski') WHERE BINARY lingua LIKE '%polacco%';
UPDATE libri SET lingua = REPLACE(lingua, 'ceco', 'Čeština') WHERE BINARY lingua LIKE '%ceco%';
UPDATE libri SET lingua = REPLACE(lingua, 'ungherese', 'Magyar') WHERE BINARY lingua LIKE '%ungherese%';
UPDATE libri SET lingua = REPLACE(lingua, 'rumeno', 'Română') WHERE BINARY lingua LIKE '%rumeno%';
UPDATE libri SET lingua = REPLACE(lingua, 'greco', 'Ελληνικά') WHERE BINARY lingua LIKE '%greco%';
UPDATE libri SET lingua = REPLACE(lingua, 'turco', 'Türkçe') WHERE BINARY lingua LIKE '%turco%';
UPDATE libri SET lingua = REPLACE(lingua, 'ebraico', 'עברית') WHERE BINARY lingua LIKE '%ebraico%';
UPDATE libri SET lingua = REPLACE(lingua, 'hindi', 'हिन्दी') WHERE BINARY lingua LIKE '%hindi%';
UPDATE libri SET lingua = REPLACE(lingua, 'coreano', '한국어') WHERE BINARY lingua LIKE '%coreano%';
UPDATE libri SET lingua = REPLACE(lingua, 'thai', 'ไทย') WHERE BINARY lingua LIKE '%thai%';
UPDATE libri SET lingua = REPLACE(lingua, 'latino', 'Latina') WHERE BINARY lingua LIKE '%latino%';

-- ============================================================
-- 5. CHANGE anno_pubblicazione TO SIGNED (support BCE dates)
--    SMALLINT signed range: -32768 to 32767
-- ============================================================

SET @is_unsigned = (
    SELECT LOCATE('unsigned', LOWER(COLUMN_TYPE))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'anno_pubblicazione'
);

SET @sql = IF(@is_unsigned > 0,
    'ALTER TABLE libri MODIFY COLUMN anno_pubblicazione SMALLINT DEFAULT NULL',
    'SELECT "Column anno_pubblicazione already signed" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
