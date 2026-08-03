-- Migration 0.7.53
-- Performance: composite indexes for the "newest first" sorts used by the
-- public home page (latest books, per-genre carousels) and the catalog
-- default sort. Without them MySQL filesorts the whole libri table on
-- every home/catalog render.
-- Fully idempotent: each ADD INDEX is guarded by an INFORMATION_SCHEMA
-- check (house pattern, MySQL/MariaDB compatible), so re-running the
-- migration is a no-op.

-- ============================================================
-- 1. ADD INDEX idx_libri_deleted_created (only if not exists)
-- ============================================================
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_libri_deleted_created');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_libri_deleted_created` (`deleted_at`, `created_at`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. ADD INDEX idx_libri_genere_deleted_created (only if not exists)
-- ============================================================
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_libri_genere_deleted_created');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD INDEX `idx_libri_genere_deleted_created` (`genere_id`, `deleted_at`, `created_at`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
