-- 0.7.84: preserve the workflow of installations upgrading to Emeroteca 1.5.
-- The plugin owns its tables: ensureSchema() creates emeroteca_contributi on
-- activation and boot-time self-heal, including when the plugin was inactive.
-- INSERT IGNORE preserves an explicit choice and makes retries harmless.
--
-- Only an installation that already HAS A COLLECTION is stamped, and a
-- collection means mastheads, not tables. A plugins row only means the plugin
-- is bundled: emeroteca ships inactive, so most installations carry the row
-- with nothing behind it. And the tables alone prove nothing either: when a
-- bundled plugin is auto-registered, onInstall() runs even for an optional,
-- inactive one, which builds every table EMPTY. Stamping on either would decide
-- the initial workflow behind the operator's back and win the INSERT IGNORE race
-- against their own first choice. The table has to be probed through dynamic SQL
-- because naming a table that does not exist fails the whole statement.
SET @emeroteca_has_table = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_testate');
SET @sql = IF(@emeroteca_has_table > 0,
    'SELECT COUNT(*) INTO @emeroteca_has_masthead FROM (SELECT 1 FROM emeroteca_testate LIMIT 1) AS probe',
    'SELECT 0 INTO @emeroteca_has_masthead');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
INSERT IGNORE INTO plugin_settings (plugin_id, setting_key, setting_value)
SELECT p.id, 'mode', 'complete'
FROM plugins p
WHERE p.name = 'emeroteca' AND @emeroteca_has_masthead > 0;
