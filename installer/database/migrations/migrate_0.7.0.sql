-- Migration script for Pinakes 0.7.0
-- =============================================================================
-- VIAF/ISNI Authority Control + UNIMARC support
--
-- Changes:
--   autori.viaf_id / viaf_uri
--   autori.isni_id / isni_uri
--   autori.authority_source / authority_confidence
--   author_authority_alternates
--
-- UNIMARC export is handled in the oai-pmh-server plugin (no schema change).
-- All statements are idempotent.
-- =============================================================================

-- ─── autori.viaf_id ───────────────────────────────────────────────────────────
-- VIAF (Virtual International Authority File) cluster ID for each author.
-- Example: '56629711' links to https://viaf.org/viaf/56629711 (Umberto Eco).

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'viaf_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN viaf_id VARCHAR(50) DEFAULT NULL AFTER sito_web',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- Additional authority columns used by the viaf-authority plugin.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'viaf_uri'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN viaf_uri VARCHAR(255) DEFAULT NULL AFTER viaf_id',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'isni_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN isni_id CHAR(19) DEFAULT NULL AFTER viaf_uri',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'isni_uri'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN isni_uri VARCHAR(255) DEFAULT NULL AFTER isni_id',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'authority_source'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN authority_source ENUM(''manual'',''viaf'',''isni'',''sbn'',''wikidata'') DEFAULT NULL AFTER isni_uri',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND COLUMN_NAME  = 'authority_confidence'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE autori ADD COLUMN authority_confidence ENUM(''exact'',''probable'',''candidate'',''rejected'') DEFAULT NULL AFTER authority_source',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- Index for VIAF-ID lookups.
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND INDEX_NAME   = 'idx_viaf_id'
);
SET @sql2 = IF(
    @idx_exists = 0,
    'ALTER TABLE autori ADD KEY idx_viaf_id (viaf_id)',
    'SELECT 1'
);
PREPARE _stmt2 FROM @sql2;
EXECUTE _stmt2;
DEALLOCATE PREPARE _stmt2;

-- Unique ISNI lookups, matching plugin activation.
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'autori'
      AND INDEX_NAME   = 'uq_isni_id'
);
SET @sql2 = IF(
    @idx_exists = 0,
    'ALTER TABLE autori ADD UNIQUE KEY uq_isni_id (isni_id)',
    'SELECT 1'
);
PREPARE _stmt2 FROM @sql2;
EXECUTE _stmt2;
DEALLOCATE PREPARE _stmt2;

CREATE TABLE IF NOT EXISTS author_authority_alternates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    autore_id   INT NOT NULL,
    source      ENUM('viaf','isni','sbn','wikidata','manual') NOT NULL,
    authority_id VARCHAR(100) NOT NULL,
    label       VARCHAR(255) DEFAULT NULL,
    uri         VARCHAR(255) DEFAULT NULL,
    confidence  ENUM('exact','probable','candidate','rejected') DEFAULT 'candidate',
    payload_json JSON DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_autore_id (autore_id),
    KEY idx_authority (source, authority_id),
    CONSTRAINT fk_author_authority_alternates_autore
        FOREIGN KEY (autore_id) REFERENCES autori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Register viaf-authority plugin ──────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('viaf-authority',
     'VIAF Authority Control',
     'Collegamento degli autori al Virtual International Authority File (VIAF/OCLC) e ISNI (ISO 27729). Aggiunge campi VIAF/ISNI, alternates e API di riconciliazione W3C.',
     '1.1.0', 'Fabiodalez', '',
     'https://viaf.org/',
     0,
     'viaf-authority',
     'wrapper.php',
     '8.1',
     '0.7.0',
     '{"category":"authority","tags":["viaf","isni","authority-control","authors","interoperability","linked-data"],"optional":true,"status":"stable"}',
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
