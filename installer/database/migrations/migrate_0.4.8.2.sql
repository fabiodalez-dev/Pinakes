-- Migration script for Pinakes 0.4.8.2
-- Description: Import logs tracking system for CSV and LibraryThing imports
-- Date: 2026-02-06
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before creating table and indexes

-- This migration adds comprehensive import tracking and error logging
-- for better monitoring and debugging of CSV/LibraryThing imports

-- ============================================================
-- 1. IMPORT_LOGS TABLE
-- ============================================================

SET @table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_logs');
SET @sql = IF(@table_exists = 0, '
CREATE TABLE import_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    import_id VARCHAR(36) UNIQUE NOT NULL COMMENT ''Unique identifier for this import session'',
    import_type ENUM(''csv'', ''librarything'') NOT NULL COMMENT ''Type of import'',
    file_name VARCHAR(255) NULL COMMENT ''Original filename uploaded'',
    user_id INT NULL COMMENT ''User who initiated the import'',
    total_rows INT NOT NULL DEFAULT 0 COMMENT ''Total rows in CSV'',
    imported INT NOT NULL DEFAULT 0 COMMENT ''Successfully imported books (new)'',
    updated INT NOT NULL DEFAULT 0 COMMENT ''Updated existing books'',
    failed INT NOT NULL DEFAULT 0 COMMENT ''Failed rows'',
    authors_created INT NOT NULL DEFAULT 0 COMMENT ''New authors created'',
    publishers_created INT NOT NULL DEFAULT 0 COMMENT ''New publishers created'',
    scraped INT NOT NULL DEFAULT 0 COMMENT ''Books enriched via scraping'',
    errors_json MEDIUMTEXT NULL COMMENT ''JSON array of errors with line numbers and messages'',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT ''When import started'',
    completed_at TIMESTAMP NULL COMMENT ''When import completed or failed'',
    status ENUM(''processing'', ''completed'', ''failed'') DEFAULT ''processing'' COMMENT ''Current import status'',
    INDEX idx_user_id (user_id),
    INDEX idx_import_type (import_type),
    INDEX idx_started_at (started_at),
    INDEX idx_status (status),
    INDEX idx_type_status (import_type, status),
    FOREIGN KEY (user_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Import tracking and error logging''
', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. COMPOSITE INDEX (if table exists but index doesn't)
-- ============================================================

SET @table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_logs'
);

SET @index_exists = IF(@table_exists = 1, (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'import_logs'
    AND INDEX_NAME = 'idx_type_status'
), 0);

SET @sql = IF(@table_exists = 0,
    'SELECT "Table import_logs not found - skipping index creation" AS message',
    IF(@index_exists = 0,
        'CREATE INDEX idx_type_status ON import_logs (import_type, status)',
        'SELECT "Index idx_type_status already exists" AS message'
    )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
