-- Pinakes v0.7.4 — MAG digital_assets table + NCIP partner/transactions schema additions
-- digital_assets: per-book digitization metadata (url, md5_hash, filesize, dimensions, ppi)
-- ncip_partners: add isil and notes columns (standard ILS partner attributes)
-- ncip_transactions: align columns with plugin schema (partner_id, prestito_id, status, error_msg)

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

-- ncip_transactions: upgrade path — add columns introduced in v0.7.4.
-- The old schema (from ≤v0.7.3) had only: id, message_type, related_loan_id, request_id, created_at.
-- The plugin's ensureSchema() creates the full schema, but on upgrades the table already exists so
-- CREATE TABLE IF NOT EXISTS is a no-op. We add the missing columns and drop the old one here.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'partner_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ncip_transactions ADD COLUMN partner_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'prestito_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ncip_transactions ADD COLUMN prestito_id INT NULL AFTER message_type',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'status'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE ncip_transactions ADD COLUMN status ENUM('pending','success','error') NOT NULL DEFAULT 'pending' AFTER request_id",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'error_msg'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE ncip_transactions ADD COLUMN error_msg VARCHAR(1000) NULL AFTER status',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Backfill prestito_id from related_loan_id before dropping to preserve historical references.
SET @src_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'related_loan_id'
);
SET @dst_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'prestito_id'
);
SET @sql = IF(
    @src_exists > 0 AND @dst_exists > 0,
    'UPDATE ncip_transactions SET prestito_id = related_loan_id WHERE prestito_id IS NULL AND related_loan_id IS NOT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Drop legacy column (safe: related_loan_id is not referenced in plugin code from v0.7.4 onward).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_transactions'
      AND COLUMN_NAME  = 'related_loan_id'
);
SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE ncip_transactions DROP COLUMN related_loan_id',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─── Self-contained upgrade for installs at v0.7.3 ───────────────────────────
-- migrate_0.7.3.sql is skipped when upgrading FROM exactly v0.7.3 (updater lower-bound
-- check). The prestiti schema changes and plugin registration below are idempotent.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND COLUMN_NAME  = 'ncip_request_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE prestiti ADD COLUMN ncip_request_id VARCHAR(255) NULL DEFAULT NULL AFTER origine',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND INDEX_NAME   = 'idx_prestiti_ncip_request_id'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE prestiti ADD KEY idx_prestiti_ncip_request_id (ncip_request_id)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @origin_has_ncip = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND COLUMN_NAME  = 'origine'
      AND COLUMN_TYPE LIKE '%ncip%'
);
SET @sql = IF(
    @origin_has_ncip = 0,
    "ALTER TABLE prestiti MODIFY COLUMN origine ENUM('richiesta','prenotazione','diretto','ncip') COLLATE utf8mb4_unicode_ci DEFAULT 'richiesta'",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─── Plugin registrations (idempotent via ON DUPLICATE KEY UPDATE) ────────────
-- Included here so upgrades skipping intermediate 0.7.x migrations still register
-- all plugins introduced in this release cycle.

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('ncip-server',
     'NCIP 2.0 Server',
     'Implementa il protocollo NISO Circulation Interchange Protocol (NCIP) 2.0 per lo scambio di informazioni sui prestiti con ILS, self-service kiosk e sistemi di rete bibliotecaria.',
     '1.0.0', 'Fabiodalez', '',
     'https://www.niso.org/standards-committees/ncip',
     0, 'ncip-server', 'wrapper.php', '8.1', '0.7.3',
     '{"category":"protocol","tags":["ncip","ils","circulation","interoperability","niso"],"optional":true,"status":"stable"}',
     NOW())
ON DUPLICATE KEY UPDATE
    display_name  = VALUES(display_name),
    description   = VALUES(description),
    version       = VALUES(version),
    plugin_url    = VALUES(plugin_url),
    path          = VALUES(path),
    main_file     = VALUES(main_file),
    requires_php  = VALUES(requires_php),
    requires_app  = VALUES(requires_app),
    metadata      = VALUES(metadata);

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('openurl-resolver',
     'OpenURL Z39.88 Resolver',
     'Implementa il protocollo OpenURL Z39.88-2004 con resolver /openurl e metadati COinS embedded nelle pagine libro per l''integrazione con Zotero, Mendeley e altri reference manager.',
     '1.0.0', 'Fabiodalez', '',
     'https://www.niso.org/standards-committees/openurl',
     0, 'openurl-resolver', 'wrapper.php', '8.1', '0.7.2',
     '{"category":"protocol","tags":["openurl","z3988","coins","zotero","reference-manager","interoperability"],"optional":true,"status":"stable"}',
     NOW())
ON DUPLICATE KEY UPDATE
    display_name  = VALUES(display_name),
    description   = VALUES(description),
    version       = VALUES(version),
    plugin_url    = VALUES(plugin_url),
    path          = VALUES(path),
    main_file     = VALUES(main_file),
    requires_php  = VALUES(requires_php),
    requires_app  = VALUES(requires_app),
    metadata      = VALUES(metadata);

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('bibframe-linked-data',
     'BIBFRAME 2.0 Linked Data',
     'Espone il catalogo libri come Linked Data in formato BIBFRAME 2.0 (JSON-LD e Turtle). Endpoint /api/bibframe/book/{id} con content negotiation.',
     '1.0.0', 'Fabiodalez', '',
     'http://id.loc.gov/ontologies/bibframe/',
     0, 'bibframe-linked-data', 'wrapper.php', '8.1', '0.7.1',
     '{"category":"linkeddata","tags":["bibframe","linked-data","rdf","json-ld","turtle","lod"],"optional":true,"status":"stable"}',
     NOW())
ON DUPLICATE KEY UPDATE
    display_name  = VALUES(display_name),
    description   = VALUES(description),
    version       = VALUES(version),
    plugin_url    = VALUES(plugin_url),
    path          = VALUES(path),
    main_file     = VALUES(main_file),
    requires_php  = VALUES(requires_php),
    requires_app  = VALUES(requires_app),
    metadata      = VALUES(metadata);

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('resource-sync',
     'ResourceSync',
     'Implementa il protocollo ResourceSync (ANSI/NISO Z39.99-2014) per la sincronizzazione del catalogo con harvester nazionali.',
     '1.0.0', 'Fabiodalez', '',
     'http://www.openarchives.org/rs/toc/',
     0, 'resource-sync', 'wrapper.php', '8.1', '0.7.1',
     '{"category":"protocol","tags":["resourcesync","harvesting","synchronization","interoperability"],"optional":true,"status":"stable"}',
     NOW())
ON DUPLICATE KEY UPDATE
    display_name  = VALUES(display_name),
    description   = VALUES(description),
    version       = VALUES(version),
    plugin_url    = VALUES(plugin_url),
    path          = VALUES(path),
    main_file     = VALUES(main_file),
    requires_php  = VALUES(requires_php),
    requires_app  = VALUES(requires_app),
    metadata      = VALUES(metadata);
