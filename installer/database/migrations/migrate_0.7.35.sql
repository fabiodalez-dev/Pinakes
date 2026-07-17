-- Migration 0.7.35 — add Telegram social link setting.
--
-- Existing installations upgraded to 0.7.35 must get app.social_telegram
-- in system_settings so the new admin/footer integration can persist/read it.
-- INSERT IGNORE keeps this idempotent on reruns and on fresh installs seeded
-- from installer/database/data_*.sql.

INSERT IGNORE INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES
('app', 'social_telegram', '', 'Telegram profile URL');
