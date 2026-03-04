-- Migration script for Pinakes 0.4.9.8
-- Description: Schema fixes from codebase review — isbn10 UNIQUE, ean default NULL,
--              prestiti FK fix, mensole.descrizione type fix
-- Date: 2026-03-04
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Checks before altering

-- =============================================================================
-- DB-H1: Add UNIQUE index on isbn10 (isbn13 already has one)
-- =============================================================================
-- Deduplicate isbn10 values before adding UNIQUE constraint:
-- keep the lowest id for each duplicate, nullify the rest
UPDATE `libri` AS l1
  JOIN (
    SELECT isbn10, MIN(id) AS keep_id
    FROM `libri`
    WHERE isbn10 IS NOT NULL AND isbn10 != '' AND deleted_at IS NULL
    GROUP BY isbn10
    HAVING COUNT(*) > 1
  ) AS dups ON l1.isbn10 = dups.isbn10 AND l1.id != dups.keep_id
SET l1.isbn10 = NULL;

SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'libri'
              AND INDEX_NAME = 'isbn10');
SET @sql = IF(@idx = 0,
    'ALTER TABLE `libri` ADD UNIQUE KEY `isbn10` (`isbn10`)',
    'DO 0 /* isbn10 UNIQUE index already exists — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- DB-H2: Fix ean default from '' to NULL and add UNIQUE index
-- =============================================================================
-- Convert existing empty strings to NULL
UPDATE `libri` SET `ean` = NULL WHERE `ean` = '';

-- Deduplicate ean values before adding UNIQUE constraint
UPDATE `libri` AS l1
  JOIN (
    SELECT ean, MIN(id) AS keep_id
    FROM `libri`
    WHERE ean IS NOT NULL AND ean != '' AND deleted_at IS NULL
    GROUP BY ean
    HAVING COUNT(*) > 1
  ) AS dups ON l1.ean = dups.ean AND l1.id != dups.keep_id
SET l1.ean = NULL;

-- Change column default from '' to NULL
SET @col_default = (SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'libri'
                      AND COLUMN_NAME = 'ean');
SET @sql = IF(@col_default = '',
    'ALTER TABLE `libri` MODIFY COLUMN `ean` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''European Article Number''',
    'DO 0 /* ean default already NULL — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add UNIQUE index on ean
SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'libri'
              AND INDEX_NAME = 'ean');
SET @sql = IF(@idx = 0,
    'ALTER TABLE `libri` ADD UNIQUE KEY `ean` (`ean`)',
    'DO 0 /* ean UNIQUE index already exists — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- DB-H3: Fix prestiti_ibfk_3 FK — should reference utenti(id), not staff(id)
-- =============================================================================
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'prestiti'
                    AND CONSTRAINT_NAME = 'prestiti_ibfk_3'
                    AND CONSTRAINT_TYPE = 'FOREIGN KEY');
-- Check if it references the wrong table (staff instead of utenti)
SET @refs_staff = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'prestiti'
                     AND CONSTRAINT_NAME = 'prestiti_ibfk_3'
                     AND REFERENCED_TABLE_NAME = 'staff');
SET @sql = IF(@fk_exists > 0 AND @refs_staff > 0,
    'ALTER TABLE `prestiti` DROP FOREIGN KEY `prestiti_ibfk_3`',
    'DO 0 /* prestiti_ibfk_3 does not reference staff — skipping drop */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Clean orphan processed_by values that don't exist in utenti
UPDATE `prestiti` SET `processed_by` = NULL
WHERE `processed_by` IS NOT NULL
  AND `processed_by` NOT IN (SELECT `id` FROM `utenti`);

-- Re-add FK referencing utenti(id) if it doesn't exist yet
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'prestiti'
                    AND CONSTRAINT_NAME = 'prestiti_ibfk_3'
                    AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `prestiti` ADD CONSTRAINT `prestiti_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `utenti` (`id`)',
    'DO 0 /* prestiti_ibfk_3 already correct — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- DB-M3: Fix mensole.descrizione type — int NOT NULL → varchar(255) DEFAULT NULL
-- =============================================================================
SET @col_type = (SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'mensole'
                   AND COLUMN_NAME = 'descrizione');
SET @sql = IF(@col_type = 'int',
    'ALTER TABLE `mensole` MODIFY COLUMN `descrizione` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL',
    'DO 0 /* mensole.descrizione already varchar — skipping */');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
