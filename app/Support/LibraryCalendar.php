<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;

/**
 * The days the library is closed, and every date rule that depends on them.
 *
 * Two sources: the weekly closed days (`loans.closed_weekdays`, a comma list
 * of 0 = Sunday … 6 = Saturday) and the `calendario_chiusure` table (one row
 * per closed interval, optionally recurring every year, optionally for one
 * branch only). With neither configured every day is open, and each method
 * returns exactly what the code did before the calendar existed: that is the
 * zero-config equivalence the loan paths rely on.
 *
 * All arithmetic runs on Y-m-d strings in UTC. A calendar day has no time of
 * day, so a DST change in app.timezone can never make a day last 23 or 25
 * hours here. "Today" is never computed in this class: callers pass
 * DateHelper::today() (the app timezone).
 *
 * The two static funnels, dueDate() and pickupDeadline(), are the only entry
 * points the loan code uses. A later CirculationPolicy computes durations; the
 * final date still goes through here.
 */
final class LibraryCalendar
{
    /** Upper bound on day-by-day scans, so an all-closed setup cannot loop forever. */
    private const MAX_SCAN_DAYS = 1100;

    /** Longest yearly closure (days between start and end): a year less a day. */
    public const MAX_YEARLY_SPAN_DAYS = 364;

    /** Longest one-off closure: ten years. */
    public const MAX_ONCE_SPAN_DAYS = 3660;

    /** @var array<int, true> */
    private array $closedWeekdays = [];

    /** @var list<array{start: string, end: string, yearly: bool, sede: ?int}> */
    private array $closures = [];

    /** @var array<int, self> per-connection cache, keyed by spl_object_id */
    private static array $instances = [];

