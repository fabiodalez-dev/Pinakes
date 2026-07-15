-- Migration 0.7.36 — contributor roles: add 'colorista' to libri_autori.ruolo
--
-- Issue #237: illustrator/translator/curator/colorist become first-class author
-- entities via libri_autori.ruolo (previously the form wrote every author as
-- 'principale' and kept illustrator/translator/curator as free-text columns on
-- `libri`). This adds the missing 'colorista' value to the role enum.
--
-- The free-text-to-entity BACKFILL of existing libri.illustratore/traduttore/
-- curatore values needs row logic (split + canonical find-or-create), so the
-- migration runners invoke App\Support\ContributorBackfill after this SQL.
-- MaintenanceService remains an idempotent safety net for interrupted upgrades.
--
-- CSV and LibraryThing reimports also need to replace only links they created,
-- while preserving manual role links. The provenance table below records that
-- ownership separately from libri_autori's public role contract.
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

CREATE TABLE IF NOT EXISTS `libri_autori_import_sources` (
    `libro_id` int NOT NULL,
    `autore_id` int NOT NULL,
    `ruolo` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
    `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`libro_id`, `autore_id`, `ruolo`, `source`),
    KEY `idx_lais_autore` (`autore_id`),
    CONSTRAINT `libri_autori_import_sources_book_fk`
        FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
    CONSTRAINT `libri_autori_import_sources_author_fk`
        FOREIGN KEY (`autore_id`) REFERENCES `autori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
