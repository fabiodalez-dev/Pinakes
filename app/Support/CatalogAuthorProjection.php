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

    /** @var \WeakMap<\mysqli, true>|null */
    private static ?\WeakMap $columnsKnownPresent = null;

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
            // Derived display data must never abort a book/author save. The
            // legacy SELECT remains available until the projection is repaired.
            SecureLogger::warning('CatalogAuthorProjection rebuild failed', [
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
}
