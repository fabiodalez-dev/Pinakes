<?php
declare(strict_types=1);

/**
 * GET /me/loans tells the reader the last day to collect a ready loan
 * (Mobile API 1.12.0), and it is the day the staff desk shows: the stored
 * pickup day moved to the next open day of the copy branch. Runs on the
 * isolated schema of settings-pr-independent-25.
 *
 * Run: php tests/mobile-pickup-deadline.unit.php
 */
use App\Plugins\MobileApi\Controllers\ActionsController;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

/** A branch holiday written straight to the table, so no save reconciles the stored pickup day. */
function pickupHoliday(SettingsReview25 $t, string $day, int $branch): void
{
    $stmt = $t->db->prepare("INSERT INTO festivita_personalizzate (nome,tipo,date_specifiche,durata_giorni,sede_id,attiva) VALUES ('Pickup holiday','dates',?,1,?,1)");
    $stmt->bind_param('si', $day, $branch);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
}

function readyLoan(SettingsReview25 $t, string $pickup): int
{
    $loan = $t->loan($t->book($t->branchA), $t->day(14), ['start' => $t->today, 'status' => 'da_ritirare']);
    $t->db->query("UPDATE prestiti SET pickup_deadline='{$pickup}' WHERE id={$loan}");
    return $loan;
}

/** @return array<string, mixed> the reader's own row for $loan in GET /me/loans */
function readerLoan(SettingsReview25 $t, int $loan): array
{
    $request = $t->request('/api/v1/me/loans')->withAttribute('mobile_user', ['id' => $t->reader]);
    $body = json_decode((string) (new ActionsController($t->db))->myLoans($request, new Response())->getBody(), true, flags: JSON_THROW_ON_ERROR);
    foreach (['pending', 'active', 'history'] as $group) {
        foreach ($body['data'][$group] as $row) {
            if ((int) $row['id'] === $loan) {
                return $row;
            }
        }
    }
    throw new RuntimeException("loan {$loan} missing from /me/loans");
}

$cases = [];
$cases['a ready loan whose pickup day the branch closes shows the reopening day to reader and desk'] = static function (SettingsReview25 $t): void {
    $loan = readyLoan($t, $t->day(2));
    pickupHoliday($t, $t->day(2), $t->branchA);
    $reader = readerLoan($t, $loan);
    SettingsReview25::eq($reader['pickup_deadline'], $t->day(3), 'The reader collects until the day the branch reopens');
    SettingsReview25::eq((new StaffLoanReader($t->db))->loan($loan)['pickup_deadline'], $reader['pickup_deadline'], 'The desk shows the same last pickup day');
    SettingsReview25::eq(substr((string) $t->scalar("SELECT pickup_deadline FROM prestiti WHERE id={$loan}"), 0, 10), $t->day(2), 'The stored pickup day is left as written');
};
$cases['a closure in another branch does not move the pickup day'] = static function (SettingsReview25 $t): void {
    $loan = readyLoan($t, $t->day(2));
    pickupHoliday($t, $t->day(2), $t->branchB);
    SettingsReview25::eq(readerLoan($t, $loan)['pickup_deadline'], $t->day(2), 'Only the copy branch calendar counts');
};
$cases['without calendar rules the stored pickup day is shown as is'] = static function (SettingsReview25 $t): void {
    $loan = readyLoan($t, $t->day(2));
    SettingsReview25::check(!LibraryCalendar::forDb($t->db)->hasRules(), 'The fixture has no calendar rules');
    SettingsReview25::eq(readerLoan($t, $loan)['pickup_deadline'], $t->day(2), 'The stored day');
};
$cases['a ready loan without a stored pickup day carries null, never its return day'] = static function (SettingsReview25 $t): void {
    $loan = $t->loan($t->book($t->branchA), $t->day(14), ['start' => $t->today, 'status' => 'da_ritirare']);
    pickupHoliday($t, $t->day(2), $t->branchA);
    SettingsReview25::eq($t->scalar("SELECT pickup_deadline FROM prestiti WHERE id={$loan}"), null, 'The fixture stores no pickup day');
    $row = readerLoan($t, $loan);
    SettingsReview25::eq([$row['pickup_deadline'], $row['due_at']], [null, $t->day(14)], 'The return day is not offered as the pickup day');
};
$cases['loans that are not waiting for pickup carry no pickup day'] = static function (SettingsReview25 $t): void {
    $out = $t->loan($t->book($t->branchA), $t->day(5));
    $t->db->query("UPDATE prestiti SET pickup_deadline='{$t->day(-3)}' WHERE id={$out}");
    $row = readerLoan($t, $out);
    SettingsReview25::check(array_key_exists('pickup_deadline', $row), 'The field is always present');
    SettingsReview25::eq($row['pickup_deadline'], null, 'A loan already collected has no pickup day');
};

$passed = $failed = 0;
$suite = null;
$savedSession = $_SESSION ?? [];
try {
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$passed, &$failed): void {
        foreach ($cases as $name => $case) {
            try {
                $suite->reset();
                $case($suite);
                $passed++;
                echo "PASS {$name}\n";
            } catch (Throwable $error) {
                $failed++;
                echo "FAIL {$name}: {$error->getMessage()}\n";
            }
        }
    });
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, 'FAIL setup: ' . $error->getMessage() . PHP_EOL);
} finally {
    $_SESSION = $savedSession;
    LibraryCalendar::reset();
    $suite?->close();
}
echo "Mobile pickup deadline: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
