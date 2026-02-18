-- Migration script for Pinakes 0.4.8.3
-- Description: Add index on updated_at for configurable "Latest Books" sort order
-- Date: 2026-02-18
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'libri'
                  AND INDEX_NAME   = 'idx_libri_updated_at');
SET @sql := IF(@exists = 0,
    'ALTER TABLE `libri` ADD KEY `idx_libri_updated_at` (`updated_at`)',
    'DO 0 /* idx_libri_updated_at already exists — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
