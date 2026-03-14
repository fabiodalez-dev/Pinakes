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
-- population via BookRepository on each insert/update. Existing rows are
-- lazily backfilled on first read via getById(); SearchController uses
-- COALESCE(descrizione_plain, descrizione) as fallback until backfill completes.

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

-- =============================================================================
-- Add default sharing providers setting
-- =============================================================================
-- Enables Facebook, X, WhatsApp and Email share buttons on the book detail page.
-- Admins can customise the selection in Settings > Sharing.

INSERT IGNORE INTO system_settings (category, setting_key, setting_value, updated_at)
VALUES ('sharing', 'enabled_providers', 'facebook,x,whatsapp,email', NOW());

-- =============================================================================
-- Add unique index on plugin_hooks for atomic upsert registration
-- =============================================================================
-- Prevents duplicate hook rows from concurrent registerHooks() calls.
-- First, deduplicate existing rows (keep the one with the smallest id).

DELETE ph1
FROM plugin_hooks ph1
JOIN plugin_hooks ph2
  ON ph1.plugin_id       = ph2.plugin_id
 AND ph1.hook_name       = ph2.hook_name
 AND ph1.callback_method = ph2.callback_method
 AND ph1.id > ph2.id;

-- Drop old 4-column unique key if it exists (included callback_class which
-- is NOT part of the runtime identity — the dispatcher deduplicates by
-- plugin_id + hook_name + callback_method only)
SET @old_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'plugin_hooks'
                  AND INDEX_NAME = 'uk_plugin_hook_callback');
SET @sql = IF(@old_idx > 0,
    'ALTER TABLE plugin_hooks DROP INDEX uk_plugin_hook_callback',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Re-create with correct 3-column key
SET @new_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'plugin_hooks'
                  AND INDEX_NAME = 'uk_plugin_hook_callback');
SET @sql = IF(@new_idx = 0,
    'ALTER TABLE plugin_hooks ADD UNIQUE KEY uk_plugin_hook_callback (plugin_id, hook_name, callback_method)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Add curatore column to libri table
-- =============================================================================
-- Stores the curator/editor of the book (if applicable).

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND COLUMN_NAME = 'curatore');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE libri ADD COLUMN curatore VARCHAR(255) DEFAULT NULL AFTER illustratore',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Add curatore role to libri_autori.ruolo ENUM
-- =============================================================================
-- Extends the role enum to include curator alongside principale, co-autore,
-- traduttore, and illustratore.

SET @has_curatore = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'libri_autori'
                       AND COLUMN_NAME = 'ruolo'
                       AND COLUMN_TYPE LIKE '%curatore%');
SET @sql = IF(@has_curatore = 0,
    'ALTER TABLE libri_autori MODIFY COLUMN ruolo ENUM(''principale'',''co-autore'',''traduttore'',''illustratore'',''curatore'') NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
