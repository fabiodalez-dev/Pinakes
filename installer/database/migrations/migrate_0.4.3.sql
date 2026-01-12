-- Migration script for Pinakes 0.4.3
-- Description: Add 'annullato' and 'scaduto' to prestiti status enum, add sedi table for multi-branch support
-- Date: 2025-12-10
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- Note: Uses simple statements that the Updater can handle (no DELIMITER needed)
--       Updater ignores error 1061 (duplicate key), 1050 (table exists), 1060 (duplicate column)

-- 1. Expand prestiti status ENUM
ALTER TABLE `prestiti` MODIFY COLUMN `stato` ENUM('pendente','prenotato','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente';

-- 2. Add index for deleted_at (Updater ignores error 1061 if index exists)
ALTER TABLE `libri` ADD INDEX `idx_libri_deleted_at` (`deleted_at`);

-- 3. Create sedi table for multi-branch support (Updater ignores error 1050 if table exists)
CREATE TABLE IF NOT EXISTS `sedi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome della sede (es. Sede Centrale, Succursale Nord)',
  `codice` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Codice identificativo della sede',
  `indirizzo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cap` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orari_apertura` text COLLATE utf8mb4_unicode_ci COMMENT 'Orari di apertura in formato libero',
  `note` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_codice` (`codice`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sedi della biblioteca (per sistemi multi-sede)';

-- 4. Add index for sede_id in copie (Updater ignores error 1061 if index exists)
ALTER TABLE `copie` ADD INDEX `idx_sede_id` (`sede_id`);

-- 5. Add FK constraint for sede_id (may fail if constraint exists - acceptable)
ALTER TABLE `copie` ADD CONSTRAINT `copie_ibfk_2` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL;
