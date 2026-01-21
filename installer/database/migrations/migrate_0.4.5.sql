-- Migration script for Pinakes 0.4.5
-- Description: Add pickup confirmation workflow with 'da_ritirare' state
-- Date: 2025-01-21
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- Note: Uses simple statements that the Updater can handle (no DELIMITER needed)
--       Updater ignores error 1061 (duplicate key), 1050 (table exists), 1060 (duplicate column)

-- ============================================================
-- 1. EXPAND PRESTITI STATUS ENUM
-- Add 'da_ritirare' state for pickup confirmation workflow
-- ============================================================
ALTER TABLE `prestiti` MODIFY COLUMN `stato` ENUM('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente';

-- ============================================================
-- 2. ADD PICKUP_DEADLINE COLUMN
-- Tracks when user must pick up the approved loan
-- ============================================================
ALTER TABLE `prestiti` ADD COLUMN `pickup_deadline` DATE DEFAULT NULL AFTER `data_scadenza`;

-- ============================================================
-- 3. ADD PICKUP_EXPIRY_DAYS SETTING
-- Default: 3 days to pick up an approved loan
-- ============================================================
INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`) VALUES ('loans', 'pickup_expiry_days', '3', 'Giorni per ritirare un prestito approvato prima che scada') ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ============================================================
-- 4. ADD INDEX FOR PICKUP_DEADLINE (for expiry checks)
-- Updater ignores error 1061 if index exists
-- ============================================================
ALTER TABLE `prestiti` ADD INDEX `idx_prestiti_pickup_deadline` (`pickup_deadline`);

-- ============================================================
-- 5. ADD EMAIL TEMPLATES FOR PICKUP WORKFLOW
-- Updater uses ON DUPLICATE KEY UPDATE for idempotency
-- Note: Templates on single lines to avoid Updater parsing issues
-- ============================================================

-- Template: Pickup Ready (IT)
INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_ready', 'it_IT', '📦 Libro pronto per il ritiro!', '<h2>Libro pronto per il ritiro!</h2><p>Ciao {{utente_nome}},</p><p>Siamo lieti di informarti che il tuo libro è pronto per essere ritirato!</p><div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;"><h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3><p style="margin: 5px 0;"><strong>Periodo prestito:</strong> {{data_inizio}} - {{data_fine}}</p><p style="margin: 5px 0;"><strong>Scadenza ritiro:</strong> {{scadenza_ritiro}}</p></div><div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;"><p><strong>📦 Come ritirare</strong></p><p>{{pickup_instructions}}</p></div><p>Buona lettura!</p>', 'Inviata quando un prestito è stato approvato e il libro è pronto per il ritiro.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- Template: Pickup Expired (IT)
INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_expired', 'it_IT', '⏰ Tempo per il ritiro scaduto', '<h2>Tempo per il ritiro scaduto</h2><p>Ciao {{utente_nome}},</p><p>Purtroppo non hai ritirato il libro entro il tempo previsto.</p><div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;"><p><strong>Libro:</strong> {{libro_titolo}}</p><p><strong>Scadenza ritiro:</strong> {{scadenza_ritiro}}</p></div><p>Il prestito è stato automaticamente annullato e il libro è stato reso disponibile per altri utenti.</p><p>Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p><p>Cordiali saluti,<br>Il team della biblioteca</p>', 'Inviata quando il tempo per ritirare un libro è scaduto e il prestito è stato annullato.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- Template: Pickup Cancelled (IT)
INSERT INTO `email_templates` (`name`, `locale`, `subject`, `body`, `description`, `active`, `created_at`, `updated_at`) VALUES ('loan_pickup_cancelled', 'it_IT', '❌ Ritiro annullato', '<h2>Ritiro annullato</h2><p>Ciao {{utente_nome}},</p><p>Ti informiamo che il ritiro del seguente libro è stato annullato:</p><div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;"><p><strong>Libro:</strong> {{libro_titolo}}</p><p><strong>Motivo:</strong> {{motivo}}</p></div><p>Il libro è stato reso disponibile per altri utenti. Se desideri ancora questo libro, ti invitiamo a effettuare una nuova richiesta di prestito.</p><p>Cordiali saluti,<br>Il team della biblioteca</p>', 'Inviata quando un ritiro viene annullato dall''amministratore.', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `updated_at` = NOW();

-- End of migration
