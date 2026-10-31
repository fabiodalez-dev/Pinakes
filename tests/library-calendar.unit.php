<?php
declare(strict_types=1);

/**
 * LibraryCalendar: closed weekdays, closure ranges, yearly recurrence,
 * branch-specific closures, Easter over several years, DST, ICS import and
 * the two loan funnels' pure arithmetic.
 *
 * Pure (no database). Run: php tests/library-calendar.unit.php
 */

use App\Support\LibraryCalendar;

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if ($ok) {
        echo "OK   {$label}\n";
    } else {
        $failures++;
        echo "FAIL {$label}\n";
    }
};
$eq = static function ($actual, $expected, string $label) use ($check): void {
    $check($actual === $expected, $label . ($actual === $expected ? '' : ' (got ' . var_export($actual, true) . ', expected ' . var_export($expected, true) . ')'));
};

// ── Zero config: every day open, every funnel is plain arithmetic ─────────
$empty = new LibraryCalendar();
$eq($empty->hasRules(), false, 'no rules configured');
$eq($empty->isOpen('2026-12-25'), true, 'without rules Christmas is open');
$eq($empty->nextOpenDay('2026-11-01'), '2026-11-01', 'without rules nextOpenDay is identity');
$eq($empty->addOpenDays('2026-10-30', 3), '2026-11-02', 'without rules addOpenDays is +N calendar days');
$eq($empty->closedDatesBetween('2026-01-01', '2026-12-31'), [], 'without rules no closed dates');
$eq($empty->openDaysBetween('2026-10-01', '2026-10-31'), 30, 'without rules openDaysBetween is the day difference');

// ── Weekly closed days ────────────────────────────────────────────────────
$sundays = new LibraryCalendar([0]);
$eq($sundays->isOpen('2026-11-01'), false, 'Sunday 2026-11-01 closed with closed_weekdays=0');
$eq($sundays->isOpen('2026-11-02'), true, 'Monday open');
$eq($sundays->nextOpenDay('2026-11-01'), '2026-11-02', 'Sunday shifts to Monday');
$eq($sundays->shiftForward('2026-10-31'), '2026-10-31', 'an open Saturday does not move');
$eq($sundays->previousOpenDay('2026-11-01'), '2026-10-31', 'previousOpenDay of Sunday is Saturday');
$sunMon = new LibraryCalendar([0, 1]);
$eq($sunMon->nextOpenDay('2026-11-01'), '2026-11-03', 'Sunday+Monday closed: Sunday shifts to Tuesday');
$eq($sunMon->closedWeekdays(), [0, 1], 'closedWeekdays returns the sorted list');
$eq(LibraryCalendar::parseWeekdays('1, 0,7,x,1,6'), [0, 1, 6], 'parseWeekdays drops invalid and duplicate values');
$all = new LibraryCalendar([0, 1, 2, 3, 4, 5, 6]);
$eq($all->hasRules(), false, 'seven closed weekdays are ignored (no day to move to)');

// ── Closure ranges ────────────────────────────────────────────────────────
$xmas = new LibraryCalendar([], [['data_inizio' => '2026-12-24', 'data_fine' => '2026-12-26', 'ricorrente_annuale' => 0, 'sede_id' => null]]);
$eq($xmas->isOpen('2026-12-23'), true, 'day before the range open');
$eq($xmas->isOpen('2026-12-24') || $xmas->isOpen('2026-12-25') || $xmas->isOpen('2026-12-26'), false, 'every day of the range closed');
$eq($xmas->nextOpenDay('2026-12-25'), '2026-12-27', 'Dec 25 inside 24-26 shifts to Dec 27');
$eq($xmas->isOpen('2027-12-25'), true, 'a non-recurring closure does not repeat next year');
$eq($xmas->closedDatesBetween('2026-12-20', '2026-12-31'), ['2026-12-24', '2026-12-25', '2026-12-26'], 'closedDatesBetween lists the range');
$eq($xmas->openDaysBetween('2026-12-20', '2026-12-31'), 8, 'openDaysBetween skips the 3 closed days (11 - 3)');
$swapped = new LibraryCalendar([], [['start' => '2026-08-20', 'end' => '2026-08-10']]);
$eq($swapped->isOpen('2026-08-15'), false, 'an inverted range is normalised');

