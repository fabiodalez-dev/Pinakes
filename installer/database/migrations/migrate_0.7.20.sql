-- Migration 0.7.20 — loan/reservation state-model unification (fix/loan-state-bugs)
--
-- Wires the dead `restituito_in_ritardo` column (added to schema.sql) and cleans
-- up historical rows that violate the canonical state invariants:
--   I1  CLOSED  ⇒ attivo=0
--   I4  in_ritardo ⇒ attivo=1 ALWAYS (a returned-late loan is restituito + flag, never in_ritardo)
--   I8  stato='completato' is forbidden (success = restituito)
--
-- NOTE: the loan-integrity triggers (incl. the new I7 copy/book guard) are NOT
-- shipped here — the updater re-applies installer/database/triggers.sql with its
-- DELIMITER-aware splitter after migrations (Updater::reapplyTriggers), so
-- upgraded installs pick up the trigger change automatically.
--
-- Every statement is idempotent / re-runnable.

-- ─── 1A. restituito_in_ritardo column (fresh installs already have it via
--         schema.sql; upgraded installs do not). information_schema-guarded so
--         it is portable across MySQL 8 (no ADD COLUMN IF NOT EXISTS) and MariaDB.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'restituito_in_ritardo');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `prestiti` ADD COLUMN `restituito_in_ritardo` tinyint(1) NOT NULL DEFAULT 0 AFTER `data_restituzione`",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── 1B. Backfill the returned-late flag from historical returns.
UPDATE `prestiti`
   SET `restituito_in_ritardo` = 1
 WHERE `stato` = 'restituito'
   AND `data_restituzione` IS NOT NULL
   AND `data_scadenza` IS NOT NULL
   AND `data_restituzione` > `data_scadenza`
   AND `restituito_in_ritardo` = 0;

-- ─── 1C. Normalize the legacy "returned late" overload: rows closed as
--         stato='in_ritardo' with attivo=0 become restituito + flag (I4).
UPDATE `prestiti`
   SET `restituito_in_ritardo` = 1,
       `stato` = 'restituito'
 WHERE `attivo` = 0
   AND `stato` = 'in_ritardo';

-- ─── 1D. Normalize any invalid stato (e.g. 'completato' written by the old
--         CopyController bug, or '' coerced on non-strict installs) for closed rows (I8).
UPDATE `prestiti`
   SET `stato` = 'restituito',
       `data_restituzione` = COALESCE(`data_restituzione`, CURDATE())
 WHERE `attivo` = 0
   AND `stato` NOT IN ('pendente','prenotato','da_ritirare','in_corso','restituito',
                       'in_ritardo','perso','danneggiato','annullato','scaduto');

-- ─── 1E. Resurrected returns: closed loans wrongly left attivo=1 (I1 / BUG1).
--         Discriminator: data_restituzione present but attivo still 1.
UPDATE `prestiti`
   SET `attivo` = 0,
       `stato`  = CASE WHEN `stato` IN ('restituito','perso','danneggiato','annullato','scaduto')
                       THEN `stato` ELSE 'restituito' END
 WHERE `data_restituzione` IS NOT NULL
   AND `attivo` = 1;

-- ─── 1F. Dead-period holds stuck 'prenotato' whose whole window is already past
--         (BUG8): mark scaduto + inactive so they stop occupying capacity.
UPDATE `prestiti`
   SET `stato` = 'scaduto',
       `attivo` = 0
 WHERE `attivo` = 1
   AND `stato` = 'prenotato'
   AND `data_scadenza` < CURDATE();

-- NOTE: steps 1E/1F change occupancy. The updater runs a full availability
-- recalc after migrations (Updater::recalculateAfterMigrations) so copie.stato
-- and libri.copie_disponibili/stato are re-derived for every affected book.
