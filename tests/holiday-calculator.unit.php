<?php
declare(strict_types=1);

/**
 * The holiday rules of every preset country, checked against KNOWN dates
 * (official calendars of those years), plus the invariants of the table.
 * Pure: no database. Run: php tests/holiday-calculator.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Holidays\HolidayCalculator as H;
use App\Support\Holidays\HolidayRules;

$pass = 0;
$fail = 0;
$eq = static function ($actual, $expected, string $label) use (&$pass, &$fail): void {
    if ($actual === $expected) {
        $pass++;
        echo "OK   {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
};
/** The date of rule $id of $country/$region in $year (null when absent or not that year). */
$date = static function (string $country, string $region, int $year, string $id): ?string {
    foreach (H::forYear($country, $region, $year) as $h) {
        if ($h['id'] === "{$country}:{$id}") {
            return $h['date'];
        }
    }
    return null;
};
$has = static function (string $country, string $region, string $id): bool {
    foreach (H::rulesFor($country, $region) as $rule) {
        if ($rule['id'] === $id) {
            return true;
        }
    }
    return false;
};

// ── The table ─────────────────────────────────────────────────────────────
$countries = HolidayRules::countries();
$eq(count($countries) >= 22, true, 'at least 22 countries');
foreach (['IT', 'US', 'GB', 'IE', 'DE', 'FR', 'DK', 'ES', 'PT', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'FI', 'PL', 'CA', 'AU', 'BR', 'AR', 'MX'] as $cc) {
    $eq(isset($countries[$cc]), true, "{$cc} is a preset country");
}
$kinds = [];
$allUnique = true;
$allPrefixed = true;
$allDated = true;
$regionsKnown = true;
foreach ($countries as $cc => $country) {
    $seen = [];
    foreach ($country['rules'] as $rule) {
        $kinds[$rule['rule'][0]] = true;
        if (isset($seen[$rule['id']])) {
            $allUnique = false;
            echo "     duplicate id {$cc}:{$rule['id']}\n";
        }
        $seen[$rule['id']] = true;
        foreach ($rule['regions'] ?? [] as $region) {
            if (!isset($country['regions'][$region])) {
                $regionsKnown = false;
                echo "     unknown region {$region} in {$cc}:{$rule['id']}\n";
            }
        }
        // Every rule that exists in 2026 has a date in 2026.
        if (!isset($rule['until']) && (($rule['from'] ?? 0) <= 2026) && H::dateOf($rule, 2026) === null) {
            $allDated = false;
            echo "     no 2026 date for {$cc}:{$rule['id']}\n";
        }
    }
    foreach (H::forYear($cc, '', 2026) as $h) {
        if (!str_starts_with($h['id'], $cc . ':')) {
            $allPrefixed = false;
        }
    }
    $eq(count(H::rulesFor($cc, H::normaliseRegion($cc, ''))) >= 4, true, "{$cc} has at least 4 holidays without a region");
}
$eq($allUnique, true, 'rule ids are unique in each country');
$eq($allPrefixed, true, 'every id is CC:-prefixed');
$eq($regionsKnown, true, 'every rule region is a region of its country');
$eq($allDated, true, 'every current rule has a date in 2026');
ksort($kinds);
$eq(array_keys($kinds), ['easter', 'fixed', 'fixed_if', 'nth_weekday', 'weekday_on_or_after', 'weekday_on_or_before'], 'all six rule kinds are used');

// ── Easter-based, several years ───────────────────────────────────────────
$eq($date('IT', '', 2024, 'easter_monday'), '2024-04-01', 'IT Pasquetta 2024');
$eq($date('IT', '', 2025, 'easter'), '2025-04-20', 'IT Pasqua 2025');
$eq($date('IT', '', 2026, 'easter'), '2026-04-05', 'IT Pasqua 2026');
$eq($date('DE', '', 2026, 'karfreitag'), '2026-04-03', 'DE Karfreitag 2026');
$eq($date('DE', '', 2027, 'christi_himmelfahrt'), '2027-05-06', 'DE Christi Himmelfahrt 2027');
$eq($date('DE', '', 2025, 'pfingstmontag'), '2025-06-09', 'DE Pfingstmontag 2025');
$eq($date('FR', '', 2028, 'lundi_de_pentecote'), '2028-06-05', 'FR Lundi de Pentecôte 2028');
$eq($date('DK', '', 2026, 'skaertorsdag'), '2026-04-02', 'DK Skærtorsdag 2026');
$eq($date('PT', '', 2026, 'corpo_de_deus'), '2026-06-04', 'PT Corpo de Deus 2026');
$eq($date('BR', '', 2026, 'carnaval_terca'), '2026-02-17', 'BR Carnaval (terça) 2026');
$eq($date('PL', '', 2026, 'zielone_swiatki'), '2026-05-24', 'PL Zielone Świątki 2026');

