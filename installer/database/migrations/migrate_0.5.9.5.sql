-- Migration script for Pinakes 0.5.9.5
-- Description: Series grouping and cycle/season metadata for collane
-- Date: 2026-04-29
-- FULLY IDEMPOTENT: Uses INFORMATION_SCHEMA guards for each column/index

-- =============================================================================
-- Extend collane metadata so a leaf series can belong to an umbrella group
-- and optionally declare a cycle/season label and ordering.
-- =============================================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND COLUMN_NAME = 'parent_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE collane ADD COLUMN parent_id INT NULL COMMENT ''Parent series/cycle for nested series hierarchies'' AFTER id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND COLUMN_NAME = 'tipo'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE collane ADD COLUMN tipo VARCHAR(32) NOT NULL DEFAULT ''serie'' COMMENT ''Series kind: serie, universo, ciclo, stagione, spin_off, arco, collezione_editoriale, altro'' AFTER parent_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND COLUMN_NAME = 'gruppo_serie'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE collane ADD COLUMN gruppo_serie VARCHAR(100) NULL COMMENT ''Umbrella series/universe grouping for spin-offs'' AFTER descrizione',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND COLUMN_NAME = 'ciclo'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE collane ADD COLUMN ciclo VARCHAR(100) NULL COMMENT ''Cycle or season label inside the series group'' AFTER gruppo_serie',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND COLUMN_NAME = 'ordine_ciclo'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE collane ADD COLUMN ordine_ciclo SMALLINT UNSIGNED NULL COMMENT ''Sort order for cycle/season inside the group'' AFTER ciclo',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND INDEX_NAME = 'idx_collane_parent'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE collane ADD INDEX idx_collane_parent (parent_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND INDEX_NAME = 'idx_collane_tipo'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE collane ADD INDEX idx_collane_tipo (tipo)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND INDEX_NAME = 'idx_collane_gruppo_serie'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE collane ADD INDEX idx_collane_gruppo_serie (gruppo_serie)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND CONSTRAINT_NAME = 'fk_collane_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE collane ADD CONSTRAINT fk_collane_parent FOREIGN KEY (parent_id) REFERENCES collane(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- Multiple series memberships per book.
-- libri.collana / libri.numero_serie remain the primary legacy fields.
-- =============================================================================

CREATE TABLE IF NOT EXISTS libri_collane (
    id INT AUTO_INCREMENT PRIMARY KEY,
    libro_id INT NOT NULL,
    collana_id INT NOT NULL,
    numero_serie VARCHAR(50) DEFAULT NULL,
    tipo_appartenenza VARCHAR(32) NOT NULL DEFAULT 'principale',
    is_principale TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_libro_collana (libro_id, collana_id),
    KEY idx_lc_collana (collana_id),
    KEY idx_lc_principale (libro_id, is_principale),
    CONSTRAINT fk_lc_libro FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
    CONSTRAINT fk_lc_collana FOREIGN KEY (collana_id) REFERENCES collane(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO collane (nome)
SELECT DISTINCT l.collana
  FROM libri l
 WHERE l.collana IS NOT NULL
   AND l.collana != ''
   AND l.deleted_at IS NULL;

INSERT IGNORE INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale)
SELECT l.id, c.id, l.numero_serie, 'principale', 1
  FROM libri l
  JOIN collane c ON c.nome = l.collana
 WHERE l.collana IS NOT NULL
   AND l.collana != ''
   AND l.deleted_at IS NULL;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND INDEX_NAME = 'idx_collane_gruppo_ordine'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE collane ADD INDEX idx_collane_gruppo_ordine (gruppo_serie, ordine_ciclo, nome)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- DATA-4 (review): CHECK constraint on `tipo` so direct INSERTs from plugins,
-- CSV imports, scrapers cannot land arbitrary values that bypass the
-- application-side normalizeType() whitelist.
-- Requires MySQL 8.0.16+ (CHECK enforcement). Pre-8.0.16 silently parses
-- but doesn't enforce — acceptable degradation since the application path
-- already validates.
-- =============================================================================
SET @chk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'collane'
      AND CONSTRAINT_NAME = 'chk_collane_tipo'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql = IF(@chk_exists = 0,
    "ALTER TABLE collane ADD CONSTRAINT chk_collane_tipo CHECK (tipo IN ('serie','universo','ciclo','stagione','spin_off','arco','collezione_editoriale','altro'))",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
