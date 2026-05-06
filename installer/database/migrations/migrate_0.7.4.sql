-- Pinakes v0.7.4 — MAG digital_assets table + NCIP partner schema additions
-- digital_assets: per-book digitization metadata (url, md5_hash, filesize, dimensions, ppi)
-- ncip_partners: add isil and notes columns (standard ILS partner attributes)

CREATE TABLE IF NOT EXISTS digital_assets (
    id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    libro_id     INT               NOT NULL,
    url          VARCHAR(500)      NOT NULL,
    md5_hash     CHAR(32)          NOT NULL DEFAULT '',
    filesize     BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    image_width  INT UNSIGNED      NOT NULL DEFAULT 0,
    image_height INT UNSIGNED      NOT NULL DEFAULT 0,
    ppi          SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    filetype     VARCHAR(32)       NOT NULL DEFAULT 'PDF',
    created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_libro_id (libro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ncip_partners: create if it does not exist yet (fresh-install path);
-- upgrade path: the ALTER TABLE statements below add columns that may be missing.
CREATE TABLE IF NOT EXISTS ncip_partners (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(64)   NULL DEFAULT NULL,
    name         VARCHAR(255)  NOT NULL,
    agency_id    VARCHAR(255)  NULL,
    endpoint_url VARCHAR(500)  NULL,
    isil         VARCHAR(64)   NULL,
    notes        TEXT          NULL,
    active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code (code),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade path: add columns if missing.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_partners'
      AND COLUMN_NAME  = 'isil'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ncip_partners ADD COLUMN isil VARCHAR(64) NULL AFTER endpoint_url',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_partners'
      AND COLUMN_NAME  = 'notes'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ncip_partners ADD COLUMN notes TEXT NULL AFTER isil',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

ALTER TABLE ncip_partners MODIFY COLUMN code VARCHAR(64) NULL DEFAULT NULL;
