<?php
declare(strict_types=1);

/**
 * LibraryCalendar::parseIcs() reads what calendar apps really export beyond
 * DTSTART/DTEND/RRULE/EXDATE:
 *
 *  A. DURATION instead of DTEND: DTSTART;VALUE=DATE:20261224 + DURATION:P14D
 *     is fourteen closed days, not one;
 *  B. STATUS:CANCELLED: a cancelled event closes nothing;
 *  C. RECURRENCE-ID: an event that overrides one occurrence of a recurring
 *     event replaces that occurrence instead of closing a second day.
 *
 * Pure function, no database: the time zone is passed in.
 *
 * Run: php tests/library-calendar-ics-edge-cases.unit.php
 */

use App\Support\LibraryCalendar;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$eq = static function (mixed $actual, mixed $expected, string $label) use (&$passed, &$failed): void {
    if ($actual === $expected) {
        $passed++;
        echo "OK   {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n       expected " . json_encode($expected) . "\n       actual   " . json_encode($actual) . "\n";
};

/** The events of a calendar holding these VEVENT bodies, as [start, end, yearly] triples. */
$parse = static function (string ...$events): array {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
    foreach ($events as $body) {
        $ics .= "BEGIN:VEVENT\r\n" . str_replace("\n", "\r\n", trim($body)) . "\r\nEND:VEVENT\r\n";
    }
    $ics .= "END:VCALENDAR\r\n";
    return array_map(
        static fn (array $e): array => [$e['start'], $e['end'], $e['yearly']],
        LibraryCalendar::parseIcs($ics, 1000, 'Europe/Rome')
    );
};

// ── A. DURATION ────────────────────────────────────────────────────────────
$eq($parse("DTSTART;VALUE=DATE:20261224\nDURATION:P14D\nSUMMARY:Christmas"), [['2026-12-24', '2027-01-06', false]],
    'A1 an all-day event of P14D closes fourteen days');
$eq($parse("DURATION:P14D\nSUMMARY:Christmas\nDTSTART;VALUE=DATE:20261224"), [['2026-12-24', '2027-01-06', false]],
    'A2 DURATION before DTSTART is read all the same');
$eq($parse("DTSTART;VALUE=DATE:20261224\nDURATION:P2W"), [['2026-12-24', '2027-01-06', false]],
    'A3 a duration in weeks');
$eq($parse("DTSTART;VALUE=DATE:20261224\nDURATION:P1D"), [['2026-12-24', '2026-12-24', false]],
    'A4 P1D is the one day of DTSTART');
$eq($parse("DTSTART:20261224T100000\nDURATION:PT2H"), [['2026-12-24', '2026-12-24', false]],
    'A5 two hours in the morning stay on the day');
$eq($parse("DTSTART:20261224T220000\nDURATION:PT2H"), [['2026-12-24', '2026-12-24', false]],
    'A6 ending at midnight does not close the next day, as a DTEND at midnight');
$eq($parse("DTSTART:20261224T090000\nDURATION:P1DT12H"), [['2026-12-24', '2026-12-25', false]],
    'A7 a day and a half reaches the next day');
// The night the clocks go back (25 October 2026 in Europe/Rome) lasts 25
// hours: 24 elapsed hours from 00:30 end at 23:30 of the same day.
$eq($parse("DTSTART:20261025T003000\nDURATION:PT24H"), [['2026-10-25', '2026-10-25', false]],
    'A8 the time part is elapsed time: PT24H across the end of DST stays on the day');
$eq($parse("DTSTART:20261025T003000\nDURATION:P1D"), [['2026-10-25', '2026-10-26', false]],
    'A9 while P1D is a calendar day, and reaches the same time of the next one');
$eq($parse("DTSTART:20261224T230000Z\nDURATION:PT1H"), [['2026-12-25', '2026-12-25', false]],
    'A8 the duration starts from the local day of a UTC DTSTART');
// 22:30 UTC on 28 March 2026 is 23:30 in Rome; a day later in UTC is 22:30
// UTC on the 29th, which after the clocks go forward is 00:30 on the 30th.
// Adding the day on Rome's clock instead would stop at 23:30 on the 29th.
$eq($parse("DTSTART:20260328T223000Z\nDURATION:P1D"), [['2026-03-28', '2026-03-30', false]],
    'A8b a UTC DTSTART adds its days in UTC, across the start of DST');
// 18:30 in New York on 7 March is 00:30 on the 8th in Rome. New York's
// clocks go forward on the 8th, so a day later there is 18:30 EDT, 23:30 in
// Rome: still the 8th. A day on Rome's clock would reach 00:30 on the 9th.
$eq($parse("DTSTART;TZID=America/New_York:20260307T183000\nDURATION:P1D"), [['2026-03-08', '2026-03-08', false]],
    'A8c a TZID DTSTART adds its days on its own clock (New York changes on 8 March)');
$eq($parse("DTSTART;VALUE=DATE:20261224\nDTEND;VALUE=DATE:20261226\nDURATION:P14D"), [['2026-12-24', '2026-12-25', false]],
    'A9 a DTEND, when present, is what counts');
foreach (['-P3D', 'P', '14D', 'PT', 'P1Y', 'garbage'] as $i => $invalid) {
    $eq($parse("DTSTART;VALUE=DATE:20261224\nDURATION:{$invalid}"), [['2026-12-24', '2026-12-24', false]],
        "A10.{$i} an unusable duration ({$invalid}) leaves the single day");
}
$eq($parse("DTSTART;VALUE=DATE:20260815\nDURATION:P3D\nRRULE:FREQ=YEARLY"), [['2026-08-15', '2026-08-17', true]],
    'A11 a yearly event keeps its duration');

$alarm = LibraryCalendar::parseIcs(
    "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20261224\nSUMMARY:Closed\n"
    . "BEGIN:VALARM\nACTION:EMAIL\nTRIGGER:-PT30M\nREPEAT:2\nDURATION:P2D\nSUMMARY:Reminder\nEND:VALARM\n"
    . "END:VEVENT\nEND:VCALENDAR\n",
    1000,
    'Europe/Rome'
);
$eq($alarm, [['start' => '2026-12-24', 'end' => '2026-12-24', 'description' => 'Closed', 'yearly' => false]],
    "A12 an alarm's DURATION and SUMMARY are not the event's");

// ── B. STATUS ──────────────────────────────────────────────────────────────
$eq($parse("DTSTART;VALUE=DATE:20261224\nSTATUS:CANCELLED\nSUMMARY:Called off"), [],
    'B1 a cancelled event is not a closure');
$eq($parse("STATUS:cancelled \nDTSTART;VALUE=DATE:20261224"), [],
    'B2 whatever its case and wherever it stands in the event');
$eq($parse("DTSTART;VALUE=DATE:20261224\nSTATUS:CONFIRMED", "DTSTART;VALUE=DATE:20261226\nSTATUS:TENTATIVE", "DTSTART;VALUE=DATE:20261228\nSTATUS:CANCELLED"),
    [['2026-12-24', '2026-12-24', false], ['2026-12-26', '2026-12-26', false]],
    'B3 confirmed and tentative events are still imported');

// ── C. RECURRENCE-ID ───────────────────────────────────────────────────────
$master = "UID:xmas@example\nDTSTART;VALUE=DATE:20261224\nRRULE:FREQ=YEARLY\nSUMMARY:Closed";
$eq($parse($master), [['2026-12-24', '2026-12-24', true]],
    'C0 control: the recurring event alone is one yearly closure');
$moved = "UID:xmas@example\nRECURRENCE-ID;VALUE=DATE:20261224\nDTSTART;VALUE=DATE:20261227\nSUMMARY:Closed (moved)";
$eq($parse($master, $moved), [['2026-12-27', '2026-12-27', false]],
    'C1 an occurrence moved to another day closes that day, not both');
$eq($parse($moved, $master), [['2026-12-27', '2026-12-27', false]],
    'C2 the same when the override comes first in the file');
$eq($parse($master, "UID:xmas@example\nRECURRENCE-ID;VALUE=DATE:20261224\nDTSTART;VALUE=DATE:20261224\nSUMMARY:Closed (renamed)"),
    [['2026-12-24', '2026-12-24', false]],
    'C3 an override that keeps the day does not import it twice');
$eq($parse($master, "UID:xmas@example\nRECURRENCE-ID;VALUE=DATE:20271224\nDTSTART;VALUE=DATE:20271227"),
    [['2026-12-24', '2026-12-24', false], ['2027-12-27', '2027-12-27', false]],
    'C4 a later occurrence moved: the first one once (a stored closure cannot skip a year), plus the moved day');
$eq($parse($master, "UID:xmas@example\nRECURRENCE-ID;VALUE=DATE:20261224\nDTSTART;VALUE=DATE:20261224\nSTATUS:CANCELLED"), [],
    'C5 an occurrence cancelled through its override closes nothing');
$eq($parse($master, "UID:other@example\nRECURRENCE-ID;VALUE=DATE:20261224\nDTSTART;VALUE=DATE:20261227"),
    [['2026-12-24', '2026-12-24', true], ['2026-12-27', '2026-12-27', false]],
    'C6 the override of another event leaves this one yearly');
$timedMaster = "UID:late@example\nDTSTART;TZID=Europe/Rome:20261224T090000\nDTEND;TZID=Europe/Rome:20261224T180000\nRRULE:FREQ=YEARLY";
$eq($parse($timedMaster, "UID:late@example\nRECURRENCE-ID;TZID=Europe/Rome:20261224T090000\nDTSTART;TZID=Europe/Rome:20261228T090000\nDTEND;TZID=Europe/Rome:20261228T180000"),
    [['2026-12-28', '2026-12-28', false]],
    'C7 a timed occurrence is matched by its date and time');
$eq($parse($moved), [['2026-12-27', '2026-12-27', false]],
    'C8 an override whose recurring event is not in the file is its own day');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
