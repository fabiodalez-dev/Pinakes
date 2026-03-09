-- Migration script for Pinakes 0.4.9.9
-- Description: Add descrizione_plain column for HTML-free search, inline PDF viewer hook
-- Date: 2026-03-09
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering

-- =============================================================================
-- Add descrizione_plain column (plaintext copy of descrizione for search)
-- =============================================================================
-- The descrizione field stores TinyMCE HTML. Searching with LIKE on raw HTML
-- causes HTML tag names/attributes to be matched (e.g. searching "strong" matches
-- <strong> tags). This column stores strip_tags() version for clean search.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND COLUMN_NAME = 'descrizione_plain');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `libri` ADD COLUMN `descrizione_plain` TEXT DEFAULT NULL COMMENT ''Plaintext description for search (strip_tags of descrizione)'' AFTER `descrizione`',
    'DO 0 /* descrizione_plain column already exists — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Backfill descrizione_plain from existing descrizione rows
-- =============================================================================
-- MySQL doesn't have strip_tags(), so we use REGEXP_REPLACE (MySQL 8.0+)
-- For MySQL 5.7/MariaDB, the PHP application will handle backfill on first run
-- via BookRepository. This migration handles MySQL 8.0+ inline.

SET @mysql_version = (SELECT VERSION());
SET @has_regexp_replace = (SELECT @mysql_version REGEXP '^[89]\\.' OR @mysql_version REGEXP '^1[0-9]\\.');

-- MySQL 8.0+ backfill: strip HTML tags using REGEXP_REPLACE
SET @sql = IF(@has_regexp_replace,
    'UPDATE `libri` SET `descrizione_plain` = REGEXP_REPLACE(`descrizione`, ''<[^>]+>'', '''') WHERE `descrizione` IS NOT NULL AND `descrizione` != '''' AND `descrizione_plain` IS NULL',
    'DO 0 /* MySQL < 8.0 — backfill will be handled by PHP application */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
