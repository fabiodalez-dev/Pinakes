<?php

declare(strict_types=1);

namespace App\Support\Holidays;

use App\Support\LibraryCalendar;

/**
 * Turns the rules of HolidayRules into dates. Pure: no database, no clock.
 * All arithmetic is on Y-m-d strings in UTC, like LibraryCalendar.
 */
final class HolidayCalculator
{
    private const WEEKDAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** @return array{name: string, regions?: array<string, string>, region_required?: bool, rules: list<array<string, mixed>>}|null */
    public static function country(string $code): ?array
    {
        return HolidayRules::countries()[strtoupper($code)] ?? null;
    }

    /**
     * Country code => display name, sorted by name.
     *
     * @return array<string, string>
     */
    public static function countryNames(): array
    {
        $names = [];
        foreach (HolidayRules::countries() as $code => $country) {
            $names[$code] = $country['name'];
        }
        uasort($names, static fn (string $a, string $b): int => strcmp(self::sortKey($a), self::sortKey($b)));
        return $names;
    }

    /** @return array<string, string> region code => name (empty when the country has none) */
    public static function regions(string $country): array
    {
        return self::country($country)['regions'] ?? [];
    }

    /**
     * The region to use: $region when the country has it, the first region
     * when the country needs one (United Kingdom), '' otherwise.
     */
    public static function normaliseRegion(string $country, string $region): string
    {
        $regions = self::regions($country);
        if ($region !== '' && isset($regions[$region])) {
            return $region;
        }
        if (!empty(self::country($country)['region_required']) && $regions !== []) {
            return (string) array_key_first($regions);
        }
        return '';
    }

    /**
     * The rules that apply to $country / $region, in table order: the
     * national ones plus, with a region, those of that region.
     *
     * @return list<array<string, mixed>>
     */
    public static function rulesFor(string $country, string $region = ''): array
    {
        $data = self::country($country);
        if ($data === null) {
            return [];
        }
        $out = [];
        foreach ($data['rules'] as $rule) {
            $regions = $rule['regions'] ?? null;
            if (is_array($regions) && !in_array($region, $regions, true)) {
                continue;
            }
            $out[] = $rule;
        }
        return $out;
    }

    /**
     * Each applicable rule with its date in $year (null when the holiday
     * does not exist that year).
     *
     * @return list<array{id: string, name: string, date: ?string, dates: list<string>, yearly: bool, regional: bool, optional: bool}>
     */
    public static function forYear(string $country, string $region, int $year): array
    {
        $country = strtoupper($country);
        $out = [];
        foreach (self::rulesFor($country, $region) as $rule) {
            $out[] = [
                'id' => $country . ':' . (string) $rule['id'],
                'name' => (string) $rule['name'],
                'date' => self::dateOf($rule, $year),
                'dates' => self::datesOf($rule, $year),
                'yearly' => self::isYearly($rule),
                'regional' => isset($rule['regions']),
                'optional' => !empty($rule['optional']),
            ];
        }
        return $out;
    }

    /**
     * The same date every year: a fixed day that never moves and always
     * exists. It is stored once as a yearly closure; any other rule is
     * stored as one row per year.
     *
     * @param array<string, mixed> $rule
     */
    public static function isYearly(array $rule): bool
    {
        $kind = $rule['rule'][0] ?? null;
        return $kind === 'fixed'
            && empty($rule['observed'])
            && !isset($rule['from'])
            && !isset($rule['until'])
            && empty($rule['overrides']);
    }

    /**
     * The date of one rule in $year, after the weekend rule and the yearly
     * overrides; null when the year is outside from/until.
     *
     * @param array<string, mixed> $rule
     */
    public static function dateOf(array $rule, int $year): ?string
    {
        if (isset($rule['from']) && $year < (int) $rule['from']) {
            return null;
        }
        if (isset($rule['until']) && $year > (int) $rule['until']) {
            return null;
        }
        $overrides = $rule['overrides'] ?? [];
        if (is_array($overrides) && isset($overrides[$year]) && is_string($overrides[$year])) {
            return $overrides[$year];
        }
        $spec = $rule['rule'] ?? null;
        if (!is_array($spec)) {
            return null;
        }
        $date = self::evaluate($spec, $year);
        if ($date === null) {
            return null;
        }
        $observed = $rule['observed'] ?? null;
        return is_string($observed) && $observed !== '' ? self::observe($date, $observed) : $date;
    }

    /** Weekend rules that add a day in lieu: the holiday itself keeps its date. */
    private const IN_LIEU_MODES = ['us', 'next_monday', 'pair'];

