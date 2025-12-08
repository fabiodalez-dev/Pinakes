-- Migration script for Pinakes 0.4.0
-- Description: GDPR compliance - Privacy consent tracking and persistent sessions
-- Date: 2025-12-07
-- Compatibility: MySQL 5.7+ and MariaDB 10.0+
-- Note: ALTER TABLE statements may produce "Duplicate column" warnings on re-run - this is safe

-- ============================================================
-- 0. UPDATE SYSTEM TABLES (required for updater to function)
-- These tables MUST be created first before any logging can happen
-- ============================================================

CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `version` VARCHAR(20) NOT NULL COMMENT 'Version number (e.g., 0.3.0)',
    `filename` VARCHAR(255) NOT NULL COMMENT 'Migration filename',
    `batch` INT NOT NULL DEFAULT 1 COMMENT 'Batch number for rollback',
    `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When migration was executed',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_version` (`version`),
    KEY `idx_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks executed database migrations';

CREATE TABLE IF NOT EXISTS `update_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `from_version` VARCHAR(20) NOT NULL,
    `to_version` VARCHAR(20) NOT NULL,
    `status` ENUM('started', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'started',
    `backup_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to backup file',
    `error_message` TEXT,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    `executed_by` INT DEFAULT NULL COMMENT 'User ID who initiated update',
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs all update attempts';

-- ============================================================
-- 1. GDPR PRIVACY CONSENT TRACKING
-- Add columns to track privacy policy acceptance
-- ============================================================

ALTER TABLE `utenti` ADD COLUMN `privacy_accettata` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email_verificata`;

ALTER TABLE `utenti` ADD COLUMN `data_accettazione_privacy` DATETIME DEFAULT NULL AFTER `privacy_accettata`;

ALTER TABLE `utenti` ADD COLUMN `privacy_policy_version` VARCHAR(20) DEFAULT NULL AFTER `data_accettazione_privacy`;

-- ============================================================
-- 2. PERSISTENT SESSIONS TABLE (Database-backed "Remember Me")
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `utente_id` INT NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of the token',
    `device_info` VARCHAR(255) DEFAULT NULL COMMENT 'Browser/device identifier',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6',
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_user_sessions_utente_id` (`utente_id`),
    KEY `idx_user_sessions_token_hash` (`token_hash`),
    KEY `idx_user_sessions_expires_at` (`expires_at`),
    KEY `idx_user_sessions_is_revoked` (`is_revoked`),
    CONSTRAINT `fk_user_sessions_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. GDPR DATA EXPORT/DELETE TRACKING
-- ============================================================

CREATE TABLE IF NOT EXISTS `gdpr_requests` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `utente_id` INT DEFAULT NULL COMMENT 'NULL if user deleted',
    `utente_email` VARCHAR(255) NOT NULL COMMENT 'Preserved for audit',
    `request_type` ENUM('export', 'delete', 'rectification') NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    `processed_by` INT DEFAULT NULL COMMENT 'Admin user ID',
    `notes` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_gdpr_requests_utente_id` (`utente_id`),
    KEY `idx_gdpr_requests_status` (`status`),
    KEY `idx_gdpr_requests_type` (`request_type`),
    CONSTRAINT `fk_gdpr_requests_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_gdpr_requests_admin` FOREIGN KEY (`processed_by`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. CONSENT LOG TABLE (GDPR Article 7 audit trail)
-- ============================================================

CREATE TABLE IF NOT EXISTS `consent_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `utente_id` INT DEFAULT NULL,
    `consent_type` VARCHAR(50) NOT NULL COMMENT 'privacy_policy, marketing, analytics, etc.',
    `consent_given` TINYINT(1) NOT NULL,
    `consent_version` VARCHAR(20) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_consent_log_utente_id` (`utente_id`),
    KEY `idx_consent_log_type` (`consent_type`),
    KEY `idx_consent_log_created_at` (`created_at`),
    KEY `idx_consent_log_utente_type` (`utente_id`, `consent_type`, `created_at`),
    CONSTRAINT `fk_consent_log_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. BACKFILL: Set privacy_accettata=1 for existing active users
-- ============================================================

UPDATE `utenti`
SET `privacy_accettata` = 1,
    `data_accettazione_privacy` = `data_registrazione`,
    `privacy_policy_version` = '1.0'
WHERE `privacy_accettata` = 0
  AND `stato` = 'attivo';

-- End of migration
