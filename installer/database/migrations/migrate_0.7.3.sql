-- Migration script for Pinakes 0.7.3
-- =============================================================================
-- NCIP (NISO Circulation Interchange Protocol) 2.0 server plugin
--
-- No schema changes — the plugin reads from existing tables (libri, utenti,
-- prestiti). This file only registers the plugin.
-- =============================================================================

-- ─── Register ncip-server plugin ─────────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('ncip-server',
     'NCIP 2.0 Server',
     'Implementa il protocollo NISO Circulation Interchange Protocol (NCIP) 2.0 per lo scambio di informazioni sui prestiti con ILS, self-service kiosk e sistemi di rete bibliotecaria. Espone l''endpoint /ncip con supporto a LookupItem, LookupUser, CheckOutItem, CheckInItem, RenewItem.',
     '1.0.0', 'Fabiodalez', '',
     'https://www.niso.org/standards-committees/ncip',
     0,
     'ncip-server',
     'wrapper.php',
     '8.1',
     '0.7.3',
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
