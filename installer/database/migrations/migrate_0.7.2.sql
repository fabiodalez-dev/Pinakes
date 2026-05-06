-- Migration script for Pinakes 0.7.2
-- =============================================================================
-- OpenURL Z39.88 Resolver + COinS plugin
--
-- No schema changes — the plugin reads from existing tables (libri, autori,
-- editori). This file only registers the plugin.
-- =============================================================================

-- ─── Register openurl-resolver plugin ────────────────────────────────────────

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
     0,
     'openurl-resolver',
     'wrapper.php',
     '8.1',
     '0.7.2',
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
