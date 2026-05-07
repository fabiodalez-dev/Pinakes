-- Seed: archival_unit_files — multi-document support for the Archives plugin.
-- Creates one fondo (E2E_FILE_FONDS_001) and two items below it, then inserts
-- archival_unit_files rows simulating 3 files on the fondo and 1 on each item.
-- File paths are fictional (/uploads/archives/documents/test-*.pdf) — they do
-- not exist on disk, which lets interoperability tests verify XML structure
-- without requiring real files.  Upload and delete tests use separate fixture
-- files that are created at test-runtime.
--
-- Idempotent: uses INSERT IGNORE on archival_units and
-- INSERT INTO ... ON DUPLICATE KEY UPDATE on archival_unit_files.
-- Safe to re-run; also safe to run after archives-feature-20.sql.
--
-- v1 (2026-05-07): initial version.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. Ensure archival_unit_files table exists ──────────────────────────────
CREATE TABLE IF NOT EXISTS archival_unit_files (
    id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    unit_id           BIGINT UNSIGNED  NOT NULL,
    file_path         VARCHAR(500)     NOT NULL,
    file_mime         VARCHAR(127)     NOT NULL DEFAULT 'application/octet-stream',
    original_filename VARCHAR(255)     NOT NULL DEFAULT '',
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_unit_id (unit_id),
    CONSTRAINT fk_archival_unit_files_unit_seed
        FOREIGN KEY (unit_id) REFERENCES archival_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Fondo (top-level) ────────────────────────────────────────────────────
INSERT IGNORE INTO archival_units
    (reference_code, institution_code, level, constructed_title, formal_title,
     date_start, date_end, extent, scope_content, language_codes,
     specific_material, local_classification, created_at)
VALUES
    ('E2E_FILE_FONDS_001', 'PINAKES', 'fonds',
     'Fondo E2E Multi-documento',
     'Fondo test per upload multi-documento',
     1900, 1990,
     '3 fascicoli, 12 buste',
     'Fondo di test per verifica caricamento documenti multipli per unità archivistica.',
     'ita',
     'text', 'E2EFILE', NOW());

-- ── 3. Item A (figlio del fondo) ────────────────────────────────────────────
INSERT IGNORE INTO archival_units
    (parent_id, reference_code, institution_code, level, constructed_title,
     date_start, date_end, scope_content, language_codes, specific_material,
     local_classification, created_at)
SELECT
    (SELECT id FROM archival_units WHERE reference_code = 'E2E_FILE_FONDS_001' LIMIT 1),
    'E2E_FILE_ITEM_001', 'PINAKES', 'item',
    'Item A - documento singolo',
    1945, 1945,
    'Singolo documento di prova allegato all''unità A.',
    'ita', 'text', 'E2EFILE-A', NOW()
FROM DUAL
WHERE EXISTS (SELECT 1 FROM archival_units WHERE reference_code = 'E2E_FILE_FONDS_001');

-- ── 4. Item B (figlio del fondo) ────────────────────────────────────────────
INSERT IGNORE INTO archival_units
    (parent_id, reference_code, institution_code, level, constructed_title,
     date_start, date_end, scope_content, language_codes, specific_material,
     local_classification, created_at)
SELECT
    (SELECT id FROM archival_units WHERE reference_code = 'E2E_FILE_FONDS_001' LIMIT 1),
    'E2E_FILE_ITEM_002', 'PINAKES', 'item',
    'Item B - senza documenti',
    1960, 1975,
    'Unità senza documenti allegati (verifica assenza lista).',
    'ita', 'text', 'E2EFILE-B', NOW()
FROM DUAL
WHERE EXISTS (SELECT 1 FROM archival_units WHERE reference_code = 'E2E_FILE_FONDS_001');

-- ── 5. archival_unit_files rows for the fondo (3 files) ─────────────────────
-- These paths are fictional; they do not need to exist on disk for
-- interoperability XML tests. Use sort_order 0, 1, 2.
INSERT INTO archival_unit_files
    (unit_id, file_path, file_mime, original_filename, sort_order)
SELECT
    u.id,
    CONCAT('/uploads/archives/documents/', u.id, '-e2e-doc-a.pdf'),
    'application/pdf',
    'inventario-generale.pdf',
    0
FROM archival_units u
WHERE u.reference_code = 'E2E_FILE_FONDS_001'
  AND NOT EXISTS (
      SELECT 1 FROM archival_unit_files f
       WHERE f.unit_id = u.id
         AND f.original_filename = 'inventario-generale.pdf'
  );

INSERT INTO archival_unit_files
    (unit_id, file_path, file_mime, original_filename, sort_order)
SELECT
    u.id,
    CONCAT('/uploads/archives/documents/', u.id, '-e2e-doc-b.pdf'),
    'application/pdf',
    'registro-entrate-uscite.pdf',
    1
FROM archival_units u
WHERE u.reference_code = 'E2E_FILE_FONDS_001'
  AND NOT EXISTS (
      SELECT 1 FROM archival_unit_files f
       WHERE f.unit_id = u.id
         AND f.original_filename = 'registro-entrate-uscite.pdf'
  );

INSERT INTO archival_unit_files
    (unit_id, file_path, file_mime, original_filename, sort_order)
SELECT
    u.id,
    CONCAT('/uploads/archives/documents/', u.id, '-e2e-doc-c.pdf'),
    'application/pdf',
    'corrispondenza-1900-1990.pdf',
    2
FROM archival_units u
WHERE u.reference_code = 'E2E_FILE_FONDS_001'
  AND NOT EXISTS (
      SELECT 1 FROM archival_unit_files f
       WHERE f.unit_id = u.id
         AND f.original_filename = 'corrispondenza-1900-1990.pdf'
  );

-- ── 6. archival_unit_files rows for Item A (1 file) ─────────────────────────
INSERT INTO archival_unit_files
    (unit_id, file_path, file_mime, original_filename, sort_order)
SELECT
    u.id,
    CONCAT('/uploads/archives/documents/', u.id, '-e2e-item-a.pdf'),
    'application/pdf',
    'lettera-1945.pdf',
    0
FROM archival_units u
WHERE u.reference_code = 'E2E_FILE_ITEM_001'
  AND NOT EXISTS (
      SELECT 1 FROM archival_unit_files f
       WHERE f.unit_id = u.id
         AND f.original_filename = 'lettera-1945.pdf'
  );

-- ── No files for Item B (tests that the empty-list message appears) ──────────
