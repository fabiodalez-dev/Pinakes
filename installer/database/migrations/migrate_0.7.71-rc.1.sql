-- Migration 0.7.71-rc.1 — materialized catalog aggregates and author projection.
--
-- Existing installs receive three nullable, write-maintained display columns
-- that replace the public catalog's three correlated author subqueries. The
-- snapshot table shares bounded count/facet payloads between APCu workers and
-- is keyed by QueryCache's canonical catalog generation.
--
-- Every DDL statement is guarded so interrupted/repeated upgrades are safe.

SET @has_catalog_author_display = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'catalog_author_display'
);
SET @sql = IF(
    @has_catalog_author_display = 0,
    'ALTER TABLE `libri` ADD COLUMN `catalog_author_display` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Derived principal-author display used by public catalog listings'' AFTER `search_index`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_catalog_author_name = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'catalog_author_name'
);
SET @sql = IF(
    @has_catalog_author_name = 0,
    'ALTER TABLE `libri` ADD COLUMN `catalog_author_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Derived canonical name of the principal catalog author'' AFTER `catalog_author_display`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_catalog_author_sort = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'catalog_author_sort'
);
SET @sql = IF(
    @has_catalog_author_sort = 0,
    'ALTER TABLE `libri` ADD COLUMN `catalog_author_sort` VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT ''Derived surname/sort key for catalog author ordering'' AFTER `catalog_author_name`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill in one grouped pass over libri_autori: principale wins over
-- co-autore, then explicit credit order, then author id. Avoid three correlated
-- scans per existing book during the migration itself.
SET @old_group_concat_max_len = @@SESSION.group_concat_max_len;
SET SESSION group_concat_max_len = 1000000;
UPDATE `libri` l
LEFT JOIN (
    SELECT la.libro_id,
           SUBSTRING_INDEX(GROUP_CONCAT(
               CASE
                   WHEN TRIM(COALESCE(a.pseudonimo, '')) <> ''
                    AND TRIM(COALESCE(a.nome, '')) <> ''
                    AND BINARY TRIM(COALESCE(a.pseudonimo, '')) <> BINARY TRIM(COALESCE(a.nome, ''))
                       THEN CONCAT(TRIM(a.pseudonimo), ' (', TRIM(a.nome), ')')
                   WHEN TRIM(COALESCE(a.nome, '')) <> '' THEN TRIM(a.nome)
                   ELSE TRIM(COALESCE(a.pseudonimo, ''))
               END
               ORDER BY (la.ruolo = 'principale') DESC,
                        COALESCE(la.ordine_credito, 2147483647), la.autore_id
               SEPARATOR 0x1F
           ), 0x1F, 1) AS author_display,
           SUBSTRING_INDEX(GROUP_CONCAT(
               TRIM(COALESCE(a.nome, ''))
               ORDER BY (la.ruolo = 'principale') DESC,
                        COALESCE(la.ordine_credito, 2147483647), la.autore_id
               SEPARATOR 0x1F
           ), 0x1F, 1) AS author_name,
           SUBSTRING_INDEX(GROUP_CONCAT(
               SUBSTRING_INDEX(
                   COALESCE(NULLIF(TRIM(a.pseudonimo), ''), TRIM(COALESCE(a.nome, ''))),
                   ' ', -1
               )
               ORDER BY (la.ruolo = 'principale') DESC,
                        COALESCE(la.ordine_credito, 2147483647), la.autore_id
               SEPARATOR 0x1F
           ), 0x1F, 1) AS author_sort
    FROM `libri_autori` la
    JOIN `autori` a ON a.id = la.autore_id
    WHERE la.ruolo IN ('principale', 'co-autore')
    GROUP BY la.libro_id
) projection ON projection.libro_id = l.id
SET l.`catalog_author_display` = NULLIF(LEFT(projection.author_display, 512), ''),
    l.`catalog_author_name` = NULLIF(LEFT(projection.author_name, 255), ''),
    l.`catalog_author_sort` = NULLIF(LEFT(projection.author_sort, 160), '');
SET SESSION group_concat_max_len = @old_group_concat_max_len;

SET @has_catalog_author_sort_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
      AND INDEX_NAME = 'idx_libri_catalog_author_sort'
);
SET @sql = IF(
    @has_catalog_author_sort_index = 0,
    'ALTER TABLE `libri` ADD INDEX `idx_libri_catalog_author_sort` (`catalog_author_sort`, `id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `catalog_materialized_snapshots` (
    `cache_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `generation` BIGINT NOT NULL,
    `payload` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cache_key`),
    KEY `idx_catalog_snapshots_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Generation-bound shared materialization of bounded catalog counts and facets';