// ── Denmark: Store bededag abolished from 2024 ────────────────────────────
$eq($date('DK', '', 2023, 'store_bededag'), '2023-05-05', 'DK Store bededag 2023 (4th Friday after Easter)');
$eq($date('DK', '', 2024, 'store_bededag'), null, 'DK Store bededag gone in 2024');
$eq($date('DK', '', 2026, 'store_bededag'), null, 'DK Store bededag gone in 2026');
$eq($date('DK', '', 2026, 'grundlovsdag'), '2026-06-05', 'DK Grundlovsdag');

// ── United States: nth weekday and observed days ──────────────────────────
$eq($date('US', '', 2026, 'thanksgiving'), '2026-11-26', 'US Thanksgiving 2026');
$eq($date('US', '', 2025, 'thanksgiving'), '2025-11-27', 'US Thanksgiving 2025');
$eq($date('US', '', 2026, 'memorial_day'), '2026-05-25', 'US Memorial Day 2026 (last Monday of May)');
$eq($date('US', '', 2026, 'mlk_day'), '2026-01-19', 'US MLK Day 2026');
$eq($date('US', '', 2026, 'labor_day'), '2026-09-07', 'US Labor Day 2026');
$eq($date('US', '', 2026, 'independence_day'), '2026-07-03', 'US Independence Day 2026 (Saturday) observed Friday 3');
$eq($date('US', '', 2027, 'independence_day'), '2027-07-05', 'US Independence Day 2027 (Sunday) observed Monday 5');
$eq($date('US', '', 2022, 'new_year'), '2021-12-31', 'US New Year 2022 (Saturday) observed Friday 31 Dec 2021');
$eq($date('US', '', 2020, 'juneteenth'), null, 'US Juneteenth not before 2021');
$eq($date('US', '', 2027, 'juneteenth'), '2027-06-18', 'US Juneteenth 2027 (Saturday) observed Friday');

// ── United Kingdom: substitute days, the VE Day and Jubilee moves ─────────
$eq($date('GB', 'ENG', 2026, 'early_may'), '2026-05-04', 'GB early May 2026');
$eq($date('GB', 'ENG', 2020, 'early_may'), '2020-05-08', 'GB early May 2020 moved to VE Day (Friday 8)');
$eq($date('GB', 'ENG', 2022, 'spring'), '2022-06-02', 'GB spring 2022 moved for the Platinum Jubilee');
$eq($date('GB', 'ENG', 2026, 'spring'), '2026-05-25', 'GB spring 2026');
$eq($date('GB', 'ENG', 2027, 'christmas'), '2027-12-27', 'GB Christmas 2027 (Saturday) → Monday 27');
$eq($date('GB', 'ENG', 2027, 'boxing_day'), '2027-12-28', 'GB Boxing Day 2027 (Sunday) → Tuesday 28');
$eq($date('GB', 'ENG', 2022, 'christmas'), '2022-12-27', 'GB Christmas 2022 (Sunday) → Tuesday 27');
$eq($date('GB', 'ENG', 2022, 'boxing_day'), '2022-12-26', 'GB Boxing Day 2022 stays Monday 26');
$eq($date('GB', 'ENG', 2023, 'new_year'), '2023-01-02', 'GB New Year 2023 (Sunday) → Monday 2');
$eq($date('GB', 'SCT', 2022, 'new_year_sct'), '2022-01-03', 'SCT New Year 2022 (Saturday) → Monday 3');
$eq($date('GB', 'SCT', 2022, 'second_january'), '2022-01-04', 'SCT 2 January 2022 (Sunday) → Tuesday 4');
$eq($date('GB', 'SCT', 2026, 'summer_sct'), '2026-08-03', 'SCT summer bank holiday = first Monday of August');
$eq($date('GB', 'ENG', 2026, 'summer'), '2026-08-31', 'ENG summer bank holiday = last Monday of August');
$eq($has('GB', 'SCT', 'easter_monday'), false, 'no Easter Monday in Scotland');
$eq($has('GB', 'NIR', 'st_patrick') && !$has('GB', 'ENG', 'st_patrick'), true, "St Patrick's Day only in Northern Ireland");
$eq(H::normaliseRegion('GB', ''), 'ENG', 'the United Kingdom always has a nation');

