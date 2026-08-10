<?php
declare(strict_types=1);

/**
 * Loan/reservation coherence audit — regression guards.
 *
 * Pins the fixes from the 2026-08 coherence review so none of them silently
 * regresses. Each check is source-invariant: it asserts the presence (or
 * absence) of the exact construct whose lack caused the incoherence.
 *
 *  1. getBookAvailabilityData() must 404 soft-deleted books (it used to serve
 *     real per-day occupancy for them, anonymously via /api/libro/{id}/availability).
 *  2. calculateAvailability() default start must be the APP timezone today
 *     (a bare `new DateTime()` made the mobile calendar start on "yesterday"
 *     between midnight and 2am Rome time).
 *  3. next_due on /api/books/{id}/availability must consider only HOLDING
 *     states and dates >= today (it could return the past due date of an
 *     in_ritardo loan).
 *  4. DashboardStats must use the app clock, never CURDATE()/date('Y-m-d')
 *     (three different "todays" on the same dashboard).
 *  5. LoanRepository::update() fallback must honour loan_duration_days
 *     (hardcoded '+14 days' halved the seeded 30-day default).
 *  6. rejectLoan must promote the waitlist after freeing capacity, like every
 *     other release path, and flush deferred notifications after commit.
 *  7. DataIntegrity must expire prenotazioni on the app clock, not NOW()
 *     (ReservationManager uses DateHelper for the same rows).
 *  8. PrestitiController::store() must strict-validate both dates as ISO
 *     (the old strtotime guard passed unparseable input via int<=false).
 *  9. The occupied_ranges payload must include the pendente-with-copy arm
 *     and the active reservation queue (it contradicted first_available).
 * 10. User dashboard overdue displays must use the app clock, matching the
 *     cron's `data_scadenza < today` semantics.
 */

$root = dirname(__DIR__);

$reservations = (string) file_get_contents($root . '/app/Controllers/ReservationsController.php');
$web          = (string) file_get_contents($root . '/app/Routes/web.php');
$dashboard    = (string) file_get_contents($root . '/app/Models/DashboardStats.php');
$loanRepo     = (string) file_get_contents($root . '/app/Models/LoanRepository.php');
$approval     = (string) file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
$integrity    = (string) file_get_contents($root . '/app/Support/DataIntegrity.php');
$prestiti     = (string) file_get_contents($root . '/app/Controllers/PrestitiController.php');
$prenotView   = (string) file_get_contents($root . '/app/Views/user_dashboard/prenotazioni.php');
$indexView    = (string) file_get_contents($root . '/app/Views/user_dashboard/index.php');
$pendingView  = (string) file_get_contents($root . '/app/Views/admin/pending_loans.php');

$checks = [];

// 1. Soft-delete guard in the availability data provider (+ nullable contract).
$checks['getBookAvailabilityData guards deleted_at and returns ?array'] =
    str_contains($reservations, 'SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL')
    && preg_match('/function getBookAvailabilityData\([^)]*\):\s*\?array/', $reservations) === 1;

// 2. App-timezone default start in calculateAvailability.
$checks['calculateAvailability defaults start to DateHelper::today()'] =
    str_contains($reservations, 'new DateTime($startDate ?: \App\Support\DateHelper::today())')
    && !preg_match('/\$start = \$startDate \? new DateTime\(\$startDate\) : new DateTime\(\);/', $reservations);

// 3. next_due: holding states only, never in the past.
$nextDuePos = strpos($web, 'AS next_due FROM prestiti');
$checks['next_due filters holding states and >= today'] =
    $nextDuePos !== false
    && str_contains(substr($web, $nextDuePos, 400), "stato IN ('in_corso','in_ritardo','da_ritirare','prenotato')")
    && str_contains(substr($web, $nextDuePos, 400), 'data_scadenza >= ?');

// 4. DashboardStats: one clock (DateHelper), no CURDATE()/process date().
$checks['DashboardStats uses only the app clock'] =
    !str_contains($dashboard, 'CURDATE()')
    && !str_contains($dashboard, "date('Y-m-d')")
    && substr_count($dashboard, 'DateHelper::today()') >= 3;

// 5. LoanRepository::update() fallback honours the configured duration.
$checks['LoanRepository::update fallback reads loan_duration_days'] =
    !str_contains($loanRepo, "+14 days")
    && str_contains($loanRepo, "get('loans', 'loan_duration_days', '30')");

// 6. rejectLoan promotes the waitlist + flushes deferred notifications.
$rejectPos = strpos($approval, 'function rejectLoan');
$rejectBody = $rejectPos !== false ? substr($approval, $rejectPos, 10000) : '';
$checks['rejectLoan promotes waitlist and flushes notifications'] =
    str_contains($rejectBody, 'processBookAvailability($bookId)')
    && str_contains($rejectBody, 'flushDeferredNotifications()');

// 7. DataIntegrity expires prenotazioni on the app clock.
$checks['DataIntegrity uses app clock for reservation expiry'] =
    !preg_match('/data_scadenza_prenotazione < NOW\(\)/', $integrity)
    && substr_count($integrity, 'data_scadenza_prenotazione < ?') >= 2;

// 8. store() strict-validates both dates (same rule as update()).
$storePos = strpos($prestiti, 'public function store(');
$storeBody = $storePos !== false ? substr($prestiti, $storePos, 4000) : '';
$checks['store() ISO-validates data_prestito and data_scadenza'] =
    substr_count($storeBody, 'DateHelper::isISODateFormat') >= 2
    && !str_contains($storeBody, 'strtotime($data_scadenza) <= strtotime($data_prestito)');

// 9. occupied_ranges: pendente-with-copy arm + reservation queue included.
$rangesPos = strpos($web, 'Intervalli occupati');
$rangesBody = $rangesPos !== false ? substr($web, $rangesPos, 2500) : '';
$checks['occupied_ranges includes pendente-with-copy and prenotazioni'] =
    str_contains($rangesBody, "stato = 'pendente' AND copia_id IS NOT NULL")
    && str_contains($rangesBody, "FROM prenotazioni");

// 10. Views: overdue/day-count displays on the app clock.
$checks['user dashboard views use the app clock for overdue'] =
    !preg_match('/strtotime\(\$dueAt\) < time\(\)/', $prenotView)
    && !preg_match('/strtotime\(\$scadenza\) < time\(\)/', $prenotView)
    && str_contains($indexView, 'strtotime(\App\Support\DateHelper::today())')
    && str_contains($pendingView, 'DateHelper::today()');

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