    /**
     * Every closed day of one rule in $year: the observed date, plus the
     * actual date when a weekend rule adds a day in lieu (us, next_monday,
     * pair) instead of moving the holiday. Koningsdag (sunday_back) and the
     * Argentine movable holidays (ar_movable) move: only the new date is a
     * holiday. A yearly override is the one date of that year.
     *
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    public static function datesOf(array $rule, int $year): array
    {
        $observed = self::dateOf($rule, $year);
        if ($observed === null) {
            return [];
        }
        $mode = $rule['observed'] ?? null;
        $overrides = $rule['overrides'] ?? [];
        if (!is_string($mode) || !in_array($mode, self::IN_LIEU_MODES, true)
            || (is_array($overrides) && isset($overrides[$year])) || !is_array($rule['rule'] ?? null)) {
            return [$observed];
        }
        $actual = self::evaluate($rule['rule'], $year);
        return $actual !== null && $actual !== $observed ? [$actual, $observed] : [$observed];
    }

    /**
     * @param array<int, mixed> $spec
     */
    public static function evaluate(array $spec, int $year): ?string
    {
        $kind = $spec[0] ?? null;
        switch ($kind) {
            case 'fixed':
                return self::ymd($year, (string) $spec[1]);
            case 'easter':
                return LibraryCalendar::shift(LibraryCalendar::easterSunday($year), (int) $spec[1]);
            case 'nth_weekday':
                $date = self::nthWeekday($year, (int) $spec[1], self::weekdayIndex((string) $spec[2]), (int) $spec[3]);
                return $date === null ? null : LibraryCalendar::shift($date, (int) ($spec[4] ?? 0));
            case 'weekday_on_or_before':
                $anchor = self::ymd($year, (string) $spec[1]);
                if ($anchor === null) {
                    return null;
                }
                $back = (self::weekday($anchor) - self::weekdayIndex((string) $spec[2]) + 7) % 7;
                return LibraryCalendar::shift($anchor, -$back);
            case 'weekday_on_or_after':
                $anchor = self::ymd($year, (string) $spec[1]);
                if ($anchor === null) {
                    return null;
                }
                $forward = (self::weekdayIndex((string) $spec[2]) - self::weekday($anchor) + 7) % 7;
                return LibraryCalendar::shift($anchor, $forward);
            case 'fixed_if':
                $date = self::ymd($year, (string) $spec[1]);
                if ($date === null) {
                    return null;
                }
                $days = array_map(static fn ($d): int => self::weekdayIndex((string) $d), (array) $spec[2]);
                if (in_array(self::weekday($date), $days, true)) {
                    return $date;
                }
                return is_array($spec[3] ?? null) ? self::evaluate($spec[3], $year) : null;
        }
        return null;
    }

    /**
     * The day off for a holiday that falls on a weekend:
     *  - us           Saturday → Friday before, Sunday → Monday after;
     *  - next_monday  Saturday or Sunday → the Monday after;
     *  - pair         Saturday or Sunday → two days later: for two holidays
     *                 in a row (Christmas and Boxing Day, 1 and 2 January in
     *                 Scotland) the pair lands on Monday and Tuesday;
     *  - sunday_back  Sunday → the Saturday before (Koningsdag);
     *  - ar_movable   Tuesday/Wednesday → the Monday before, Thursday/Friday
     *                 → the Monday after (Argentina, law 27.399).
     */
    public static function observe(string $date, string $mode): string
    {
        $weekday = self::weekday($date);
        switch ($mode) {
            case 'us':
                return $weekday === 6 ? LibraryCalendar::shift($date, -1) : ($weekday === 0 ? LibraryCalendar::shift($date, 1) : $date);
            case 'next_monday':
                return $weekday === 6 ? LibraryCalendar::shift($date, 2) : ($weekday === 0 ? LibraryCalendar::shift($date, 1) : $date);
            case 'pair':
                return ($weekday === 6 || $weekday === 0) ? LibraryCalendar::shift($date, 2) : $date;
            case 'sunday_back':
                return $weekday === 0 ? LibraryCalendar::shift($date, -1) : $date;
            case 'ar_movable':
                return match ($weekday) {
                    2 => LibraryCalendar::shift($date, -1),
                    3 => LibraryCalendar::shift($date, -2),
                    4 => LibraryCalendar::shift($date, 4),
                    5 => LibraryCalendar::shift($date, 3),
                    default => $date,
                };
        }
        return $date;
    }

    /** The $n-th $weekday (0 = Sunday) of $month; $n = -1 is the last one. */
    public static function nthWeekday(int $year, int $month, int $weekday, int $n): ?string
    {
        if ($month < 1 || $month > 12 || $weekday < 0 || $n === 0 || $n > 5 || $n < -1) {
            return null;
        }
        if ($n > 0) {
            $first = sprintf('%04d-%02d-01', $year, $month);
            $date = LibraryCalendar::shift($first, (($weekday - self::weekday($first) + 7) % 7) + 7 * ($n - 1));
            return substr($date, 0, 7) === substr($first, 0, 7) ? $date : null;
        }
        $last = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        return LibraryCalendar::shift($last, -((self::weekday($last) - $weekday + 7) % 7));
    }

    private static function ymd(int $year, string $monthDay): ?string
    {
        $date = sprintf('%04d-%s', $year, $monthDay);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private static function weekday(string $ymd): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));
        return $date === false ? -1 : (int) $date->format('w');
    }

    private static function weekdayIndex(string $name): int
    {
        return self::WEEKDAYS[strtolower($name)] ?? -1;
    }

    /** Sort key without accents, so "Éire" sorts with the E. */
    private static function sortKey(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $name = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        return mb_strtolower($name, 'UTF-8');
    }
}
