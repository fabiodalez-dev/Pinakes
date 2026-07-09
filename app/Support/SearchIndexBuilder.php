<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maintains the denormalized `libri`.`search_index` FULLTEXT column.
 *
 * `search_index` folds together, per book, the fields users actually search
 * by — title, subtitle, the book's author names, its publisher name(s),
 * ISBN10/ISBN13/EAN, keywords and the (plain-text) description — so the catalog
 * / autocomplete / preview searches can match on a single MATCH(search_index)
 * AGAINST(...) instead of a long OR-of-LIKE chain plus a per-row author EXISTS
 * subquery. Common HTML entities are decoded on the concatenated value so
 * entity-encoded fields (`L&#039;orologio`) tokenize as real words.
 *
 * Author and publisher names live in JOINed tables (libri_autori/autori,
 * editori, libri_editori), so the column can NOT be a MySQL generated column:
 * it is rebuilt here after every book/author/publisher save, and seeded once by
 * migrate_0.7.31.sql for pre-existing rows.
 *
 * SOFT-DELETE: every UPDATE is scoped `AND deleted_at IS NULL` (project rule 2).
 */
final class SearchIndexBuilder
{
    private function __construct()
    {
    }

    /**
     * Rebuild one book's search_index from its current title, subtitle, author
     * names, publisher name(s), ISBN/EAN and keywords. No-op if the column does
     * not exist yet (e.g. code deployed but migration not yet run).
     */
    public static function rebuild(\mysqli $db, int $bookId): void
    {
        if ($bookId <= 0 || !self::columnExists($db)) {
            return;
        }

        try {
            // Match the migration's backfill: a book with many authors/publishers
            // must not have its GROUP_CONCAT silently truncated at the 1024-byte
            // default, otherwise runtime and backfill would produce different
            // values. Best-effort — a failure here must not break the rebuild.
            try {
                $db->query('SET SESSION group_concat_max_len = 1000000');
            } catch (\Throwable $ignored) {
            }

            $hasJunction = SchemaInfo::hasLibriEditori($db);

            // Secondary publishers (issue #143) folded in only when the junction
            // table exists; otherwise search_index carries the primary publisher.
            $junctionJoin = $hasJunction
                ? "LEFT JOIN (
                        SELECT le.libro_id, GROUP_CONCAT(e2.nome SEPARATOR ' ') AS editori_sec
                        FROM libri_editori le
                        JOIN editori e2 ON e2.id = le.editore_id
                        WHERE le.libro_id = ?
                        GROUP BY le.libro_id
                    ) ex ON ex.libro_id = l.id"
                : '';
            $junctionField = $hasJunction ? 'ex.editori_sec,' : '';

            // Raw columns may be stored HTML-entity-encoded (e.g. `L&#039;orologio`,
            // `Q&amp;A`), which FULLTEXT tokenizes wrong (`l`,`039`,`orologio`).
            // Decode the common entities on the FINAL concatenated value — the
            // IDENTICAL REPLACE chain lives in migrate_0.7.31.sql's backfill so
            // runtime and backfill produce the same content. &amp; is decoded
            // OUTERMOST (last) so `&amp;lt;` does not double-decode.
            $sql = "UPDATE libri l
                LEFT JOIN (
                        SELECT la.libro_id, GROUP_CONCAT(a.nome SEPARATOR ' ') AS autori
                        FROM libri_autori la
                        JOIN autori a ON a.id = la.autore_id
                        WHERE la.libro_id = ?
                        GROUP BY la.libro_id
                    ) ax ON ax.libro_id = l.id
                LEFT JOIN editori e ON e.id = l.editore_id
                {$junctionJoin}
                SET l.search_index = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        CONCAT_WS(' ',
                            l.titolo, l.sottotitolo, ax.autori, e.nome, {$junctionField}
                            l.isbn10, l.isbn13, l.ean, l.parole_chiave,
                            COALESCE(l.descrizione_plain, l.descrizione))
                    , '&#039;', ''''), '&#39;', ''''), '&quot;', '\"'), '&lt;', '<'), '&gt;', '>'), '&nbsp;', ' '), '&amp;', '&')
                WHERE l.id = ? AND l.deleted_at IS NULL";

            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return;
            }
            if ($hasJunction) {
                $stmt->bind_param('iii', $bookId, $bookId, $bookId);
            } else {
                $stmt->bind_param('ii', $bookId, $bookId);
            }
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // The search index is a derived cache — never let a rebuild failure
            // break the surrounding save. Log and move on.
            SecureLogger::warning('SearchIndexBuilder::rebuild failed', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rebuild search_index for every (non-deleted) book linked to an author.
     * Called when an author's name changes (edit / merge / delete).
     */
    public static function rebuildForAuthor(\mysqli $db, int $authorId): void
    {
        if ($authorId <= 0) {
            return;
        }
        self::rebuildMany($db, self::bookIdsForAuthor($db, $authorId));
    }

    /**
     * Rebuild search_index for every (non-deleted) book linked to a publisher,
     * via either the primary FK (libri.editore_id) or the secondary junction
     * (libri_editori). Called when a publisher's name changes.
     */
    public static function rebuildForPublisher(\mysqli $db, int $publisherId): void
    {
        if ($publisherId <= 0) {
            return;
        }
        self::rebuildMany($db, self::bookIdsForPublisher($db, $publisherId));
    }

    /**
     * Collect the book ids linked to an author BEFORE any mutating statement,
     * so callers can snapshot the affected set ahead of a merge/delete that
     * removes the linking rows. Returns non-deleted books only.
     *
     * @return int[]
     */
    public static function bookIdsForAuthor(\mysqli $db, int $authorId): array
    {
        if ($authorId <= 0) {
            return [];
        }
        $ids = [];
        try {
            $stmt = $db->prepare(
                'SELECT la.libro_id FROM libri_autori la
                 JOIN libri l ON l.id = la.libro_id
                 WHERE la.autore_id = ? AND l.deleted_at IS NULL'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('i', $authorId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_row()) {
                $ids[] = (int) $row[0];
            }
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::warning('SearchIndexBuilder::bookIdsForAuthor failed', [
                'author_id' => $authorId,
                'error' => $e->getMessage(),
            ]);
        }
        return $ids;
    }

    /**
     * Collect the book ids linked to a publisher (primary FK OR secondary
     * junction) BEFORE any mutating statement. Returns non-deleted books only.
     *
     * @return int[]
     */
    public static function bookIdsForPublisher(\mysqli $db, int $publisherId): array
    {
        if ($publisherId <= 0) {
            return [];
        }
        $ids = [];
        try {
            $stmt = $db->prepare(
                'SELECT id FROM libri WHERE editore_id = ? AND deleted_at IS NULL'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('i', $publisherId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_row()) {
                $ids[(int) $row[0]] = (int) $row[0];
            }
            $stmt->close();

            if (SchemaInfo::hasLibriEditori($db)) {
                $stmt = $db->prepare(
                    'SELECT le.libro_id FROM libri_editori le
                     JOIN libri l ON l.id = le.libro_id
                     WHERE le.editore_id = ? AND l.deleted_at IS NULL'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('i', $publisherId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_row()) {
                        $ids[(int) $row[0]] = (int) $row[0];
                    }
                    $stmt->close();
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('SearchIndexBuilder::bookIdsForPublisher failed', [
                'publisher_id' => $publisherId,
                'error' => $e->getMessage(),
            ]);
        }
        return array_values($ids);
    }

    /**
     * Rebuild a batch of books. Accepts a pre-captured id list (see
     * bookIdsForAuthor/bookIdsForPublisher) so merge/delete callers can snapshot
     * the affected set before the linking rows disappear.
     *
     * @param int[] $bookIds
     */
    public static function rebuildMany(\mysqli $db, array $bookIds): void
    {
        foreach ($bookIds as $bookId) {
            self::rebuild($db, (int) $bookId);
        }
    }

    /**
     * Build the WHERE fragment for a user book search against a FULLTEXT
     * column (typically `l.search_index`).
     *
     * Long tokens (>= 3 chars, above innodb_ft_min_token_size) are folded into a
     * single BOOLEAN-mode AGAINST string as `+token*` (prefix, AND semantics —
     * every word must match). Tokens shorter than 3 chars, which FULLTEXT can
     * not index, fall back to a `LIKE '%token%'` on the same column so 1–2 char
     * searches keep working. All parts are ANDed together, preserving the
     * original "every word must match somewhere" behaviour.
     *
     * UPGRADE-WINDOW SAFETY: when the `search_index` column does not exist yet
     * (new PHP deployed but the admin has not run the 0.7.31 DB migration),
     * MATCH/LIKE on it would 500 every catalog search + autocomplete/preview
     * (1191 "Can't find FULLTEXT index" / 1054 "Unknown column"). In that case
     * we fall back to a LIKE-of-OR chain over the REAL columns (titolo,
     * sottotitolo, isbn10, isbn13, ean) so search keeps working until the
     * migration runs.
     *
     * @return array{sql:string, params:array<int,string>, types:string}|null
     *   null when the query yields no usable token (caller should add no
     *   condition and return all rows).
     */
    public static function buildSearchCondition(\mysqli $db, string $column, string $searchQuery): ?array
    {
        $searchQuery = trim($searchQuery);
        if ($searchQuery === '') {
            return null;
        }

        // Pre-migration: the denormalized FULLTEXT column is not there yet.
        // Match on the real columns instead so search does not 500.
        if (!self::columnExists($db)) {
            return self::buildLegacyCondition($column, $searchQuery);
        }

        $words = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $booleanTerms = [];
        $parts = [];
        $params = [];
        $types = '';

        foreach ($words as $word) {
            $wordBase = rtrim($word, '*');
            $sanitizedBase = self::escapeFulltextWord($wordBase, false);
            if ($sanitizedBase === '') {
                continue;
            }
            if (strlen($wordBase) >= 3) {
                $booleanTerms[] = '+' . self::escapeFulltextWord($word)
                    . (str_ends_with($word, '*') ? '' : '*');
            } else {
                // Below ft_min_token_size — FULLTEXT can't match it, LIKE fallback.
                $parts[] = "{$column} LIKE ?";
                $params[] = '%' . $wordBase . '%';
                $types .= 's';
            }
        }

        // MATCH goes first so its parameter aligns before the short-token LIKEs.
        if (!empty($booleanTerms)) {
            array_unshift($parts, "MATCH({$column}) AGAINST (? IN BOOLEAN MODE)");
            array_unshift($params, implode(' ', $booleanTerms));
            $types = 's' . $types;
        }

        if (empty($parts)) {
            return null;
        }

        return [
            'sql' => '(' . implode(' AND ', $parts) . ')',
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * Pre-migration fallback: the `search_index` column does not exist yet, so
     * build the condition over the REAL columns that a book always carries
     * (titolo, sottotitolo, isbn10, isbn13, ean). For each word we emit an
     * OR-of-LIKE over those five columns, ANDed across words (every word must
     * match somewhere), mirroring the normal path's "all words required"
     * semantics and returning the same {sql,params,types} shape so callers bind
     * generically.
     *
     * The table alias is derived from $column: the part before '.', e.g. 'l'
     * from 'l.search_index' (empty when there is no dot).
     *
     * @return array{sql:string, params:array<int,string>, types:string}|null
     */
    private static function buildLegacyCondition(string $column, string $searchQuery): ?array
    {
        $dot = strpos($column, '.');
        $prefix = $dot === false ? '' : substr($column, 0, $dot + 1);

        $words = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        $params = [];
        $types = '';

        foreach ($words as $word) {
            $like = '%' . $word . '%';
            $parts[] = "({$prefix}titolo LIKE ? OR {$prefix}sottotitolo LIKE ?"
                . " OR {$prefix}isbn10 LIKE ? OR {$prefix}isbn13 LIKE ? OR {$prefix}ean LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        if (empty($parts)) {
            return null;
        }

        return [
            'sql' => '(' . implode(' AND ', $parts) . ')',
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * Strip FULLTEXT BOOLEAN MODE operators from a word. MySQL FULLTEXT has no
     * backslash escaping, so the operators (+ - > < ( ) ~ * " @) must be
     * removed. A trailing '*' (prefix wildcard) is preserved when allowed.
     */
    private static function escapeFulltextWord(string $word, bool $allowTrailingWildcard = true): string
    {
        $hasTrailingWildcard = $allowTrailingWildcard && str_ends_with($word, '*');
        if ($hasTrailingWildcard) {
            $word = substr($word, 0, -1);
        }

        $word = str_replace(
            ['+', '-', '>', '<', '(', ')', '~', '*', '"', '@'],
            '',
            $word
        );

        if ($hasTrailingWildcard && $word !== '') {
            $word .= '*';
        }

        return $word;
    }

    /** @var bool|null */
    private static $columnExists = null;

    private static function columnExists(\mysqli $db): bool
    {
        if (self::$columnExists === null) {
            try {
                $res = $db->query("SHOW COLUMNS FROM libri LIKE 'search_index'");
                self::$columnExists = $res !== false && $res->num_rows > 0;
                if ($res instanceof \mysqli_result) {
                    $res->free();
                }
            } catch (\Throwable $e) {
                self::$columnExists = false;
            }
        }
        return self::$columnExists;
    }
}
