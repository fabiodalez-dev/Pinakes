-- Archives plugin schema (issue #103) — full CRUD + authority + MARCXML + SRU
-- Idempotent: every statement uses CREATE TABLE IF NOT EXISTS or a
-- pre-check on INFORMATION_SCHEMA, so re-running the migration is a no-op.
--
-- Even though ArchivesPlugin::ensureSchema() also creates these tables at
-- activation time, we ship them in the migration so:
--   * a fresh 0.5.9 install has the tables present without needing the
--     plugin to be toggled from /admin/plugins first;
--   * an admin who uninstalls and reinstalls the plugin doesn't lose data —
--     the migration path is authoritative;
--   * schema drift between ensureSchema() and this file is avoided by
--     keeping both in sync (PR reviewers enforce this).

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
    -- Phase 5 — photographic items (ABA billedmarc)
    specific_material    ENUM('text','photograph','poster','postcard','drawing','audio','video','other',
                              'map','picture','object','film','microform','electronic','mixed')
                         NOT NULL DEFAULT 'text',
    dimensions           VARCHAR(100) NULL,
    color_mode           ENUM('bw','color','mixed') NULL,
    photographer         VARCHAR(255) NULL,
    publisher            VARCHAR(255) NULL,
    collection_name      VARCHAR(255) NULL,
    local_classification VARCHAR(64)  NULL,
    -- Phase 5+ per-document assets (optional cover image + downloadable
    -- file). All four columns default to NULL; non-null values are
    -- relative URLs under /uploads/archives/.
    cover_image_path     VARCHAR(500) NULL,
    document_path        VARCHAR(500) NULL,
    document_mime        VARCHAR(100) NULL,
    document_filename    VARCHAR(255) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reference (institution_code, reference_code),
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

-- ─── Idempotent second pass: conditional ALTERs for installations that had
-- an older ensureSchema() snapshot of these tables (e.g. a dev env that
-- activated the plugin at phase 2 and upgraded to 0.5.9 afterwards).
-- CREATE TABLE IF NOT EXISTS above is a no-op on existing tables; the
-- ALTER pattern below adds every column/index/constraint that was
-- introduced after the initial phase-1b DDL, only if it is absent.

-- helper macro: set @col_exists from INFORMATION_SCHEMA.COLUMNS
--   @col_exists := exists?(TABLE_SCHEMA = DB(), TABLE_NAME = ?, COLUMN_NAME = ?)
-- then PREPARE an ALTER if missing, otherwise a SELECT 1 no-op.
-- Repeated for every column added after phase 1b.

-- archival_units — phase 2b: material_status
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='material_status');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN material_status ENUM('unclassified','cataloguing','completed') NOT NULL DEFAULT 'unclassified' AFTER physical_location", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- archival_units — phase 5: photograph / ABA billedmarc extensions
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND COLUMN_NAME='specific_material');
SET @s := IF(@c=0, "ALTER TABLE archival_units ADD COLUMN specific_material ENUM('text','photograph','poster','postcard','drawing','audio','video','other') NOT NULL DEFAULT 'text'", 'SELECT 1');
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

-- archival_units — extend specific_material ENUM with ABA billedmarc +
-- MARC21 material-form codes (map/picture/object/film/microform/
-- electronic/mixed). New values appended after the original phase-5
-- eight, so ENUM ordinals 1-8 stay stable and the ALTER is
-- metadata-only (no data rewrite). Only runs when the column type
-- doesn't already include the new values.
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

-- archival_units — per-document assets (cover + downloadable file).
-- Idempotent: add only if missing. All NULL-able, no data migration.
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

-- archival_units — phase 7: interoperability standards (IIIF, ARK, RightsStatements)
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

-- archival_units — FULLTEXT index (may be missing on older ensureSchema())
SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units' AND INDEX_NAME='ft_search');
SET @s := IF(@i=0, "ALTER TABLE archival_units ADD FULLTEXT KEY ft_search (formal_title, constructed_title, scope_content, archival_history)", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- authority_records — phase 2b extended ISAAR fields
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND COLUMN_NAME='gender');
SET @s := IF(@c=0, "ALTER TABLE authority_records ADD COLUMN gender ENUM('female','male','other','unknown') NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND COLUMN_NAME='external_refs');
SET @s := IF(@c=0, "ALTER TABLE authority_records ADD COLUMN external_refs TEXT NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @i := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='authority_records' AND INDEX_NAME='ft_search');
SET @s := IF(@i=0, "ALTER TABLE authority_records ADD FULLTEXT KEY ft_search (authorised_form, parallel_forms, history, functions)", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- archival_unit_authority — role and idx_authority existed from phase 2
-- (link table was introduced complete). Nothing conditional needed here.

-- autori_authority_link — phase 2b (created wholesale by CREATE TABLE above).
-- Nothing conditional needed either.

-- ─── Register the plugin row so a fresh 0.5.9 install sees `archives` in
-- /admin/plugins immediately (is_active=0 — opt-in). Use upsert instead of
-- INSERT IGNORE so that display_name/description/version/metadata are
-- refreshed if the row already exists (e.g. auto-registered by
-- ArchivesPlugin::autoRegisterBundledPlugins()), while preserving is_active
-- — never flip an admin's opt-in decision during a schema bump.
INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('archives',
     'Archives (ISAD(G) / ISAAR(CPF))',
     'Gestione di materiale archivistico e fotografico secondo gli standard ISAD(G) e ISAAR(CPF). Modello gerarchico a 4 livelli, MARCXML round-trip, SRU endpoint.',
     '1.0.0', '', '',
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

-- Bump version row if the migration runner tracks it (no-op when the
-- table doesn't exist).
SET @has_meta = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meta');
SET @sql = IF(@has_meta > 0,
    "INSERT INTO meta (k, v) VALUES ('schema_version','0.5.9') ON DUPLICATE KEY UPDATE v='0.5.9'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
