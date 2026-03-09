-- Migration script for Pinakes 0.4.9.9
-- Description: Add descrizione_plain column for HTML-free search
-- Date: 2026-03-09
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering

-- =============================================================================
-- Add descrizione_plain column (plaintext copy of descrizione for search)
-- =============================================================================
-- The descrizione field stores TinyMCE HTML. Searching with LIKE on raw HTML
-- causes HTML tag names/attributes to be matched (e.g. searching "strong" matches
-- <strong> tags). This column stores strip_tags() version for clean search.
--
-- Backfill note: MySQL lacks strip_tags(), so the PHP application handles
-- backfill via BookRepository on each insert/update. Existing rows are backfilled
-- on first access by the application.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND COLUMN_NAME = 'descrizione_plain');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE libri ADD COLUMN descrizione_plain TEXT DEFAULT NULL AFTER descrizione',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
