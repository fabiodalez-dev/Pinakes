-- Migration script for Pinakes 0.5.0
-- Description: Add curatore field, SEO/LLM readiness features
-- Date: 2026-03-14
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering

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
