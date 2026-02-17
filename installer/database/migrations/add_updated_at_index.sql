-- Add index on updated_at for configurable "Latest Books" sort order
-- This index supports ORDER BY updated_at DESC without a full table scan
-- Idempotent: skips if index already exists
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'libri'
                  AND INDEX_NAME   = 'idx_libri_updated_at');
SET @sql := IF(@exists = 0,
    'ALTER TABLE `libri` ADD KEY `idx_libri_updated_at` (`updated_at`)',
    'SELECT ''Index idx_libri_updated_at already exists — skipping''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
