-- Migration 0.7.61-rc.1 — backfill per-copy rows for legacy books
--
-- 0.7.61 derives libri.copie_totali / copie_disponibili / stato from the
-- `copie` table (DataIntegrity::recalculateBookAvailability). Installs whose
-- books predate copy tracking carry only the legacy counters and have NO
-- `copie` rows: the first recalc would count zero copies and zero out every
-- legacy book (206 books -> 0/0/non_disponibile observed in the field).
--
-- This migration runs inside Updater::runMigrations(), i.e. BEFORE the
-- post-migration recalculateAllBookAvailability() call, and materialises the
-- legacy counters into real `copie` rows:
--
--   Pass A  mint the missing copies with the app's "{base}-C{N}" inventory
--           codes (base = libri.numero_inventario, or "LIB-{id}" when empty),
--           numbering after the book's existing rows. INSERT IGNORE skips the
--           rare code collision (numero_inventario is globally unique).
--   Pass B  re-check the deficit and fill whatever Pass A had to skip using
--           the per-book-unique "LIB-{id}-C{N}" base, numbering above the
--           highest existing numeric suffix of that base, so it can never
--           collide with pre-existing rows or with itself.
--   Pass C  bind active book-level loans (copia_id IS NULL) to distinct free
--           copies so the derived availability keeps reflecting them: without
--           a copia_id the new model cannot see the loan and the book would
--           read fully available while physically out.
--
-- The whole file is idempotent: each pass recomputes the deficit (0 on
-- re-run / on installs that already track per-copy rows), never touches
-- existing copie rows (including out-of-circulation states: perso,
-- danneggiato, manutenzione, in_restauro, in_trasferimento), never rebinds a
-- loan that already has a copia_id, and never subtracts anything.

-- Pass A: create the missing copies using the book's own inventory base.
INSERT IGNORE INTO `copie` (libro_id, numero_inventario, stato, note)
SELECT l.id,
       CONCAT(
           CASE
               WHEN l.numero_inventario IS NULL OR TRIM(l.numero_inventario) = ''
                   THEN CONCAT('LIB-', l.id)
               ELSE TRIM(l.numero_inventario)
           END,
           '-C',
           seq.n + (SELECT COUNT(*) FROM `copie` c0 WHERE c0.libro_id = l.id)
       ),
       'disponibile',
       'Backfilled from the pre-0.7.61 copy counter during upgrade'
FROM `libri` l
JOIN (
    SELECT h.d * 100 + t.d * 10 + u.d + 1 AS n
    FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) h
    CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
    CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) seq
    ON seq.n <= GREATEST(
        COALESCE(l.copie_totali, 0) - (
            SELECT COUNT(*)
            FROM `copie` c1
            WHERE c1.libro_id = l.id
              AND c1.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
        ),
        0
    )
WHERE l.deleted_at IS NULL;

-- Pass B: fill any deficit Pass A could not cover because of an inventory-code
-- collision (e.g. two books sharing the same numero_inventario base). The
-- "LIB-{id}-C{N}" base embeds the book id and numbering starts above the
-- highest numeric suffix already present for that exact base, so the produced
-- codes are collision-free by construction.
INSERT IGNORE INTO `copie` (libro_id, numero_inventario, stato, note)
SELECT l.id,
       CONCAT(
           'LIB-', l.id, '-C',
           seq.n + COALESCE((
               SELECT MAX(CAST(SUBSTRING(c3.numero_inventario, CHAR_LENGTH(CONCAT('LIB-', l.id, '-C')) + 1) AS UNSIGNED))
               FROM `copie` c3
               WHERE c3.numero_inventario LIKE CONCAT('LIB-', l.id, '-C%')
           ), 0)
       ),
       'disponibile',
       'Backfilled from the pre-0.7.61 copy counter during upgrade'
FROM `libri` l
JOIN (
    SELECT h.d * 100 + t.d * 10 + u.d + 1 AS n
    FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) h
    CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
    CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
) seq
    ON seq.n <= GREATEST(
        COALESCE(l.copie_totali, 0) - (
            SELECT COUNT(*)
            FROM `copie` c1
            WHERE c1.libro_id = l.id
              AND c1.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
        ),
        0
    )
WHERE l.deleted_at IS NULL;

-- Pass C: bind legacy book-level active loans to distinct free copies.
-- Loans are ranked per book by id; free 'disponibile' copies (not already held
-- by an active or copy-bound pending loan) are ranked per book by id; equal
-- ranks are paired, so no copy is ever assigned to two loans. Loans that
-- already carry a copia_id are untouched. If a book has fewer free copies than
-- unbound active loans (inconsistent legacy counters), the surplus loans keep
-- copia_id NULL exactly as before this migration.
UPDATE `prestiti` p
JOIN (
    SELECT p1.id AS prestito_id,
           p1.libro_id AS libro_id,
           (
               SELECT COUNT(*)
               FROM `prestiti` p2
               WHERE p2.libro_id = p1.libro_id
                 AND p2.attivo = 1
                 AND p2.copia_id IS NULL
                 AND p2.stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
                 AND p2.id < p1.id
           ) AS rn
    FROM `prestiti` p1
    WHERE p1.attivo = 1
      AND p1.copia_id IS NULL
      AND p1.stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
) lr ON lr.prestito_id = p.id
JOIN (
    SELECT c1.id AS copia_id,
           c1.libro_id AS libro_id,
           (
               SELECT COUNT(*)
               FROM `copie` c2
               WHERE c2.libro_id = c1.libro_id
                 AND c2.stato = 'disponibile'
                 AND c2.id < c1.id
                 AND NOT EXISTS (
                     SELECT 1 FROM `prestiti` p3
                     WHERE p3.copia_id = c2.id
                       AND (p3.attivo = 1 OR (p3.attivo = 0 AND p3.stato = 'pendente'))
                 )
           ) AS rn
    FROM `copie` c1
    WHERE c1.stato = 'disponibile'
      AND NOT EXISTS (
          SELECT 1 FROM `prestiti` p4
          WHERE p4.copia_id = c1.id
            AND (p4.attivo = 1 OR (p4.attivo = 0 AND p4.stato = 'pendente'))
      )
) cr ON cr.libro_id = lr.libro_id AND cr.rn = lr.rn
SET p.copia_id = cr.copia_id;
