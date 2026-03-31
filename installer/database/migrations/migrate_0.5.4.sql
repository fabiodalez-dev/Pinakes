-- Add tipo_media column to libri table
-- FULLY IDEMPOTENT

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND COLUMN_NAME = 'tipo_media');
SET @formato_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'libri'
                         AND COLUMN_NAME = 'formato');
SET @sql = IF(@col_exists = 0,
    IF(@formato_exists > 0,
        "ALTER TABLE libri ADD COLUMN tipo_media ENUM('libro','disco','audiolibro','dvd','altro') NOT NULL DEFAULT 'libro' AFTER formato",
        "ALTER TABLE libri ADD COLUMN tipo_media ENUM('libro','disco','audiolibro','dvd','altro') NOT NULL DEFAULT 'libro'"),
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Composite index for media filtering + soft delete
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND INDEX_NAME = 'idx_libri_tipo_media_deleted_at');
SET @idx_order_ok = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'libri'
                       AND INDEX_NAME = 'idx_libri_tipo_media_deleted_at'
                       AND ((SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'deleted_at')
                         OR (SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'tipo_media')));
SET @sql = IF(@idx_exists > 0 AND @idx_order_ok <> 2,
    'ALTER TABLE libri DROP INDEX idx_libri_tipo_media_deleted_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'libri'
                     AND INDEX_NAME = 'idx_libri_tipo_media_deleted_at');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE libri ADD INDEX idx_libri_tipo_media_deleted_at (deleted_at, tipo_media)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @old_tipo_idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'libri'
                              AND INDEX_NAME = 'idx_libri_tipo_media');
SET @sql = IF(@old_tipo_idx_exists > 0,
    'ALTER TABLE libri DROP INDEX idx_libri_tipo_media',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @old_deleted_idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'libri'
                                 AND INDEX_NAME = 'idx_libri_deleted_at');
SET @sql = IF(@old_deleted_idx_exists > 0,
    'ALTER TABLE libri DROP INDEX idx_libri_deleted_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Auto-populate from existing formato values (LIKE for partial matches)
UPDATE libri SET tipo_media = 'disco'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%cd%' OR LOWER(formato) LIKE '%compact disc%'
       OR LOWER(formato) LIKE '%vinyl%' OR LOWER(formato) LIKE '%vinile%'
       OR LOWER(formato) LIKE '%lp%' OR LOWER(formato) LIKE '%cassett%'
       OR LOWER(formato) LIKE '%audio cassetta%' OR LOWER(formato) LIKE '%audio-cassetta%'
       OR LOWER(formato) LIKE '%audiocassetta%'
       OR LOWER(formato) REGEXP '[[:<:]]music[[:>:]]'
       OR LOWER(formato) REGEXP '[[:<:]]musik[[:>:]]')
  AND LOWER(formato) NOT LIKE '%audiolibro%' AND LOWER(formato) NOT LIKE '%audiobook%';

UPDATE libri SET tipo_media = 'audiolibro'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%audiolibro%' OR LOWER(formato) LIKE '%audiobook%' OR LOWER(formato) LIKE '%audio book%');

UPDATE libri SET tipo_media = 'dvd'
WHERE tipo_media = 'libro'
  AND (LOWER(formato) LIKE '%dvd%' OR LOWER(formato) LIKE '%blu-ray%'
       OR LOWER(formato) LIKE '%blu_ray%' OR LOWER(formato) LIKE '%blu ray%'
       OR LOWER(formato) LIKE '%bluray%');
