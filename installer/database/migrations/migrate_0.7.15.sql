-- Multi-publisher support (issue #143).
--
-- Books can now have more than one publisher. We add a `libri_editori`
-- junction (mirroring `libri_autori`) and keep `libri.editore_id` as the
-- PRIMARY publisher (ordine=0) so every existing single-publisher consumer
-- (OAI-PMH, BIBFRAME, UNIMARC export, CSV export, the /publisher/{name}
-- route, list/catalog views) keeps working unchanged.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE, safe to re-run.

CREATE TABLE IF NOT EXISTS `libri_editori` (
  `libro_id` int NOT NULL,
  `editore_id` int NOT NULL,
  `ordine` int DEFAULT NULL,
  PRIMARY KEY (`libro_id`,`editore_id`),
  KEY `libro_id` (`libro_id`),
  KEY `editore_id` (`editore_id`),
  CONSTRAINT `libri_editori_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `libri_editori_ibfk_2` FOREIGN KEY (`editore_id`) REFERENCES `editori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill the junction from the existing single publisher as the primary
-- (ordine=0). INSERT IGNORE keeps this safe to re-run and avoids clobbering
-- additional publishers a librarian may have already added.
INSERT IGNORE INTO `libri_editori` (`libro_id`, `editore_id`, `ordine`)
SELECT `id`, `editore_id`, 0
FROM `libri`
WHERE `editore_id` IS NOT NULL;

-- #153: bookcase ("scaffali") codes are multi-character (e.g. "L1", "L2"),
-- but the legacy single-letter `lettera` column carried a UNIQUE constraint
-- that rejected any second bookcase sharing a first letter. Drop that index
-- (idempotently). `codice` stays the unique identifier (uniq_codice);
-- `lettera` remains only as a derived display/preference helper, not unique.
SET @idx_lettera_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'scaffali'
                             AND INDEX_NAME = 'lettera');
SET @sql = IF(@idx_lettera_exists > 0,
    'ALTER TABLE `scaffali` DROP INDEX `lettera`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
