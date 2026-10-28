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

echo "F. Amounts\n";
$check(FineParameters::toCents('2') === 200 && FineParameters::toCents('2.5') === 250 && FineParameters::toCents('2,05') === 205, 'toCents parses 2 / 2.5 / 2,05');
$check(FineParameters::toCents('') === null && FineParameters::toCents('-1') === null && FineParameters::toCents('1.234') === null && FineParameters::toCents('abc') === null, 'toCents rejects empty, negative, 3 decimals, text');
$check(FineParameters::centsToDecimal(1250) === '12.50' && FineParameters::centsToDecimal(-5) === '-0.05' && FineParameters::centsToDecimal(0) === '0.00', 'centsToDecimal');
$check(FineParameters::decimalToCents('-12.50') === -1250 && FineParameters::decimalToCents('0.10') === 10 && FineParameters::decimalToCents(null) === 0, 'decimalToCents (no float drift on 0.10)');
$check(FineParameters::format(1250, 'it_IT', 'EUR') === '12,50 €' && FineParameters::format(1250, 'en_US', 'USD') === 'USD 12.50', 'format per locale and currency');
$check($params('1', 0, '', '10')->debtBlockThresholdCents === 1000, 'debt threshold in cents');
$check(FineParameters::fromSettings(['fine_grace_days' => '-4'])->graceDays === 0, 'negative grace clamps to 0');

echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
