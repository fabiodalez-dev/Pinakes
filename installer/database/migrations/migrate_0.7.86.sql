-- 0.7.86: make plugin_data's key pair actually unique, so setData() upserts.
--
-- PluginManager::setData() has always written with INSERT ... ON DUPLICATE KEY
-- UPDATE, but plugin_data declares `KEY idx_plugin_key (plugin_id, data_key)`
-- without UNIQUE. With no unique constraint there is never a duplicate to
-- detect, so every call INSERTs another row and getData() returns whichever
-- one the optimizer hands back first — in practice the oldest, i.e. the value
-- the caller thought it had overwritten. The bug is silent because the table
-- is barely used today; it stops being silent the moment anything stores state
-- there and reads it back.
--
-- Order matters: duplicates must go BEFORE the constraint, or the ALTER fails
-- on exactly the installations that need it most. An installation that never
-- called setData() has an empty table and this whole file is a no-op.
--
-- Idempotent throughout: the DELETE finds nothing on a second run, and the
-- ALTER is guarded on INFORMATION_SCHEMA.STATISTICS the same way every other
-- index migration in this project is (migrate_0.4.5.sql, migrate_0.4.6.sql).

-- ============================================================
-- 1. COLLAPSE DUPLICATES, KEEPING THE NEWEST ROW PER (plugin_id, data_key)
-- ============================================================
-- MAX(id) is the right survivor: AUTO_INCREMENT order is insertion order, and
-- the most recent INSERT is the value the last setData() caller intended to
-- store. The self-join is wrapped in a derived table because MySQL refuses to
-- DELETE from a table it is also selecting from in a subquery.
DELETE pd FROM `plugin_data` pd
INNER JOIN (
    SELECT MAX(`id`) AS keep_id, `plugin_id`, `data_key`
    FROM `plugin_data`
    GROUP BY `plugin_id`, `data_key`
    HAVING COUNT(*) > 1
) AS dupes
    ON pd.`plugin_id` = dupes.`plugin_id`
   AND pd.`data_key`  = dupes.`data_key`
   AND pd.`id`       <> dupes.keep_id;

-- ============================================================
-- 2. REPLACE THE NON-UNIQUE KEY WITH A UNIQUE ONE
-- ============================================================
-- Drop first, then add: the two indexes cover the same columns, so keeping the
-- plain KEY around would be dead weight InnoDB still has to maintain on every
-- write. Both statements are guarded independently so a half-applied run (drop
-- succeeded, add did not) still converges on re-run.
SET @plugin_data_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_data');

SET @old_key_exists = IF(@plugin_data_exists = 1, (
    SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_data'
      AND INDEX_NAME = 'idx_plugin_key' AND NON_UNIQUE = 1
), 0);

SET @sql = IF(@old_key_exists > 0,
    'ALTER TABLE `plugin_data` DROP INDEX `idx_plugin_key`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @uniq_key_exists = IF(@plugin_data_exists = 1, (
    SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_data'
      AND INDEX_NAME = 'uniq_plugin_key'
), 0);

SET @sql = IF(@plugin_data_exists = 1 AND @uniq_key_exists = 0,
    'ALTER TABLE `plugin_data` ADD UNIQUE KEY `uniq_plugin_key` (`plugin_id`, `data_key`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- End of migration
