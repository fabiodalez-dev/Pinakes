-- Migration script for Pinakes 0.4.9.1
-- Description: Performance optimization for import_logs table
-- Date: 2026-02-05
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before creating index

-- This migration adds a composite index for improved query performance
-- on filtered import history views (by type and status)

-- ============================================================
-- 1. COMPOSITE INDEX ON (import_type, status)
-- ============================================================

-- Check if index already exists
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'import_logs'
    AND INDEX_NAME = 'idx_type_status'
);

-- Create index only if it doesn't exist
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_type_status ON import_logs (import_type, status)',
    'SELECT "Index idx_type_status already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- PERFORMANCE NOTES
-- ============================================================
-- This composite index optimizes queries like:
-- - SELECT * FROM import_logs WHERE import_type = 'csv' AND status = 'completed'
-- - SELECT * FROM import_logs WHERE import_type = 'librarything' AND status = 'failed'
--
-- Query order matters:
-- - (import_type, status) is optimal for filtering by type first
-- - Existing indexes: import_id (UNIQUE), user_id, import_type, started_at, status
--
-- Index cardinality:
-- - import_type: Low (2 values: csv, librarything)
-- - status: Low (3 values: processing, completed, failed)
-- - Combined selectivity improves with both columns
--
-- Typical use case:
-- Admin filtering import history by type and viewing failed imports
