-- Migration script for Pinakes 0.6.0
-- =============================================================================
-- OAI-PMH server plugin — books endpoint + MAG 2.0.1 support
--
-- New tables:
--   oai_deleted_records   — persistent tracking of soft-deleted books/units
--   oai_resumption_tokens — DB-backed resumption tokens (24h TTL)
--   mag_project_config    — MAG 2.0.1 project configuration for ICCU/Internet Culturale
--
-- New trigger:
--   trg_libri_soft_delete — on libri UPDATE, records soft-delete to oai_deleted_records
--   trg_archival_soft_del — on archival_units UPDATE, records soft-delete too
--
-- All statements are idempotent (CREATE TABLE IF NOT EXISTS, DROP TRIGGER IF EXISTS).
-- =============================================================================

-- ─── oai_deleted_records ─────────────────────────────────────────────────────
-- Persistent store for OAI-PMH deleted record tracking.
-- OAI-PMH spec §2.6: repositories with deletedRecord=persistent must return
-- deleted record headers (with status="deleted") indefinitely.

CREATE TABLE IF NOT EXISTS oai_deleted_records (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type  ENUM('book','archival_unit') NOT NULL,
    entity_id    INT NOT NULL,
    oai_id       VARCHAR(255) NOT NULL,
    datestamp    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entity (entity_type, entity_id),
    KEY idx_datestamp (datestamp),
    KEY idx_oai_id (oai_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── oai_resumption_tokens ───────────────────────────────────────────────────
-- DB-backed resumption tokens for ListRecords/ListIdentifiers pagination.
-- 24-hour TTL; the plugin purges expired tokens on each OAI request.

CREATE TABLE IF NOT EXISTS oai_resumption_tokens (
    token        VARCHAR(64) NOT NULL,
    payload      JSON NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME NOT NULL,
    PRIMARY KEY (token),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── mag_project_config ──────────────────────────────────────────────────────
-- MAG 2.0.1 (Metadati Amministrativi e Gestionali) project configuration.
-- Required by Internet Culturale / ICCU for digital library submissions.

CREATE TABLE IF NOT EXISTS mag_project_config (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code     VARCHAR(64) NOT NULL,
    institution_code VARCHAR(16) NOT NULL DEFAULT 'IT',
    collection_name  VARCHAR(255) NOT NULL DEFAULT '',
    rights_statement VARCHAR(500) NOT NULL DEFAULT 'In Copyright',
    base_url         VARCHAR(500) NOT NULL DEFAULT '',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_code (project_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Note on triggers ────────────────────────────────────────────────────────
-- The trg_libri_soft_delete and trg_archival_soft_delete triggers are created
-- by OaiPmhServerPlugin::installTriggers() during plugin activation (PHP-side)
-- because MySQL CLI cannot handle DELIMITER changes in piped SQL files.
-- The migration file intentionally omits them to stay CLI-compatible.

-- ─── Register oai-pmh-server plugin ──────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('oai-pmh-server',
     'OAI-PMH Server (libri + archivi)',
     'Server OAI-PMH 2.0 completo per esposizione del catalogo libri e archivi a harvester nazionali (Internet Culturale, Europeana, DPLA). Supporta oai_dc, MARCXML, MODS e MAG 2.0.1. Endpoint GET/POST /oai con deletedRecord=persistent.',
     '1.0.0', 'Fabiodalez', '',
     'https://www.openarchives.org/pmh/',
     0,
     'oai-pmh-server',
     'wrapper.php',
     '8.1',
     '0.6.0',
     '{"category":"protocol","tags":["oai-pmh","interoperability","iccu","europeana","marcxml","mods","mag","books"],"optional":true,"status":"stable"}',
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
