<?php
declare(strict_types=1);

/**
 * CirculationPolicy (Phase 0): with only the global loans settings, every
 * number the service returns equals the one each historical read site used
 * to compute by hand. The old expressions are reproduced verbatim below, one
 * per read site, and compared over a grid of stored values (missing, valid,
 * zero, negative, malformed).
 *
 * Run: php tests/circulation-policy-equivalence.unit.php
 */

use App\Models\SettingsRepository;
use App\Services\CirculationPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
try {
    $db = new mysqli(
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_USER'] ?? '',
        $env['DB_PASS'] ?? '',
        $env['DB_NAME'] ?? '',
        (int) ($env['DB_PORT'] ?? 3306),
        getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)
    );
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable: {$e->getMessage()}\n");
    exit(1);
}

/** Settings stub: the grid value of each key, without touching system_settings. */
final class GridSettings extends SettingsRepository
{
    /** @param array<string, string|null> $values */
    public function __construct(mysqli $db, private array $values)
    {
        parent::__construct($db);
    }

    public function get(string $category, string $key, ?string $default = null): ?string
    {
        if ($category !== 'loans' || !array_key_exists($key, $this->values) || $this->values[$key] === null) {
            return $default;
        }
        return $this->values[$key];
    }
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
    } else {
        $failed++;
        echo "FAIL {$label}\n";
    }
};

// Historical expressions, verbatim from the read sites they replaced.
$old = [
    // SettingsRepository::loanDurationDays() (PrestitiController store/createForm/renew, LoanRepository, StaffLoanReader)
    'duration' => static fn (SettingsRepository $s): int => (($d = (int) ($s->get('loans', 'loan_duration_days', '30') ?? 30)) >= 1 ? $d : 30),
    // ReservationsAdminController::loanDurationDays(), NCIP CheckOut/RequestItem/RenewItem
    'duration_ncip' => static fn (SettingsRepository $s): int => (($d = (int) ($s->get('loans', 'loan_duration_days', '30') ?? 30)) > 0 ? $d : 30),
    // SettingsRepository::maxLoanDurationDays() (ReservationsController, UserActionsController, FrontendController)
    'max_request' => static fn (SettingsRepository $s): int => (($d = (int) ($s->get('loans', 'max_loan_duration_days', '90') ?? 90)) >= 1 ? $d : 90),
    // PrestitiController::renew()/update()/bulkExtend(), NCIP extendLoan (after the < 0 → 3 guard)
    'renewals' => static fn (SettingsRepository $s): int => (($r = (int) ($s->get('loans', 'max_renewals', '3') ?? 3)) < 0 ? 3 : $r),
    // LibriController (numeric validation before the cast)
    'renewals_numeric' => static function (SettingsRepository $s): int {
        $raw = $s->get('loans', 'max_renewals', '3');
        $r = is_numeric($raw) ? (int) $raw : 3;
        return $r < 0 ? 3 : $r;
    },
    // PrestitiController/LoanApprovalController/UserActionsController/ReservationsController/NCIP: `if ($maxLoans > 0)`
    'max_loans' => static fn (SettingsRepository $s): int => (int) ($s->get('loans', 'max_active_loans_per_user', '0') ?? 0),
    // ReservationManager / CapacityService
    'max_loans_clamped' => static fn (SettingsRepository $s): int => max(0, (int) ($s->get('loans', 'max_active_loans_per_user', '0') ?? 0)),
];

$grid = [
    'loan_duration_days' => [null, '30', '14', '1', '0', '-5', '365'],
    'max_loan_duration_days' => [null, '90', '7', '0', '-1', '400'],
    'max_renewals' => [null, '3', '0', '1', '-2', '100'],
    'max_active_loans_per_user' => [null, '0', '1', '5', '-3'],
];

