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
    INDEX idx_libro_id (libro_id),
    CONSTRAINT fk_digital_assets_libro
        FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add FK on existing digital_assets table (upgrade path: table already created without FK).
-- Check by column semantics (not constraint name) to handle fresh-install vs upgrade paths.
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'digital_assets'
      AND COLUMN_NAME = 'libro_id'
      AND REFERENCED_TABLE_NAME = 'libri'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE digital_assets ADD CONSTRAINT fk_digital_assets_libro
     FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mag_project_config: align legacy/fresh schemas with OaiPmhServerPlugin.
CREATE TABLE IF NOT EXISTS mag_project_config (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code     VARCHAR(64) NOT NULL DEFAULT 'PINAKES',
    institution_code VARCHAR(16) NOT NULL DEFAULT 'IT',
    collection_name  VARCHAR(255) NOT NULL DEFAULT 'Biblioteca Pinakes',
    rights_statement VARCHAR(500) NOT NULL DEFAULT 'In Copyright',
    base_url         VARCHAR(500) NOT NULL DEFAULT '',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_code (project_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND COLUMN_NAME  = 'institution_code'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE mag_project_config ADD COLUMN institution_code VARCHAR(16) NOT NULL DEFAULT 'IT' AFTER project_code",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND COLUMN_NAME  = 'collection_name'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE mag_project_config ADD COLUMN collection_name VARCHAR(255) NOT NULL DEFAULT 'Biblioteca Pinakes' AFTER institution_code",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @legacy_collection_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND COLUMN_NAME  = 'collection'
);
SET @sql = IF(
    @legacy_collection_exists > 0,
    "UPDATE mag_project_config SET collection_name = collection WHERE collection_name IN ('', 'Biblioteca Pinakes') AND collection <> ''",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND COLUMN_NAME  = 'rights_statement'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE mag_project_config ADD COLUMN rights_statement VARCHAR(500) NOT NULL DEFAULT 'In Copyright' AFTER collection_name",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND COLUMN_NAME  = 'base_url'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE mag_project_config ADD COLUMN base_url VARCHAR(500) NOT NULL DEFAULT '' AFTER rights_statement",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mag_project_config'
      AND INDEX_NAME   = 'uq_project_code'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE mag_project_config ADD UNIQUE KEY uq_project_code (project_code)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

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

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ncip_partners'
      AND COLUMN_NAME  = 'code'
);
SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE ncip_partners MODIFY COLUMN code VARCHAR(64) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Ensure ncip_transactions exists for fresh installs or installs upgrading from ≤0.7.3
-- that skip migrate_0.7.3.sql. The ALTER TABLE guards below are safe no-ops when
-- all columns already exist.
CREATE TABLE IF NOT EXISTS ncip_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    partner_id     INT NULL,
    message_type   VARCHAR(64) NOT NULL,
    prestito_id    INT NULL,
    request_id     VARCHAR(255) NULL,
    status         ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
    error_msg      VARCHAR(1000) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_message_type (message_type),
    KEY idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ncip_transactions: upgrade path — add columns introduced in v0.7.4.
-- The CREATE TABLE above handles fresh installs and 0.7.3 upgrades (where migrate_0.7.3.sql was
-- skipped). On installs that already ran migrate_0.7.3.sql, the table exists with the old schema
-- (id, message_type, related_loan_id, request_id, created_at) — the ALTER TABLE guards below
-- add new columns and drop the legacy one idempotently.
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

-- Add FK prestito_id → prestiti(id) ON DELETE SET NULL if not yet present.
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA           = DATABASE()
      AND TABLE_NAME             = 'ncip_transactions'
      AND COLUMN_NAME            = 'prestito_id'
      AND REFERENCED_TABLE_NAME  = 'prestiti'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE ncip_transactions ADD CONSTRAINT ncip_transactions_ibfk_2 FOREIGN KEY (prestito_id) REFERENCES prestiti (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ─── Self-contained upgrade for installs at v0.7.3 ───────────────────────────
-- migrate_0.7.3.sql is skipped when upgrading FROM exactly v0.7.3 (updater lower-bound
-- check). The prestiti schema changes and plugin registration below are idempotent.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'viaf_uri'
);
SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE autori MODIFY COLUMN viaf_uri VARCHAR(500) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'isni_uri'
);
SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE autori MODIFY COLUMN isni_uri VARCHAR(500) DEFAULT NULL',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

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

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND COLUMN_NAME  = 'origine'
);
SET @origin_has_ncip = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND COLUMN_NAME  = 'origine'
      AND COLUMN_TYPE LIKE '%ncip%'
);
SET @sql = IF(
    @col_exists > 0 AND @origin_has_ncip = 0,
    "ALTER TABLE prestiti MODIFY COLUMN origine ENUM('richiesta','prenotazione','diretto','ncip') COLLATE utf8mb4_unicode_ci DEFAULT 'richiesta'",
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
-- ^ Duplicates the ENUM change from migrate_0.7.3.sql for installs upgrading
--   directly to 0.7.4 that skipped 0.7.3; the IF guard makes it idempotent.

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
     '1.0.0', 'Fabiodalez', NULL,
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
     '1.0.0', 'Fabiodalez', NULL,
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
     '1.0.0', 'Fabiodalez', NULL,
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
     '1.0.0', 'Fabiodalez', NULL,
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

-- Legacy bundled plugin registrations. These rows pre-date the v0.7.x
-- interoperability stack, but older release schemas may not have them in the
-- plugin registry. Keep this in the release migration so upgrades land with the
-- same plugin catalogue as fresh installs.
INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('api-book-scraper',
     'API Book Scraper',
     'Client per servizi web di scraping dati libri tramite ISBN/EAN con API key authentication. Priorità più alta di Open Library.',
     '1.1.1', 'Fabiodalez', NULL,
     NULL,
     0, 'api-book-scraper', 'wrapper.php', '8.0', '0.4.0',
     '{"category":"scraping","priority":3,"api_version":"1.0","documentation":"README.md","php_extensions":["curl","json"],"hooks":[]}',
     NOW()),
    ('deezer',
     'Deezer Music Search',
     'Integrazione con Deezer per arricchire i dati musicali. Cerca per titolo/artista, fornisce copertine HD, tracklist con durate, genere. Nessun token richiesto.',
     '1.0.0', 'Fabiodalez', NULL,
     'https://developers.deezer.com',
     0, 'deezer', 'wrapper.php', '8.0', '0.5.0',
     '{"category":"scraping","tags":["api","deezer","scraping","music","covers"],"priority":15,"optional":true}',
     NOW()),
    ('dewey-editor',
     'Dewey Classification Editor',
     'Editor visuale per gestire le classificazioni Dewey. Permette di aggiungere, modificare ed eliminare codici decimali, importare/esportare file JSON con validazione automatica.',
     '1.0.1', 'Fabiodalez', NULL,
     NULL,
     0, 'dewey-editor', 'DeweyEditorPlugin.php', '7.4', '0.4.0',
     '{"category":"admin","tags":["dewey","classification","editor","json","admin"],"priority":10}',
     NOW()),
    ('digital-library',
     'Digital Library (eBooks/Audiobooks)',
     'Gestione eBooks (PDF/ePub) e audiobooks con player integrato Green Audio Player e visualizzatore PDF inline. Consente upload, riproduzione e lettura di contenuti digitali direttamente nella biblioteca.',
     '1.3.0', 'Fabiodalez', NULL,
     NULL,
     0, 'digital-library', 'wrapper.php', '8.0', '0.4.0',
     '{"category":"media","priority":10,"php_extensions":["gd","fileinfo"],"hooks":[]}',
     NOW()),
    ('discogs',
     'Music Scraper (Discogs, MusicBrainz, Deezer)',
     'Scraping multi-sorgente di metadati musicali: Discogs (barcode/titolo), MusicBrainz + Cover Art Archive (fallback barcode), Deezer (copertine HD). Supporta CD, LP, vinili, cassette.',
     '1.1.0', 'Fabiodalez', NULL,
     'https://www.discogs.com',
     0, 'discogs', 'wrapper.php', '8.0', '0.5.4',
     '{"optional":true,"category":"scraping","tags":["api","discogs","musicbrainz","deezer","scraping","music","vinyl","cd"],"priority":8}',
     NOW()),
    ('goodlib',
     'GoodLib — External Sources',
     'Aggiunge badge cliccabili alla scheda libro per cercare su Anna''s Archive, Z-Library e Project Gutenberg con un click. Ispirato all''estensione browser GoodLib.',
     '1.0.0', 'Fabiodalez', NULL,
     NULL,
     0, 'goodlib', 'wrapper.php', '8.0', '0.4.0',
     '{"category":"discovery","priority":10,"php_extensions":[],"assets":{"css":[],"js":[]},"hooks":[]}',
     NOW()),
    ('musicbrainz',
     'MusicBrainz + Cover Art Archive',
     'Integrazione con MusicBrainz e Cover Art Archive per metadati musicali. Cerca per barcode, fornisce artista, album, tracklist, etichetta, copertine HD. Open data, nessun token richiesto.',
     '1.0.0', 'Fabiodalez', NULL,
     'https://musicbrainz.org',
     0, 'musicbrainz', 'wrapper.php', '8.0', '0.5.0',
     '{"category":"scraping","tags":["api","musicbrainz","coverart","scraping","music"],"priority":7,"optional":true}',
     NOW()),
    ('open-library',
     'Open Library Scraper',
     'Integrazione con le API di Open Library (openlibrary.org) per lo scraping di metadati dei libri. Fornisce dati completi su edizioni, opere, autori e copertine ad alta risoluzione.',
     '1.0.1', 'Fabiodalez', NULL,
     'https://openlibrary.org',
     0, 'open-library', 'wrapper.php', '7.4', '0.4.0',
     '{"category":"scraping","tags":["api","openlibrary","scraping","books","covers"],"priority":5}',
     NOW()),
    ('z39-server',
     'Z39.50/SRU Integration (Server & Client)',
     'Soluzione completa per l''interoperabilità bibliotecaria. Server SRU per esporre il catalogo e Client Z39.50/SRU per importazione (Copy Cataloging) e ricerca federata. Supporta MARC21, MARCXML, Dublin Core e MODS.',
     '1.2.3', 'Fabiodalez', NULL,
     'https://www.loc.gov/standards/sru/',
     0, 'z39-server', 'Z39ServerPlugin.php', '7.4', '0.4.0',
     '{"category":"protocol","tags":["z39.50","sru","marc","marcxml","dublin-core","protocol","interoperability","client","federated-search"],"priority":10}',
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

-- ─── archival_unit_files: multi-document support per archival unit ───────────
-- archival_unit_files belongs to the Archives plugin.  If Archives was never
-- activated on this install, archival_units does not exist yet; creating
-- archival_unit_files with a FK to archival_units would fail with error 1824.
-- Guard: create the table unconditionally (no FK), then add the FK and migrate
-- existing document_path rows only when archival_units is already present.
-- When Archives is later activated, ArchivesPlugin::ensureSchema() will handle
-- both tables idempotently.

SET @au_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'
);

CREATE TABLE IF NOT EXISTS archival_unit_files (
    id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    unit_id           BIGINT UNSIGNED  NOT NULL,
    file_path         VARCHAR(500)     NOT NULL,
    file_mime         VARCHAR(127)     NOT NULL DEFAULT 'application/octet-stream',
    original_filename VARCHAR(255)     NOT NULL DEFAULT '',
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_unit_id (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_uf_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA           = DATABASE()
      AND TABLE_NAME             = 'archival_unit_files'
      AND COLUMN_NAME            = 'unit_id'
      AND REFERENCED_TABLE_NAME  = 'archival_units'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @sql = IF(
    @au_exists > 0 AND @fk_uf_exists = 0,
    'ALTER TABLE archival_unit_files ADD CONSTRAINT fk_archival_unit_files_unit FOREIGN KEY (unit_id) REFERENCES archival_units(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @sql = IF(
    @au_exists > 0,
    'INSERT INTO archival_unit_files (unit_id, file_path, file_mime, original_filename, sort_order) SELECT id, document_path, COALESCE(NULLIF(document_mime, ''''), ''application/octet-stream''), COALESCE(NULLIF(document_filename, ''''), SUBSTRING_INDEX(document_path, ''/'', -1)), 0 FROM archival_units WHERE document_path IS NOT NULL AND document_path <> '''' AND NOT EXISTS (SELECT 1 FROM archival_unit_files f WHERE f.unit_id = archival_units.id AND f.file_path = archival_units.document_path)',
    'SELECT 1'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Ensure archives plugin hooks are registered.
-- Uses INSERT ... ON DUPLICATE KEY to be idempotent (safe to re-run).
-- Covers installations where onActivate() was never re-called after adding
-- the search.unified.sources and frontend.catalog.archive_results hooks.
INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
SELECT p.id, 'app.routes.register', 'ArchivesPlugin', 'registerRoutes', 10, 1, NOW()
  FROM plugins p WHERE p.name = 'archives'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
SELECT p.id, 'admin.menu.render', 'ArchivesPlugin', 'renderAdminMenuEntry', 10, 1, NOW()
  FROM plugins p WHERE p.name = 'archives'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
SELECT p.id, 'search.unified.sources', 'ArchivesPlugin', 'addArchivalSources', 10, 1, NOW()
  FROM plugins p WHERE p.name = 'archives'
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
SELECT p.id, 'frontend.catalog.archive_results', 'ArchivesPlugin', 'getPublicArchiveResults', 10, 1, NOW()
  FROM plugins p WHERE p.name = 'archives'
ON DUPLICATE KEY UPDATE is_active = 1;

-- archival_units — phase 7 safety fallback (idempotent; mirrors migrate_0.5.9.sql phase-7 block).
-- Ensures IIIF/ARK/rights columns exist even on fresh installs that skipped migrate_0.5.9.sql.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='iiif_manifest_url');
SET @sql = IF(@c=0, 'ALTER TABLE archival_units ADD COLUMN iiif_manifest_url VARCHAR(2000) NULL', 'SELECT 1');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='rights_statement_url');
SET @sql = IF(@c=0, 'ALTER TABLE archival_units ADD COLUMN rights_statement_url VARCHAR(500) NULL', 'SELECT 1');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='ark_identifier');
SET @sql = IF(@c=0, 'ALTER TABLE archival_units ADD COLUMN ark_identifier VARCHAR(255) NULL', 'SELECT 1');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='version_note');
SET @sql = IF(@c=0, 'ALTER TABLE archival_units ADD COLUMN version_note VARCHAR(500) NULL', 'SELECT 1');
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- openurl-resolver registration is already covered by the INSERT above (line ~406).

-- Backfill fr_FR into existing installs (mirrors migrate_0.5.9.1.sql pattern for de_DE).
-- New installs get fr_FR via data_XX.sql; this ensures upgrade paths also have the row.
-- INSERT IGNORE is idempotent: no-op if the row already exists.
INSERT IGNORE INTO `languages`
    (`code`, `name`, `native_name`, `flag_emoji`, `is_default`, `is_active`, `translation_file`, `total_keys`, `translated_keys`, `completion_percentage`)
VALUES
    ('fr_FR', 'French', 'Français', '🇫🇷', 0, 1, 'locale/fr_FR.json', 4080, 4080, 100.00);
