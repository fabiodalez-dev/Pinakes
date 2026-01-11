-- Migration script for Pinakes 0.4.3
-- Description: Add 'annullato' and 'scaduto' to prestiti status enum, add index for soft-delete queries
-- Date: 2025-12-10

ALTER TABLE `prestiti` MODIFY COLUMN `stato` ENUM('pendente','prenotato','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente';

-- Add index for deleted_at to optimize soft-delete queries (SitemapGenerator, etc.)
ALTER TABLE `libri` ADD INDEX IF NOT EXISTS `idx_libri_deleted_at` (`deleted_at`);
