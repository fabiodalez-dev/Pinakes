<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Order of the back-office quick search (#463).
 *
 * The results arrive in blocks: core books first, then whatever the plugins
 * append (articles and periodicals from the emeroteca, archive units). Read as
 * one list that put the exact title the operator typed below every book that
 * merely mentioned a word of it. Here the records (books, articles,
 * periodicals, archive units) form ONE list: the titles that match what was
 * typed come first — the exact title, then the ones starting with it, then
 * the ones containing it — and inside each group the order is alphabetical,
 * by the rules of the operator's language (æ, ø, å sort as Danish, not as
 * bytes). Authors and publishers follow, in the order they came.
 */
final class QuickSearchOrder
{
    /** Result types that are catalogue records, sorted together. */
    private const RECORD_TYPES = ['book', 'article', 'periodical', 'archive'];

    /**
     * @param array<int, mixed> $results
     * @param int|null $limit total kept; the records give way first, so the
     *                        authors and publishers that matched stay listed
     *                        (at least half the slots remain records)
     * @return array<int, mixed>
     */
    public static function apply(array $results, string $query, ?string $locale = null, ?int $limit = null): array
    {
        $records = [];
        $others = [];
        foreach ($results as $item) {
            if (is_array($item) && in_array((string) ($item['type'] ?? ''), self::RECORD_TYPES, true)) {
                $records[] = $item;
            } else {
                $others[] = $item;
            }
        }
        if (count($records) < 2) {
            return self::cut($records, $others, $limit);
        }

        $needle = self::fold($query);
        $collator = null;
        if (class_exists(\Collator::class)) {
            $collator = \Collator::create($locale ?? I18n::getLocale());
            if ($collator !== null) {
                // "Bog 2" before "Bog 10".
                $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
            }
        }

        $keyed = [];
        foreach ($records as $i => $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $keyed[] = [self::tier(self::fold($label), $needle), $label, $i, $item];
        }
        usort($keyed, static function (array $a, array $b) use ($collator): int {
            if ($a[0] !== $b[0]) {
                return $a[0] <=> $b[0];
            }
            $byLabel = $collator !== null
                ? (int) $collator->compare($a[1], $b[1])
                : strcmp(mb_strtolower($a[1]), mb_strtolower($b[1]));
            return $byLabel !== 0 ? $byLabel : $a[2] <=> $b[2];
        });

        return self::cut(array_map(static fn (array $k): mixed => $k[3], $keyed), $others, $limit);
    }

    /**
     * @param array<int, mixed> $records
     * @param array<int, mixed> $others
     * @return array<int, mixed>
     */
    private static function cut(array $records, array $others, ?int $limit): array
    {
        if ($limit === null || count($records) + count($others) <= $limit) {
            return array_merge($records, $others);
        }
        $recordSlots = max(intdiv($limit + 1, 2), $limit - count($others));
        $records = array_slice($records, 0, $recordSlots);
        return array_slice(array_merge($records, $others), 0, $limit);
    }

    /** 0 exact title, 1 title starts with the query, 2 contains it, 3 other match. */
    private static function tier(string $label, string $needle): int
    {
        if ($needle === '') {
            return 3;
        }
        if ($label === $needle) {
            return 0;
        }
        if (str_starts_with($label, $needle)) {
            return 1;
        }
        return str_contains($label, $needle) ? 2 : 3;
    }

    /** Case- and spacing-insensitive form used only to compare with the query. */
    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
