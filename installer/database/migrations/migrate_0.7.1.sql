-- Migration script for Pinakes 0.7.1
-- =============================================================================
-- BIBFRAME 2.0 Linked Data + ResourceSync plugins
--
-- No schema changes — both plugins read from existing tables (libri, autori,
-- editori, archival_units). This file only registers the plugins.
-- =============================================================================

-- ─── Register bibframe-linked-data plugin ─────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('bibframe-linked-data',
     'BIBFRAME 2.0 Linked Data',
     'Espone il catalogo libri come Linked Data in formato BIBFRAME 2.0 (JSON-LD e Turtle). Endpoint /api/bibframe/book/{id} con content negotiation (application/ld+json, text/turtle). Conforme alle linee guida Library of Congress per la transizione da MARC a BIBFRAME.',
     '1.0.0', 'Fabiodalez', '',
     'http://id.loc.gov/ontologies/bibframe/',
     0,
     'bibframe-linked-data',
     'wrapper.php',
     '8.1',
     '0.7.1',
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

-- ─── Register resource-sync plugin ───────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('resource-sync',
     'ResourceSync',
     'Implementa il protocollo ResourceSync (ANSI/NISO Z39.99-2014) per la sincronizzazione del catalogo con harvester nazionali. Espone /.well-known/resourcesync, /resync/capabilitylist.xml, /resync/resourcelist.xml e /resync/changelist.xml.',
     '1.0.0', 'Fabiodalez', '',
     'http://www.openarchives.org/rs/toc/',
     0,
     'resource-sync',
     'wrapper.php',
     '8.1',
     '0.7.1',
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