// ── Yearly recurrence ─────────────────────────────────────────────────────
$yearly = new LibraryCalendar([], [
    ['data_inizio' => '2020-08-10', 'data_fine' => '2020-08-20', 'ricorrente_annuale' => 1],
    ['data_inizio' => '2025-12-31', 'data_fine' => '2026-01-02', 'ricorrente_annuale' => '1'],
]);
foreach ([2020, 2026, 2031] as $y) {
    $eq($yearly->isOpen("{$y}-08-15"), false, "yearly Aug 10-20 closes Aug 15 in {$y}");
    $eq($yearly->isOpen("{$y}-08-21"), true, "yearly Aug 10-20 leaves Aug 21 open in {$y}");
}
$eq($yearly->isOpen('2019-08-15'), false, 'a yearly closure applies to earlier years too');
$eq($yearly->isOpen('2030-12-31') || $yearly->isOpen('2031-01-01') || $yearly->isOpen('2031-01-02'), false, 'a yearly span crossing New Year wraps');
$eq($yearly->isOpen('2031-01-03'), true, 'the day after a wrapped yearly span is open');
$eq($yearly->nextOpenDay('2030-12-31'), '2031-01-03', 'shift across a wrapped yearly span');
$leap = new LibraryCalendar([], [['start' => '2024-02-29', 'end' => '2024-02-29', 'yearly' => true]]);
$eq($leap->isOpen('2028-02-29'), false, 'a yearly Feb 29 closes Feb 29 in leap years');
$eq($leap->isOpen('2027-03-01'), true, 'and does not close Mar 1 in other years');

// A yearly span of a year or more cannot repeat: it is applied once, as the
// interval stored, and never closes a day outside it.
$eq(LibraryCalendar::spanDays('2026-01-01', '2026-01-01'), 0, 'spanDays: same day is 0');
$eq(LibraryCalendar::spanDays('2026-01-01', '2026-12-31'), 364, 'spanDays: a year less a day is 364');
$eq(LibraryCalendar::spanDays('2026-01-01', 'nope'), -1, 'spanDays: not a date is -1');
$eq(LibraryCalendar::MAX_YEARLY_SPAN_DAYS, 363, 'the yearly limit is a year less two days (a 364-day span leaves only Feb 29 open)');
$decade = new LibraryCalendar([], [['start' => '2026-01-01', 'end' => '2035-12-31', 'yearly' => true]]);
$eq($decade->isOpen('2030-06-15'), false, 'a ten-year yearly row closes a day inside its interval');
$eq($decade->isOpen('2040-06-15'), true, 'and leaves an arbitrary later date open (it does not close every day)');
$eq($decade->isOpen('2025-06-15'), true, 'nor an earlier one');
$twoYears = new LibraryCalendar([], [['start' => '2020-03-01', 'end' => '2022-02-28', 'yearly' => true]]);
$eq($twoYears->isOpen('2021-07-01'), false, 'a two-year yearly row closes its own days');
$eq($twoYears->isOpen('2026-07-01'), true, 'and not the same day years later');
$longest = new LibraryCalendar([], [['start' => '2026-06-01', 'end' => '2027-05-30', 'yearly' => true]]);
$eq($longest->isOpen('2030-12-25'), false, 'a 363-day yearly span (Jun 1 - May 30) still recurs and wraps');
$eq($longest->isOpen('2030-05-30'), false, 'its last month-day is closed');
$eq($longest->isOpen('2030-05-31'), true, 'and May 31 stays open every year');
$eq($longest->nextOpenDay('2030-12-25'), '2031-05-31', 'so a due date inside it reaches an open day');
$eq($longest->hasRules(), true, 'it is a rule');
$fullYear = new LibraryCalendar([], [['start' => '2026-06-01', 'end' => '2027-05-31', 'yearly' => true]]);
$eq($fullYear->isOpen('2030-12-25'), true, 'a 364-day yearly span (Jun 1 - May 31) would close every day: it does not recur');
$eq($fullYear->isOpen('2026-12-25'), false, 'but closes its own interval');
$leapOnly = new LibraryCalendar([], [['start' => '2024-03-01', 'end' => '2025-02-28', 'yearly' => true]]);
$eq($leapOnly->isOpen('2026-07-01'), true, 'a Mar 1 - Feb 28 yearly span (only Feb 29 left) does not recur either');

