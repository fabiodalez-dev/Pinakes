-- Migration script for Pinakes 0.5.0
-- Description: Add social sharing settings + fix missing descrizione_plain column
-- Date: 2026-03-10
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering, uses INSERT IGNORE

-- =============================================================================
-- Fix: ensure descrizione_plain column exists (missed in 0.4.9.9 schema.sql)
-- =============================================================================
-- migrate_0.4.9.9.sql added this column for upgrades, but schema.sql was not
-- updated, so installs between 0.4.9.9 and 0.5.0 may be missing it.
-- SearchController::books() uses COALESCE(descrizione_plain, descrizione)
-- which fails if the column doesn't exist.

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

-- Deduplicate existing rows (keep the one with the smallest id)
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
ALTER TABLE plugin_hooks ADD UNIQUE KEY uk_plugin_hook_callback (plugin_id, hook_name, callback_method);
