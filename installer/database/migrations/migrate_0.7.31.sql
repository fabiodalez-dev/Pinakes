-- Migration 0.7.31 — denormalized FULLTEXT search column on `libri`
--
-- Adds `libri`.`search_index` (MEDIUMTEXT) and a dedicated single-column
-- FULLTEXT index `ft_libri_search_index` on it, then backfills every existing
-- row. `search_index` folds together, per book, the fields users actually
-- search by — title, subtitle, the book's author names, its publisher name(s),
-- ISBN10/ISBN13/EAN and keywords — so the catalog / autocomplete / preview
-- searches can match on a single MATCH(search_index) AGAINST(...) instead of a
-- long OR-of-LIKE chain plus a per-row author EXISTS subquery.
--
-- The column can NOT be a MySQL generated column because author and publisher
-- names live in JOINed tables (libri_autori/autori, editori, libri_editori), so
-- it is maintained app-side by App\Support\SearchIndexBuilder on every
-- book/author/publisher save, and seeded once here for pre-existing rows.
--
-- Idempotent (project rule 6 + convention): the column ADD and the FULLTEXT KEY
-- ADD are each guarded by an information_schema probe (MySQL 8 has no
-- "ADD COLUMN/KEY IF NOT EXISTS" and this must stay portable to MariaDB). The
-- backfill is a plain UPDATE that recomputes the same value, so re-running it is
-- a harmless no-op. One file per release version is mandatory: the updater runs
-- a migration iff its version is > the installed version AND <= the target
-- version (0.7.31), so every 0.7.31 change lives here.

-- Order matters on large tables: ADD COLUMN, then backfill, then ADD FULLTEXT
-- KEY LAST — so InnoDB builds the FULLTEXT index once over already-populated
-- rows instead of maintaining it row-by-row during the backfill UPDATE.

-- ─── libri.search_index column ───────────────────────────────────────────
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'search_index');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `libri` ADD COLUMN `search_index` MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `parole_chiave`",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── Backfill existing rows ───────────────────────────────────────────────
-- Raise group_concat_max_len so a book with many authors/publishers is not
-- silently truncated at the 1024-byte default. CONCAT_WS skips NULLs, so a book
-- with no subtitle/authors/publisher still backfills from the fields it has.
-- The plain-text description (COALESCE(descrizione_plain, descrizione)) is
-- folded in AFTER the keywords so books findable only by a description word
-- stay findable. The nested REPLACE chain decodes the common HTML entities on
-- the FINAL concatenated value (raw columns may be entity-encoded, e.g.
-- `L&#039;orologio`, `Q&amp;A`, which FULLTEXT would tokenize as `l`/`039`).
-- This is the IDENTICAL expression App\Support\SearchIndexBuilder::rebuild()
-- uses so runtime and backfill produce the same content; &amp; is decoded
-- OUTERMOST (last) so `&amp;lt;` does not double-decode.
SET SESSION group_concat_max_len = 1000000;

UPDATE `libri` l
LEFT JOIN (
        SELECT la.libro_id, GROUP_CONCAT(a.nome SEPARATOR ' ') AS autori
        FROM `libri_autori` la
        JOIN `autori` a ON a.id = la.autore_id
        GROUP BY la.libro_id
    ) ax ON ax.libro_id = l.id
LEFT JOIN `editori` e ON e.id = l.editore_id
LEFT JOIN (
        SELECT le.libro_id, GROUP_CONCAT(e2.nome SEPARATOR ' ') AS editori_sec
        FROM `libri_editori` le
        JOIN `editori` e2 ON e2.id = le.editore_id
        GROUP BY le.libro_id
    ) ex ON ex.libro_id = l.id