// ── A one-off closure longer than the scan bound is stepped over whole ───
// A once closure may span ten years (MAX_ONCE_SPAN_DAYS) while a scan walks
// at most ~3 years day by day: the scans jump to the end of the closure.
$fiveYears = new LibraryCalendar([], [['start' => '2026-01-01', 'end' => '2030-12-31']]);
$eq(LibraryCalendar::spanDays('2026-01-01', '2030-12-31') <= LibraryCalendar::MAX_ONCE_SPAN_DAYS, true, 'a five-year once closure is admissible');
$eq($fiveYears->nextOpenDay('2026-06-15'), '2031-01-01', 'nextOpenDay inside a five-year closure is the day after it ends');
$eq($fiveYears->nextOpenDay('2026-01-01'), '2031-01-01', 'from its first day too');
$eq($fiveYears->previousOpenDay('2030-06-15'), '2025-12-31', 'previousOpenDay inside it is the day before it starts');
$eq($fiveYears->previousOpenDay('2030-12-31'), '2025-12-31', 'from its last day too');
$eq($fiveYears->addOpenDays('2026-06-15', 3), '2031-01-03', 'addOpenDays from inside it counts open days after it');
$eq($fiveYears->addOpenDays('2025-12-30', 3), '2031-01-02', 'addOpenDays across it: Dec 31, then Jan 1 and 2 of 2031');
$eq($fiveYears->closedDatesBetween('2027-01-01', '2027-01-31'), array_map(static fn (int $d): string => sprintf('2027-01-%02d', $d), range(1, 31)), 'closedDatesBetween inside it lists every day of the month asked');
$cut = $fiveYears->closedDatesBetween('2026-01-01', '2030-12-31');
$eq($cut[0] === '2026-01-01' && count($cut) > 1000 && count($cut) < 1826 && LibraryCalendar::shift($cut[0], count($cut) - 1) === end($cut), true, 'closedDatesBetween over the whole closure is cut at the scan bound, consecutive from the start');
$eq($fiveYears->openDaysBetween('2025-12-31', '2031-01-02'), 2, 'openDaysBetween across it counts only the two days after it');
// Around the jump: closed weekdays and a branch closure right after it still apply.
$afterJump = new LibraryCalendar([3], [['start' => '2026-01-01', 'end' => '2030-12-31'], ['start' => '2031-01-02', 'end' => '2031-01-02', 'sede_id' => 2]]);
$eq($afterJump->nextOpenDay('2028-02-29'), '2031-01-02', '2031-01-01 is a Wednesday (closed weekday): the jump lands on the next open day');
$eq($afterJump->nextOpenDay('2028-02-29', 2), '2031-01-03', 'and branch 2, closed on Jan 2 as well, opens on Jan 3');
$eq($afterJump->previousOpenDay('2028-02-29'), '2025-12-30', '2025-12-31 is a Wednesday: previousOpenDay steps back once more');
$twoCut = new LibraryCalendar([], [['start' => '2026-01-01', 'end' => '2027-12-31'], ['start' => '2027-06-01', 'end' => '2030-12-31']]);
$eq($twoCut->nextOpenDay('2026-06-15'), '2031-01-01', 'overlapping once closures are stepped over one after the other');
$eq($twoCut->previousOpenDay('2030-06-15'), '2025-12-31', 'backwards too');
$branchOnly = new LibraryCalendar([], [['start' => '2026-01-01', 'end' => '2030-12-31', 'sede_id' => 5]]);
$eq($branchOnly->nextOpenDay('2026-06-15'), '2026-06-15', 'a branch-only closure does not move the library as a whole');
$eq($branchOnly->nextOpenDay('2026-06-15', 5), '2031-01-01', 'but moves its branch past it');
// Every day closed (two yearly closures covering the year): the scans still
// end after the bound and fall back as before, quickly.
$allYear = new LibraryCalendar([], [['start' => '2026-01-01', 'end' => '2026-06-30', 'yearly' => true], ['start' => '2026-07-01', 'end' => '2026-12-31', 'yearly' => true]]);
$started = microtime(true);
$eq($allYear->nextOpenDay('2026-06-15'), '2026-06-15', 'all days closed: nextOpenDay returns its input after the bound');
$eq($allYear->previousOpenDay('2026-06-15'), '2026-06-15', 'all days closed: previousOpenDay returns its input');
$eq($allYear->addOpenDays('2026-06-15', 3), '2026-06-18', 'all days closed: addOpenDays falls back to +N calendar days');
$eq(microtime(true) - $started < 2.0, true, 'the all-closed fallbacks finish quickly');