$cases = 0;
foreach ($grid['loan_duration_days'] as $duration) {
    foreach ($grid['max_loan_duration_days'] as $maxRequest) {
        foreach ($grid['max_renewals'] as $renewals) {
            foreach ($grid['max_active_loans_per_user'] as $maxLoans) {
                $settings = new GridSettings($db, [
                    'loan_duration_days' => $duration,
                    'max_loan_duration_days' => $maxRequest,
                    'max_renewals' => $renewals,
                    'max_active_loans_per_user' => $maxLoans,
                ]);
                $tag = json_encode([$duration, $maxRequest, $renewals, $maxLoans]);
                $policy = new CirculationPolicy($db, $settings);
                foreach ([$policy->defaults(), $policy->forLoan(1, 1), $policy->forLoan(999999, 999999, 5)] as $i => $p) {
                    $check($p->durationDays === $old['duration']($settings), "duration #{$i} {$tag}");
                    $check($p->durationDays === $old['duration_ncip']($settings), "duration (NCIP form) #{$i} {$tag}");
                    $check($p->renewalDays === $old['duration']($settings), "renewal length = loan duration #{$i} {$tag}");
                    $check($p->maxRequestDays === $old['max_request']($settings), "max request #{$i} {$tag}");
                    $check($p->requestDurationDays() === min($old['duration']($settings), $old['max_request']($settings)), "request default #{$i} {$tag}");
                    $check($p->maxRenewals === $old['renewals']($settings), "renewals #{$i} {$tag}");
                    $check($p->maxRenewals === $old['renewals_numeric']($settings), "renewals (numeric form) #{$i} {$tag}");
                    $check(($p->maxActiveLoans > 0) === ($old['max_loans']($settings) > 0), "limit enabled #{$i} {$tag}");
                    $check($p->maxActiveLoans === $old['max_loans_clamped']($settings), "limit value #{$i} {$tag}");
                    $check($p->loanable === true && $p->maxActiveForMedia === 0, "loanable, no media limit #{$i} {$tag}");
                    $check($p->dailyFine === null && $p->graceDays === null && $p->maxFinePerLoan === null && $p->debtBlockThreshold === null, "no fines #{$i} {$tag}");
                }
                $user = $policy->forUser(1);
                $check($user->maxActiveLoans === $old['max_loans_clamped']($settings), "user limit {$tag}");
                $check($user->reservationsEnabled === true && $user->maxReservations === 0 && $user->cardDurationDays === null, "user reservations/card unchanged {$tag}");
                // The SQL form of the limit (queue promotion, capacity) evaluates
                // to the same number for a patron of the inheriting default type.
                $limitSql = $policy->totalLoanLimitSql('u');
                $evaluated = (int) $db->query("SELECT {$limitSql} FROM (SELECT NULL AS tipologia_id) u")->fetch_row()[0];
                $check($evaluated === $old['max_loans_clamped']($settings), "SQL limit {$tag}");
                $check($policy->anyTotalLoanLimit() === ($old['max_loans_clamped']($settings) > 0), "SQL limit gate {$tag}");
                // The deprecated SettingsRepository helpers delegate and keep their numbers.
                $check($settings->loanDurationDays() === $old['duration']($settings), "deprecated loanDurationDays {$tag}");
                $check($settings->maxLoanDurationDays() === $old['max_request']($settings), "deprecated maxLoanDurationDays {$tag}");
                $check($settings->requestLoanDurationDays() === min($old['duration']($settings), $old['max_request']($settings)), "deprecated requestLoanDurationDays {$tag}");
                $cases++;
            }
        }
    }
}

// Malformed values: a non-numeric renewals value falls back to 3 everywhere
// (the cast forms used to read it as 0 — the only site-level divergence, now
// resolved towards the validated LibriController reading).
$malformed = new GridSettings($db, ['max_renewals' => 'abc']);
$check((new CirculationPolicy($db, $malformed))->defaults()->maxRenewals === 3, 'non-numeric max_renewals falls back to 3');

// dueDateFor() equals both historical arithmetic forms (strtotime and
// DateTimeImmutable::modify) across month ends, leap days and DST changes.
$tzBefore = date_default_timezone_get();
foreach (['UTC', 'Europe/Rome', 'America/New_York'] as $tz) {
    date_default_timezone_set($tz);
    foreach (['2026-01-31', '2026-02-28', '2028-02-29', '2026-03-28', '2026-03-29', '2026-10-24', '2026-10-25', '2026-12-31'] as $start) {
        foreach ([0, 1, 7, 14, 30, 31, 90, 365] as $days) {
            $viaStrtotime = date('Y-m-d', strtotime($start . " +{$days} days"));
            $viaModify = (new DateTimeImmutable($start))->modify("+{$days} days")->format('Y-m-d');
            $new = CirculationPolicy::dueDateFor($start, $days);
            $check($new === $viaStrtotime && $new === $viaModify, "dueDateFor({$start}, {$days}) in {$tz}");
        }
    }
    $check(CirculationPolicy::dueDateFor('2026-11-02 23:59:59', 3) === '2026-11-05', "dueDateFor ignores a time part in {$tz}");
}
date_default_timezone_set($tzBefore);

// The shared state set is the one every limit check counted.
$check(CirculationPolicy::ACTIVE_LOAN_STATES === ['prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'], 'active-loan states');
$check(CirculationPolicy::ACTIVE_LOAN_STATES_SQL === "'" . implode("','", CirculationPolicy::ACTIVE_LOAN_STATES) . "'", 'SQL list matches the array');

echo "{$cases} setting combinations\n";
if ($failed > 0) {
    echo "FAILED: {$failed} of " . ($passed + $failed) . " checks\n";
    exit(1);
}
echo "SUCCESS: {$passed} checks\n";