// ── Germany: Länder ───────────────────────────────────────────────────────
$eq($date('DE', 'SN', 2026, 'buss_und_bettag'), '2026-11-18', 'DE Buß- und Bettag 2026 (Sachsen)');
$eq($date('DE', 'SN', 2025, 'buss_und_bettag'), '2025-11-19', 'DE Buß- und Bettag 2025 (Sachsen)');
$eq($date('DE', 'SN', 2027, 'buss_und_bettag'), '2027-11-17', 'DE Buß- und Bettag 2027 (Sachsen)');
$eq($has('DE', 'BY', 'buss_und_bettag'), false, 'no Buß- und Bettag in Bayern');
$eq($date('DE', 'BY', 2026, 'fronleichnam'), '2026-06-04', 'DE Fronleichnam 2026 in Bayern');
$eq($has('DE', 'BE', 'fronleichnam'), false, 'no Fronleichnam in Berlin');
$eq($has('DE', '', 'fronleichnam'), false, 'no Fronleichnam without a Land');
$eq($date('DE', 'BE', 2026, 'frauentag'), '2026-03-08', 'Frauentag in Berlin');
$eq($date('DE', 'MV', 2022, 'frauentag_mv'), null, 'Frauentag in Mecklenburg-Vorpommern only from 2023');
$eq($date('DE', 'NI', 2017, 'reformationstag_nord'), null, 'Reformationstag in Niedersachsen only from 2018');

// ── Canada, Australia, Ireland ────────────────────────────────────────────
$eq($date('CA', '', 2026, 'victoria_day'), '2026-05-18', 'CA Victoria Day 2026 (Monday before 25 May)');
$eq($date('CA', '', 2027, 'victoria_day'), '2027-05-24', 'CA Victoria Day 2027 (24 May is a Monday)');
$eq($date('CA', '', 2026, 'thanksgiving'), '2026-10-12', 'CA Thanksgiving 2026 (2nd Monday of October)');
$eq($date('CA', '', 2029, 'canada_day'), '2029-07-02', 'CA Canada Day 2029 (Sunday) → Monday 2');
$eq($date('CA', '', 2020, 'truth_and_reconciliation'), null, 'CA Truth and Reconciliation from 2021');
$eq($date('CA', 'QC', 2026, 'fete_nationale_quebec'), '2026-06-24', 'Fête nationale du Québec');
$eq($has('CA', 'QC', 'family_day'), false, 'no Family Day in Québec');
$eq($date('CA', 'ON', 2026, 'family_day'), '2026-02-16', 'Family Day 2026 in Ontario');
$eq($date('AU', '', 2026, 'australia_day'), '2026-01-26', 'AU Australia Day 2026 (Monday)');
$eq($date('AU', '', 2025, 'boxing_day'), '2025-12-26', 'AU Boxing Day 2025 (Friday)');
$eq($date('AU', '', 2021, 'boxing_day'), '2021-12-28', 'AU Boxing Day 2021 (Sunday) → Tuesday 28');
$eq($date('AU', 'NSW', 2026, 'kings_birthday'), '2026-06-08', "AU King's Birthday 2026 in NSW");
$eq($date('AU', 'QLD', 2026, 'kings_birthday_qld'), '2026-10-05', "AU King's Birthday 2026 in Queensland (1st Monday of October)");
$eq($date('AU', 'VIC', 2026, 'melbourne_cup'), '2026-11-03', 'AU Melbourne Cup 2026');
$eq($date('IE', '', 2026, 'st_brigid'), '2026-02-02', "IE St Brigid's Day 2026 (first Monday of February)");
$eq($date('IE', '', 2030, 'st_brigid'), '2030-02-01', "IE St Brigid's Day 2030 (1 February is a Friday)");
$eq($date('IE', '', 2022, 'st_brigid'), null, "IE St Brigid's Day only from 2023");
$eq($date('IE', '', 2026, 'october_holiday'), '2026-10-26', 'IE October Holiday 2026');

