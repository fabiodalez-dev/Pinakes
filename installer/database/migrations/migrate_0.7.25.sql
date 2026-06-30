-- Migration 0.7.25 — book-form field-type fixes
--
-- (A) `libri.tipo_acquisizione` ENUM → free-text VARCHAR(50).
-- (B) `libri.stato` ENUM gains 'non_disponibile' so the availability engine can
--     label a book whose copies are all out of circulation (in repair / lost /
--     damaged / transfer) instead of leaving the derived flag stale.
--
-- The book form renders `tipo_acquisizione` as a free-text input whose
-- placeholder invites values like "Prestito" — but the column was
-- enum('acquisto','donazione'), so anything outside those two was silently
-- coerced to the 'acquisto' default on save (the user's input was lost).
--
-- Widen the column to VARCHAR(50) so it stores what the form accepts. Existing
-- 'acquisto'/'donazione' rows are preserved verbatim (an ENUM→VARCHAR widening
-- copies the string labels), the DEFAULT and NULL-ability are kept, so no
-- existing install is broken. Idempotent: the ALTER only runs while the column
-- is still an ENUM, so re-running after conversion (or on a fresh install whose
-- schema.sql already ships VARCHAR) is a no-op.
--
-- One file per release version is mandatory (project rule 6): the updater runs a
-- migration iff its version is > the installed version AND <= the target version.

-- ─── libri.tipo_acquisizione: enum → varchar(50) ─────────────────────────
SET @is_enum = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'tipo_acquisizione'
      AND DATA_TYPE = 'enum');
SET @sql = IF(@is_enum = 1,
    "ALTER TABLE `libri` MODIFY COLUMN `tipo_acquisizione` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'acquisto'",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── libri.stato: add 'non_disponibile' to the enum ──────────────────────
-- DataIntegrity::recalculateBookAvailability now derives this value for a book
-- that has copies but none circulating (all in manutenzione/in_restauro/perso/
-- danneggiato/in_trasferimento) and none loaned/reserved. Idempotent: only runs
-- while the value is absent from the column definition.
SET @has_val = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'libri'
      AND COLUMN_NAME = 'stato'
      AND COLUMN_TYPE LIKE '%non_disponibile%');
SET @sql = IF(@has_val = 0,
    "ALTER TABLE `libri` MODIFY COLUMN `stato` enum('disponibile','prestato','prenotato','perso','danneggiato','non_disponibile') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile'",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
