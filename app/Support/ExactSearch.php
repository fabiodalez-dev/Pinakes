<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quoted "exact spelling" search terms (issue #501).
 *
 * Text columns use utf8mb4_unicode_ci, which folds accents: `haffner` matches
 * `Häffner`, `muller` matches `Müller`. That stays the default because it lets
 * people search without typing every diacritic. Wrapping a term in double
 * quotes opts out: `"haffner"` matches only Haffner, and a quoted group with
 * spaces (`"thomas mann"`) must appear as that exact phrase. Letter case is
 * still ignored inside quotes.
 *
 * The comparison is `LOWER(expr) COLLATE utf8mb4_bin LIKE LOWER(?)`: LOWER()
 * removes case, the binary collation keeps accents distinct. It is spelled this
 * way instead of an accent-sensitive collation because those have different
 * names on MySQL 8 (utf8mb4_0900_as_ci) and MariaDB (uca1400_as_ci) and do not
 * exist on older servers, while utf8mb4_bin exists everywhere.
 */
final class ExactSearch
{
    /** Straight and typographic quotes (mobile keyboards type „…“ and “…”). */
    private const QUOTES = '"“”„«»';

    /** Most words a single filter box turns into separate conditions. */
    public const MAX_FILTER_TERMS = 20;

    private function __construct()
    {
    }

    /**
     * Split a free-text query into terms. Quoted groups become one exact term
     * (inner whitespace collapsed); an unclosed quote runs to the end of the
     * query. Everything else is split on whitespace into tolerant terms.
     *
     * @return list<array{term: string, exact: bool}>
     */
    public static function tokenize(string $query): array
    {
        $query = self::scrub($query);
        $q = '[' . preg_quote(self::QUOTES, '/') . ']';
        $parts = preg_split('/(' . $q . '[^' . preg_quote(self::QUOTES, '/') . ']*(?:' . $q . '|$))/u', $query, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $parts = [$query];
        }

        $terms = [];
        foreach ($parts as $part) {
            if (preg_match('/^' . $q . '/u', $part) === 1) {
                $inner = self::collapse(self::stripQuotes($part));
                if ($inner !== '') {
                    $terms[] = ['term' => $inner, 'exact' => true];
                }
                continue;
            }
            foreach (preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $terms[] = ['term' => $word, 'exact' => false];
            }
        }
        return $terms;
    }

    /**
     * Parse a single-value column filter (admin list "Name" box): the whole
     * value is one term, exact when it starts with a quote. Several quoted
     * groups in the box (`"Thomas" "Mann"`) are joined into one phrase, so a
     * quote never ends up inside the LIKE pattern as a literal character.
     *
     * @return array{term: string, exact: bool}
     */
    public static function parseFilter(string $value): array
    {
        $value = trim(self::scrub($value));
        if ($value !== '' && preg_match('/^[' . preg_quote(self::QUOTES, '/') . ']/u', $value) === 1) {
            $words = array_column(self::tokenize($value), 'term');
            return ['term' => self::collapse(implode(' ', $words)), 'exact' => true];
        }
        return ['term' => $value, 'exact' => false];
    }

    /**
     * WHERE fragment for "$expr is exactly $value": accents distinct, case
     * ignored. For lookups by a name taken from a link (author page, publisher
     * filter), where the collation would otherwise treat `Haffner` and
     * `Häffner` as the same name. The plain `=` stays first so an index on
     * $expr still narrows the rows; the binary comparison then drops the
     * accent-folded matches.
     *
     * @return array{sql: string, params: list<string>, types: string}
     */
    public static function equalsCondition(string $expr, string $value): array
    {
        return [
            'sql' => "({$expr} = ? AND LOWER({$expr}) COLLATE utf8mb4_bin = LOWER(?))",
            'params' => [$value, $value],
            'types' => 'ss',
        ];
    }

    /**
     * Same as equalsCondition() for a list of accepted values (IN).
     *
     * @param list<string> $values
     * @return array{sql: string, params: list<string>, types: string}|null
     */
    public static function inCondition(string $expr, array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        $plain = implode(',', array_fill(0, count($values), '?'));
        $lowered = implode(',', array_fill(0, count($values), 'LOWER(?)'));
        return [
            'sql' => "({$expr} IN ({$plain}) AND LOWER({$expr}) COLLATE utf8mb4_bin IN ({$lowered}))",
            'params' => array_merge($values, $values),
            'types' => str_repeat('s', 2 * count($values)),
        ];
    }

    /**
     * ORDER BY fragment that puts the exact spelling (accents included, case
     * ignored) first among rows an accent-folding `$expr = ?` admitted. Bind
     * the same value again. Used by find-by-name lookups so an existing
     * `Häffner` wins over `Haffner` when both are on file, also when the
     * incoming name differs only in case.
     */
    public static function exactFirstSql(string $expr): string
    {
        return "(LOWER({$expr}) COLLATE utf8mb4_bin = LOWER(?)) DESC";
    }

    /**
     * WHERE fragment for a single-value filter matched against any of $exprs
     * (OR), honouring quotes via parseFilter(). Null when nothing is left to
     * match (e.g. the value was only a quote).
     *
     * A box that mixes quoted and unquoted words (`archivio "haffner"`,
     * `"haffner" archivio`) is not one phrase: every word must match, each
     * with its own rule, as in wordsCondition(). Otherwise the quotes of a
     * value that does not start with one would be searched as literal
     * characters and find nothing.
     *
     * @param list<string> $exprs
     * @return array{sql: string, params: list<string>, types: string}|null
     */
    public static function filterCondition(array $exprs, string $value): ?array
    {
        $tokens = self::tokenize($value);
        if (count($tokens) > 1 && in_array(true, array_column($tokens, 'exact'), true)) {
            // Same budget as the other word searches: a pasted wall of words
            // must not turn into thousands of placeholders.
            return self::wordsCondition($exprs, $value, self::MAX_FILTER_TERMS);
        }
        ['term' => $term, 'exact' => $exact] = self::parseFilter($value);
        if ($term === '' || $exprs === []) {
            return null;
        }
        $sql = [];
        $params = [];
        foreach ($exprs as $expr) {
            $sql[] = self::likeSql($expr, $exact);
            $params[] = self::likePattern($term);
        }
        return [
            'sql' => '(' . implode(' OR ', $sql) . ')',
            'params' => $params,
            'types' => str_repeat('s', count($params)),
        ];
    }

    /**
     * WHERE fragment for a multi-word query: every term (see tokenize()) must
     * match at least one of $exprs. Null when the query has no usable term.
     *
     * @param list<string> $exprs
     * @return array{sql: string, params: list<string>, types: string}|null
     */
    public static function wordsCondition(array $exprs, string $query, ?int $maxTerms = null): ?array
    {
        $sql = [];
        $params = [];
        $terms = self::tokenize($query);
        if ($maxTerms !== null) {
            $terms = array_slice($terms, 0, max(0, $maxTerms));
        }
        foreach ($terms as ['term' => $term, 'exact' => $exact]) {
            $or = [];
            foreach ($exprs as $expr) {
                $or[] = self::likeSql($expr, $exact);
                $params[] = self::likePattern($term);
            }
            $sql[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($sql === [] || $exprs === []) {
            return null;
        }
        return [
            'sql' => '(' . implode(' AND ', $sql) . ')',
            'params' => $params,
            'types' => str_repeat('s', count($params)),
        ];
    }

    /**
     * SQL for "$expr contains the bound pattern". Bind likePattern($term).
     */
    public static function likeSql(string $expr, bool $exact): string
    {
        return $exact
            ? "LOWER({$expr}) COLLATE utf8mb4_bin LIKE LOWER(?) ESCAPE '\\\\'"
            : "{$expr} LIKE ? ESCAPE '\\\\'";
    }

    /** `%term%` with LIKE metacharacters escaped (backslash is the ESCAPE char). */
    public static function likePattern(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }

    /**
     * Replace invalid UTF-8 so the /u regexes cannot fail. A failed split
     * would yield no term at all, and "no term" means "no filter": a garbled
     * query would then list the whole catalogue instead of nothing.
     */
    private static function scrub(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }

    private static function stripQuotes(string $value): string
    {
        $q = preg_quote(self::QUOTES, '/');
        return (string) preg_replace('/^[' . $q . ']+|[' . $q . ']+$/u', '', trim($value));
    }

    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