SET l.`search_index` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        CONCAT_WS(' ',
            l.titolo, l.sottotitolo, ax.autori, e.nome, ex.editori_sec,
            l.isbn10, l.isbn13, l.ean, l.parole_chiave,
            COALESCE(l.descrizione_plain, l.descrizione))
    , '&#039;', ''''), '&#39;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&nbsp;', ' '), '&amp;', '&')
WHERE l.deleted_at IS NULL;

-- ─── FULLTEXT KEY on libri.search_index (LAST — over populated rows) ───────
-- Distinct name from the pre-existing multi-column `ft_libri_search`
-- (titolo,sottotitolo,descrizione,parole_chiave) to avoid a 1061 collision.
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'ft_libri_search_index');
SET @sql = IF(@idx_exists = 0,
    "ALTER TABLE `libri` ADD FULLTEXT KEY `ft_libri_search_index` (`search_index`)",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── libri LibraryThing columns backfill (review/rating/comment/private_comment) ───
-- These were added by migrate_0.4.7.sql, but the updater only runs migrations
-- NEWER than the version being upgraded FROM. An install that first updated at
-- 0.7.x never ran 0.4.7, so on it these columns are absent — and code that
-- assumes them (e.g. the Book Club affinity page ORDER BY l.rating, the
-- LibraryThing importer, the book-detail view) then fails with 1054 "Unknown
-- column". Re-add them here, each guarded, so every 0.7.31+ install has them.
-- Idempotent: an install that already has a column skips it via the probe.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'review');
SET @sql = IF(@col_exists = 0, "ALTER TABLE `libri` ADD COLUMN `review` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Book review (LibraryThing)' AFTER `descrizione`", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'rating');
SET @sql = IF(@col_exists = 0, "ALTER TABLE `libri` ADD COLUMN `rating` TINYINT UNSIGNED NULL COMMENT 'Rating 1-5 (LibraryThing)' AFTER `review`", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'comment');
SET @sql = IF(@col_exists = 0, "ALTER TABLE `libri` ADD COLUMN `comment` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Public comment (LibraryThing)' AFTER `rating`", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'private_comment');
SET @sql = IF(@col_exists = 0, "ALTER TABLE `libri` ADD COLUMN `private_comment` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Private comment (LibraryThing)' AFTER `comment`", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── libri.rating index + CHECK (match installer/database/schema.sql) ───
-- migrate_0.4.7 shipped idx_lt_rating and chk_lt_rating alongside the rating
-- column; re-add them here (guarded) so an install that regains rating via
-- this migration also regains the same index + range constraint, not just the
-- bare column. Idempotent via information_schema probes.
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND INDEX_NAME = 'idx_lt_rating');
SET @sql = IF(@idx_exists = 0, "ALTER TABLE `libri` ADD KEY `idx_lt_rating` (`rating`)", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Normalise any out-of-range rating BEFORE adding the CHECK, otherwise the
-- ALTER ... ADD CONSTRAINT fails on pre-existing rows (e.g. a legacy import that
-- wrote 0 or a 1-10 scale) and aborts the whole migration. Idempotent: on an
-- install that already has the constraint there are no violating rows, so this
-- touches nothing. Keep NULLs as-is (they satisfy the constraint).
UPDATE `libri` SET `rating` = NULL WHERE `rating` IS NOT NULL AND `rating` NOT BETWEEN 1 AND 5;

-- CHECK constraint names are schema-unique (not per-table) and this MySQL/
-- MariaDB exposes them in CHECK_CONSTRAINTS (keyed by schema+name), NOT
-- TABLE_CONSTRAINTS — probing the wrong table would re-issue the ADD on an
-- install that already has it and fail with "Duplicate check constraint name".
SET @chk_exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_lt_rating');
SET @sql = IF(@chk_exists = 0, "ALTER TABLE `libri` ADD CONSTRAINT `chk_lt_rating` CHECK (`rating` IS NULL OR `rating` BETWEEN 1 AND 5)", "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── oai_deleted_records.created_at backfill ───────────────────────────────
-- schema.sql defines `oai_deleted_records`.`created_at` (timestamp NOT NULL
-- DEFAULT CURRENT_TIMESTAMP), but the column was added to schema.sql after the
-- table already shipped, and no earlier migration added it to existing tables.
-- So an install created before the column existed (e.g. bibliodoc, first seen
-- 2026) has the table WITHOUT created_at — and any code/tooling that assumes
-- the schema.sql shape drifts. Re-add it here, guarded, so every 0.7.31+ install
-- with the OAI table converges to schema.sql. Doubly guarded: the table only
-- exists where the OAI-PMH feature has been used, so skip cleanly when absent
-- (a fresh install without OAI has no such table). Idempotent via the probes.
SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oai_deleted_records');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oai_deleted_records' AND COLUMN_NAME = 'created_at');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
    "ALTER TABLE `oai_deleted_records` ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
    "SELECT 1");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
