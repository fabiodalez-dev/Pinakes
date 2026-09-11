-- 0.7.84: preserve the workflow of installations upgrading to Emeroteca 1.5.
-- The plugin owns its tables: ensureSchema() creates emeroteca_contributi on
-- activation and boot-time self-heal, including when the plugin was inactive.
-- INSERT IGNORE preserves an explicit choice and makes retries harmless.
INSERT IGNORE INTO plugin_settings (plugin_id, setting_key, setting_value)
SELECT id, 'mode', 'complete' FROM plugins WHERE name = 'emeroteca';
