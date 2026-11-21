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
