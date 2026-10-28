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

-- A starting profile for volunteers who only take part in stocktakes.
INSERT IGNORE INTO ruoli (nome, permessi) VALUES
('Revisore inventario', '["backend.access","inventory.manage"]');
-- ── end Inventory stocktake ──

-- ── MARC bulk import / copy cataloguing ──
-- import_logs.import_type gains 'marc': the MARC bulk import and copy
-- cataloguing record their runs in the import history like the CSV and
-- LibraryThing imports. The new enum is computed from the current COLUMN_TYPE
-- (marc appended at the end), so every value already there is kept, whatever
-- order the 0.9.0 sections run in.
SET @marc_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_logs' AND COLUMN_NAME = 'import_type');
SET @sql = IF(@marc_type IS NOT NULL AND LOCATE('''marc''', @marc_type) = 0, CONCAT('ALTER TABLE import_logs MODIFY COLUMN import_type ', LEFT(@marc_type, CHAR_LENGTH(@marc_type) - 1), ',''marc'') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT ''Type of import'''), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ── end MARC bulk import / copy cataloguing ──

-- ── minor features: visitor log, suppliers, RDA overrides ──
-- presenze: one row per library visit (member card scan or guest).
-- fornitori + libri.fornitore_id: the supplier a book was acquired from.
-- libri.rda_*_type: optional RDA content/media/carrier overrides (NULL = derived from tipo_media).
CREATE TABLE IF NOT EXISTS `fornitori` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referente` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sito_web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indirizzo` text COLLATE utf8mb4_unicode_ci,
  `partita_iva` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fornitori_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Suppliers books are acquired from';

CREATE TABLE IF NOT EXISTS `presenze` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int DEFAULT NULL,
  `sede_id` int DEFAULT NULL,
  `tipo` enum('socio','ospite') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ospite',
  `nome_ospite` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_presenze_created` (`created_at`),
  KEY `idx_presenze_utente` (`utente_id`),
  KEY `idx_presenze_sede` (`sede_id`),
  CONSTRAINT `fk_presenze_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_presenze_sede` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Visitor log: one row per library visit (member card scan or guest)';

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'fornitore_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `fornitore_id` int DEFAULT NULL COMMENT ''Supplier the copy was acquired from (fornitori)'' AFTER `editore_id`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_libri_fornitore');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `libri` ADD KEY `idx_libri_fornitore` (`fornitore_id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND CONSTRAINT_NAME = 'fk_libri_fornitore' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `libri` ADD CONSTRAINT `fk_libri_fornitore` FOREIGN KEY (`fornitore_id`) REFERENCES `fornitori` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'rda_content_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `rda_content_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''RDA content type override (LC code), NULL = derived from tipo_media'' AFTER `tipo_media`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'rda_media_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `rda_media_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''RDA media type override (LC code), NULL = derived from tipo_media'' AFTER `rda_content_type`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'rda_carrier_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `libri` ADD COLUMN `rda_carrier_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''RDA carrier type override (LC code), NULL = derived from tipo_media'' AFTER `rda_media_type`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ── end minor features ──

-- ── patron-cards-import ──
-- The user CSV import writes its run into import_logs like the book imports do,
-- so the import_type enum gains a users value. The new type is computed from the
-- current COLUMN_TYPE instead of being spelled out, which keeps any value another
-- section of this file (or a future release) has already added to the enum.
SET @pci_type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'import_logs' AND COLUMN_NAME = 'import_type');
SET @pci_sql = IF(@pci_type IS NOT NULL AND LOCATE('''users''', @pci_type) = 0, CONCAT('ALTER TABLE import_logs MODIFY COLUMN import_type ', LEFT(@pci_type, CHAR_LENGTH(@pci_type) - 1), ',''users'') NOT NULL COMMENT ''Type of import'''), 'SELECT 1');
PREPARE pci_stmt FROM @pci_sql;
EXECUTE pci_stmt;
DEALLOCATE PREPARE pci_stmt;
-- ── end patron-cards-import ──

