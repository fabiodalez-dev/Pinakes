-- Migration script for Pinakes 0.7.0
-- =============================================================================
-- VIAF Authority Control + UNIMARC support
--
-- Changes:
--   autori.viaf_id   — VIAF cluster identifier for authority control
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

-- ─── Register viaf-authority plugin ──────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('viaf-authority',
     'VIAF Authority Control',
     'Collegamento degli autori al Virtual International Authority File (VIAF/OCLC). Aggiunge viaf_id alla tabella autori e un endpoint di ricerca per l\'authority control bibliografico.',
     '1.0.0', 'Fabiodalez', '',
     'https://viaf.org/',
     0,
     'viaf-authority',
     'wrapper.php',
     '8.1',
     '0.7.0',
     '{"category":"authority","tags":["viaf","authority-control","authors","interoperability"],"optional":true,"status":"stable"}',
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
