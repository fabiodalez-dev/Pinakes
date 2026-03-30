-- Add tipo_media column to libri table
-- FULLY IDEMPOTENT

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND COLUMN_NAME = 'tipo_media');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE libri ADD COLUMN tipo_media ENUM('libro','disco','audiolibro','dvd','altro') NOT NULL DEFAULT 'libro' AFTER formato",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for filtering
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND INDEX_NAME = 'idx_libri_tipo_media');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE libri ADD INDEX idx_libri_tipo_media (tipo_media)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Auto-populate from existing formato values
UPDATE libri SET tipo_media = 'disco'
WHERE tipo_media = 'libro'
  AND LOWER(formato) IN ('cd_audio','vinile','lp','cassetta','vinyl','cd','cassette','audiocassetta');

UPDATE libri SET tipo_media = 'audiolibro'
WHERE tipo_media = 'libro'
  AND LOWER(formato) IN ('audiolibro','audiobook');

UPDATE libri SET tipo_media = 'dvd'
WHERE tipo_media = 'libro'
  AND LOWER(formato) IN ('dvd','blu-ray','blu_ray');
