-- Migration script for Pinakes 0.5.9.7
-- =============================================================================
-- Add UNIQUE KEY on archival_units.ark_identifier so that ARK identifiers
-- cannot be duplicated across records. ARKs are used as the canonical <recordid>
-- in EAD3 exports and as seeAlso identifiers in IIIF manifests — duplicates
-- would cause harvesting and deduplication collisions.
--
-- The constraint allows multiple NULL values (MySQL UNIQUE index semantics)
-- so records without an ARK are unaffected.
--
-- Idempotent: guarded by INFORMATION_SCHEMA so re-running is safe.
-- Pre-check: any duplicate ARK values are NULLed (keeping the earliest row)
-- before the UNIQUE KEY is added.
-- =============================================================================

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'archival_units'
      AND INDEX_NAME = 'uq_ark_identifier'
);

-- Dedup: NULL-out duplicate ARKs (keep the row with the lowest id) before
-- adding the constraint. No-op if the index already exists.
SET @dedup_sql = IF(@idx_exists = 0,
    'UPDATE archival_units a1
       JOIN archival_units a2
         ON a2.ark_identifier = a1.ark_identifier AND a2.id < a1.id
      SET a1.ark_identifier = NULL
    WHERE a1.ark_identifier IS NOT NULL',
    'SELECT 1');
PREPARE dedup_stmt FROM @dedup_sql;
EXECUTE dedup_stmt;
DEALLOCATE PREPARE dedup_stmt;

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE archival_units ADD UNIQUE KEY uq_ark_identifier (ark_identifier)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
