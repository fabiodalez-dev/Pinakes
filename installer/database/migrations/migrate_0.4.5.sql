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
INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`)
VALUES ('loans', 'pickup_expiry_days', '3', 'Giorni per ritirare un prestito approvato prima che scada')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ============================================================
-- 4. ADD INDEX FOR PICKUP_DEADLINE (for expiry checks)
-- Updater ignores error 1061 if index exists
-- ============================================================
ALTER TABLE `prestiti` ADD INDEX `idx_prestiti_pickup_deadline` (`pickup_deadline`);

-- End of migration
