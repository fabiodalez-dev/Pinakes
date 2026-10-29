<?php

declare(strict_types=1);

namespace App\Support\Holidays;

use App\Models\SettingsRepository;
use App\Support\DateHelper;
use App\Support\DbAtomic;
use App\Support\I18n;

/**
 * The holidays the library chose, and their rows in calendario_chiusure.
 *
 * The choice lives in system_settings (category "calendar"): holiday_country,
 * holiday_region and holiday_rules (a JSON list of "CC:rule" ids). Each chosen
 * rule is materialised as closures marked with regola_festivita = its id: a
 * fixed date that never moves as ONE yearly row, any other rule as one row
 * per year, from the current year to YEARS_AHEAD after it. LibraryCalendar
 * keeps reading calendario_chiusure only, so the due-date engine is the same.
 *
 * Rows with regola_festivita NULL (added by hand or from an ICS file) are
 * never changed or removed here, with one exception: a row that is exactly
 * what the former Italian preset stored (same dates, no branch, the label the
 * old importer wrote in any of the five languages) is adopted by the matching
 * Italian rule instead of being duplicated.
 */
final class HolidayPresets
{
    /** Movable holidays are kept dated up to this many years after the current one. */
    public const YEARS_AHEAD = 2;

    private const SETTINGS_CATEGORY = 'calendar';

    /** Labels the former Italian preset wrote (translated at import time), per rule. */
    private const LEGACY_IT_LABELS = [
        'IT:new_year' => 'Capodanno',
        'IT:epiphany' => 'Epifania',
        'IT:easter' => 'Pasqua',
        'IT:easter_monday' => "Lunedì dell'Angelo (Pasquetta)",
        'IT:liberation' => 'Festa della Liberazione',
        'IT:labour' => 'Festa del Lavoro',
        'IT:republic' => 'Festa della Repubblica',
        'IT:ferragosto' => 'Ferragosto',
        'IT:all_saints' => 'Ognissanti',
        'IT:immaculate' => 'Immacolata Concezione',
        'IT:christmas' => 'Natale',
        'IT:st_stephen' => 'Santo Stefano',
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $legacyLabelCache = null;

    /** The country preselected before any choice: the one of the installation language. */
    public static function defaultCountry(): string
    {
        $locale = '';
        try {
            $locale = I18n::getInstallationLocale();
        } catch (\Throwable $e) {
            $locale = '';
        }
        return match (substr($locale, 0, 2)) {
            'en' => 'US',
            'de' => 'DE',
            'fr' => 'FR',
            'da' => 'DK',
            default => 'IT',
        };
    }

    /**
     * What the library chose. 'saved' is false while nothing was ever applied.
     *
     * @return array{country: string, region: string, rules: list<string>, saved: bool}
     */
    public static function selection(\mysqli $db): array
    {
        $repo = new SettingsRepository($db);
        $country = strtoupper((string) $repo->get(self::SETTINGS_CATEGORY, 'holiday_country', ''));
        $saved = $country !== '' && HolidayCalculator::country($country) !== null;
        if (!$saved) {
            $country = self::defaultCountry();
        }
        $region = HolidayCalculator::normaliseRegion($country, (string) $repo->get(self::SETTINGS_CATEGORY, 'holiday_region', ''));
        $rules = [];
        $decoded = json_decode((string) $repo->get(self::SETTINGS_CATEGORY, 'holiday_rules', '[]'), true);
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            if (is_string($id)) {
                $rules[] = $id;
            }
        }
        return ['country' => $country, 'region' => $region, 'rules' => $saved ? self::filterIds($country, $region, $rules) : [], 'saved' => $saved];
    }

    /**
     * Keep the ids that belong to $country / $region, in table order.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public static function filterIds(string $country, string $region, array $ids): array
    {
        $wanted = array_flip($ids);
        $out = [];
        foreach (HolidayCalculator::rulesFor($country, $region) as $rule) {
            $id = strtoupper($country) . ':' . (string) $rule['id'];
            if (isset($wanted[$id])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * The closures the chosen rules stand for, from $firstYear on.
     *
     * @param list<string> $ids
     * @return array<string, list<array{start: string, end: string, description: string, yearly: bool}>> rule id => rows
     */
    public static function expectedRows(string $country, string $region, array $ids, int $firstYear): array
    {
        $country = strtoupper($country);
        $wanted = array_flip($ids);
        $out = [];
        foreach (HolidayCalculator::rulesFor($country, $region) as $rule) {
            $id = $country . ':' . (string) $rule['id'];
            if (!isset($wanted[$id])) {
                continue;
            }
            $rows = [];
            if (HolidayCalculator::isYearly($rule)) {
                $date = HolidayCalculator::dateOf($rule, $firstYear);
                if ($date !== null) {
                    $rows[] = ['start' => $date, 'end' => $date, 'description' => (string) $rule['name'], 'yearly' => true];
                }
            } else {
                for ($year = $firstYear; $year <= $firstYear + self::YEARS_AHEAD; $year++) {
                    $date = HolidayCalculator::dateOf($rule, $year);
                    if ($date !== null) {
                        $rows[] = ['start' => $date, 'end' => $date, 'description' => (string) $rule['name'], 'yearly' => false];
                    }
                }
            }
            $out[$id] = $rows;
        }
        return $out;
    }

