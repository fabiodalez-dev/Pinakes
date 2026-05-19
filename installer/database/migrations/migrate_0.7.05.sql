-- Pinakes v0.7.5 — French locale (fr_FR) + i18n LibraryThing fixes
-- Ensures fr_FR language row exists AND is active for installs upgrading from 0.7.4.
-- New installs get fr_FR via data_XX.sql seeder.
-- ON DUPLICATE KEY UPDATE activates fr_FR even if a pre-existing row has is_active=0.

INSERT INTO `languages`
    (`code`, `name`, `native_name`, `flag_emoji`, `is_default`, `is_active`, `translation_file`, `total_keys`, `translated_keys`, `completion_percentage`)
VALUES
    ('fr_FR', 'French', 'Français', '🇫🇷', 0, 1, 'locale/fr_FR.json', 4145, 4145, 100.00)
ON DUPLICATE KEY UPDATE
    `is_active`             = 1,
    `translation_file`      = 'locale/fr_FR.json',
    `total_keys`            = 4145,
    `translated_keys`       = 4145,
    `completion_percentage` = 100.00;
