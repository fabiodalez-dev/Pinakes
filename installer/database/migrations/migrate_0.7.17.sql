-- Migration 0.7.17 — loan-system configurable settings.
--
-- Adds three new admin-configurable parameters to system_settings
-- (category 'loans') that control default loan duration, maximum active
-- loans per user, and maximum number of renewals.
--
-- All INSERT statements use INSERT IGNORE so the migration is idempotent and
-- safe to re-run on installations that already have the rows (e.g. fresh
-- installs seeded from data_*.sql).
--
-- Descriptions are English (the neutral default): unlike fresh installs, which
-- seed locale-specific descriptions from data_<locale>.sql, an upgrade only runs
-- this migration regardless of the instance locale. The `description` column is
-- internal metadata — the loans settings UI renders localized labels via __(),
-- not this text — so English here keeps en/de/fr upgrades consistent instead of
-- leaving Italian descriptions on non-Italian instances.

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'loan_duration_days', '30', 'Default loan duration in days');

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'max_active_loans_per_user', '0', 'Maximum active loans per user (0 = no limit)');

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'max_renewals', '3', 'Maximum number of renewals allowed per loan');

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'pickup_expiry_days', '3', 'Days to pick up an approved loan before it expires');

-- Trigger di integrità prestito (overlap copia, modello #157).
--
-- IMPORTANTE: un upgrade applica QUESTA migrazione con il codice della versione
-- DI PARTENZA (in memoria nello stesso request). Il runner delle migrazioni
-- delle versioni < 0.7.17 splitta lo SQL su ';' e NON gestisce i blocchi
-- DELIMITER / i corpi BEGIN…END dei trigger: ricrearli qui con DELIMITER
-- ROMPEREBBE l'aggiornamento con un errore di sintassi (regressione confermata
-- dal reinstall-test 0.7.16 → 0.7.17).
--
-- Quindi qui ci limitiamo a RIMUOVERE i trigger nella loro definizione
-- precedente (incompatibile col modello #157: vecchia occupazione basata solo
-- su attivo=1, senza 'da_ritirare' né pending-con-copia). DROP è uno statement
-- semplice, eseguibile da qualsiasi runner. La correttezza è garantita a
-- livello applicativo (lock FOR UPDATE + predicato di occupazione unificato in
-- tutti gli entry-point). I trigger nella definizione corrente vengono applicati
-- dalla fresh install via installer/database/triggers.sql e ri-applicati dal
-- passo post-migrazione dell'Updater (>= 0.7.17), che usa un parser
-- DELIMITER-aware.
DROP TRIGGER IF EXISTS `trg_check_active_prestito_before_insert`;
DROP TRIGGER IF EXISTS `trg_check_active_prestito_before_update`;
