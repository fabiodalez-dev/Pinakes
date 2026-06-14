-- Migration 0.7.20 — consolidated release migration
--
-- This single file carries ALL schema/data changes shipped in 0.7.20, from two
-- independent work streams that both target this release:
--   A) Issue #163 — author photo + relevant source/website links (autori columns)
--   B) fix/loan-state-bugs — loan/reservation state-model unification
--
-- One file per release version is mandatory: the updater runs a migration iff its
-- version is > the installed version AND <= the target version, so every 0.7.20
-- change must live here (CLAUDE.md rule 6). Every statement is idempotent.
--
-- NOTE (section B): the loan-integrity triggers (incl. the I7 copy/book guard) are
-- NOT shipped here — the updater re-applies installer/database/triggers.sql with
-- its DELIMITER-aware splitter after migrations (Updater::reapplyTriggers), so
-- upgraded installs pick up the trigger change automatically.

-- ═══════════════════════════════════════════════════════════════════════
-- SECTION A — Issue #163: author photo + relevant source/website links
-- ═══════════════════════════════════════════════════════════════════════
--
-- Adds two nullable columns to `autori`:
--   foto         — author photo: an uploaded file path (/uploads/autori/...) OR
--                  an external image URL.
--   collegamenti — JSON array of relevant sources/websites, each entry
--                  { "etichetta": "<label>", "url": "<https url>" }.
-- Idempotent: each ALTER is guarded by an information_schema check.

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

-- ═══════════════════════════════════════════════════════════════════════
-- SECTION B — fix/loan-state-bugs: loan/reservation state-model unification
-- ═══════════════════════════════════════════════════════════════════════
--
-- Wires the dead `restituito_in_ritardo` column (added to schema.sql) and cleans
-- up historical rows that violate the canonical state invariants:
--   I1  CLOSED  ⇒ attivo=0
--   I4  in_ritardo ⇒ attivo=1 ALWAYS (a returned-late loan is restituito + flag, never in_ritardo)
--   I8  stato='completato' is forbidden (success = restituito)

-- ─── B1A. restituito_in_ritardo column (fresh installs already have it via
--          schema.sql; upgraded installs do not). information_schema-guarded so
--          it is portable across MySQL 8 (no ADD COLUMN IF NOT EXISTS) and MariaDB.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'restituito_in_ritardo');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `prestiti` ADD COLUMN `restituito_in_ritardo` tinyint(1) NOT NULL DEFAULT 0 AFTER `data_restituzione`",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── B1B. Backfill the returned-late flag from historical returns.
UPDATE `prestiti`
   SET `restituito_in_ritardo` = 1
 WHERE `stato` = 'restituito'
   AND `data_restituzione` IS NOT NULL
   AND `data_scadenza` IS NOT NULL
   AND `data_restituzione` > `data_scadenza`
   AND `restituito_in_ritardo` = 0;

-- ─── B1C. Normalize the legacy "returned late" overload: rows closed as
--          stato='in_ritardo' with attivo=0 become restituito + flag (I4).
UPDATE `prestiti`
   SET `restituito_in_ritardo` = 1,
       `stato` = 'restituito'
 WHERE `attivo` = 0
   AND `stato` = 'in_ritardo';

-- ─── B1D. Normalize any invalid stato (e.g. 'completato' written by the old
--          CopyController bug, or '' coerced on non-strict installs) for closed rows (I8).
UPDATE `prestiti`
   SET `stato` = 'restituito',
       `data_restituzione` = COALESCE(`data_restituzione`, CURDATE())
 WHERE `attivo` = 0
   AND `stato` NOT IN ('pendente','prenotato','da_ritirare','in_corso','restituito',
                       'in_ritardo','perso','danneggiato','annullato','scaduto');

-- ─── B1E. Resurrected returns: closed loans wrongly left attivo=1 (I1 / BUG1).
--          Discriminator: data_restituzione present but attivo still 1.
UPDATE `prestiti`
   SET `attivo` = 0,
       `stato`  = CASE WHEN `stato` IN ('restituito','perso','danneggiato','annullato','scaduto')
                       THEN `stato` ELSE 'restituito' END
 WHERE `data_restituzione` IS NOT NULL
   AND `attivo` = 1;

-- ─── B1F. Dead-period holds stuck 'prenotato' whose whole window is already past
--          (BUG8): mark scaduto + inactive so they stop occupying capacity.
UPDATE `prestiti`
   SET `stato` = 'scaduto',
       `attivo` = 0
 WHERE `attivo` = 1
   AND `stato` = 'prenotato'
   AND `data_scadenza` < CURDATE();

-- NOTE (section B): steps B1E/B1F change occupancy. The updater runs a full
-- availability recalc after migrations (Updater::recalculateAfterMigrations) so
-- copie.stato and libri.copie_disponibili/stato are re-derived for every book.
