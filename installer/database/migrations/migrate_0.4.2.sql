-- Migration script for Pinakes 0.4.2
-- Description: Implement Reservation Edge Cases support
-- Date: 2025-12-10
-- Compatibility: MySQL 5.7+ and MariaDB 10.0+

-- 1. Add deleted_at to libri for Soft Deletes (Case 10)
ALTER TABLE `libri` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`;

-- 2. Add sede_id to copie (Case 7)
ALTER TABLE `copie` ADD COLUMN `sede_id` INT DEFAULT NULL AFTER `libro_id`;

-- 3. Update copie status ENUM to include 'prenotato', 'in_restauro', 'in_trasferimento' (Case 9)
ALTER TABLE `copie` MODIFY COLUMN `stato` ENUM('disponibile','prestato','prenotato','manutenzione','in_restauro','perso','danneggiato','in_trasferimento') COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile';

-- End of migration
