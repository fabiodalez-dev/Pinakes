-- Migration script for Pinakes 0.5.1
-- Description: Multi-volume works support, collana index
-- Date: 2026-03-17
-- Compatibility: MySQL 8.0.16+, MariaDB 10.2.1+ (CHECK constraints enforced from these versions)
-- FULLY IDEMPOTENT: Checks before altering

-- =============================================================================
-- Create volumi table for multi-volume works
-- =============================================================================
-- Links a parent work (opera) to its individual volumes (each a separate libro).
-- Each volume is an independent book with its own metadata, authors, and loans.

SET @tbl_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'volumi');
SET @sql = IF(@tbl_exists = 0,
    'CREATE TABLE volumi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opera_id INT NOT NULL COMMENT ''Parent work (the complete multi-volume work)'',
        volume_id INT NOT NULL COMMENT ''Child book (individual volume)'',
        numero_volume SMALLINT UNSIGNED DEFAULT 1 COMMENT ''Volume number within the work'',
        titolo_volume VARCHAR(255) DEFAULT NULL COMMENT ''Override title for this volume (if different from book title)'',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_volume_id (volume_id),
        KEY idx_opera (opera_id),
        CONSTRAINT fk_volumi_opera FOREIGN KEY (opera_id) REFERENCES libri(id) ON DELETE CASCADE,
        CONSTRAINT fk_volumi_volume FOREIGN KEY (volume_id) REFERENCES libri(id) ON DELETE CASCADE,
        CONSTRAINT chk_volumi_not_self CHECK (opera_id <> volume_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Create collane table for series metadata (name, description)
-- =============================================================================

SET @tbl_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'collane');
SET @sql = IF(@tbl_exists = 0,
    'CREATE TABLE collane (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL COMMENT ''Series name (must match libri.collana values)'',
        descrizione TEXT DEFAULT NULL COMMENT ''Series description'',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_collana_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Add index on collana for series browsing queries
-- =============================================================================
-- Needed for "Other volumes in this series" feature which queries
-- WHERE collana = ? AND deleted_at IS NULL

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND INDEX_NAME = 'idx_collana');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE libri ADD INDEX idx_collana (collana)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
