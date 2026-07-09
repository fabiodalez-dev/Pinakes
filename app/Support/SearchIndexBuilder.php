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
        if ($bookId <= 0) {
            return;
        }
        self::rebuildMany($db, [$bookId]);
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
        // De-dupe + drop non-positive ids.
        $ids = [];
        foreach ($bookIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === [] || !self::columnExists($db)) {
            return;
        }

        try {
            // Match the migration's backfill: a book with many authors/publishers
            // must not have its GROUP_CONCAT silently truncated at the 1024-byte
            // default. Set once for the whole batch — best-effort.
            try {
                $db->query('SET SESSION group_concat_max_len = 1000000');
            } catch (\Throwable $ignored) {
            }

            $hasJunction = SchemaInfo::hasLibriEditori($db);

            // ONE UPDATE per chunk (not per book): renaming/merging a prolific
            // author/publisher with thousands of linked books would otherwise fire
            // thousands of synchronous UPDATEs on the request thread. Chunk so the
            // IN(...) placeholder count stays well under the bind limit.
            foreach (array_chunk($ids, self::REBUILD_CHUNK) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));

                // Secondary publishers (issue #143) folded in only when the
                // junction table exists; both correlated subqueries are scoped to
                // the chunk's ids so they stay cheap.
                $junctionJoin = $hasJunction
                    ? "LEFT JOIN (
                            SELECT le.libro_id, GROUP_CONCAT(e2.nome SEPARATOR ' ') AS editori_sec
                            FROM libri_editori le
                            JOIN editori e2 ON e2.id = le.editore_id
                            WHERE le.libro_id IN ({$ph})
                            GROUP BY le.libro_id
                        ) ex ON ex.libro_id = l.id"
                    : '';
                $junctionField = $hasJunction ? 'ex.editori_sec,' : '';

                // Raw columns may be stored HTML-entity-encoded (e.g.
                // `L&#039;orologio`, `Q&amp;A`), which FULLTEXT tokenizes wrong.
                // Decode the common entities on the FINAL concatenated value — the
                // IDENTICAL REPLACE chain lives in migrate_0.7.31.sql's backfill so
                // runtime and backfill produce the same content. &amp; is decoded
                // OUTERMOST (last) so `&amp;lt;` does not double-decode.
                $sql = "UPDATE libri l
                    LEFT JOIN (
                            SELECT la.libro_id, GROUP_CONCAT(a.nome SEPARATOR ' ') AS autori
                            FROM libri_autori la
                            JOIN autori a ON a.id = la.autore_id
                            WHERE la.libro_id IN ({$ph})
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
                    WHERE l.id IN ({$ph}) AND l.deleted_at IS NULL";

                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    continue;
                }
                // Bind the chunk's ids once per IN(...): author subquery,
                // [junction subquery], then the outer WHERE — same order as the SQL.
                $bind = $chunk;                          // author subquery
                if ($hasJunction) {
                    $bind = array_merge($bind, $chunk);  // junction subquery
                }
                $bind = array_merge($bind, $chunk);      // outer WHERE
                $stmt->bind_param(str_repeat('i', count($bind)), ...$bind);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // The search index is a derived cache — never let a rebuild failure
            // break the surrounding save. Log and move on.
            SecureLogger::warning('SearchIndexBuilder::rebuildMany failed', [
                'count' => count($ids),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Books per batch UPDATE — keeps the IN(...) placeholder count bounded. */
    private const REBUILD_CHUNK = 500;

    /**
     * Build the WHERE fragment for a user book search against a FULLTEXT
     * column (typically `l.search_index`).
     *
     * Tokens that MySQL can index safely are folded into a single BOOLEAN-mode
     * AGAINST string as `+token*` (prefix, AND semantics — every word must
     * match). Stopwords, tokens shorter than innodb_ft_min_token_size and terms
     * with punctuation that the FULLTEXT parser would split (`C++`, `Q&A`,
     * `L'orologio`) fall back to a `LIKE '%token%'` filter on the same column.
     * All parts are ANDed together, preserving the original "every word must
     * match somewhere" behaviour without letting unindexable required terms zero
     * out otherwise valid results.
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
            return self::buildLegacyCondition($db, $column, $searchQuery);
        }

        $words = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $booleanTerms = [];
        $parts = [];
        $params = [];
        $types = '';

        foreach ($words as $word) {
            $wordBase = trim(rtrim($word, '*'));
            if ($wordBase === '') {
                continue;
            }
            $fulltextTerm = self::fulltextTermForWord($wordBase);
            if ($fulltextTerm !== null) {
                $booleanTerms[] = '+' . $fulltextTerm . '*';
            } else {
                $parts[] = "{$column} LIKE ? ESCAPE '\\\\'";
                $params[] = self::likePattern($wordBase);
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
     * build the condition over the real book columns plus author/publisher
     * subqueries. For each word we emit an OR-of-LIKE, ANDed across words (every
     * word must match somewhere), mirroring the normal path's semantics and
     * returning the same {sql,params,types} shape so callers bind generically.
     *
     * The table alias is derived from $column: the part before '.', e.g. 'l'
     * from 'l.search_index' (empty when there is no dot).
     *
     * @return array{sql:string, params:array<int,string>, types:string}|null
     */
    private static function buildLegacyCondition(\mysqli $db, string $column, string $searchQuery): ?array
    {
        $dot = strpos($column, '.');
        $prefix = $dot === false ? '' : substr($column, 0, $dot + 1);
        $descExpr = self::descriptionExpr($db, $prefix);

        $words = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        $params = [];
        $types = '';

        foreach ($words as $word) {
            $like = self::likePattern($word);
            $bookIdExpr = $prefix . 'id';
            $publisherIdExpr = $prefix . 'editore_id';
            $parts[] = "({$prefix}titolo LIKE ? ESCAPE '\\\\'"
                . " OR {$prefix}sottotitolo LIKE ? ESCAPE '\\\\'"
                . " OR {$descExpr} LIKE ? ESCAPE '\\\\'"
                . " OR {$prefix}parole_chiave LIKE ? ESCAPE '\\\\'"
                . " OR {$prefix}isbn10 LIKE ? ESCAPE '\\\\'"
                . " OR {$prefix}isbn13 LIKE ? ESCAPE '\\\\'"
                . " OR {$prefix}ean LIKE ? ESCAPE '\\\\'"
                . " OR EXISTS (SELECT 1 FROM libri_autori la JOIN autori a ON la.autore_id = a.id WHERE la.libro_id = {$bookIdExpr} AND a.nome LIKE ? ESCAPE '\\\\')"
                . " OR EXISTS (SELECT 1 FROM editori e WHERE e.id = {$publisherIdExpr} AND e.nome LIKE ? ESCAPE '\\\\'))";
            for ($i = 0; $i < 9; $i++) {
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
     * Return a single safe FULLTEXT token for a raw user word, or null when
     * FULLTEXT would ignore/split it and the caller should use LIKE instead.
     */
    private static function fulltextTermForWord(string $word): ?string
    {
        $tokens = self::fulltextTokens($word);
        if (count($tokens) !== 1) {
            return null;
        }

        $token = $tokens[0];
        if (mb_strlen($token, 'UTF-8') < 3) {
            return null;
        }

        if (self::isDefaultFulltextStopword($token)) {
            return null;
        }

        return $token;
    }

    /**
     * Approximate MySQL/InnoDB's word tokenization for deciding whether a user
     * term is safe to require in BOOLEAN MODE.
     *
     * @return list<string>
     */
    private static function fulltextTokens(string $word): array
    {
        if (preg_match_all('/[\p{L}\p{N}_]+/u', $word, $matches) !== 1) {
            return [];
        }
        return array_values(array_filter($matches[0], static fn(string $token): bool => $token !== ''));
    }

    private static function isDefaultFulltextStopword(string $token): bool
    {
        static $stopwords = [
            'a' => true, 'about' => true, 'an' => true, 'are' => true,
            'as' => true, 'at' => true, 'be' => true, 'by' => true,
            'com' => true, 'de' => true, 'en' => true, 'for' => true,
            'from' => true, 'how' => true, 'i' => true, 'in' => true,
            'is' => true, 'it' => true, 'la' => true, 'of' => true,
            'on' => true, 'or' => true, 'that' => true, 'the' => true,
            'this' => true, 'to' => true, 'was' => true, 'what' => true,
            'when' => true, 'where' => true, 'who' => true, 'will' => true,
            'with' => true, 'und' => true, 'www' => true,
        ];

        return isset($stopwords[mb_strtolower($token, 'UTF-8')]);
    }

    private static function likePattern(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }

    private static ?bool $hasDescrizionePlain = null;

    private static function descriptionExpr(\mysqli $db, string $prefix): string
    {
        if (self::$hasDescrizionePlain === null) {
            try {
                $res = $db->query("SHOW COLUMNS FROM libri LIKE 'descrizione_plain'");
                self::$hasDescrizionePlain = $res !== false && $res->num_rows > 0;
                if ($res instanceof \mysqli_result) {
                    $res->free();
                }
            } catch (\Throwable $e) {
                self::$hasDescrizionePlain = false;
            }
        }

        return self::$hasDescrizionePlain
            ? "COALESCE(NULLIF({$prefix}descrizione_plain, ''), {$prefix}descrizione)"
            : "{$prefix}descrizione";
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
