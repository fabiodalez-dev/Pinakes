-- Pinakes 0.9.0. Each feature owns one delimited section so stacked branches concatenate.

-- ── Inventory stocktake ──
-- Stocktake sessions (plan §8): a session, the snapshot of the copies expected when it
-- was opened, and the scans. The snapshot keeps no foreign key to `copie` on purpose:
-- deleting or editing a copy during the stocktake must not rewrite what was expected.
-- Additive and idempotent on MySQL and MariaDB.
CREATE TABLE IF NOT EXISTS `inventario_sessioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sede_id` int DEFAULT NULL,
  `filtro` json DEFAULT NULL,
  `stato` enum('aperta','chiusa','annullata') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aperta',
  `totale_attese` int NOT NULL DEFAULT '0',
  `aperta_da` int DEFAULT NULL,
  `aperta_il` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `chiusa_da` int DEFAULT NULL,
  `chiusa_il` datetime DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_inventario_sessioni_stato` (`stato`),
  KEY `idx_inventario_sessioni_sede` (`sede_id`),
  KEY `idx_inventario_sessioni_aperta_da` (`aperta_da`),
  KEY `idx_inventario_sessioni_chiusa_da` (`chiusa_da`),
  CONSTRAINT `fk_inventario_sessioni_sede` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventario_sessioni_aperta_da` FOREIGN KEY (`aperta_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventario_sessioni_chiusa_da` FOREIGN KEY (`chiusa_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventario_attese` (
  `sessione_id` int NOT NULL,
  `copia_id` int NOT NULL,
  `scaffale_atteso` int DEFAULT NULL,
  `stato_all_apertura` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `esito_chiusura` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`sessione_id`,`copia_id`),
  KEY `idx_inventario_attese_copia` (`copia_id`),
  CONSTRAINT `fk_inventario_attese_sessione` FOREIGN KEY (`sessione_id`) REFERENCES `inventario_sessioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventario_scansioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sessione_id` int NOT NULL,
  `copia_id` int DEFAULT NULL,
  `codice_letto` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `esito` enum('trovata','fuori_posto','in_prestito','sconosciuta','duplicata','fuori_ambito') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scansionato_da` int DEFAULT NULL,
  `scansionato_il` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inventario_scansioni_copia` (`sessione_id`,`copia_id`),
  KEY `idx_inventario_scansioni_operatore` (`sessione_id`,`scansionato_da`),
  KEY `idx_inventario_scansioni_utente` (`scansionato_da`),
  CONSTRAINT `fk_inventario_scansioni_sessione` FOREIGN KEY (`sessione_id`) REFERENCES `inventario_sessioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventario_scansioni_utente` FOREIGN KEY (`scansionato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inventario_attese.esito_chiusura freezes each expected copy's bucket when the session
-- is closed (trovata, fuori_posto, in_prestito, mancante, persa), so the report of a
-- closed session no longer follows later loans and returns. Added here for tables
-- created by an earlier build of 0.9.0; a fresh CREATE above already has it.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_attese' AND COLUMN_NAME = 'esito_chiusura');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE inventario_attese ADD COLUMN esito_chiusura varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER stato_all_apertura', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A starting profile for volunteers who only take part in stocktakes.
INSERT IGNORE INTO ruoli (nome, permessi) VALUES
('Revisore inventario', '["backend.access","inventory.manage"]');
-- ── end Inventory stocktake ──

-- ── MARC bulk import / copy cataloguing ──
-- import_logs.import_type gains 'marc': the MARC bulk import and copy
-- cataloguing record their runs in the import history like the CSV and
-- LibraryThing imports. The enum keeps its existing values in order.
SET @marc_has_type = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_logs' AND COLUMN_NAME = 'import_type');
SET @marc_has_value = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_logs' AND COLUMN_NAME = 'import_type' AND COLUMN_TYPE LIKE '%''marc''%');
SET @sql = IF(@marc_has_type = 1 AND @marc_has_value = 0, 'ALTER TABLE import_logs MODIFY COLUMN import_type ENUM(''csv'',''librarything'',''marc'') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT ''Type of import''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ── end MARC bulk import / copy cataloguing ──
