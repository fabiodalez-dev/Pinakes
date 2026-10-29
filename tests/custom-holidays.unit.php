<?php
declare(strict_types=1);

/**
 * Holidays for every country and calendar: the Orthodox Easter computus, the
 * other calendars (Islamic Umm al-Qura, Hebrew, Chinese, Persian, Ethiopian),
 * the worldwide holiday dataset and the library's own holidays — validation,
 * description, next dates, and their effect on the due-date calendar of the
 * slot database (one branch only, suspended, module off).
 *
 * The database part runs against the slot DB (.env or E2E_DB_*) after
 * migrate_0.9.0.sql, and removes every row it adds.
 * Run: php tests/custom-holidays.unit.php
 */

use App\Support\Features;
use App\Support\Holidays\ChineseLunarCalendar;
use App\Support\Holidays\CustomHolidays;
use App\Support\Holidays\HolidayCalculator as H;
use App\Support\Holidays\HolidayDataset;
use App\Support\Holidays\WorldCalendars;
use App\Support\LibraryCalendar;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

$pass = 0;
$fail = 0;
$eq = static function ($actual, $expected, string $label) use (&$pass, &$fail): void {
    if ($actual === $expected) {
        $pass++;
        echo "OK   {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}: got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . "\n";
};
$fmt = static fn (string $pattern, string $ymd): string => (new IntlDateFormatter('it_IT', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $pattern))
    ->format(new DateTimeImmutable($ymd . ' 12:00:00', new DateTimeZone('UTC')));

// ── Orthodox Easter ────────────────────────────────────────────────────────
foreach ([2024 => '2024-05-05', 2025 => '2025-04-20', 2026 => '2026-04-12', 2027 => '2027-05-02', 2028 => '2028-04-16', 2100 => '2100-05-02'] as $year => $date) {
    $eq(H::orthodoxEaster($year), $date, "Orthodox Easter {$year}");
}
$eq(H::datesOf(['rule' => ['orthodox_easter', 1]], 2026), ['2026-04-13'], 'Orthodox Easter Monday 2026');

// ── Other calendars ────────────────────────────────────────────────────────
$eq(WorldCalendars::datesIn('islamic-umalqura', 10, 1, 2026), ['2026-03-20'], 'Eid al-Fitr 2026 (1 Shawwal 1447)');
$eq(WorldCalendars::datesIn('islamic-umalqura', 12, 10, 2026), ['2026-05-27'], 'Eid al-Adha 2026 (10 Dhu al-Hijjah)');
$eq(WorldCalendars::datesIn('islamic-umalqura', 10, 1, 2033), ['2033-01-03', '2033-12-23'], 'Eid al-Fitr falls twice in 2033');
$eq(WorldCalendars::datesIn('hebrew', 1, 1, 2026), ['2026-09-12'], 'Rosh Hashanah 5787');
$eq(WorldCalendars::datesIn('hebrew', 8, 15, 2026), ['2026-04-02'], 'Pesach 2026 (15 Nisan)');
$eq(WorldCalendars::datesIn('hebrew', 6, 14, 2026), [], 'no Adar I in a common year');
$eq(WorldCalendars::datesIn('hebrew', 7, 14, 2026), ['2026-03-03'], 'Purim 2026 (14 Adar)');
$eq(WorldCalendars::datesIn('persian', 1, 1, 2026), ['2026-03-21'], 'Nowruz 1405');
$eq(WorldCalendars::datesIn('ethiopic', 1, 1, 2026), ['2026-09-11'], 'Enkutatash 2026 (1 Meskerem)');
$eq(WorldCalendars::datesIn('chinese', 1, 1, 2027), ['2027-02-06'], 'Lunar New Year 2027 (ICU says 7 February)');
$eq(WorldCalendars::datesIn('chinese', 1, 1, 2030), ['2030-02-03'], 'Lunar New Year 2030 (ICU says 2 February)');
$eq(WorldCalendars::datesIn('chinese', 8, 15, 2026), ['2026-09-25'], 'Mid-Autumn 2026');
$eq(WorldCalendars::datesIn('chinese', 5, 5, 2026), ['2026-06-19'], 'Dragon Boat 2026');
$eq(WorldCalendars::datesIn('nope', 1, 1, 2026), [], 'an unknown calendar has no dates');
$eq(ChineseLunarCalendar::toGregorian(2101, 1, 1), null, 'the lunar table ends in 2100');
$eq(ChineseLunarCalendar::toGregorian(2026, 1, 30) === null || ChineseLunarCalendar::monthDays(2026, 1) === 30, true, 'a 30th day exists only in a 30-day month');
$eq(count(WorldCalendars::months('hebrew')), 13, 'the Hebrew calendar has 13 months to choose from');

// ── Worldwide dataset ──────────────────────────────────────────────────────
$eq(count(H::codes()) >= 249, true, 'every country and territory of the dataset is offered');
$eq(in_array('2026-03-20', HolidayDataset::dates('SA', 'eid_al_fitr_holiday', 2026), true), true, 'Saudi Arabia: Eid al-Fitr holiday 2026');
$eq(in_array('2027-02-06', HolidayDataset::dates('CN', 'chinese_new_year_spring_festival', 2027), true), true, 'China: Spring Festival 2027');
$eq(HolidayDataset::dates('IN', 'diwali', 2026), ['2026-11-08'], 'India: Diwali 2026');
$eq(H::country('SA')['dataset'] ?? false, true, 'a country without a hand-written table comes from the dataset');
$eq(isset(H::country('IT')['dataset']), false, 'Italy keeps its hand-written table');
$eq(HolidayDataset::years('KE'), [2010, 2060], 'the dataset years are declared');
$eq(HolidayDataset::decode('d|1225:2010-2012,2015'), [2010 => ['2010-12-25'], 2011 => ['2011-12-25'], 2012 => ['2012-12-25'], 2015 => ['2015-12-25']], 'by-day encoding');
$eq(HolidayDataset::decode('y|2026:0319-0321,1225'), [2026 => ['2026-03-19', '2026-03-20', '2026-03-21', '2026-12-25']], 'by-year encoding with a run of days');
$india = array_column(H::rulesFor('IN', 'KA'), 'id');
$eq(in_array('ugadi', $india, true) && !in_array('ugadi', array_column(H::rulesFor('IN', 'DL'), 'id'), true), true, 'a regional holiday shows in its region only');
$eq(H::datesOf(H::rulesFor('SA')[0], 2026) !== [], true, 'a dataset rule gives its dates through datesOf()');
$eq(is_string(H::countryName('JP')) && H::countryName('JP') !== 'JP', true, 'country names come from ICU in the interface language');

// ── Validation of the admin form ───────────────────────────────────────────
$valid = static fn (array $data) => CustomHolidays::validate(array_replace(['nome' => 'ZZ', 'attiva' => '1'], $data), [7]);
$eq(is_string(CustomHolidays::validate(['tipo' => 'fixed'], [])), true, 'a holiday needs a name');
$eq(is_string($valid(['tipo' => 'nope'])), true, 'an unknown kind is refused');
$eq(is_string($valid(['tipo' => 'fixed', 'fixed_mese' => '2', 'fixed_giorno' => '31'])), true, '31 February is refused');
$leap = $valid(['tipo' => 'fixed', 'fixed_mese' => '2', 'fixed_giorno' => '29']);
$eq(is_array($leap) && $leap['mese'] === 2 && $leap['giorno'] === 29, true, '29 February is accepted');
$eq(H::datesOf(CustomHolidays::rule($leap + ['id' => 1]), 2027), [], '29 February is skipped in a common year');
$eq(is_string($valid(['tipo' => 'nth_weekday', 'nth_mese' => '10', 'nth_giorno_settimana' => '1', 'nth_occorrenza' => '6'])), true, 'a sixth weekday is refused');
$eq(is_string($valid(['tipo' => 'easter', 'scarto' => '201'])), true, 'an Easter offset beyond 200 days is refused');
$eq(is_string($valid(['tipo' => 'calendar', 'calendario' => 'mayan', 'cal_giorno' => '1'])), true, 'an unknown calendar is refused');
$eq(is_string($valid(['tipo' => 'calendar', 'calendario' => 'hebrew', 'cal_mese_hebrew' => '14', 'cal_giorno' => '1'])), true, 'a month the calendar does not have is refused');
$eq(is_string($valid(['tipo' => 'dates', 'date_specifiche' => 'tomorrow'])), true, 'a list without a valid date is refused');
$eq(is_string($valid(['tipo' => 'fixed', 'fixed_mese' => '1', 'fixed_giorno' => '1', 'durata_giorni' => '15'])), true, 'more than 14 days is refused');
$eq(is_string($valid(['tipo' => 'fixed', 'fixed_mese' => '1', 'fixed_giorno' => '1', 'anno_inizio' => '2030', 'anno_fine' => '2020'])), true, 'a last year before the first is refused');
$branchless = $valid(['tipo' => 'fixed', 'fixed_mese' => '6', 'fixed_giorno' => '24', 'sede_id' => '99']);
$eq(is_array($branchless) && $branchless['sede_id'] === null, true, 'an unknown branch means every branch');
$multi = $valid(['tipo' => 'fixed', 'fixed_mese' => '12', 'fixed_giorno' => '24', 'durata_giorni' => '3', 'fine_settimana' => 'next_monday']);
$eq(is_array($multi) && $multi['fine_settimana'] === null, true, 'a weekend rule applies to one-day holidays only');
$eq(H::datesOf(CustomHolidays::rule($multi + ['id' => 1]), 2026), ['2026-12-24', '2026-12-25', '2026-12-26'], 'a three-day closure');
$lieu = $valid(['tipo' => 'fixed', 'fixed_mese' => '6', 'fixed_giorno' => '27', 'fine_settimana' => 'next_monday']);
$eq(H::datesOf(CustomHolidays::rule($lieu + ['id' => 1]), 2026), ['2026-06-27', '2026-06-29'], 'Saturday 27 June 2026 also closes Monday 29');
$eid = $valid(['tipo' => 'calendar', 'calendario' => 'islamic-umalqura', 'cal_mese_islamic-umalqura' => '10', 'cal_giorno' => '1', 'durata_giorni' => '3']);
$eq(H::datesOf(CustomHolidays::rule($eid + ['id' => 1]), 2026), ['2026-03-20', '2026-03-21', '2026-03-22'], 'Eid al-Fitr as three days');
$dates = $valid(['tipo' => 'dates', 'date_specifiche' => "2027-11-08\n2026-11-08, 2026-11-08"]);
$eq(is_array($dates) ? $dates['date_specifiche'] : null, '2026-11-08,2027-11-08', 'specific dates are sorted and deduplicated');
$eq(CustomHolidays::nextDates($dates + ['id' => 1], '2026-11-09'), ['2027-11-08'], 'next dates start from today');

// ── Descriptions ───────────────────────────────────────────────────────────
$eq(CustomHolidays::describe(['tipo' => 'fixed', 'mese' => 6, 'giorno' => 24], $fmt), 'Ogni anno il 24 giugno', 'description of a fixed day');
$eq(CustomHolidays::describe(['tipo' => 'nth_weekday', 'mese' => 10, 'giorno_settimana' => 0, 'occorrenza' => 1], $fmt), 'La prima domenica di ottobre', 'Sunday is feminine in the Italian description');
$eq(CustomHolidays::describe(['tipo' => 'nth_weekday', 'mese' => 11, 'giorno_settimana' => 4, 'occorrenza' => -1], $fmt), 'L’ultimo giovedì di novembre', 'description of a last weekday');
$eq(CustomHolidays::describe(['tipo' => 'orthodox_easter', 'scarto_giorni' => -2], $fmt), '2 giorni prima di Pasqua ortodossa', 'description relative to Orthodox Easter');
$eq(CustomHolidays::describe(['tipo' => 'calendar', 'calendario' => 'hebrew', 'mese' => 1, 'giorno' => 1, 'durata_giorni' => 2], $fmt), 'Calendario Ebraico: 1 Tishrei · 2 giorni', 'description in another calendar');

// ── Database: the due-date calendar ────────────────────────────────────────
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pw = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = $socket !== '' && file_exists($socket)
    ? new mysqli(null, $user, $pw, $name, 0, $socket)
    : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pw, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
$db->set_charset('utf8mb4');
if (!CustomHolidays::schemaReady($db)) {
    fwrite(STDERR, "FAIL: festivita_personalizzate missing — apply migrate_0.9.0.sql first\n");
    exit(1);
}
$branch = null;
try {
    $db->query("INSERT INTO sedi (nome) VALUES ('ZZ custom holiday branch')");
    $branch = (int) $db->insert_id;
    Features::override('closures_calendar', true);
    $add = static function (array $data) use ($db, $branch): int {
        $row = CustomHolidays::validate(array_replace(['nome' => 'ZZ custom', 'attiva' => '1'], $data), [$branch]);
        if (!is_array($row)) {
            throw new RuntimeException('invalid test holiday: ' . $row);
        }
        $id = CustomHolidays::save($db, $row);
        LibraryCalendar::reset();
        return $id;
    };
    // 2031-03-04 is a Tuesday, a day no preset or weekly rule closes.
    $everywhere = $add(['tipo' => 'dates', 'date_specifiche' => '2031-03-04']);
    $eq(LibraryCalendar::forDb($db)->isOpen('2031-03-04'), false, 'a custom holiday closes the library');
    $eq(LibraryCalendar::dueDate($db, '2031-03-04') !== '2031-03-04', true, 'a due date on it moves to the next open day');
    $eq(CustomHolidays::find($db, $everywhere)['nome'] ?? null, 'ZZ custom', 'the holiday is stored');

    $onlyBranch = $add(['tipo' => 'dates', 'date_specifiche' => '2031-03-05', 'sede_id' => (string) $branch]);
    $calendar = LibraryCalendar::forDb($db);
    $eq([$calendar->isOpen('2031-03-05', $branch), $calendar->isOpen('2031-03-05', $branch + 100000), $calendar->isOpen('2031-03-05')], [false, true, true], 'a branch holiday closes that branch only');

    CustomHolidays::setActive($db, $everywhere, false);
    LibraryCalendar::reset();
    $eq(LibraryCalendar::forDb($db)->isOpen('2031-03-04'), true, 'a suspended holiday closes nothing');
    CustomHolidays::setActive($db, $everywhere, true);

    Features::override('closures_calendar', false);
    LibraryCalendar::reset();
    $eq(LibraryCalendar::forDb($db)->isOpen('2031-03-04'), true, 'with the calendar module off a custom holiday closes nothing');
    $eq(LibraryCalendar::fromDatabase($db, configuration: true)->isOpen('2031-03-04'), false, 'the admin preview still shows it');
    Features::override('closures_calendar', true);

    $eq(CustomHolidays::delete($db, $onlyBranch), true, 'a custom holiday is deleted');
    $db->query("DELETE FROM sedi WHERE id = {$branch}");
    $branch = null;
    $eq(CustomHolidays::find($db, $onlyBranch), null, 'and is gone');
} finally {
    $db->query("DELETE FROM festivita_personalizzate WHERE nome = 'ZZ custom'");
    if ($branch !== null) {
        $db->query("DELETE FROM sedi WHERE id = {$branch}");
    }
    Features::resetOverrides();
    LibraryCalendar::reset();
}

echo ($fail === 0 ? 'SUCCESS' : 'FAILURE') . " {$pass} checks, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