-- ── Circulation policy: patron types, rules matrix, consultation-only copies ──
-- Patron types (tipologie_lettore) decide the circulation rules; the role
-- (utenti.tipo_utente) keeps deciding permissions. Every rule column is NULL =
-- inherit, so the seeded "Standard" type changes nothing (zero-config
-- equivalence). No user is touched: utenti.tipologia_id NULL = default type.
CREATE TABLE IF NOT EXISTS `tipologie_lettore` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codice` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `max_prestiti` int DEFAULT NULL,
  `durata_prestito_giorni` int DEFAULT NULL,
  `max_durata_richiesta_giorni` int DEFAULT NULL,
  `max_rinnovi` int DEFAULT NULL,
  `giorni_rinnovo` int DEFAULT NULL,
  `prenotazioni_abilitate` tinyint(1) NOT NULL DEFAULT '1',
  `max_prenotazioni` int DEFAULT NULL,
  `durata_tessera_giorni` int DEFAULT NULL,
  `multa_giornaliera` decimal(10,2) DEFAULT NULL,
  `giorni_tolleranza` int DEFAULT NULL,
  `multa_massima_prestito` decimal(10,2) DEFAULT NULL,
  `soglia_blocco_debito` decimal(10,2) DEFAULT NULL,
  `predefinita` tinyint(1) NOT NULL DEFAULT '0',
  `attiva` tinyint(1) NOT NULL DEFAULT '1',
  `ordinamento` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tipologie_lettore_codice` (`codice`),
  KEY `idx_tipologie_lettore_ordinamento` (`ordinamento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exceptions of the matrix patron type x material (libri.tipo_media).
-- Sentinels instead of NULL keep the UNIQUE key effective: tipologia_id 0 =
-- every patron type, tipo_media '*' = every material.
CREATE TABLE IF NOT EXISTS `regole_prestito` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipologia_id` int NOT NULL DEFAULT '0',
  `tipo_media` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '*',
  `prestabile` tinyint(1) DEFAULT NULL,
  `max_prestiti` int DEFAULT NULL,
  `durata_prestito_giorni` int DEFAULT NULL,
  `max_rinnovi` int DEFAULT NULL,
  `giorni_rinnovo` int DEFAULT NULL,
  `multa_giornaliera` decimal(10,2) DEFAULT NULL,
  `multa_massima_prestito` decimal(10,2) DEFAULT NULL,
  `giorni_tolleranza` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_regole_prestito_tipologia_media` (`tipologia_id`,`tipo_media`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The default patron type, inheriting every rule from the global settings.
-- Seeded only into an empty table: an existing set of types is never touched.
INSERT INTO `tipologie_lettore` (`codice`, `nome`, `predefinita`, `ordinamento`)
SELECT 'standard', 'Standard', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tipologie_lettore`);

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'tipologia_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `utenti` ADD COLUMN `tipologia_id` int DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND INDEX_NAME = 'idx_utenti_tipologia');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `utenti` ADD KEY `idx_utenti_tipologia` (`tipologia_id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND CONSTRAINT_NAME = 'fk_utenti_tipologia' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `utenti` ADD CONSTRAINT `fk_utenti_tipologia` FOREIGN KEY (`tipologia_id`) REFERENCES `tipologie_lettore` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A single copy kept for in-house consultation (SLiMS "no loan" item status).
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copie' AND COLUMN_NAME = 'solo_consultazione');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `copie` ADD COLUMN `solo_consultazione` tinyint(1) NOT NULL DEFAULT ''0''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Card-expiry warning (loans.card_expiry_warning_days): the expiry date the
-- warning was sent for, so it goes out once per expiry and a renewed card
-- re-arms it. NULL = never warned.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'card_expiry_warning_sent_for');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `utenti` ADD COLUMN `card_expiry_warning_sent_for` date DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ── end Circulation policy ──

