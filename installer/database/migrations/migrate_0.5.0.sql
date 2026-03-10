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

INSERT IGNORE INTO system_settings (category, setting_key, setting_value, description, updated_at)
VALUES ('sharing', 'enabled_providers', 'facebook,x,whatsapp,email',
        'Enabled social sharing providers on book detail page', NOW());
