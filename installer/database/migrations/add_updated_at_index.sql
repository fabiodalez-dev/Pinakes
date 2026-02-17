-- Add index on updated_at for configurable "Latest Books" sort order
-- This index supports ORDER BY updated_at DESC without a full table scan
ALTER TABLE `libri` ADD KEY `idx_libri_updated_at` (`updated_at`);