    /** The migration that adds calendario_chiusure.regola_festivita has run. */
    public static function schemaReady(\mysqli $db): bool
    {
        try {
            $result = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure' AND COLUMN_NAME IN ('regola_festivita', 'attiva')");
            if (!$result instanceof \mysqli_result) {
                return false;
            }
            $row = $result->fetch_row();
            $result->free();
            return (int) ($row[0] ?? 0) === 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Save the choice and bring calendario_chiusure in line with it: rows of
     * rules no longer chosen are removed, missing rows are added (or, for a
     * former Italian preset row, adopted), rows already there stay. Applying
     * the same choice twice changes nothing.
     *
     * @param list<string> $ids
     * @return array{added: int, removed: int, adopted: int}
     */
    public static function apply(\mysqli $db, string $country, string $region, array $ids, ?string $today = null): array
    {
        $country = strtoupper($country);
        if (HolidayCalculator::country($country) === null) {
            throw new \InvalidArgumentException('Unknown country: ' . $country);
        }
        $region = HolidayCalculator::normaliseRegion($country, $region);
        $ids = self::filterIds($country, $region, $ids);
        $firstYear = (int) substr($today ?? DateHelper::today(), 0, 4);
        $counts = ['added' => 0, 'removed' => 0, 'adopted' => 0];

        DbAtomic::run($db, static function () use ($db, $country, $region, $ids, $firstYear, &$counts): void {
            $repo = new SettingsRepository($db);
            $repo->set(self::SETTINGS_CATEGORY, 'holiday_country', $country);
            $repo->set(self::SETTINGS_CATEGORY, 'holiday_region', $region);
            $repo->set(self::SETTINGS_CATEGORY, 'holiday_rules', (string) json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Unticked (or of another country): every row of that rule goes.
            if ($ids === []) {
                $db->query('DELETE FROM calendario_chiusure WHERE regola_festivita IS NOT NULL');
                $counts['removed'] += max(0, $db->affected_rows);
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM calendario_chiusure WHERE regola_festivita IS NOT NULL AND regola_festivita NOT IN ({$placeholders})");
                $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
                $stmt->execute();
                $counts['removed'] += max(0, $stmt->affected_rows);
                $stmt->close();
            }

            foreach (self::expectedRows($country, $region, $ids, $firstYear) as $ruleId => $rows) {
                $counts['removed'] += self::pruneStale($db, $ruleId, $rows, $firstYear);
                foreach ($rows as $row) {
                    $outcome = self::ensureRow($db, $ruleId, $row);
                    if ($outcome !== null) {
                        $counts[$outcome]++;
                    }
                }
            }
        }, 'HolidayPresets::apply');

        return $counts;
    }

    /**
     * Yearly upkeep (maintenance): the chosen movable holidays get their rows
     * up to YEARS_AHEAD years after the current one. Adds only; the rows of
     * past years stay as history. Returns the number of rows added.
     */
    public static function ensureYears(\mysqli $db, ?string $today = null): int
    {
        if (!self::schemaReady($db)) {
            return 0;
        }
        $selection = self::selection($db);
        if (!$selection['saved'] || $selection['rules'] === []) {
            return 0;
        }
        $firstYear = (int) substr($today ?? DateHelper::today(), 0, 4);
        $added = 0;
        DbAtomic::run($db, static function () use ($db, $selection, $firstYear, &$added): void {
            foreach (self::expectedRows($selection['country'], $selection['region'], $selection['rules'], $firstYear) as $ruleId => $rows) {
                foreach ($rows as $row) {
                    if (self::ensureRow($db, $ruleId, $row) !== null) {
                        $added++;
                    }
                }
            }
        }, 'HolidayPresets::ensureYears');
        return $added;
    }

    /**
     * Rows of a still-chosen rule that no longer match its dates from
     * $firstYear on (a rule whose shape changed); earlier years stay.
     *
     * @param list<array{start: string, end: string, description: string, yearly: bool}> $rows
     */
    private static function pruneStale(\mysqli $db, string $ruleId, array $rows, int $firstYear): int
    {
        $keepYearly = [];
        $keepDates = [];
        foreach ($rows as $row) {
            if ($row['yearly']) {
                $keepYearly[substr($row['start'], 5)] = true;
            } else {
                $keepDates[$row['start']] = true;
            }
        }
        $from = $keepDates !== [] ? min(array_keys($keepDates)) : sprintf('%04d-01-01', $firstYear);
        $stmt = $db->prepare('SELECT id, data_inizio, ricorrente_annuale FROM calendario_chiusure WHERE regola_festivita = ?');
        $stmt->bind_param('s', $ruleId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $removed = 0;
        foreach ($existing as $row) {
            $start = substr((string) $row['data_inizio'], 0, 10);
            $stale = (int) $row['ricorrente_annuale'] === 1
                ? !isset($keepYearly[substr($start, 5)])
                : ($start >= $from && !isset($keepDates[$start]));
            if ($stale) {
                $id = (int) $row['id'];
                $delete = $db->prepare('DELETE FROM calendario_chiusure WHERE id = ?');
                $delete->bind_param('i', $id);
                $delete->execute();
                $removed += max(0, $delete->affected_rows);
                $delete->close();
            }
        }
        return $removed;
    }

    /**
     * One expected row: already there (null), adopted from a former preset
     * row ('adopted') or inserted ('added').
     *
     * @param array{start: string, end: string, description: string, yearly: bool} $row
     * @return 'added'|'adopted'|null
     */
    private static function ensureRow(\mysqli $db, string $ruleId, array $row): ?string
    {
        $yearly = $row['yearly'] ? 1 : 0;
        if ($yearly === 1) {
            $md = substr($row['start'], 5);
            $stmt = $db->prepare("SELECT 1 FROM calendario_chiusure WHERE regola_festivita = ? AND ricorrente_annuale = 1 AND DATE_FORMAT(data_inizio, '%m-%d') = ? LIMIT 1");
            $stmt->bind_param('ss', $ruleId, $md);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM calendario_chiusure WHERE regola_festivita = ? AND ricorrente_annuale = 0 AND data_inizio = ? LIMIT 1');
            $stmt->bind_param('ss', $ruleId, $row['start']);
        }
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($found) {
            return null;
        }

        $legacy = self::legacyLabels($ruleId);
        if ($legacy !== []) {
            $placeholders = implode(',', array_fill(0, count($legacy), '?'));
            if ($yearly === 1) {
                $md = substr($row['start'], 5);
                $sql = "SELECT id FROM calendario_chiusure WHERE regola_festivita IS NULL AND sede_id IS NULL AND ricorrente_annuale = 1
                        AND DATE_FORMAT(data_inizio, '%m-%d') = ? AND DATE_FORMAT(data_fine, '%m-%d') = ? AND descrizione IN ({$placeholders}) ORDER BY id LIMIT 1";
                $params = array_merge([$md, $md], $legacy);
            } else {
                $sql = "SELECT id FROM calendario_chiusure WHERE regola_festivita IS NULL AND sede_id IS NULL AND ricorrente_annuale = 0
                        AND data_inizio = ? AND data_fine = ? AND descrizione IN ({$placeholders}) ORDER BY id LIMIT 1";
                $params = array_merge([$row['start'], $row['end']], $legacy);
            }
            $stmt = $db->prepare($sql);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $match = $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($match !== null) {
                $id = (int) $match[0];
                $update = $db->prepare('UPDATE calendario_chiusure SET regola_festivita = ?, descrizione = ?, attiva = 1 WHERE id = ?');
                $update->bind_param('ssi', $ruleId, $row['description'], $id);
                $update->execute();
                $update->close();
                return 'adopted';
            }
        }

        $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id, regola_festivita, attiva) VALUES (?, ?, ?, ?, NULL, ?, 1)');
        $stmt->bind_param('sssis', $row['start'], $row['end'], $row['description'], $yearly, $ruleId);
        $stmt->execute();
        $stmt->close();
        return 'added';
    }

    /**
     * The descriptions the former Italian preset could have stored for a
     * rule: the Italian label and its translation in every locale file.
     *
     * @return list<string>
     */
    public static function legacyLabels(string $ruleId): array
    {
        if (!isset(self::LEGACY_IT_LABELS[$ruleId])) {
            return [];
        }
        if (self::$legacyLabelCache === null) {
            self::$legacyLabelCache = [];
            $catalogues = [];
            foreach (glob(dirname(__DIR__, 3) . '/locale/??_??.json') ?: [] as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $catalogues[] = $decoded;
                }
            }
            foreach (self::LEGACY_IT_LABELS as $id => $label) {
                $labels = [$label => true];
                foreach ($catalogues as $catalogue) {
                    if (isset($catalogue[$label]) && is_string($catalogue[$label]) && $catalogue[$label] !== '') {
                        $labels[$catalogue[$label]] = true;
                    }
                }
                self::$legacyLabelCache[$id] = array_map('strval', array_keys($labels));
            }
        }
        return self::$legacyLabelCache[$ruleId] ?? [];
    }
}