-- ── closures calendar ──
-- calendario_chiusure: the days the library is closed (one row per interval,
-- optionally every year, optionally for one branch). Due dates that fall on a
-- closed day move to the next open day; pickup deadlines count open days.
-- The weekly closed days live in system_settings (loans.closed_weekdays) and
-- need no schema. Additive and idempotent: CREATE TABLE IF NOT EXISTS.
CREATE TABLE IF NOT EXISTS `calendario_chiusure` (
  `id` int NOT NULL AUTO_INCREMENT,
  `data_inizio` date NOT NULL,
  `data_fine` date NOT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ricorrente_annuale` tinyint(1) NOT NULL DEFAULT '0',
  `sede_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_calendario_chiusure_range` (`data_inizio`,`data_fine`),
  KEY `idx_calendario_chiusure_sede` (`sede_id`),
  CONSTRAINT `fk_calendario_chiusure_sede` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ── end closures calendar ──

-- ── fines-ledger (Phase 4: fines, patron ledger, debt block) ──
-- movimenti_lettore is an append-only patron ledger: the balance is SUM(importo),
-- positive = debt, negative = payment/waiver. chiave_idempotenza keeps a charge
-- from being written twice (late:<loan>, penalty:<loan>).
CREATE TABLE IF NOT EXISTS `movimenti_lettore` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int NOT NULL,
  `prestito_id` int DEFAULT NULL,
  `tipo` enum('multa_ritardo','addebito_perdita','addebito_danno','addebito_manuale','pagamento','condono','storno') COLLATE utf8mb4_unicode_ci NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metodo_pagamento` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creato_da` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `chiave_idempotenza` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_movimenti_chiave` (`chiave_idempotenza`),
  KEY `idx_movimenti_utente` (`utente_id`,`importo`),
  KEY `idx_movimenti_prestito` (`prestito_id`),
  KEY `idx_movimenti_tipo_data` (`tipo`,`created_at`),
  KEY `idx_movimenti_creato_da` (`creato_da`),
  CONSTRAINT `fk_movimenti_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_movimenti_prestito` FOREIGN KEY (`prestito_id`) REFERENCES `prestiti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movimenti_creato_da` FOREIGN KEY (`creato_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing prestiti.sanzione amounts (assessed by hand when a copy was closed lost
-- or damaged) become ledger charges. prestiti.sanzione stays and is still written
-- as a mirror. The key penalty:<loan id> is the one the live return path uses, so
-- the conversion happens exactly once, however many times the upgrade runs.
-- Batches of 5000 loans in id order; each statement is its own short transaction
-- and resumes where the previous one stopped. Rows left after the last batch
-- (an archive with more than 100000 assessed penalties) are converted by
-- PatronLedger::backfillLegacyPenalties() during maintenance.
SET @fl_sql = 'INSERT INTO movimenti_lettore (utente_id, prestito_id, tipo, importo, descrizione, creato_da, created_at, chiave_idempotenza)
 SELECT p.utente_id, p.id, IF(p.stato = ''perso'', ''addebito_perdita'', ''addebito_danno''), p.sanzione,
        ''Importo registrato alla chiusura del prestito'', p.processed_by,
        COALESCE(CAST(p.data_restituzione AS DATETIME), p.updated_at, p.created_at, NOW()), CONCAT(''penalty:'', p.id)
   FROM prestiti p
  WHERE p.sanzione > 0
    AND NOT EXISTS (SELECT 1 FROM movimenti_lettore m WHERE m.chiave_idempotenza = CONCAT(''penalty:'', p.id))
  ORDER BY p.id
  LIMIT 5000
 ON DUPLICATE KEY UPDATE movimenti_lettore.id = movimenti_lettore.id';
PREPARE fl_stmt FROM @fl_sql;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
EXECUTE fl_stmt;
DEALLOCATE PREPARE fl_stmt;
-- ── end fines-ledger ──
