<?php
declare(strict_types=1);

/**
 * FineCalculator + FineParameters (plan §7): pure arithmetic, no database.
 *
 *  - grace is a THRESHOLD (decision 5): up to graceDays late = 0, beyond it
 *    every late day counts from day one;
 *  - optional cap per loan;
 *  - an injected "is open day" callable removes closed days from the count;
 *  - an on-time (or early) return is never fined; fines off = 0 always;
 *  - amount parsing and formatting in cents.
 *
 * Run: php tests/fine-calculator.unit.php (exit code is the verdict)
 */

use App\Services\Circulation\PolicyResolver;
use App\Services\FineCalculator;
use App\Services\FineParameters;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$params = static fn (string $daily, int $grace = 0, string $cap = '', string $threshold = ''): FineParameters => FineParameters::fromSettings([
    'daily_fine' => $daily,
    'fine_grace_days' => (string) $grace,
    'fine_max_per_loan' => $cap,
    'debt_block_threshold' => $threshold,
]);

$calc = new FineCalculator();

echo "A. Days late\n";
$check($calc->daysLate('2026-10-10', '2026-10-10') === 0, 'returned on the due date: 0 days');
$check($calc->daysLate('2026-10-10', '2026-10-05') === 0, 'returned early: 0 days');
$check($calc->daysLate('2026-10-10', '2026-10-15') === 5, 'returned 5 days after: 5 days');
$check($calc->daysLate('2026-03-28', '2026-03-30') === 2, 'across the DST change: calendar days, not hours');
$check($calc->daysLate('2025-12-30', '2026-01-02') === 3, 'across the year boundary');
$check($calc->daysLate('not-a-date', '2026-01-02') === 0 && $calc->daysLate('2026-02-30', '2026-03-02') === 0, 'malformed dates count as 0');

echo "B. Fines disabled (zero config)\n";
$check(!FineParameters::fromSettings([])->finesEnabled(), 'no settings: fines disabled');
$check($calc->lateFineCents(FineParameters::fromSettings([]), '2026-01-01', '2026-12-31') === 0, 'no settings: a year late costs nothing');
$check($calc->lateFineCents($params('0'), '2026-01-01', '2026-02-01') === 0, 'daily fine 0: nothing');
$check(!FineParameters::fromSettings([])->debtBlockEnabled(), 'no settings: no debt block');

echo "C. Daily fine, grace as threshold\n";
$check($calc->lateFineCents($params('0.50'), '2026-10-10', '2026-10-15') === 250, '5 days × 0.50 = 2.50');
$check($calc->lateFineCents($params('0,50'), '2026-10-10', '2026-10-15') === 250, 'decimal comma accepted');
$check($calc->lateFineCents($params('1', 3), '2026-10-10', '2026-10-12') === 0, 'grace 3, 2 days late: no fine');
$check($calc->lateFineCents($params('1', 3), '2026-10-10', '2026-10-13') === 0, 'grace 3, exactly 3 days late: no fine');
$check($calc->lateFineCents($params('1', 3), '2026-10-10', '2026-10-14') === 400, 'grace 3, 4 days late: charged from day one (4.00, not 1.00)');
$check($calc->lateFineCents($params('1', 3), '2026-10-10', '2026-10-10') === 0, 'on-time return with grace: 0');

echo "D. Cap per loan\n";
$check($calc->lateFineCents($params('1', 0, '5'), '2026-10-01', '2026-10-04') === 300, 'below the cap: 3.00');
$check($calc->lateFineCents($params('1', 0, '5'), '2026-10-01', '2026-10-31') === 500, '30 days with cap 5: 5.00');
$check($calc->lateFineCents($params('1', 0, '0'), '2026-10-01', '2026-10-31') === 3000, 'cap 0 means no cap: 30.00');
$check($params('1', 0, '0')->maxFinePerLoanCents === null && $params('1', 0, '')->maxFinePerLoanCents === null, 'empty or 0 cap → null');

