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
