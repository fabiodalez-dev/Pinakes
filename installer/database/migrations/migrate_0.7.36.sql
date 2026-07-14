-- Migration 0.7.36 — contributor roles: add 'colorista' to libri_autori.ruolo
--
-- Issue #237: illustrator/translator/curator/colorist become first-class author
-- entities via libri_autori.ruolo (previously the form wrote every author as
-- 'principale' and kept illustrator/translator/curator as free-text columns on
-- `libri`). This adds the missing 'colorista' value to the role enum.
--
-- The free-text-to-entity BACKFILL of existing libri.illustratore/traduttore/
-- curatore values is NOT done here: it needs row logic (comma-split +
-- find-or-create) that pure SQL can't do safely, and the updater only runs .sql
-- migrations. It runs once as a guarded self-heal — App\Support\ContributorBackfill,
-- invoked from MaintenanceService::runAll() (cron + admin login) — idempotent via a
-- system_settings marker. This file only performs the schema change.
--
-- Idempotent (project rule 6): the MODIFY is applied only when 'colorista' is not
-- already present in the column type, guarded via information_schema so re-running
-- the migration is a no-op and it stays portable to MariaDB.

SET @has_colorista = (
    SELECT LOCATE('colorista', COLUMN_TYPE)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri_autori'
      AND COLUMN_NAME = 'ruolo'
);

SET @sql = IF(
    COALESCE(@has_colorista, 0) = 0,
    "ALTER TABLE `libri_autori` MODIFY `ruolo` enum('principale','co-autore','traduttore','illustratore','curatore','colorista') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'migration 0.7.36: colorista role already present' AS note"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
