-- 0.7.84: preserve the workflow of installations upgrading to Emeroteca 1.5.
-- The plugin owns its tables: ensureSchema() creates emeroteca_contributi on
-- activation and boot-time self-heal, including when the plugin was inactive.
-- INSERT IGNORE preserves an explicit choice and makes retries harmless.
--
-- Only an installation that ALREADY HAS a collection is stamped. A plugins row
-- means the plugin is bundled, not that it was ever used: emeroteca ships
-- inactive, so most installations carry the row with no tables behind it.
-- Stamping those would decide the initial workflow behind the operator's back
-- and win the INSERT IGNORE race against their own first choice. Leaving them
-- unstamped is what lets the admin pick the initial state themselves.
INSERT IGNORE INTO plugin_settings (plugin_id, setting_key, setting_value)
SELECT p.id, 'mode', 'complete'
FROM plugins p
WHERE p.name = 'emeroteca'
  AND EXISTS (
    SELECT 1 FROM information_schema.TABLES t
    WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'emeroteca_testate'
  );
