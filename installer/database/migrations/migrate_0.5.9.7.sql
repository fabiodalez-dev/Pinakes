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
-- =============================================================================

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'archival_units'
      AND INDEX_NAME = 'uq_ark_identifier'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE archival_units ADD UNIQUE KEY uq_ark_identifier (ark_identifier)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
