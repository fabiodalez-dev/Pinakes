-- Pinakes v0.7.41 — Danish (da_DK) language registration
--
-- Registers the Danish locale in the `languages` table so existing installs
-- that upgrade to v0.7.41 pick up da_DK and expose it in the language UI.
-- Fresh installs already seed da_DK via data_*.sql; this migration covers the
-- upgrade path.
--
-- Idempotent: the row is upserted (ON DUPLICATE KEY UPDATE) to the same
-- canonical values every time, so re-running the migration is safe and simply
-- refreshes the metadata / re-activates the language.
--
-- total_keys/translated_keys mirror the shipped locale/da_DK.json key count
-- (6607 keys, exact parity with locale/it_IT.json).
--
-- See CLAUDE.md "Migration file version MUST be ≤ release version": this file
-- runs only once version.json reaches 0.7.41 (updater compares
-- migrationVersion <= toVersion).

INSERT INTO `languages` (`code`, `name`, `native_name`, `flag_emoji`, `is_default`, `is_active`, `translation_file`, `total_keys`, `translated_keys`, `completion_percentage`, `created_at`, `updated_at`)
VALUES ('da_DK', 'Danish', 'Dansk', '🇩🇰', 0, 1, 'locale/da_DK.json', 6607, 6607, 100.00, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    is_active = 1,
    name = VALUES(name),
    native_name = VALUES(native_name),
    flag_emoji = VALUES(flag_emoji),
    translation_file = VALUES(translation_file),
    total_keys = VALUES(total_keys),
    translated_keys = VALUES(translated_keys),
    completion_percentage = VALUES(completion_percentage),
    updated_at = NOW();
