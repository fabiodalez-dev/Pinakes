-- Migration script for Pinakes 0.5.9.7
-- =============================================================================
-- Self-contained archives schema migration.
--
-- IMPORTANT: migrate_0.5.9.sql (version 0.5.9) is the original schema file
-- for the archives plugin. Because 0.5.9 < 0.5.9.6 (the previous release),
-- it does NOT run for users upgrading from 0.5.9.x → 0.5.9.7. This file
-- consolidates the full archives DDL so that upgrading from ANY version
-- before 0.5.9.7 creates all tables, adds all columns, registers the plugin,
-- and adds the UNIQUE KEY on ark_identifier in a single idempotent pass.
--
-- All statements use CREATE TABLE IF NOT EXISTS or INFORMATION_SCHEMA guards,
-- so re-running this migration (e.g. on a system that already ran
-- migrate_0.5.9.sql) is a safe no-op.
-- =============================================================================

-- ─── Part 1: Core tables (archives plugin schema) ───────────────────────────

CREATE TABLE IF NOT EXISTS archival_units (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id            BIGINT UNSIGNED NULL,
    reference_code       VARCHAR(64)  NOT NULL,
    institution_code     VARCHAR(16)  NOT NULL DEFAULT 'PINAKES',
    level                ENUM('fonds','series','file','item') NOT NULL,
    formal_title         VARCHAR(500) NULL,
    constructed_title    VARCHAR(500) NOT NULL,
    date_start           SMALLINT NULL,
    date_end             SMALLINT NULL,
    predominant_dates    VARCHAR(255) NULL,
    date_gaps            VARCHAR(255) NULL,
    extent               VARCHAR(500) NULL,
    scope_content        TEXT NULL,
    appraisal            TEXT NULL,
    accruals             ENUM('none','completed','ongoing','irregular') NULL,
    arrangement_system   VARCHAR(255) NULL,
    access_conditions    VARCHAR(255) NULL,
    reproduction_rules   VARCHAR(255) NULL,
    language_codes       VARCHAR(64)  NULL,
    finding_aids         TEXT NULL,
    originals_location   VARCHAR(500) NULL,
    copies_location      VARCHAR(500) NULL,
    related_units        TEXT NULL,
    archival_history     TEXT NULL,
    acquisition_source   VARCHAR(500) NULL,
    physical_location    VARCHAR(255) NULL,
    material_status      ENUM('unclassified','cataloguing','completed') NOT NULL DEFAULT 'unclassified',
    registration_date    DATE NULL,
    specific_material    ENUM('text','photograph','poster','postcard','drawing','audio','video','other',
                              'map','picture','object','film','microform','electronic','mixed')
                         NOT NULL DEFAULT 'text',
    dimensions           VARCHAR(100) NULL,
    color_mode           ENUM('bw','color','mixed') NULL,
    photographer         VARCHAR(255) NULL,
    publisher            VARCHAR(255) NULL,
    collection_name      VARCHAR(255) NULL,
    local_classification VARCHAR(64)  NULL,
    cover_image_path     VARCHAR(500) NULL,
    document_path        VARCHAR(500) NULL,
    document_mime        VARCHAR(100) NULL,
    document_filename    VARCHAR(255) NULL,
    iiif_manifest_url    VARCHAR(2000) NULL,
    rights_statement_url VARCHAR(500) NULL,
    ark_identifier       VARCHAR(255) NULL,
    version_note         VARCHAR(500) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reference (institution_code, reference_code),
    UNIQUE KEY uq_ark_identifier (ark_identifier),
    KEY idx_parent (parent_id),
    KEY idx_level (level),
    KEY idx_dates (date_start, date_end),
    KEY idx_deleted (deleted_at),
    FULLTEXT KEY ft_search (formal_title, constructed_title, scope_content, archival_history),
    CONSTRAINT fk_archival_parent FOREIGN KEY (parent_id) REFERENCES archival_units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS authority_records (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type               ENUM('person','corporate','family') NOT NULL,
    authorised_form    VARCHAR(500) NOT NULL,
    parallel_forms     TEXT NULL,
    other_forms        TEXT NULL,
    identifiers        VARCHAR(500) NULL,
    dates_of_existence VARCHAR(255) NULL,
    history            TEXT NULL,
    places             TEXT NULL,
    legal_status       VARCHAR(255) NULL,
    functions          TEXT NULL,
    mandates           TEXT NULL,
    internal_structure TEXT NULL,
    general_context    TEXT NULL,
    gender             ENUM('female','male','other','unknown') NULL,
    external_refs      TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_type (type),
    KEY idx_deleted (deleted_at),
    FULLTEXT KEY ft_search (authorised_form, parallel_forms, history, functions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archival_unit_authority (
    archival_unit_id BIGINT UNSIGNED NOT NULL,
    authority_id     BIGINT UNSIGNED NOT NULL,
    role             ENUM('creator','subject','recipient','custodian','associated') NOT NULL DEFAULT 'subject',
    PRIMARY KEY (archival_unit_id, authority_id, role),
    KEY idx_authority (authority_id, role),
    CONSTRAINT fk_aua_unit FOREIGN KEY (archival_unit_id) REFERENCES archival_units(id) ON DELETE CASCADE,
    CONSTRAINT fk_aua_auth FOREIGN KEY (authority_id)     REFERENCES authority_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autori_authority_link (
    autori_id    INT             NOT NULL,
    authority_id BIGINT UNSIGNED NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (autori_id, authority_id),
    KEY idx_authority_autore (authority_id),
    CONSTRAINT fk_aal_authority FOREIGN KEY (authority_id) REFERENCES authority_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Part 2: Idempotent column additions (for installs that had an older ────
-- ensureSchema() snapshot without all columns)

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='material_status');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN material_status ENUM('unclassified','cataloguing','completed') NOT NULL DEFAULT 'unclassified' AFTER physical_location", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='registration_date');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN registration_date DATE NULL AFTER material_status", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='specific_material');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN specific_material ENUM('text','photograph','poster','postcard','drawing','audio','video','other','map','picture','object','film','microform','electronic','mixed') NOT NULL DEFAULT 'text'", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='dimensions');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN dimensions VARCHAR(100) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='color_mode');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN color_mode ENUM('bw','color','mixed') NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='photographer');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN photographer VARCHAR(255) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='publisher');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN publisher VARCHAR(255) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='collection_name');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN collection_name VARCHAR(255) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='local_classification');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN local_classification VARCHAR(64) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @enum_has_mixed := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'archival_units'
       AND COLUMN_NAME = 'specific_material'
       AND COLUMN_TYPE LIKE '%mixed%'
);
SET @s := IF(@enum_has_mixed = 0,
    "ALTER TABLE archival_units MODIFY COLUMN specific_material
        ENUM('text','photograph','poster','postcard','drawing','audio','video','other',
             'map','picture','object','film','microform','electronic','mixed')
        NOT NULL DEFAULT 'text'",
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='cover_image_path');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN cover_image_path VARCHAR(500) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='document_path');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN document_path VARCHAR(500) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='document_mime');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN document_mime VARCHAR(100) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='document_filename');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN document_filename VARCHAR(255) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='iiif_manifest_url');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN iiif_manifest_url VARCHAR(2000) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='rights_statement_url');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN rights_statement_url VARCHAR(500) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='ark_identifier');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN ark_identifier VARCHAR(255) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='version_note');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN version_note VARCHAR(500) NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND INDEX_NAME='ft_search');
SET @s := IF(@i=0, "ALTER TABLE archival_units ADD FULLTEXT KEY ft_search (formal_title, constructed_title, scope_content, archival_history)", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND COLUMN_NAME='gender');
SET @s := IF(@c=0, "ALTER TABLE authority_records ADD COLUMN gender ENUM('female','male','other','unknown') NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND COLUMN_NAME='external_refs');
SET @s := IF(@c=0, "ALTER TABLE authority_records ADD COLUMN external_refs TEXT NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND INDEX_NAME='ft_search');
SET @s := IF(@i=0, "ALTER TABLE authority_records ADD FULLTEXT KEY ft_search (authorised_form, parallel_forms, history, functions)", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ─── Part 3: Register archives plugin row ────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('archives',
     'Archives (ISAD(G) / ISAAR(CPF))',
     'Gestione di materiale archivistico e fotografico secondo gli standard ISAD(G) e ISAAR(CPF). Modello gerarchico a 4 livelli, MARCXML round-trip, SRU endpoint.',
     '1.1.0', '', '',
     'https://github.com/fabiodalez-dev/Pinakes/issues/103',
     0,
     'archives',
     'wrapper.php',
     '8.1',
     '0.5.9',
     '{"category":"archives","optional":true,"status":"phase-6"}',
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

-- ─── Part 4: UNIQUE KEY on ark_identifier ────────────────────────────────────

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'archival_units'
      AND INDEX_NAME = 'uq_ark_identifier'
);

-- Dedup: NULL-out duplicate ARKs (keep the row with the lowest id) before
-- adding the constraint. No-op if the index already exists.
SET @dedup_count = IF(@idx_exists = 0,
    (SELECT COUNT(*) FROM archival_units a1
       JOIN archival_units a2
         ON a2.ark_identifier = a1.ark_identifier AND a2.id < a1.id
      WHERE a1.ark_identifier IS NOT NULL),
    0);
SELECT CONCAT('migrate_0.5.9.7: nulling ', @dedup_count, ' duplicate ark_identifier value(s)') AS migration_notice;

SET @dedup_sql = IF(@idx_exists = 0,
    'UPDATE archival_units a1
       JOIN archival_units a2
         ON a2.ark_identifier = a1.ark_identifier AND a2.id < a1.id
      SET a1.ark_identifier = NULL
    WHERE a1.ark_identifier IS NOT NULL',
    'SELECT 1');
PREPARE dedup_stmt FROM @dedup_sql;
EXECUTE dedup_stmt;
DEALLOCATE PREPARE dedup_stmt;

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE archival_units ADD UNIQUE KEY uq_ark_identifier (ark_identifier)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