    /**
     * @param list<int>|array<int, int> $closedWeekdays 0 = Sunday … 6 = Saturday
     * @param list<array{data_inizio?: string, data_fine?: string, start?: string, end?: string, ricorrente_annuale?: int|string|bool, yearly?: bool, sede_id?: int|string|null, sede?: int|null}> $closures
     */
    public function __construct(array $closedWeekdays = [], array $closures = [])
    {
        foreach ($closedWeekdays as $weekday) {
            $weekday = (int) $weekday;
            if ($weekday >= 0 && $weekday <= 6) {
                $this->closedWeekdays[$weekday] = true;
            }
        }
        // Seven closed weekdays would leave no day to move a date to. Treat
        // that misconfiguration as "no weekly closure" rather than letting
        // every due date run to the scan limit.
        if (count($this->closedWeekdays) === 7) {
            $this->closedWeekdays = [];
        }
        foreach ($closures as $row) {
            $start = (string) ($row['data_inizio'] ?? $row['start'] ?? '');
            $end = (string) ($row['data_fine'] ?? $row['end'] ?? $start);
            if (!DateHelper::isISODateFormat($start) || !DateHelper::isISODateFormat($end)) {
                continue;
            }
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }
            $sede = $row['sede_id'] ?? $row['sede'] ?? null;
            // A yearly closure repeats its month-day span, which only makes
            // sense for a span shorter than a year. A longer row (the form
            // refuses one; an old import may have stored one) is applied once,
            // as the interval it names, instead of closing every day.
            $yearly = (bool) ($row['ricorrente_annuale'] ?? $row['yearly'] ?? false)
                && self::spanDays($start, $end) <= self::MAX_YEARLY_SPAN_DAYS;
            $this->closures[] = [
                'start' => $start,
                'end' => $end,
                'yearly' => $yearly,
                'sede' => ($sede === null || $sede === '' || (int) $sede <= 0) ? null : (int) $sede,
            ];
        }
    }

    /** The calendar stored in the database (settings + closures table). */
    public static function fromDatabase(\mysqli $db): self
    {
        return new self(self::loadClosedWeekdays($db), self::loadClosures($db));
    }

    /**
     * One calendar per connection and request. Writers call reset() after a
     * change so the same request sees it.
     */
    public static function forDb(\mysqli $db): self
    {
        $key = spl_object_id($db);
        return self::$instances[$key] ??= self::fromDatabase($db);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }

    // ── Funnels used by the loan code ───────────────────────────────────────

    /**
     * THE due-date rule: a due date that falls on a closed day moves forward
     * to the next open day (plan §13, decision 7). Every place that stores a
     * data_scadenza calls this with the date it computed or received.
     * $copiaId, when known, applies that copy's branch closures too.
     */
    public static function dueDate(\mysqli $db, string $ymd, ?int $copiaId = null): string
    {
        if (!DateHelper::isISODateFormat($ymd)) {
            return $ymd;
        }
        $calendar = self::forDb($db);
        if (!$calendar->hasRules()) {
            return $ymd;
        }
        return $calendar->nextOpenDay($ymd, self::branchOfCopy($db, $copiaId));
    }

    /**
     * The pickup deadline: $days OPEN days after $from, never past $capAt (the
     * loan's due date). With no closures this is exactly $from + $days.
     */
    public static function pickupDeadline(\mysqli $db, string $from, int $days, ?string $capAt = null, ?int $copiaId = null): string
    {
        $days = max(0, $days);
        $calendar = self::forDb($db);
        $deadline = $calendar->hasRules()
            ? $calendar->addOpenDays($from, $days, self::branchOfCopy($db, $copiaId))
            : self::shift($from, $days);
        if ($capAt !== null && $capAt !== '' && $deadline > $capAt) {
            $deadline = $capAt;
        }
        return $deadline;
    }

    /**
     * After a calendar change: a pickup still waiting whose deadline now falls
     * on a closed day (of the copy's branch) lasts until the next open day,
     * because the reader never had that day to come in. The new deadline is
     * stored in prestiti.pickup_deadline, so every check that reads it (pickup
     * confirmation, the reader's cancellation, the expiry sweep, the expired
     * badges) applies the same rule. A deadline whose next open day is already
     * past is left to the expiry sweep. Returns the number of pickups moved.
     */
    public static function extendPickupDeadlines(\mysqli $db): int
    {
        $calendar = self::forDb($db);
        if (!$calendar->hasRules()) {
            return 0;
        }
        $today = DateHelper::today();
        $result = $db->query(
            "SELECT p.id, p.pickup_deadline, c.sede_id
             FROM prestiti p
             LEFT JOIN copie c ON c.id = p.copia_id
             WHERE p.stato = 'da_ritirare' AND p.attivo = 1 AND p.pickup_deadline IS NOT NULL"
        );
        if (!$result instanceof \mysqli_result) {
            return 0;
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        $moved = 0;
        $stmt = $db->prepare(
            "UPDATE prestiti SET pickup_deadline = ?
             WHERE id = ? AND stato = 'da_ritirare' AND attivo = 1 AND pickup_deadline = ?"
        );
        foreach ($rows as $row) {
            $deadline = substr((string) $row['pickup_deadline'], 0, 10);
            $next = $calendar->nextOpenDay($deadline, $row['sede_id'] !== null ? (int) $row['sede_id'] : null);
            if ($next === $deadline || $next < $today) {
                continue;
            }
            $id = (int) $row['id'];
            $stmt->bind_param('sis', $next, $id, $deadline);
            $stmt->execute();
            $moved += max(0, $stmt->affected_rows);
        }
        $stmt->close();
        return $moved;
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    /** True when a weekly closed day or a closure row is configured. */
    public function hasRules(): bool
    {
        return $this->closedWeekdays !== [] || $this->closures !== [];
    }

    /**
     * Whether the library is open on $ymd. A branch-specific closure counts
     * only when that branch is asked for; a closure without a branch counts
     * for every branch and for the library as a whole ($sedeId = null).
     */
    public function isOpen(string $ymd, ?int $sedeId = null): bool
    {
        if (!DateHelper::isISODateFormat($ymd)) {
            return true;
        }
        if ($this->closedWeekdays !== [] && isset($this->closedWeekdays[self::weekday($ymd)])) {
            return false;
        }
        $monthDay = substr($ymd, 5);
        foreach ($this->closures as $closure) {
            if ($closure['sede'] !== null && $closure['sede'] !== $sedeId) {
                continue;
            }
            if ($closure['yearly']) {
                if (self::recurringCovers($closure['start'], $closure['end'], $ymd, $monthDay)) {
                    return false;
                }
            } elseif ($ymd >= $closure['start'] && $ymd <= $closure['end']) {
                return false;
            }
        }
        return true;
    }

    /** $ymd itself when open, otherwise the first open day after it. */
    public function nextOpenDay(string $ymd, ?int $sedeId = null): string
    {
        if (!DateHelper::isISODateFormat($ymd)) {
            return $ymd;
        }
        $day = $ymd;
        for ($i = 0; $i < self::MAX_SCAN_DAYS; $i++) {
            if ($this->isOpen($day, $sedeId)) {
                return $day;
            }
            $day = self::shift($day, 1);
        }
        return $ymd;
    }

    /** Same as nextOpenDay(): the name the loan code reads best with. */
    public function shiftForward(string $ymd, ?int $sedeId = null): string
    {
        return $this->nextOpenDay($ymd, $sedeId);
    }

    /** $ymd itself when open, otherwise the last open day before it. */
    public function previousOpenDay(string $ymd, ?int $sedeId = null): string
    {
        if (!DateHelper::isISODateFormat($ymd)) {
            return $ymd;
        }
        $day = $ymd;
        for ($i = 0; $i < self::MAX_SCAN_DAYS; $i++) {
            if ($this->isOpen($day, $sedeId)) {
                return $day;
            }
            $day = self::shift($day, -1);
        }
        return $ymd;
    }

    /** The day $n open days after $from ($from itself excluded). */
    public function addOpenDays(string $from, int $n, ?int $sedeId = null): string
    {
        if (!DateHelper::isISODateFormat($from) || $n <= 0) {
            return $from;
        }
        $day = $from;
        $counted = 0;
        for ($i = 0; $i < self::MAX_SCAN_DAYS && $counted < $n; $i++) {
            $day = self::shift($day, 1);
            if ($this->isOpen($day, $sedeId)) {
                $counted++;
            }
        }
        return $counted < $n ? self::shift($from, $n) : $day;
    }

    /**
     * Closed days in [$from, $to], both included, for the request calendars.
     *
     * @return list<string>
     */
    public function closedDatesBetween(string $from, string $to, ?int $sedeId = null): array
    {
        if (!$this->hasRules() || !DateHelper::isISODateFormat($from) || !DateHelper::isISODateFormat($to) || $to < $from) {
            return [];
        }
        $closed = [];
        $day = $from;
        for ($i = 0; $i < self::MAX_SCAN_DAYS && $day <= $to; $i++) {
            if (!$this->isOpen($day, $sedeId)) {
                $closed[] = $day;
            }
            $day = self::shift($day, 1);
        }
        return $closed;
    }

    /**
     * Open days after $from up to and including $to: the days a late loan
     * counts when closed days do not (fines). 0 when $to <= $from.
     */
    public function openDaysBetween(string $from, string $to, ?int $sedeId = null): int
    {
        if (!DateHelper::isISODateFormat($from) || !DateHelper::isISODateFormat($to) || $to <= $from) {
            return 0;
        }
        if (!$this->hasRules()) {
            return (int) (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))
                ->diff(new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->days;
        }
        $count = 0;
        $day = $from;
        while (true) {
            $day = self::shift($day, 1);
            if ($day > $to) {
                break;
            }
            if ($this->isOpen($day, $sedeId)) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<int> */
    public function closedWeekdays(): array
    {
        $days = array_keys($this->closedWeekdays);
        sort($days);
        return $days;
    }

    // ── Presets and import ──────────────────────────────────────────────────

    /**
     * Easter Sunday (Gregorian) with the anonymous Meeus/Jones/Butcher
     * algorithm: no dependency on the `calendar` extension.
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Italian national holidays for $year. Fixed dates recur every year;
     * Easter and Easter Monday move, so they are dated for that year only.
     *
     * @return list<array{start: string, end: string, description: string, yearly: bool}>
     */
    public static function italianHolidays(int $year): array
    {
        $fixed = [
            '01-01' => __('Capodanno'),
            '01-06' => __('Epifania'),
            '04-25' => __('Festa della Liberazione'),
            '05-01' => __('Festa del Lavoro'),
            '06-02' => __('Festa della Repubblica'),
            '08-15' => __('Ferragosto'),
            '11-01' => __('Ognissanti'),
            '12-08' => __('Immacolata Concezione'),
            '12-25' => __('Natale'),
            '12-26' => __('Santo Stefano'),
        ];
        $holidays = [];
        foreach ($fixed as $monthDay => $label) {
            $date = sprintf('%04d-%s', $year, $monthDay);
            $holidays[] = ['start' => $date, 'end' => $date, 'description' => $label, 'yearly' => true];
        }
        $easter = self::easterSunday($year);
        $holidays[] = ['start' => $easter, 'end' => $easter, 'description' => __('Pasqua'), 'yearly' => false];
        $monday = self::shift($easter, 1);
        $holidays[] = ['start' => $monday, 'end' => $monday, 'description' => __("Lunedì dell'Angelo (Pasquetta)"), 'yearly' => false];
        usort($holidays, static fn (array $x, array $y): int => strcmp($x['start'], $y['start']));
        return $holidays;
    }

    /**
     * Closed intervals from an iCalendar file: every VEVENT with a DTSTART.
     * All-day events have an exclusive DTEND (RFC 5545), so the last closed
     * day is the day before it; RRULE:FREQ=YEARLY makes the row recurring.
     * Other recurrences are imported as their first occurrence only. A UTC
     * DATE-TIME (trailing Z) is read in $timezone (app.timezone by default)
     * before its day is taken. The spans are not validated here: the import
     * applies the same limits as the form.
     *
     * @return list<array{start: string, end: string, description: string, yearly: bool}>
     */
    public static function parseIcs(string $ics, int $maxEvents = 1000, ?string $timezone = null): array
    {
        $timezone ??= self::appTimezone();
        // Unfold continuation lines (RFC 5545 §3.1).
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        $ics = preg_replace("/\n[ \t]/", '', $ics) ?? $ics;
        $events = [];
        $current = null;
        foreach (explode("\n", $ics) as $line) {
            $line = rtrim($line);
            if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
                $current = ['start' => null, 'end' => null, 'endIsDate' => false, 'summary' => '', 'yearly' => false];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (strcasecmp($line, 'END:VEVENT') === 0) {
                if ($current['start'] !== null) {
                    $start = $current['start'];
                    $end = $current['end'];
                    if ($end === null) {
                        $end = $start;
                    } elseif ($current['endIsDate']) {
                        $end = self::shift($end, -1);
                    }
                    if ($end < $start) {
                        $end = $start;
                    }
                    $events[] = [
                        'start' => $start,
                        'end' => $end,
                        'description' => mb_substr(trim($current['summary']), 0, 255),
                        'yearly' => $current['yearly'],
                    ];
                    if (count($events) >= $maxEvents) {
                        break;
                    }
                }
                $current = null;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $nameAndParams = strtoupper(substr($line, 0, $colon));
            $value = substr($line, $colon + 1);
            $name = explode(';', $nameAndParams)[0];
            if ($name === 'DTSTART' || $name === 'DTEND') {
                $date = self::icsDate($value, $timezone);
                if ($date === null) {
                    continue;
                }
                $isDate = (str_contains($nameAndParams, 'VALUE=DATE') && !str_contains($nameAndParams, 'VALUE=DATE-TIME'))
                    || preg_match('/^\d{8}$/', trim($value)) === 1;
                if ($name === 'DTSTART') {
                    $current['start'] = $date;
                } else {
                    $current['end'] = $date;
                    $current['endIsDate'] = $isDate;
                }
            } elseif ($name === 'SUMMARY') {
                $current['summary'] = str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], $value);
            } elseif ($name === 'RRULE') {
                $current['yearly'] = preg_match('/(^|;)FREQ=YEARLY(;|$)/i', $value) === 1;
            }
        }
        return $events;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** Y-m-d plus $days, in UTC (no DST: a day is a day). */
    public static function shift(string $ymd, int $days): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));
        if ($date === false) {
            return $ymd;
        }
        return $days === 0 ? $date->format('Y-m-d') : $date->modify(($days > 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private static function weekday(string $ymd): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));
        return $date === false ? -1 : (int) $date->format('w');
    }

    /** Days between two Y-m-d dates (0 for the same day); -1 when either is not a date. */
    public static function spanDays(string $start, string $end): int
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, new \DateTimeZone('UTC'));
        if ($from === false || $to === false) {
            return -1;
        }
        $days = $from->diff($to)->days;
        return $days === false ? -1 : (int) $days;
    }

    /**
     * A yearly closure repeats its month-day span every year. A span that
     * crosses New Year (12-24 → 01-06) wraps. The constructor keeps only
     * spans shorter than a year recurring, so $ymd is closed only when its
     * month-day falls inside the span: no span closes every day.
     */
    private static function recurringCovers(string $start, string $end, string $ymd, string $monthDay): bool
    {
        $from = substr($start, 5);
        $to = substr($end, 5);
        if ($to < $from) {
            return $monthDay >= $from || $monthDay <= $to; // wraps over New Year
        }
        return $monthDay >= $from && $monthDay <= $to;
    }

    /**
     * The day of an iCalendar DATE or DATE-TIME. A floating DATE-TIME and a
     * DATE are taken as written; a UTC DATE-TIME (trailing Z) is converted
     * to $timezone first, so 20260410T230000Z in Europe/Rome is 2026-04-11.
     */
    private static function icsDate(string $value, string $timezone): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?(Z?))?/i', $value, $m) !== 1) {
            return null;
        }
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        if (!DateHelper::isISODateFormat($date)) {
            return null;
        }
        if (($m[7] ?? '') === '') {
            return $date;
        }
        try {
            $utc = new \DateTimeImmutable(sprintf('%sT%s:%s:%s', $date, $m[4], $m[5], $m[6] !== '' ? $m[6] : '00'), new \DateTimeZone('UTC'));
            return $utc->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /** app.timezone, with the same fallback as DateHelper::today(). */
    private static function appTimezone(): string
    {
        try {
            $tz = (string) ConfigStore::get('app.timezone', 'Europe/Rome');
            new \DateTimeZone($tz);
            return $tz;
        } catch (\Throwable $e) {
            return 'Europe/Rome';
        }
    }

    /**
     * @return list<int>
     */
    public static function parseWeekdays(?string $raw): array
    {
        $days = [];
        foreach (explode(',', (string) $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part <= 6) {
                $days[(int) $part] = (int) $part;
            }
        }
        ksort($days);
        return array_values($days);
    }

    /** @return list<int> */
    private static function loadClosedWeekdays(\mysqli $db): array
    {
        try {
            return self::parseWeekdays((new SettingsRepository($db))->get('loans', 'closed_weekdays', ''));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private static function loadClosures(\mysqli $db): array
    {
        try {
            // Before the 0.9.0 migration ran the table does not exist: no closures.
            $exists = $db->query("SHOW TABLES LIKE 'calendario_chiusure'");
            if ($exists === false || $exists->num_rows === 0) {
                return [];
            }
            $result = $db->query('SELECT data_inizio, data_fine, ricorrente_annuale, sede_id FROM calendario_chiusure');
            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $e) {
            SecureLogger::warning('LibraryCalendar: closures not readable: ' . $e->getMessage());
            return [];
        }
    }

    /** The branch (copie.sede_id) of a copy, null when unknown or none. */
    public static function branchOfCopy(\mysqli $db, ?int $copiaId): ?int
    {
        if ($copiaId === null || $copiaId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT sede_id FROM copie WHERE id = ?');
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('i', $copiaId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return ($row === null || $row[0] === null) ? null : (int) $row[0];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