echo "E. Closed days (injected calendar)\n";
// Sundays closed; 2026-10-11 and 2026-10-18 are Sundays.
$sundaysClosed = new FineCalculator(static fn (string $d): bool => (int) (new DateTimeImmutable($d))->format('N') !== 7);
$check($sundaysClosed->daysLate('2026-10-10', '2026-10-17') === 6, '7 calendar days incl. one Sunday: 6 open days');
$check($sundaysClosed->lateFineCents($params('1'), '2026-10-10', '2026-10-19') === 700, '9 calendar days, 2 Sundays: 7.00');
$check($sundaysClosed->lateFineCents($params('1', 1), '2026-10-10', '2026-10-12') === 0, 'Sat due, returned Mon: 1 open day, within grace 1');
$allClosed = new FineCalculator(static fn (string $d): bool => false);
$check($allClosed->lateFineCents($params('1'), '2026-10-01', '2026-10-20') === 0, 'library closed the whole time: no fine');
$check($sundaysClosed->daysLate('2026-10-10', '2026-10-10') === 0, 'calendar never consulted for an on-time return');
// A closure of branch 7 only (2026-10-12..13): counted for other branches and the whole library.
$branch7Closed = new FineCalculator(static fn (string $d, ?int $branch = null): bool => !($branch === 7 && $d >= '2026-10-12' && $d <= '2026-10-13'));
$check($branch7Closed->daysLate('2026-10-10', '2026-10-15', 7) === 3 && $branch7Closed->daysLate('2026-10-10', '2026-10-15', 3) === 5 && $branch7Closed->daysLate('2026-10-10', '2026-10-15') === 5, 'branch closure: skipped only for the copy of that branch');
$check($branch7Closed->lateFineCents($params('1'), '2026-10-10', '2026-10-15', 7) === 300, 'branch closure lowers the fine of that branch only');
// The real calendar plugged in the same way PatronLedger::forDb() does.
$calendar = new \App\Support\LibraryCalendar([0], [['start' => '2026-10-13', 'end' => '2026-10-14', 'yearly' => false, 'sede' => null], ['start' => '2020-10-16', 'end' => '2020-10-16', 'yearly' => true, 'sede' => null]]);
$real = new FineCalculator(static fn (string $d, ?int $b = null): bool => $calendar->isOpen($d, $b));
$check($real->daysLate('2026-10-10', '2026-10-20') === 5 && $real->daysLate('2026-10-10', '2026-10-20') === $calendar->openDaysBetween('2026-10-10', '2026-10-20'), 'LibraryCalendar: 10 days minus Sundays 11 and 18, closure 13-14 and yearly 16 = 5 open days, as openDaysBetween()');

echo "E2. Bounds: a decade overdue, with and without a calendar\n";
$everyDayOpen = new FineCalculator(static fn (string $d, ?int $b = null): bool => true);
$check($calc->daysLate('2015-01-01', '2026-01-01') === 3660, '11 years late without a calendar: the walk stops at MAX_DAYS (3660)');
$check($everyDayOpen->daysLate('2015-01-01', '2026-01-01') === 3660, '...and so does the calendar branch (every day open)');
$check($everyDayOpen->daysLate('2015-01-01', '2026-01-01') === $calc->daysLate('2015-01-01', '2026-01-01'), 'the two branches count the same days');
$check($sundaysClosed->daysLate('2015-01-01', '2026-01-01') < 3660 && $sundaysClosed->daysLate('2015-01-01', '2026-01-01') > 3000, 'with closed days the bounded walk still skips them');
$check($calc->lateFineCents($params('99999999.99'), '2015-01-01', '2026-01-01') === \App\Services\PatronLedger::MAX_AMOUNT_CENTS, 'an absurd daily fine over a decade is clamped at PatronLedger::MAX_AMOUNT_CENTS (DECIMAL(10,2))');
$check($calc->lateFineCents($params('99999999.99', 0, '5'), '2015-01-01', '2026-01-01') === 500, 'the cap per loan still applies below the bound');
$check($everyDayOpen->lateFineCents($params('99999999.99'), '2015-01-01', '2026-01-01') === $calc->lateFineCents($params('99999999.99'), '2015-01-01', '2026-01-01'), 'same clamped fine on both branches');

