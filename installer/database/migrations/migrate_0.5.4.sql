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

-- Auto-populate from existing formato values (LIKE for partial matches)
UPDATE libri SET tipo_media = 'disco'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%cd%' OR LOWER(formato) LIKE '%vinyl%' OR LOWER(formato) LIKE '%vinile%'
       OR LOWER(formato) LIKE '%lp%' OR LOWER(formato) LIKE '%cassett%' OR LOWER(formato) LIKE '%audiocassetta%')
  AND LOWER(formato) NOT LIKE '%audiolibro%' AND LOWER(formato) NOT LIKE '%audiobook%';

UPDATE libri SET tipo_media = 'audiolibro'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%audiolibro%' OR LOWER(formato) LIKE '%audiobook%');

UPDATE libri SET tipo_media = 'dvd'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%dvd%' OR LOWER(formato) LIKE '%blu-ray%' OR LOWER(formato) LIKE '%blu_ray%');