// ── Nordics, Netherlands, Switzerland, Argentina, Mexico, Italy ───────────
$eq($date('SE', '', 2026, 'midsommardagen'), '2026-06-20', 'SE Midsommardagen 2026');
$eq($date('SE', '', 2025, 'midsommardagen'), '2025-06-21', 'SE Midsommardagen 2025');
$eq($date('SE', '', 2026, 'alla_helgons_dag'), '2026-10-31', 'SE Alla helgons dag 2026');
$eq($date('FI', '', 2027, 'juhannuspaiva'), '2027-06-26', 'FI Juhannuspäivä 2027');
$eq($date('FI', '', 2025, 'pyhainpaiva'), '2025-11-01', 'FI Pyhäinpäivä 2025');
$eq($date('NL', '', 2025, 'koningsdag'), '2025-04-26', 'NL Koningsdag 2025 (27 April is a Sunday) → Saturday 26');
$eq($date('NL', '', 2026, 'koningsdag'), '2026-04-27', 'NL Koningsdag 2026');
$eq($date('CH', 'GE', 2026, 'jeune_genevois'), '2026-09-10', 'CH Jeûne genevois 2026');
$eq($date('CH', 'VD', 2026, 'lundi_du_jeune'), '2026-09-21', 'CH Lundi du Jeûne fédéral 2026 (Vaud)');
$eq($has('CH', '', 'karfreitag'), false, 'CH Good Friday only in the cantons that keep it');
$eq($date('AR', '', 2026, 'soberania_nacional'), '2026-11-23', 'AR Soberanía Nacional 2026 (Friday 20) → Monday 23');
$eq($date('AR', '', 2026, 'guemes'), '2026-06-15', 'AR Güemes 2026 (Wednesday 17) → Monday 15');
$eq($date('AR', '', 2026, 'san_martin'), '2026-08-17', 'AR San Martín 2026 stays on Monday 17');
$eq($date('MX', '', 2026, 'dia_de_la_constitucion'), '2026-02-02', 'MX Día de la Constitución 2026');
$eq($date('MX', '', 2026, 'dia_de_la_revolucion'), '2026-11-16', 'MX Día de la Revolución 2026');
$eq($date('IT', '', 2025, 'san_francesco'), null, 'IT San Francesco not in 2025');
$eq($date('IT', '', 2026, 'san_francesco'), '2026-10-04', 'IT San Francesco from 2026');
$eq($date('PL', '', 2024, 'wigilia'), null, 'PL Wigilia not a holiday in 2024');
$eq($date('PL', '', 2025, 'wigilia'), '2025-12-24', 'PL Wigilia a holiday from 2025');
$eq($date('BR', '', 2026, 'consciencia_negra'), '2026-11-20', 'BR Consciência Negra (from 2024)');

// ── Yearly or per year ────────────────────────────────────────────────────
$rule = static function (string $cc, string $id): array {
    foreach (HolidayRules::countries()[$cc]['rules'] as $r) {
        if ($r['id'] === $id) {
            return $r;
        }
    }
    return [];
};
$eq(H::isYearly($rule('IT', 'christmas')), true, 'a fixed date with no weekend rule is one yearly row');
$eq(H::isYearly($rule('US', 'christmas')), false, 'a fixed date with a weekend rule is dated per year');
$eq(H::isYearly($rule('IT', 'san_francesco')), false, 'a fixed date with a first year is dated per year');
$eq(H::isYearly($rule('GB', 'early_may')), false, 'an nth weekday is dated per year');
$eq(H::nthWeekday(2026, 2, 1, 5), null, 'there is no fifth Monday in February 2026');
$eq(H::observe('2026-07-04', 'none'), '2026-07-04', 'an unknown weekend rule leaves the date');

echo ($fail === 0 ? 'SUCCESS' : 'FAILED') . " {$pass} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
