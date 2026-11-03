<?php
declare(strict_types=1);

namespace App\Support\Holidays;

use App\Support\DateHelper;

/**
 * Holidays the library defines itself (table festivita_personalizzate): a
 * patron saint, a community feast, the library's own days. Each one is a
 * rule evaluated for any requested year, like a preset holiday, in the
 * Gregorian calendar, relative to Easter (Western or Orthodox), in another
 * calendar (Islamic, Hebrew, Chinese, Persian, Indian, Ethiopian, Coptic) or
 * on dates listed by hand, optionally for one branch only.
 */
final class CustomHolidays
{
    public const TABLE = 'festivita_personalizzate';

    public const KINDS = ['fixed', 'nth_weekday', 'easter', 'orthodox_easter', 'calendar', 'dates'];

    /** Weekend handling a library can choose: none, day in lieu on Monday, US-style Friday/Monday. */
    public const WEEKEND_MODES = ['next_monday', 'us'];

    private const WEEKDAY_CODES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** Most dates a "specific dates" holiday may list. */
    public const MAX_DATES = 400;

    /** @var array<int, bool> per connection */
    private static array $schema = [];

    public static function schemaReady(\mysqli $db): bool
    {
        $key = spl_object_id($db);
        if (!isset(self::$schema[$key])) {
            try {
                $result = $db->query("SHOW TABLES LIKE '" . self::TABLE . "'");
                self::$schema[$key] = $result instanceof \mysqli_result && $result->num_rows > 0;
            } catch (\Throwable $e) {
                self::$schema[$key] = false;
            }
        }
        return self::$schema[$key];
    }

    public static function resetSchemaCache(): void
    {
        self::$schema = [];
    }