// ── Branch-specific vs all branches ───────────────────────────────────────
$branches = new LibraryCalendar([], [
    ['start' => '2026-11-10', 'end' => '2026-11-10', 'sede_id' => 2],
    ['start' => '2026-11-11', 'end' => '2026-11-11', 'sede_id' => null],
]);
$eq($branches->isOpen('2026-11-10', 2), false, 'branch 2 closed on its own closure');
$eq($branches->isOpen('2026-11-10', 3), true, 'branch 3 open on branch 2 closure');
$eq($branches->isOpen('2026-11-10'), true, 'whole library open on a branch-only closure');
$eq($branches->isOpen('2026-11-11', 2) || $branches->isOpen('2026-11-11', 3) || $branches->isOpen('2026-11-11'), false, 'an all-branch closure closes every branch');
$eq($branches->nextOpenDay('2026-11-10', 2), '2026-11-12', 'branch 2 shifts over both closures');
$eq($branches->closedDatesBetween('2026-11-09', '2026-11-12', 2), ['2026-11-10', '2026-11-11'], 'closedDatesBetween honours the branch');

// ── Easter over several years ─────────────────────────────────────────────
$known = ['2000' => '2000-04-23', '2008' => '2008-03-23', '2019' => '2019-04-21', '2024' => '2024-03-31', '2025' => '2025-04-20', '2026' => '2026-04-05', '2027' => '2027-03-28', '2038' => '2038-04-25', '2285' => '2285-03-22'];
foreach ($known as $year => $date) {
    $eq(LibraryCalendar::easterSunday((int) $year), $date, "Easter {$year}");
}
$h2026 = LibraryCalendar::italianHolidays(2026);
$dates2026 = array_column($h2026, 'start');
$eq(count($h2026), 12, 'Italian preset has 12 holidays');
$eq(in_array('2026-04-06', $dates2026, true), true, 'Easter Monday 2026 is Apr 6');
$eq(in_array('2026-06-02', $dates2026, true), true, 'Festa della Repubblica included');
$easterRows = array_values(array_filter($h2026, static fn (array $r): bool => !$r['yearly']));
$eq(count($easterRows), 2, 'only Easter and Easter Monday are dated for the year');
$eq($dates2026, array_values(array_unique($dates2026)), 'preset dates are distinct');
$sorted = $dates2026;
sort($sorted);
$eq($dates2026, $sorted, 'preset is sorted by date');

// ── DST change (Europe/Rome: 2026-03-29 and 2026-10-25) ───────────────────
$prevTz = date_default_timezone_get();
foreach (['Europe/Rome', 'America/New_York', 'Pacific/Auckland', 'UTC'] as $tz) {
    date_default_timezone_set($tz);
    $eq(LibraryCalendar::shift('2026-03-28', 1), '2026-03-29', "DST spring: +1 day stays a day ({$tz})");
    $eq(LibraryCalendar::shift('2026-03-29', 1), '2026-03-30', "DST spring day itself ({$tz})");
    $eq(LibraryCalendar::shift('2026-10-24', 2), '2026-10-26', "DST autumn: +2 days ({$tz})");
    $eq($sundays->isOpen('2026-10-25'), false, "the DST Sunday is still a Sunday ({$tz})");
    $eq($sundays->nextOpenDay('2026-03-29'), '2026-03-30', "DST Sunday shifts to Monday ({$tz})");
    $eq($sundays->addOpenDays('2026-10-23', 3), '2026-10-27', "3 open days from Fri across DST Sunday ({$tz})");
}
date_default_timezone_set($prevTz);

