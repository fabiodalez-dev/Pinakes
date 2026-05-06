-- Migration script for Pinakes 0.7.3
-- =============================================================================
-- NCIP (NISO Circulation Interchange Protocol) 2.0 server plugin
--
-- Schema changes:
--   ncip_partners
--   ncip_transactions
--   prestiti.ncip_request_id
--   prestiti.origine includes ncip
-- =============================================================================

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

CREATE TABLE IF NOT EXISTS ncip_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    message_type   VARCHAR(64) NOT NULL,
    related_loan_id INT NULL,
    request_id     VARCHAR(255) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_message_type (message_type),
    KEY idx_related_loan_id (related_loan_id),
    KEY idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

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
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @origin_has_ncip = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'prestiti'
      AND COLUMN_NAME  = 'origine'
      AND COLUMN_TYPE LIKE '%ncip%'
);
SET @sql = IF(
    @origin_has_ncip = 0,
    'ALTER TABLE prestiti MODIFY COLUMN origine ENUM(''richiesta'',''prenotazione'',''diretto'',''ncip'') DEFAULT ''richiesta''',
    'SELECT 1'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- ─── Register ncip-server plugin ─────────────────────────────────────────────

INSERT INTO plugins
    (name, display_name, description, version, author, author_url,
     plugin_url, is_active, path, main_file, requires_php, requires_app,
     metadata, installed_at)
VALUES
    ('ncip-server',
     'NCIP 2.0 Server',
     'Implementa il protocollo NISO Circulation Interchange Protocol (NCIP) 2.0 per lo scambio di informazioni sui prestiti con ILS, self-service kiosk e sistemi di rete bibliotecaria. Espone /ncip con LookupItem, LookupUser, CheckOutItem, CheckInItem, RenewItem, RequestItem e CancelRequestItem.',
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
