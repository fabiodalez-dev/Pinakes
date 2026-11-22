-- Pinakes 0.9.6.

-- Prepare api_keys for SHA-256 hashes and display prefixes. This upgrade step
-- preserves credential data so the updater can still roll back the application
-- files if installation fails after the schema migration. The new application
-- converts the keys after installation, on API use or API settings access.
-- Downgrades after conversion need the pre-upgrade database backup.
CREATE TABLE IF NOT EXISTS api_keys (
  id int NOT NULL AUTO_INCREMENT,
  api_key varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  key_hash char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  key_prefix varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  name varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  description text COLLATE utf8mb4_unicode_ci,
  is_active tinyint(1) DEFAULT '1',
  last_used_at timestamp NULL DEFAULT NULL,
  created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY api_key (api_key),
  UNIQUE KEY uniq_key_hash (key_hash),
  KEY idx_api_key (api_key),
  KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'key_hash');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE api_keys ADD COLUMN key_hash char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER api_key', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'key_prefix');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE api_keys ADD COLUMN key_prefix varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER key_hash', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @not_null = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'api_key' AND IS_NULLABLE = 'NO');
SET @sql = IF(@not_null > 0, 'ALTER TABLE api_keys MODIFY COLUMN api_key varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND INDEX_NAME = 'uniq_key_hash');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE api_keys ADD UNIQUE KEY uniq_key_hash (key_hash)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pinakes 0.9.6: film cataloguing columns (#539).

-- Running time, cast, subtitles, age rating and region code for films on
-- disc. Fresh installs get them from schema.sql; each ADD COLUMN is guarded
-- so a database that SchemaRepair already patched is left untouched.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'durata_minuti');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `durata_minuti` smallint unsigned DEFAULT NULL COMMENT ''Running time in minutes (film/video, MARC 306)'' AFTER `rda_carrier_type`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'interpreti');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `interpreti` text COLLATE utf8mb4_unicode_ci COMMENT ''Cast / performers (film/video, MARC 511)'' AFTER `durata_minuti`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'sottotitoli');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `sottotitoli` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Subtitle languages (film/video, MARC 546)'' AFTER `interpreti`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'classificazione_eta');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `classificazione_eta` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Age rating as printed (film/video, MARC 521)'' AFTER `sottotitoli`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'codice_regione');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `codice_regione` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''DVD/Blu-ray region code (MARC 538)'' AFTER `classificazione_eta`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
