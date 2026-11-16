-- Pinakes 0.9.6.

-- api_keys: store only a SHA-256 of each public API key plus a short display
-- prefix. The full key is shown once at creation and never again; a database or
-- backup read no longer yields a key usable against /api/public.
-- Existing keys keep working: their hash and prefix are backfilled from the
-- plaintext, then the plaintext is cleared. The api_key column stays (nullable,
-- never written again) for one release so a rollback keeps a valid schema.
-- Additive and idempotent on MySQL and MariaDB.
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

-- The old lookup compared keys under a case-insensitive collation, so the hash
-- is taken over the lowercase form (ApiKeyRepository lowercases a presented key
-- before hashing it too). Generated keys are lowercase hex already.
UPDATE api_keys SET key_hash = SHA2(LOWER(api_key), 256), key_prefix = LEFT(LOWER(api_key), 8) WHERE key_hash IS NULL AND api_key IS NOT NULL AND api_key <> '';

UPDATE api_keys SET api_key = NULL WHERE key_hash IS NOT NULL AND api_key IS NOT NULL;
