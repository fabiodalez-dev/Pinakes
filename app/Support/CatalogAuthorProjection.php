<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Maintains the denormalized principal-author fields used by catalog lists.
 *
 * The public catalog previously executed three correlated author subqueries
 * per returned book. These fields are rebuilt alongside search_index by the
 * already-centralized contributor write paths, reducing each listing query to
 * direct column reads while preserving a pre-migration fallback.
 */
final class CatalogAuthorProjection
{
    private const REBUILD_CHUNK = 500;

    /** @var \WeakMap<\mysqli, true>|null Positive cache: the three columns exist. */
    private static ?\WeakMap $columnsKnownPresent = null;

    /** @var \WeakMap<\mysqli, true>|null Positive cache: the projection is complete and safe to read. */
    private static ?\WeakMap $readableKnown = null;

    private function __construct()
    {
    }

    /**
     * @param int[] $bookIds
     */
    public static function rebuildMany(\mysqli $db, array $bookIds): void
    {
        $ids = [];
        foreach ($bookIds as $bookId) {
            $bookId = (int) $bookId;
            if ($bookId > 0) {
                $ids[$bookId] = $bookId;
            }
        }
        $ids = array_values($ids);
        if ($ids === [] || !self::columnsExist($db)) {
            return;
        }

        try {
            $display = AuthorName::displaySql('a');
            $preferred = AuthorName::preferredSql('a');
            foreach (array_chunk($ids, self::REBUILD_CHUNK) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "UPDATE libri l
                    LEFT JOIN (
                        SELECT la.libro_id,
                               SUBSTRING_INDEX(GROUP_CONCAT({$display}
                                   ORDER BY (la.ruolo = 'principale') DESC,
                                            COALESCE(la.ordine_credito, 2147483647),
                                            la.autore_id
                                   SEPARATOR 0x1F), 0x1F, 1) AS author_display,
                               SUBSTRING_INDEX(GROUP_CONCAT(TRIM(COALESCE(a.nome, ''))
                                   ORDER BY (la.ruolo = 'principale') DESC,
                                            COALESCE(la.ordine_credito, 2147483647),
                                            la.autore_id
                                   SEPARATOR 0x1F), 0x1F, 1) AS author_name,
                               SUBSTRING_INDEX(GROUP_CONCAT(SUBSTRING_INDEX({$preferred}, ' ', -1)
                                   ORDER BY (la.ruolo = 'principale') DESC,
                                            COALESCE(la.ordine_credito, 2147483647),
                                            la.autore_id
                                   SEPARATOR 0x1F), 0x1F, 1) AS author_sort
                        FROM libri_autori la
                        JOIN autori a ON a.id = la.autore_id
                        WHERE la.libro_id IN ({$placeholders})
                          AND la.ruolo IN ('principale', 'co-autore')
                        GROUP BY la.libro_id
                    ) projection ON projection.libro_id = l.id
                    SET l.catalog_author_display = NULLIF(LEFT(projection.author_display, 512), ''),
                        l.catalog_author_name = NULLIF(LEFT(projection.author_name, 255), ''),
                        l.catalog_author_sort = NULLIF(LEFT(projection.author_sort, 160), '')
                    WHERE l.id IN ({$placeholders})";

                $bind = array_merge($chunk, $chunk);
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException('unable to prepare catalog author projection: ' . $db->error);
                }
                $stmt->bind_param(str_repeat('i', count($bind)), ...$bind);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Derived display data must never abort a book/author save.
            SecureLogger::warning('CatalogAuthorProjection rebuild failed', [
                'count' => count($ids),
                'error' => $e->getMessage(),
            ]);
            // A failed rebuild leaves STALE display values on these rows, which
            // the next cache generation would republish as fresh. Null them so
            // isReadable() detects the gap and the whole catalog falls back to
            // the always-correct live author subqueries until a later rebuild
            // (a subsequent contributor edit, or a full reindex) repairs them.
            // Best effort: if this write also fails there is nothing safe left
            // to do beyond the warning already logged above.
            self::invalidateRows($db, $ids);
        }
    }

    /**
     * Null the materialized author columns for the given books so the read
     * path stops trusting them. Used as the failure fallback for rebuildMany().
     *
     * @param int[] $ids already-normalized, non-empty positive ids
     */
    private static function invalidateRows(\mysqli $db, array $ids): void
    {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "UPDATE libri SET catalog_author_display = NULL,
                    catalog_author_name = NULL, catalog_author_sort = NULL
                 WHERE id IN ({$placeholders})"
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $stmt->close();
            // Drop any positive readability cache for this connection so a
            // later read on it re-probes and sees the gap.
            if (self::$readableKnown !== null) {
                unset(self::$readableKnown[$db]);
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('CatalogAuthorProjection invalidation failed', [
                'count' => count($ids),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function columnsExist(\mysqli $db): bool
    {
        self::$columnsKnownPresent ??= new \WeakMap();
        if (isset(self::$columnsKnownPresent[$db])) {
            return true;
        }

        try {
            $result = $db->query(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri'
                   AND COLUMN_NAME IN ('catalog_author_display', 'catalog_author_name', 'catalog_author_sort')"
            );
            $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
            if ((int) ($row['cnt'] ?? 0) === 3) {
                self::$columnsKnownPresent[$db] = true;
            }
        } catch (\Throwable $e) {
        }

        return isset(self::$columnsKnownPresent[$db]);
    }

    /**
     * Whether the catalog read path may trust the materialized author columns.
     *
     * `columnsExist()` only proves the schema is present, which is NOT enough
     * for reads: during the migration's ADD COLUMN → backfill window the
     * columns exist but are still NULL (wrong sort / empty author), and a
     * failed runtime rebuild nulls the affected rows via invalidateRows(). In
     * both cases the projection is incomplete and the reader must fall back to
     * the live author subqueries. A book is "incomplete" when it has a
     * principal/co-author with a non-empty name (so the backfill would have
     * produced a sort key) yet its catalog_author_sort is NULL — the exact
     * condition that leaves ordering and display wrong.
     *
     * Probed once per connection (positive result cached, matching
     * columnsExist) so the hot catalog path pays a single indexed lookup.
     */
    public static function isReadable(\mysqli $db): bool
    {
        if (!self::columnsExist($db)) {
            return false;
        }

        self::$readableKnown ??= new \WeakMap();
        if (isset(self::$readableKnown[$db])) {
            return true;
        }

        try {
            $result = $db->query(
                "SELECT EXISTS(
                    SELECT 1 FROM libri l
                    WHERE l.deleted_at IS NULL
                      AND l.catalog_author_sort IS NULL
                      AND EXISTS(
                          SELECT 1 FROM libri_autori la
                          JOIN autori a ON a.id = la.autore_id
                          WHERE la.libro_id = l.id
                            AND la.ruolo IN ('principale', 'co-autore')
                            AND (TRIM(COALESCE(a.nome, '')) <> ''
                                 OR TRIM(COALESCE(a.pseudonimo, '')) <> '')
                      )
                ) AS incomplete"
            );
            $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
            if ($row !== null && (int) $row['incomplete'] === 0) {
                self::$readableKnown[$db] = true;
                return true;
            }
        } catch (\Throwable $e) {
            // A failed probe must not upgrade to the materialized path: the
            // live subqueries are always correct, only slower.
            SecureLogger::warning('CatalogAuthorProjection readability probe failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
