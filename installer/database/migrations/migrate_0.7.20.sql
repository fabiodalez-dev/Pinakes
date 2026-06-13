-- Migration 0.7.20 — Issue #163: author photo + relevant source/website links
--
-- Adds two nullable columns to `autori`:
--   foto         — author photo: an uploaded file path (/uploads/autori/...) OR
--                  an external image URL.
--   collegamenti — JSON array of relevant sources/websites, each entry
--                  { "etichetta": "<label>", "url": "<https url>" }.
--
-- Idempotent: every ALTER is guarded by an information_schema check
-- (information_schema + PREPARE/EXECUTE), so the migration is safe to re-run.

-- ─── autori.foto ─────────────────────────────────────────────────────
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'autori' AND COLUMN_NAME = 'foto');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `autori` ADD COLUMN `foto` VARCHAR(500) NULL AFTER `sito_web`",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── autori.collegamenti ─────────────────────────────────────────────
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'autori' AND COLUMN_NAME = 'collegamenti');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `autori` ADD COLUMN `collegamenti` JSON NULL AFTER `foto`",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
