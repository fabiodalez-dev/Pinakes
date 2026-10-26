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
     * value is one term, exact when it starts with a quote.
     *
     * @return array{term: string, exact: bool}
     */
    public static function parseFilter(string $value): array
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^[' . preg_quote(self::QUOTES, '/') . ']/u', $value) === 1) {
            return ['term' => self::collapse(self::stripQuotes($value)), 'exact' => true];
        }
        return ['term' => $value, 'exact' => false];
    }

    /**
     * WHERE fragment for a single-value filter matched against any of $exprs
     * (OR), honouring quotes via parseFilter(). Null when nothing is left to
     * match (e.g. the value was only a quote).
     *
     * @param list<string> $exprs
     * @return array{sql: string, params: list<string>, types: string}|null
     */
    public static function filterCondition(array $exprs, string $value): ?array
    {
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
    public static function wordsCondition(array $exprs, string $query): ?array
    {
        $sql = [];
        $params = [];
        foreach (self::tokenize($query) as ['term' => $term, 'exact' => $exact]) {
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