// ── addOpenDays (pickup deadline arithmetic) ──────────────────────────────
$eq($sundays->addOpenDays('2026-10-30', 3), '2026-11-03', 'Fri + 3 open days with Sundays closed = Tue');
$eq($sundays->addOpenDays('2026-10-30', 0), '2026-10-30', '0 open days returns the start');
$bridge = new LibraryCalendar([0], [['start' => '2026-12-24', 'end' => '2026-12-26']]);
$eq($bridge->addOpenDays('2026-12-23', 3), '2026-12-30', 'pickup over Christmas and a Sunday counts only open days');

// ── Integration-style: 14-day loans ───────────────────────────────────────
// 2026-10-18 + 14 = 2026-11-01 (Sunday) → Monday.
$eq($sundays->nextOpenDay(LibraryCalendar::shift('2026-10-18', 14)), '2026-11-02', '14-day loan ending Sunday moves to Monday');
// 2026-12-11 + 14 = 2026-12-25, closure 24-26 → 27.
$eq($xmas->nextOpenDay(LibraryCalendar::shift('2026-12-11', 14)), '2026-12-27', '14-day loan ending Dec 25 with 24-26 closed moves to Dec 27');

// ── ICS ───────────────────────────────────────────────────────────────────
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261224\r\nDTEND;VALUE=DATE:20261227\r\nSUMMARY:Chiusura\r\n  natalizia\\, sede\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260815\r\nRRULE:FREQ=YEARLY;BYMONTH=8\r\nSUMMARY:Ferragosto\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nDTSTART:20261102T090000Z\r\nDTEND:20261102T120000Z\r\nSUMMARY:Inventario\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nSUMMARY:no start\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$parsed = LibraryCalendar::parseIcs($ics);
$eq(count($parsed), 3, 'ICS: three events with a DTSTART');
$eq($parsed[0], ['start' => '2026-12-24', 'end' => '2026-12-26', 'description' => 'Chiusura natalizia, sede', 'yearly' => false], 'ICS: all-day DTEND is exclusive, folded summary unescaped');
$eq($parsed[1]['yearly'] && $parsed[1]['start'] === '2026-08-15' && $parsed[1]['end'] === '2026-08-15', true, 'ICS: RRULE FREQ=YEARLY recurs, no DTEND = one day');
$eq([$parsed[2]['start'], $parsed[2]['end']], ['2026-11-02', '2026-11-02'], 'ICS: a timed event closes its day');
$eq(LibraryCalendar::parseIcs('not a calendar'), [], 'ICS: garbage yields nothing');

// A UTC DATE-TIME is read in the app timezone before its day is taken; a
// floating DATE-TIME and a DATE are taken as written.
$utcIcs = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20260410T230000Z\nDTEND:20260411T000000Z\nSUMMARY:utc\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20260410T230000\nSUMMARY:floating\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260410\nSUMMARY:date\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20260411T020000Z\nSUMMARY:early\nEND:VEVENT\nEND:VCALENDAR\n";
$rome = LibraryCalendar::parseIcs($utcIcs, 1000, 'Europe/Rome');
$eq([$rome[0]['start'], $rome[0]['end']], ['2026-04-11', '2026-04-11'], 'ICS: 20260410T230000Z is Apr 11 in Europe/Rome');
$eq([$rome[1]['start'], $rome[2]['start']], ['2026-04-10', '2026-04-10'], 'ICS: a floating DATE-TIME and a DATE keep their day');
$eq($rome[3]['start'], '2026-04-11', 'ICS: 20260411T020000Z stays Apr 11 in Europe/Rome');
$newYork = LibraryCalendar::parseIcs($utcIcs, 1000, 'America/New_York');
$eq($newYork[0]['start'], '2026-04-10', 'ICS: 20260410T230000Z is still Apr 10 in New York');
$eq($newYork[3]['start'], '2026-04-10', 'ICS: 20260411T020000Z is Apr 10 in New York');
$eq(LibraryCalendar::parseIcs($utcIcs, 1000, 'UTC')[0]['start'], '2026-04-10', 'ICS: in UTC the day is as written');

