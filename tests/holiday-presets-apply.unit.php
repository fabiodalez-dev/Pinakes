<?php
declare(strict_types=1);

/**
 * HolidayPresets::apply() / ensureYears() against a real sandbox database
 * (tables from installer/database/schema.sql): rows per rule, idempotency,
 * unticking removes only that rule, manual and ICS rows untouched, former
 * Italian preset rows adopted, the yearly upkeep, switched-off closures.
 *
 * Needs a user allowed to create the sandbox database (E2E_HOLIDAY_DB,
 * default "<DB_NAME>_holtest"). Run: php tests/holiday-presets-apply.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

use App\Support\Holidays\HolidayPresets;
use App\Support\LibraryCalendar;

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$sandbox = getenv('E2E_HOLIDAY_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_holtest';
if (preg_match('/^[A-Za-z0-9_]+$/', $sandbox) !== 1) {
    fwrite(STDERR, "invalid sandbox name\n");
    exit(1);
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};

$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
$ddl = [];
foreach (['sedi', 'system_settings', 'calendario_chiusure'] as $table) {
    if (preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=[^;]*;/s', $schema, $m) !== 1) {
        fwrite(STDERR, "{$table} not found in schema.sql\n");
        exit(1);
    }
    $ddl[] = $m[0];
}

$admin = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', '', (int) ($env['DB_PORT'] ?? 3306), $socket);
$exit = 1;
try {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, (int) ($env['DB_PORT'] ?? 3306), $socket);
    $db->set_charset('utf8mb4');
    foreach ($ddl as $statement) {
        $db->query($statement);
    }
    $one = static fn (string $sql): string => (string) ($db->query($sql)->fetch_row()[0] ?? '');
    $count = static fn (string $where = '1=1'): int => (int) $one("SELECT COUNT(*) FROM calendario_chiusure WHERE {$where}");
    $today = '2026-10-29';

    $check(HolidayPresets::schemaReady($db), 'schema.sql has regola_festivita and attiva');
    $check(HolidayPresets::selection($db)['saved'] === false, 'nothing is chosen on a fresh install');

    // Closures that are not presets: by hand, and as an ICS import stores them.
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-08-16','2026-08-16','Patrono',1)");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-04-06','2026-04-06','Inventario di primavera',0)");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-11-02','2026-11-05','ICS inventario',0)");
    $manualSnapshot = static fn (): string => $one("SELECT GROUP_CONCAT(CONCAT_WS('|', id, data_inizio, data_fine, descrizione, ricorrente_annuale, attiva) ORDER BY id) FROM calendario_chiusure WHERE regola_festivita IS NULL");
    $manual = $manualSnapshot();

    // 1. Three Italian holidays: two fixed (one yearly row each), one movable (three years).
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:new_year', 'IT:easter_monday', 'IT:christmas'], $today);
    $check($r === ['added' => 5, 'removed' => 0, 'adopted' => 0], 'apply: 5 rows added (1 + 3 + 1)');
    $check($count("regola_festivita = 'IT:new_year' AND ricorrente_annuale = 1") === 1, 'a fixed holiday is ONE yearly row');
    $check($one("SELECT GROUP_CONCAT(data_inizio ORDER BY data_inizio) FROM calendario_chiusure WHERE regola_festivita = 'IT:easter_monday'") === '2026-04-06,2027-03-29,2028-04-17', 'Easter Monday is dated for 2026, 2027 and 2028');
    $check($one("SELECT descrizione FROM calendario_chiusure WHERE regola_festivita = 'IT:christmas'") === 'Natale', 'the native name is the description');
    $check($count("regola_festivita IS NOT NULL AND sede_id IS NOT NULL") === 0, 'preset rows are for every branch');
    $sel = HolidayPresets::selection($db);
    $check($sel['saved'] && $sel['country'] === 'IT' && $sel['rules'] === ['IT:new_year', 'IT:easter_monday', 'IT:christmas'], 'the choice is saved');

    // 2. Applying again changes nothing.
    $before = $count();
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:christmas', 'IT:new_year', 'IT:easter_monday'], $today);
    $check($r === ['added' => 0, 'removed' => 0, 'adopted' => 0] && $count() === $before, 'reapplying adds and removes nothing');

    // 3. Unticking removes only that rule.
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:new_year', 'IT:christmas'], $today);
    $check($r['removed'] === 3 && $r['added'] === 0, 'unticking Easter Monday removes its 3 rows');
    $check($count("regola_festivita = 'IT:easter_monday'") === 0 && $count("regola_festivita IN ('IT:new_year','IT:christmas')") === 2, 'the other holidays stay');
    $check($manualSnapshot() === $manual, 'manual and ICS closures are untouched (also the one on Easter Monday 2026)');

    // 4. Unknown and foreign ids are dropped; a regional rule needs its region.
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:new_year', 'IT:christmas', 'IT:nope', 'DE:neujahr'], $today);
    $check($r['added'] === 0 && $count("regola_festivita LIKE 'DE:%'") === 0, 'ids of another country or unknown are ignored');

    // 5. Another country: every Italian row goes, the German ones come.
    $r = HolidayPresets::apply($db, 'DE', 'BE', ['DE:neujahr', 'DE:karfreitag', 'DE:fronleichnam', 'DE:frauentag'], $today);
    $check($count("regola_festivita LIKE 'IT:%'") === 0, 'switching country removes the previous country');
    $check($count("regola_festivita = 'DE:fronleichnam'") === 0, 'Fronleichnam is not stored for Berlin');
    $check($count("regola_festivita = 'DE:frauentag'") === 3 && $count("regola_festivita = 'DE:karfreitag'") === 3, 'Frauentag (from 2019) and Karfreitag are dated per year');
    $check($r['removed'] === 2 && $r['added'] === 7, 'counts: 2 removed, 7 added (1 + 3 + 3)');
    $check(HolidayPresets::selection($db)['rules'] === ['DE:neujahr', 'DE:frauentag', 'DE:karfreitag'], 'the saved choice keeps the table order and only valid ids');
    $check($manualSnapshot() === $manual, 'manual and ICS closures still untouched');

    // 6. Nothing ticked: every preset row goes, nothing else.
    $r = HolidayPresets::apply($db, 'DE', 'BE', [], $today);
    $check($r['removed'] === 7 && $count('regola_festivita IS NOT NULL') === 0 && $manualSnapshot() === $manual, 'select none removes all preset rows and only those');

    // 7. Former Italian preset rows (the 0.9.0 importer, in the language of the
    // install) are adopted, not duplicated; a manual closure on the same day
    // with another name is not.
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-12-25','2026-12-25','Natale',1)");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2025-12-26','2025-12-26','Santo Stefano',1)");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-01-01','2026-01-01','Neujahr',1)");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2027-03-29','2027-03-29','Ostermontag',0)");
    $legacyIds = $one("SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure WHERE descrizione IN ('Natale','Santo Stefano','Neujahr','Ostermontag')");
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:new_year', 'IT:easter_monday', 'IT:christmas', 'IT:st_stephen'], $today);
    $check($r['adopted'] === 4, 'four former preset rows adopted (Italian and German labels, yearly and dated)');
    $check($r['added'] === 2, 'only the missing Easter Mondays (2026, 2028) are added');
    $check($count("ricorrente_annuale = 1 AND DATE_FORMAT(data_inizio, '%m-%d') = '12-25'") === 1, 'Christmas is not duplicated');
    $check($one("SELECT GROUP_CONCAT(id ORDER BY id) FROM calendario_chiusure WHERE regola_festivita IN ('IT:new_year','IT:christmas','IT:st_stephen') OR (regola_festivita = 'IT:easter_monday' AND data_inizio = '2027-03-29')") === $legacyIds, 'the adopted rows keep their ids');
    $check($one("SELECT descrizione FROM calendario_chiusure WHERE regola_festivita = 'IT:new_year'") === 'Capodanno', 'an adopted row takes the native name');
    $check($one("SELECT regola_festivita IS NULL FROM calendario_chiusure WHERE descrizione = 'Inventario di primavera'") === '1', 'a manual closure on Easter Monday with its own name is not adopted');
    $check($manualSnapshot() === $manual, 'and the manual closures are still exactly as they were');
    $r = HolidayPresets::apply($db, 'IT', '', ['IT:new_year', 'IT:easter_monday', 'IT:christmas', 'IT:st_stephen'], $today);
    $check($r === ['added' => 0, 'removed' => 0, 'adopted' => 0], 'after adoption a reapply is a no-op');

    // 8. Yearly upkeep: a new year brings the year +2 of each movable holiday.
    $check(HolidayPresets::ensureYears($db, $today) === 0, 'ensureYears in the same year adds nothing');
    $check(HolidayPresets::ensureYears($db, '2027-01-02') === 1, 'in 2027 Easter Monday 2029 is added');
    $check($count("regola_festivita = 'IT:easter_monday' AND data_inizio = '2029-04-02'") === 1, 'Easter Monday 2029 is 2 April');
    $check(HolidayPresets::ensureYears($db, '2027-01-02') === 0, 'ensureYears is idempotent');
    $check($count("regola_festivita = 'IT:easter_monday' AND data_inizio = '2026-04-06'") === 1, 'past years stay as history');

    // 9. A rule with a first year: San Francesco only from 2026.
    HolidayPresets::apply($db, 'IT', '', ['IT:san_francesco'], '2025-06-01');
    $check($one("SELECT GROUP_CONCAT(data_inizio ORDER BY data_inizio) FROM calendario_chiusure WHERE regola_festivita = 'IT:san_francesco'") === '2026-10-04,2027-10-04', 'San Francesco dated from 2026 only');

    // 10. An observed rule of another country, and the region a nation needs.
    HolidayPresets::apply($db, 'US', '', ['US:independence_day'], $today);
    $check($one("SELECT GROUP_CONCAT(data_inizio ORDER BY data_inizio) FROM calendario_chiusure WHERE regola_festivita = 'US:independence_day'") === '2026-07-03,2027-07-05,2028-07-04', 'US Independence Day stored on the observed days');
    HolidayPresets::apply($db, 'GB', '', ['GB:new_year', 'GB:st_patrick'], $today);
    $check(HolidayPresets::selection($db)['region'] === 'ENG' && $count("regola_festivita = 'GB:st_patrick'") === 0, 'the United Kingdom falls back to England and Wales');

    $threw = false;
    try {
        HolidayPresets::apply($db, 'XX', '', [], $today);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    $check($threw, 'an unknown country is refused');

    // 11. A switched-off closure stays stored and closes nothing.
    LibraryCalendar::reset();
    $check(!LibraryCalendar::fromDatabase($db)->isOpen('2027-08-16'), 'the yearly manual closure closes 16 August');
    $db->query("UPDATE calendario_chiusure SET attiva = 0 WHERE descrizione = 'Patrono'");
    $check(LibraryCalendar::fromDatabase($db)->isOpen('2027-08-16'), 'switched off, 16 August is open');
    $check($count("descrizione = 'Patrono'") === 1, 'and the closure is still stored');
    HolidayPresets::apply($db, 'US', '', ['US:independence_day'], $today);
    LibraryCalendar::reset();
    $check(LibraryCalendar::dueDate($db, '2026-07-03') === '2026-07-04', 'a due date on a chosen holiday moves to the next open day');
    $check(LibraryCalendar::dueDate($db, '2026-09-07') === '2026-09-07', 'a due date on an unchosen holiday (Labor Day) stays');

    $db->close();
    echo "SUCCESS {$checks} holiday preset checks\n";
    $exit = 0;
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
exit($exit);
