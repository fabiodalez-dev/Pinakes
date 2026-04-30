-- Migration script for Pinakes 0.5.9.6
-- =============================================================================
-- CR review (round on PR #114, 0.5.9.5 → 0.5.9.6): align libri_collane
-- defaults and add a CHECK constraint that prevents contradictory
-- principal-membership rows.
--
-- Pre-fix the table accepted (and would default to) a state where
-- tipo_appartenenza='principale' AND is_principale=0 — two contradictory
-- truths about the same membership. No production code path triggers this
-- today (all INSERTs/UPSERTs set both fields explicitly), but a future
-- plugin/CSV/scraper that omits is_principale would land that contradiction.
--
-- Three idempotent steps:
--   1) Backfill any pre-existing rows whose two fields disagree.
--   2) Update is_principale's column default to 1 (matches tipo default).
--   3) Add chk_lc_principale_consistency CHECK so future inserts cannot
--      reintroduce the contradictory state.
--
-- Adding the CHECK after the backfill guarantees the ALTER never aborts on
-- legacy data. Requires MySQL 8.0.16+ for CHECK enforcement; older versions
-- silently parse but don't enforce — same caveat as chk_collane_tipo.
-- =============================================================================

-- Step 1: backfill — fix any row whose is_principale doesn't match tipo_appartenenza.
UPDATE libri_collane
   SET is_principale = IF(tipo_appartenenza = 'principale', 1, 0)
 WHERE (tipo_appartenenza = 'principale' AND is_principale <> 1)
    OR (tipo_appartenenza <> 'principale' AND is_principale <> 0);

-- Step 2: align column default to 1 (idempotent: only run if currently 0).
SET @current_default = (
    SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri_collane'
      AND COLUMN_NAME = 'is_principale'
);
SET @sql = IF(@current_default IS NOT NULL AND @current_default <> '1',
    'ALTER TABLE libri_collane ALTER COLUMN is_principale SET DEFAULT 1',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: add CHECK constraint (idempotent via INFORMATION_SCHEMA guard).
SET @chk_lc_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri_collane'
      AND CONSTRAINT_NAME = 'chk_lc_principale_consistency'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql = IF(@chk_lc_exists = 0,
    'ALTER TABLE libri_collane ADD CONSTRAINT chk_lc_principale_consistency CHECK ((tipo_appartenenza = \'principale\' AND is_principale = 1) OR (tipo_appartenenza <> \'principale\' AND is_principale = 0))',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
