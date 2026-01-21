-- Migration script for Pinakes 0.4.5
-- Description: Add pickup confirmation workflow with 'da_ritirare' state
-- Date: 2025-01-21
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Uses prepared statements to check before creating

-- ============================================================
-- 1. EXPAND PRESTITI STATUS ENUM (idempotent - MODIFY is safe)
-- ============================================================
ALTER TABLE `prestiti` MODIFY COLUMN `stato` ENUM('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente';

-- ============================================================
-- 2. ADD PICKUP_DEADLINE COLUMN (only if not exists)
-- ============================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'pickup_deadline');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `prestiti` ADD COLUMN `pickup_deadline` DATE DEFAULT NULL AFTER `data_scadenza`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. ADD INDEX FOR PICKUP_DEADLINE (only if not exists)
-- ============================================================
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND INDEX_NAME = 'idx_prestiti_pickup_deadline');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE `prestiti` ADD INDEX `idx_prestiti_pickup_deadline` (`pickup_deadline`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. ADD PICKUP_EXPIRY_DAYS SETTING (ON DUPLICATE KEY = idempotent)
-- ============================================================
INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES ('loans', 'pickup_expiry_days', '3', 'Giorni per ritirare un prestito approvato prima che scada') ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ============================================================
-- 5. ADD EMAIL TEMPLATES (ON DUPLICATE KEY = idempotent)
-- NOTE: HTML templates use simple formatting without CSS semicolons
-- because Updater.php uses explode(';') to parse SQL statements
-- ============================================================
INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_ready', 'it_IT', '📦 Libro pronto per il ritiro!', '<h2>Libro pronto per il ritiro!</h2><p>Ciao {{utente_nome}},</p><p>Siamo lieti di informarti che il tuo libro è pronto per essere ritirato!</p><table cellpadding="15" bgcolor="#f0f9ff"><tr><td><h3>{{libro_titolo}}</h3><p><strong>Periodo prestito:</strong> {{data_inizio}} - {{data_fine}}</p><p><strong>Scadenza ritiro:</strong> {{scadenza_ritiro}}</p></td></tr></table><table cellpadding="15" bgcolor="#ecfdf5"><tr><td><p><strong>📦 Come ritirare</strong></p><p>{{pickup_instructions}}</p></td></tr></table><p>Buona lettura!</p>', 'Inviata quando un prestito è stato approvato e il libro è pronto per il ritiro.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_expired', 'it_IT', '⏰ Tempo per il ritiro scaduto', '<h2>Tempo per il ritiro scaduto</h2><p>Ciao {{utente_nome}},</p><p>Purtroppo non hai ritirato il libro entro il tempo previsto.</p><table cellpadding="15" bgcolor="#fef2f2"><tr><td><p><strong>Libro:</strong> {{libro_titolo}}</p><p><strong>Scadenza ritiro:</strong> {{scadenza_ritiro}}</p></td></tr></table><p>Il prestito è stato automaticamente annullato e il libro è stato reso disponibile per altri utenti.</p><p>Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p><p>Cordiali saluti,<br>Il team della biblioteca</p>', 'Inviata quando il tempo per ritirare un libro è scaduto e il prestito è stato annullato.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_cancelled', 'it_IT', '❌ Ritiro annullato', '<h2>Ritiro annullato</h2><p>Ciao {{utente_nome}},</p><p>Ti informiamo che il ritiro del seguente libro è stato annullato:</p><table cellpadding="15" bgcolor="#fef2f2"><tr><td><p><strong>Libro:</strong> {{libro_titolo}}</p><p><strong>Motivo:</strong> {{motivo}}</p></td></tr></table><p>Il libro è stato reso disponibile per altri utenti. Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p><p>Cordiali saluti,<br>Il team della biblioteca</p>', 'Inviata quando un ritiro viene annullato dall''amministratore.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- End of migration
