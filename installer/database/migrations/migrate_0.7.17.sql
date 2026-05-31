-- Migration 0.7.17 — loan-system configurable settings.
--
-- Adds three new admin-configurable parameters to system_settings
-- (category 'loans') that control default loan duration, maximum active
-- loans per user, and maximum number of renewals.
--
-- All three INSERT statements use INSERT IGNORE so the migration is
-- idempotent and safe to re-run on installations that already have
-- the rows (e.g. fresh installs seeded from data_*.sql).

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'loan_duration_days', '30', 'Durata predefinita di un prestito in giorni');

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'max_active_loans_per_user', '0', 'Numero massimo di prestiti attivi per utente (0 = nessun limite)');

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('loans', 'max_renewals', '3', 'Numero massimo di rinnovi consentiti per prestito');