// A ten-year yearly event is parsed as such; the import refuses it with the
// form's limits (E2E: closures-calendar-admin.spec.js, ICS 8).
$tenYears = LibraryCalendar::parseIcs("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20360101\nRRULE:FREQ=YEARLY\nSUMMARY:decade\nEND:VEVENT\nEND:VCALENDAR\n");
$eq([$tenYears[0]['start'], $tenYears[0]['end'], $tenYears[0]['yearly']], ['2026-01-01', '2035-12-31', true], 'ICS: a ten-year yearly event is parsed as stored');
$eq(LibraryCalendar::spanDays($tenYears[0]['start'], $tenYears[0]['end']) > LibraryCalendar::MAX_YEARLY_SPAN_DAYS, true, 'ICS: and exceeds the yearly limit the import applies');

// DTEND is exclusive for timed events too: one ending at local midnight does
// not close the next day; a zero-length event at midnight keeps its day.
$midnight = LibraryCalendar::parseIcs("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261224T100000\nDTEND:20261225T000000\nSUMMARY:m\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20261224T000000\nDTEND:20261224T000000\nSUMMARY:zero\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20261224T090000Z\nDTEND:20261225T230000Z\nSUMMARY:utc midnight\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20261224T100000\nDTEND:20261225T000100\nSUMMARY:past midnight\nEND:VEVENT\nEND:VCALENDAR\n", 1000, 'Europe/Rome');
$eq([$midnight[0]['start'], $midnight[0]['end']], ['2026-12-24', '2026-12-24'], 'ICS: a timed DTEND at midnight is exclusive');
$eq([$midnight[1]['start'], $midnight[1]['end']], ['2026-12-24', '2026-12-24'], 'ICS: a zero-length event at midnight keeps its day');
$eq([$midnight[2]['start'], $midnight[2]['end']], ['2026-12-24', '2026-12-25'], 'ICS: a UTC DTEND that is local midnight (Dec 26 00:00 Rome) ends on Dec 25');
$eq([$midnight[3]['start'], $midnight[3]['end']], ['2026-12-24', '2026-12-25'], 'ICS: a DTEND one minute past midnight closes that day');

// TZID: the time is read in that zone, then in the app timezone.
$tzid = LibraryCalendar::parseIcs("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;TZID=America/New_York:20260410T230000\nSUMMARY:ny\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART;TZID=\"Europe/Rome\":20260410T230000\nSUMMARY:quoted\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART;TZID=Not/AZone:20260410T230000\nSUMMARY:unknown\nEND:VEVENT\nEND:VCALENDAR\n", 1000, 'Europe/Rome');
$eq($tzid[0]['start'], '2026-04-11', 'ICS: 23:00 in New York is Apr 11 in Europe/Rome');
$eq($tzid[1]['start'], '2026-04-10', 'ICS: a quoted TZID equal to the app timezone keeps the day');
$eq($tzid[2]['start'], '2026-04-10', 'ICS: an unknown TZID is read as floating');

// Only a plain yearly rule recurs; bounded, sparser or positional rules are
// imported as their first occurrence.
$rule = static fn (string $rrule): bool => LibraryCalendar::parseIcs("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20260601\nRRULE:{$rrule}\nEND:VEVENT\nEND:VCALENDAR\n")[0]['yearly'];
$eq($rule('FREQ=YEARLY'), true, 'RRULE: FREQ=YEARLY recurs');
$eq($rule('FREQ=YEARLY;INTERVAL=1;BYMONTH=6;BYMONTHDAY=1'), true, 'RRULE: restating DTSTART still recurs');
$eq($rule('FREQ=YEARLY;COUNT=3'), false, 'RRULE: COUNT is a first occurrence only');
$eq($rule('FREQ=YEARLY;UNTIL=20280601'), false, 'RRULE: UNTIL is a first occurrence only');
$eq($rule('FREQ=YEARLY;BYMONTH=6;BYDAY=1MO'), false, 'RRULE: a positional weekday is a first occurrence only');
$eq($rule('FREQ=YEARLY;BYDAY=MO;BYSETPOS=1'), false, 'RRULE: BYSETPOS is a first occurrence only');
$eq($rule('FREQ=YEARLY;INTERVAL=2'), false, 'RRULE: every other year is a first occurrence only');
$eq($rule('FREQ=MONTHLY'), false, 'RRULE: a monthly rule is a first occurrence only');
$eq(count(LibraryCalendar::parseIcs(str_repeat("BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nEND:VEVENT\n", 50), 10)), 10, 'ICS: the event cap holds');

echo ($failures === 0 ? 'SUCCESS' : 'FAILURE') . " {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
