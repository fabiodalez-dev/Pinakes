-- Pinakes v0.7.6 — Plugin registry repair: oai-pmh-server + viaf-authority
--
-- Finding F021 (adamsreview Phase 8):
-- The plugin-registration block at the end of migrate_0.7.4.sql (lines 395-493)
-- inserts the four NEW v0.7.x interoperability plugins
--   (ncip-server, openurl-resolver, bibframe-linked-data, resource-sync)
-- but OMITS two plugins that are part of BundledPlugins::LIST:
--   - oai-pmh-server  (originally registered by migrate_0.6.0.sql)
--   - viaf-authority  (originally registered by migrate_0.7.0.sql)
--
-- Installs that upgraded directly from a pre-0.6.0 / pre-0.7.0 schema (or that
-- had their plugins table truncated/rebuilt) and then jumped to 0.7.4 may be
-- missing rows for these two bundled plugins, even though the plugin code is
-- shipped in the release. This migration is idempotent (ON DUPLICATE KEY UPDATE)
-- so it is safe on installs that already have the rows: it will simply refresh
-- their metadata to the current canonical values.
--
-- migrate_0.7.4.sql is INTENTIONALLY NOT edited here — it is already applied on
-- production databases (the upgrade path uses recorded migration versions and
-- skips those it has already executed). Editing a migration that has already
-- run on prod has zero effect on existing installs while risking drift if it is
-- ever re-applied. The compat-preserving fix is a new migration pinned to the
-- next release version (0.7.6) so the updater picks it up on the 0.7.5 → 0.7.6
-- upgrade.
--
-- See CLAUDE.md "Migration file version MUST be ≤ release version" — version.json
-- has been bumped to 0.7.6 to ensure this file runs (the updater compares
-- migrationVersion <= toVersion).

-- ─── Re-register oai-pmh-server (canonical metadata, mirrors migrate_0.6.0.sql) ─

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

-- ─── Re-register viaf-authority (canonical metadata, mirrors migrate_0.7.0.sql) ─

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

-- ─── FIX F022: ensure fr_FR is_active=1 on installs that only ran migrate_0.7.4.sql ─
--
-- migrate_0.7.4.sql (lines 678-685) used `INSERT IGNORE` for the French language
-- backfill, which means existing fr_FR rows where is_active=0 were NEVER updated.
-- migrate_0.7.5.sql repaired this with ON DUPLICATE KEY UPDATE, but installs that
-- only executed migrate_0.7.4.sql (without subsequently running 0.7.5.sql) still
-- have fr_FR sitting at is_active=0 and the language remains hidden in the UI.
--
-- This statement guarantees fr_FR is active and its metadata is up-to-date for
-- ANY install reaching v0.7.6, regardless of whether 0.7.5.sql was applied.
-- Idempotent: re-running this migration is safe — the row is upserted to the
-- same canonical values every time.
--
-- total_keys/translated_keys mirror F023's verified key count (4993).

INSERT INTO `languages` (`code`, `name`, `native_name`, `flag_emoji`, `is_default`, `is_active`, `translation_file`, `total_keys`, `translated_keys`, `completion_percentage`, `created_at`, `updated_at`)
VALUES ('fr_FR', 'French', 'Français', '🇫🇷', 0, 1, 'locale/fr_FR.json', 4993, 4993, 100.00, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    is_active = 1,
    name = VALUES(name),
    native_name = VALUES(native_name),
    flag_emoji = VALUES(flag_emoji),
    translation_file = VALUES(translation_file),
    total_keys = VALUES(total_keys),
    translated_keys = VALUES(translated_keys),
    completion_percentage = VALUES(completion_percentage),
    updated_at = NOW();
