-- Migration script for Pinakes 0.4.8.2
-- Description: Add illustratore field + expand lingua column for multi-language support
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
    'ALTER TABLE libri MODIFY COLUMN lingua VARCHAR(255) DEFAULT NULL',
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