echo "F. Amounts\n";
$check(FineParameters::toCents('2') === 200 && FineParameters::toCents('2.5') === 250 && FineParameters::toCents('2,05') === 205, 'toCents parses 2 / 2.5 / 2,05');
$check(FineParameters::toCents('') === null && FineParameters::toCents('-1') === null && FineParameters::toCents('1.234') === null && FineParameters::toCents('abc') === null, 'toCents rejects empty, negative, 3 decimals, text');
$check(FineParameters::centsToDecimal(1250) === '12.50' && FineParameters::centsToDecimal(-5) === '-0.05' && FineParameters::centsToDecimal(0) === '0.00', 'centsToDecimal');
$check(FineParameters::decimalToCents('-12.50') === -1250 && FineParameters::decimalToCents('0.10') === 10 && FineParameters::decimalToCents(null) === 0, 'decimalToCents (no float drift on 0.10)');
$check(FineParameters::format(1250, 'it_IT', 'EUR') === '12,50 €' && FineParameters::format(1250, 'en_US', 'USD') === 'USD 12.50', 'format per locale and currency');
$check($params('1', 0, '', '10')->debtBlockThresholdCents === 1000, 'debt threshold in cents');
$check(FineParameters::fromSettings(['fine_grace_days' => '-4'])->graceDays === 0, 'negative grace clamps to 0');
$check(FineParameters::MAX_SETTING_CENTS === 999999 && FineParameters::format(FineParameters::MAX_SETTING_CENTS, 'it_IT', 'EUR') === '9.999,99 €', 'fine settings stop at 9999.99');
$check(!FineParameters::exceedsSettingMax('9999.99') && !FineParameters::exceedsSettingMax('9999,99') && !FineParameters::exceedsSettingMax('0.50'), 'amounts up to the maximum are accepted');
$check(FineParameters::exceedsSettingMax('10000') && FineParameters::exceedsSettingMax('99999999.99'), '10000 and above are over the maximum');
$check(!FineParameters::exceedsSettingMax('') && !FineParameters::exceedsSettingMax('abc') && !FineParameters::exceedsSettingMax('-5'), 'an empty or malformed amount is not "too large" (it is refused as malformed elsewhere)');

// FineParameters over CirculationPolicy layers (rule → patron type → globals).
$globals = ['duration_days' => 30, 'max_request_days' => 90, 'max_renewals' => 3, 'max_active_loans' => 0,
    'daily_fine' => '0.50', 'grace_days' => 2, 'max_fine_per_loan' => null, 'debt_block_threshold' => '10.00'];
$resolve = static fn (array $layers, ?array $type = null): FineParameters => FineParameters::fromPolicy(
    PolicyResolver::resolve($layers, $globals, PolicyResolver::resolveUser($type, $globals), 'dvd'),
    'EUR'
);
$g = $resolve([]);
$check($g->dailyFineCents === 50 && $g->graceDays === 2 && $g->maxFinePerLoanCents === null && $g->debtBlockThresholdCents === 1000, 'globals only: 0.50/day, 2 grace days, no cap, block over 10');
$t = $resolve([null, null, null, ['daily_fine' => '1.00', 'grace_days' => 0, 'max_fine_per_loan' => '4.00']], ['debt_block_threshold' => '3.00']);
$check($t->dailyFineCents === 100 && $t->graceDays === 0 && $t->maxFinePerLoanCents === 400 && $t->debtBlockThresholdCents === 300, 'patron type layer overrides every fine field and the threshold');
$r = $resolve([['daily_fine' => '2.00'], null, null, ['daily_fine' => '1.00', 'grace_days' => 5]]);
$check($r->dailyFineCents === 200 && $r->graceDays === 5, 'a material rule overrides the type daily fine, the type grace still applies');
$z = $resolve([null, null, null, ['daily_fine' => '0.00']], ['debt_block_threshold' => '0.00']);
$check(!$z->finesEnabled() && !$z->debtBlockEnabled(), 'type daily fine 0 and threshold 0: fines off, no block, whatever the globals');
$i = $resolve([null, null, null, ['daily_fine' => null, 'grace_days' => null]], ['debt_block_threshold' => null]);
$check($i->dailyFineCents === 50 && $i->graceDays === 2 && $i->debtBlockThresholdCents === 1000, 'type with every field empty inherits the globals');
$check(FineParameters::fromPolicy(PolicyResolver::resolve([], ['duration_days' => 30], PolicyResolver::resolveUser(null, [])))->finesEnabled() === false, 'no fine anywhere: disabled');

echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