    /** @return list<array<string, mixed>> every custom holiday, by name */
    public static function all(\mysqli $db): array
    {
        if (!self::schemaReady($db)) {
            return [];
        }
        $result = $db->query('SELECT f.*, s.nome AS sede_nome FROM ' . self::TABLE . ' f LEFT JOIN sedi s ON s.id = f.sede_id ORDER BY f.nome, f.id');
        return $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** @return array<string, mixed>|null */
    public static function find(\mysqli $db, int $id): ?array
    {
        if (!self::schemaReady($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * The active holidays as calendar rules (LibraryCalendar).
     *
     * @return list<array<string, mixed>>
     */
    public static function calendarRules(\mysqli $db): array
    {
        $out = [];
        foreach (self::all($db) as $row) {
            if ((int) $row['attiva'] === 1) {
                $out[] = self::rule($row);
            }
        }
        return $out;
    }

    /**
     * One stored row as a HolidayCalculator rule.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function rule(array $row): array
    {
        $month = (int) ($row['mese'] ?? 0);
        $day = (int) ($row['giorno'] ?? 0);
        $spec = match ((string) $row['tipo']) {
            'fixed' => ['fixed', sprintf('%02d-%02d', $month, $day)],
            'nth_weekday' => ['nth_weekday', $month, self::WEEKDAY_CODES[(int) ($row['giorno_settimana'] ?? 1)] ?? 'mon', (int) ($row['occorrenza'] ?? 1)],
            'easter' => ['easter', (int) ($row['scarto_giorni'] ?? 0)],
            'orthodox_easter' => ['orthodox_easter', (int) ($row['scarto_giorni'] ?? 0)],
            'calendar' => ['calendar', (string) $row['calendario'], $month, $day],
            default => ['dates', self::parseDates((string) ($row['date_specifiche'] ?? ''))],
        };
        $rule = [
            'id' => 'custom:' . (int) ($row['id'] ?? 0),
            'name' => (string) $row['nome'],
            'rule' => $spec,
            'days' => max(1, (int) ($row['durata_giorni'] ?? 1)),
            'sede' => isset($row['sede_id']) && (int) $row['sede_id'] > 0 ? (int) $row['sede_id'] : null,
        ];
        if (in_array($row['fine_settimana'] ?? null, self::WEEKEND_MODES, true)) {
            $rule['observed'] = (string) $row['fine_settimana'];
        }
        if (($row['anno_inizio'] ?? null) !== null && (int) $row['anno_inizio'] > 0) {
            $rule['from'] = (int) $row['anno_inizio'];
        }
        if (($row['anno_fine'] ?? null) !== null && (int) $row['anno_fine'] > 0) {
            $rule['until'] = (int) $row['anno_fine'];
        }
        return $rule;
    }

    /** @return list<string> valid Y-m-d dates of a comma, space or line separated list, sorted */
    public static function parseDates(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $token) {
            if ($token !== '' && DateHelper::isISODateFormat($token)) {
                $out[$token] = true;
            }
        }
        $dates = array_keys($out);
        sort($dates);
        return array_map('strval', $dates);
    }

    /**
     * The next $count closed days of $row from $from on (the list shows them).
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function nextDates(array $row, string $from, int $count = 3): array
    {
        $rule = self::rule($row);
        $year = (int) substr($from, 0, 4);
        $out = [];
        for ($y = $year; $y <= $year + 60 && count($out) < $count; $y++) {
            foreach (HolidayCalculator::datesOf($rule, $y) as $date) {
                if ($date >= $from) {
                    $out[$date] = true;
                }
            }
        }
        $dates = array_keys($out);
        sort($dates);
        return array_map('strval', array_slice($dates, 0, $count));
    }

    /**
     * Validate the admin form. Field names: nome, tipo, fixed_mese,
     * fixed_giorno, nth_occorrenza, nth_giorno_settimana, nth_mese, scarto,
     * calendario, cal_mese_<calendar>, cal_giorno, date_specifiche,
     * durata_giorni, fine_settimana, anno_inizio, anno_fine, sede_id, attiva.
     *
     * @param array<string, mixed> $data
     * @param list<int> $branchIds
     * @return array<string, mixed>|string the row to store, or the error message
     */
    public static function validate(array $data, array $branchIds): array|string
    {
        $str = static fn (string $k): string => is_scalar($data[$k] ?? null) ? trim((string) $data[$k]) : '';
        $int = static function (string $k) use ($str): ?int {
            $v = filter_var($str($k), FILTER_VALIDATE_INT);
            return $v === false ? null : (int) $v;
        };
        $name = mb_substr($str('nome'), 0, 255);
        if ($name === '') {
            return __('Indica il nome della festività.');
        }
        $kind = $str('tipo');
        if (!in_array($kind, self::KINDS, true)) {
            return __('Scegli come si calcola la festività.');
        }
        $row = [
            'nome' => $name, 'tipo' => $kind, 'mese' => null, 'giorno' => null, 'giorno_settimana' => null,
            'occorrenza' => null, 'scarto_giorni' => null, 'calendario' => null, 'date_specifiche' => null,
        ];
        switch ($kind) {
            case 'fixed':
                $month = $int('fixed_mese');
                $day = $int('fixed_giorno');
                // 2024 is a leap year: 29 February is accepted and skipped in other years.
                if ($month === null || $day === null || !checkdate($month, $day, 2024)) {
                    return __('La data indicata non esiste.');
                }
                $row['mese'] = $month;
                $row['giorno'] = $day;
                break;
            case 'nth_weekday':
                $month = $int('nth_mese');
                $weekday = $int('nth_giorno_settimana');
                $nth = $int('nth_occorrenza');
                if ($month === null || $month < 1 || $month > 12 || $weekday === null || $weekday < 0 || $weekday > 6
                    || $nth === null || !in_array($nth, [1, 2, 3, 4, 5, -1], true)) {
                    return __('Indica mese, giorno della settimana e quale occorrenza.');
                }
                $row['mese'] = $month;
                $row['giorno_settimana'] = $weekday;
                $row['occorrenza'] = $nth;
                break;
            case 'easter':
            case 'orthodox_easter':
                $offset = $int('scarto');
                if ($offset === null || $offset < -200 || $offset > 200) {
                    return __('I giorni rispetto alla Pasqua vanno da -200 a 200.');
                }
                $row['scarto_giorni'] = $offset;
                break;
            case 'calendar':
                $calendar = $str('calendario');
                if (!WorldCalendars::exists($calendar)) {
                    return __('Scegli il calendario.');
                }
                if (!WorldCalendars::available($calendar)) {
                    return __('Questo calendario richiede l’estensione PHP intl sul server.');
                }
                $month = $int('cal_mese_' . $calendar);
                $day = $int('cal_giorno');
                if ($month === null || $month < 1 || $month > count(WorldCalendars::months($calendar)) || $day === null || $day < 1 || $day > 30) {
                    return __('Indica mese e giorno del calendario scelto.');
                }
                $row['calendario'] = $calendar;
                $row['mese'] = $month;
                $row['giorno'] = $day;
                break;
            case 'dates':
                $dates = self::parseDates($str('date_specifiche'));
                if ($dates === []) {
                    return __('Indica almeno una data nel formato AAAA-MM-GG.');
                }
                if (count($dates) > self::MAX_DATES) {
                    return sprintf(__('Al massimo %d date.'), self::MAX_DATES);
                }
                $row['date_specifiche'] = implode(',', $dates);
                break;
        }
        $days = $str('durata_giorni') === '' ? 1 : $int('durata_giorni');
        if ($days === null || $days < 1 || $days > HolidayCalculator::MAX_DAYS) {
            return sprintf(__('La durata va da 1 a %d giorni.'), HolidayCalculator::MAX_DAYS);
        }
        $row['durata_giorni'] = $days;
        $weekend = $str('fine_settimana');
        $row['fine_settimana'] = in_array($weekend, self::WEEKEND_MODES, true) && $days === 1 ? $weekend : null;
        $from = $str('anno_inizio') === '' ? null : $int('anno_inizio');
        $until = $str('anno_fine') === '' ? null : $int('anno_fine');
        foreach ([$from, $until] as $year) {
            if ($year !== null && ($year < 1900 || $year > 2199)) {
                return __('Gli anni vanno dal 1900 al 2199.');
            }
        }
        if (($str('anno_inizio') !== '' && $from === null) || ($str('anno_fine') !== '' && $until === null)) {
            return __('Gli anni vanno dal 1900 al 2199.');
        }
        if ($from !== null && $until !== null && $until < $from) {
            return __('L’ultimo anno non può precedere il primo.');
        }
        $row['anno_inizio'] = $from;
        $row['anno_fine'] = $until;
        // Empty or 0 is every branch; any other value must be an existing branch,
        // never silently widened to the whole library.
        $branch = $str('sede_id') === '' ? 0 : $int('sede_id');
        if ($branch === null || ($branch !== 0 && !in_array($branch, $branchIds, true))) {
            return __('Sede non trovata.');
        }
        $row['sede_id'] = $branch === 0 ? null : $branch;
        $row['attiva'] = ($data['attiva'] ?? null) === '1' ? 1 : 0;
        return $row;
    }

    /**
     * The submitted form as a stored row, unvalidated, so that a refused form
     * comes back with what the administrator typed instead of empty or with
     * the old values. Only for refilling the form, never for saving.
     *
     * @param array<string, mixed> $data the fields read by validate()
     * @return array<string, mixed>
     */
    public static function draft(array $data, int $id = 0): array
    {
        $str = static fn (string $k): string => is_scalar($data[$k] ?? null) ? mb_substr(trim((string) $data[$k]), 0, 2000) : '';
        $kind = in_array($str('tipo'), self::KINDS, true) ? $str('tipo') : 'fixed';
        $calendar = WorldCalendars::exists($str('calendario')) ? $str('calendario') : null;
        $month = match ($kind) {
            'fixed' => $str('fixed_mese'),
            'nth_weekday' => $str('nth_mese'),
            'calendar' => $calendar !== null ? $str('cal_mese_' . $calendar) : '',
            default => '',
        };
        $day = match ($kind) {
            'fixed' => $str('fixed_giorno'),
            'calendar' => $str('cal_giorno'),
            default => '',
        };
        $blank = static fn (string $v): ?string => $v === '' ? null : $v;
        return [
            'id' => $id, 'nome' => $str('nome'), 'tipo' => $kind, 'mese' => $blank($month), 'giorno' => $blank($day),
            'giorno_settimana' => $blank($str('nth_giorno_settimana')), 'occorrenza' => $blank($str('nth_occorrenza')),
            'scarto_giorni' => $blank($str('scarto')), 'calendario' => $calendar,
            'date_specifiche' => $blank(str_replace(["\r\n", "\n", "\r"], ',', $str('date_specifiche'))),
            'durata_giorni' => $blank($str('durata_giorni')), 'fine_settimana' => $blank($str('fine_settimana')),
            'sede_id' => $blank($str('sede_id')), 'anno_inizio' => $blank($str('anno_inizio')), 'anno_fine' => $blank($str('anno_fine')),
            'attiva' => ($data['attiva'] ?? null) === '1' ? 1 : 0,
        ];
    }

    /** @param array<string, mixed> $row the output of validate() */
    public static function save(\mysqli $db, array $row, ?int $id = null): int
    {
        $values = [
            $row['nome'], $row['tipo'], $row['mese'], $row['giorno'], $row['giorno_settimana'], $row['occorrenza'],
            $row['scarto_giorni'], $row['calendario'], $row['date_specifiche'], $row['durata_giorni'],
            $row['fine_settimana'], $row['anno_inizio'], $row['anno_fine'], $row['sede_id'], $row['attiva'],
        ];
        $types = 'ssiiiiissisiiii';
        if ($id === null) {
            $stmt = $db->prepare('INSERT INTO ' . self::TABLE . ' (nome, tipo, mese, giorno, giorno_settimana, occorrenza, scarto_giorni, calendario, date_specifiche, durata_giorni, fine_settimana, anno_inizio, anno_fine, sede_id, attiva)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $newId = (int) $db->insert_id;
            $stmt->close();
            return $newId;
        }
        $values[] = $id;
        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET nome = ?, tipo = ?, mese = ?, giorno = ?, giorno_settimana = ?, occorrenza = ?, scarto_giorni = ?, calendario = ?,
                              date_specifiche = ?, durata_giorni = ?, fine_settimana = ?, anno_inizio = ?, anno_fine = ?, sede_id = ?, attiva = ? WHERE id = ?');
        $stmt->bind_param($types . 'i', ...$values);
        $stmt->execute();
        $stmt->close();
        return $id;
    }

    public static function delete(\mysqli $db, int $id): bool
    {
        $stmt = $db->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        return $deleted;
    }

    public static function setActive(\mysqli $db, int $id, bool $active): void
    {
        $flag = $active ? 1 : 0;
        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET attiva = ? WHERE id = ?');
        $stmt->bind_param('ii', $flag, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * How the holiday is computed, in the interface language.
     *
     * @param array<string, mixed> $row
     * @param callable(string, string): string $format IntlDateFormatter pattern, Y-m-d
     */
    public static function describe(array $row, callable $format): string
    {
        $month = (int) ($row['mese'] ?? 1);
        $day = (int) ($row['giorno'] ?? 1);
        $text = match ((string) $row['tipo']) {
            'fixed' => sprintf(__('Ogni anno il %s'), $format('d MMMM', sprintf('2024-%02d-%02d', $month, $day))),
            'nth_weekday' => sprintf(
                __('%1$s %2$s di %3$s'),
                self::ordinal((int) ($row['occorrenza'] ?? 1), (int) ($row['giorno_settimana'] ?? 1)),
                // 2026-11-01 is a Sunday: + weekday gives that weekday.
                $format('EEEE', \App\Support\LibraryCalendar::shift('2026-11-01', (int) ($row['giorno_settimana'] ?? 1))),
                $format('LLLL', sprintf('2024-%02d-01', $month))
            ),
            'easter' => self::offsetText((int) ($row['scarto_giorni'] ?? 0), __('Pasqua')),
            'orthodox_easter' => self::offsetText((int) ($row['scarto_giorni'] ?? 0), __('Pasqua ortodossa')),
            'calendar' => sprintf(__('Calendario %1$s: %2$d %3$s'), WorldCalendars::label((string) $row['calendario']), $day, WorldCalendars::months((string) $row['calendario'])[$month - 1] ?? (string) $month),
            default => sprintf(__('Date specifiche (%d)'), count(self::parseDates((string) ($row['date_specifiche'] ?? '')))),
        };
        $days = (int) ($row['durata_giorni'] ?? 1);
        if ($days > 1) {
            $text .= ' · ' . sprintf(__('%d giorni'), $days);
        }
        return $text;
    }

    /** "Il primo lunedì", but "la prima domenica": in the Italian base Sunday is feminine. */
    private static function ordinal(int $nth, int $weekday): string
    {
        if ($weekday === 0) {
            return match ($nth) {
                1 => __('La prima'),
                2 => __('La seconda'),
                3 => __('La terza'),
                4 => __('La quarta'),
                5 => __('La quinta'),
                default => __('L’ultima'),
            };
        }
        return match ($nth) {
            1 => __('Il primo'),
            2 => __('Il secondo'),
            3 => __('Il terzo'),
            4 => __('Il quarto'),
            5 => __('Il quinto'),
            default => __('L’ultimo'),
        };
    }

    private static function offsetText(int $offset, string $feast): string
    {
        if ($offset === 0) {
            return $feast;
        }
        return $offset > 0
            ? sprintf(__('%1$d giorni dopo %2$s'), $offset, $feast)
            : sprintf(__('%1$d giorni prima di %2$s'), -$offset, $feast);
    }
}
