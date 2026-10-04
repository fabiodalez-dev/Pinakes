-- Place of publication for books (#412: Chicago and Harvard cite it). Idempotent upgrade.
SET @place_column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='libri' AND COLUMN_NAME='luogo_pubblicazione');
SET @place_column_sql = IF(@place_column_exists=0,
 'ALTER TABLE libri ADD COLUMN luogo_pubblicazione VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL COMMENT ''Place of publication as printed (MARC 264 $a)'' AFTER edizione',
 'SELECT 1');
PREPARE place_column_stmt FROM @place_column_sql;
EXECUTE place_column_stmt;
DEALLOCATE PREPARE place_column_stmt;
