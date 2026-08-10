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

// ── Follow-up fixes (agreed design decisions + #301 second report) ──────────

// 11. #301: the book-detail modal path (createReservation) must honour the
//     automatic-approval setting like UserActionsController::loan does.
$checks['createReservation honours auto_approve_requests (#301)'] =
    str_contains($reservations, 'autoApproveLoanRequest($request, $loanRequestId)')
    && str_contains($reservations, "'automatic_loan_approval'");

// 12. rejectLoan must keep an audit row (annullato), never DELETE it.
$checks['rejectLoan cancels with audit instead of deleting'] =
    !str_contains($rejectBody, 'DELETE FROM prestiti')
    && str_contains($rejectBody, "SET stato = 'annullato'")
    && str_contains($rejectBody, 'processed_by');

// 13. Renewal confirmation email: template in base + all 4 locale overrides,
//     NotificationService sender, and the post-commit call in renew().
$mailBase = (string) file_get_contents($root . '/app/Support/SettingsMailTemplates.php');
$notif = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$renewedEverywhere = str_contains($mailBase, "'loan_renewed'");
foreach (['da_DK', 'de_DE', 'en_US', 'fr_FR'] as $mailLocale) {
    $renewedEverywhere = $renewedEverywhere
        && str_contains((string) file_get_contents($root . "/app/Support/mail_templates/{$mailLocale}.php"), "'loan_renewed'");
}
$checks['loan_renewed template exists in base + 4 locale overrides'] = $renewedEverywhere;
$checks['renew() sends the renewal confirmation post-commit'] =
    str_contains($notif, 'function sendLoanRenewedNotification')
    && str_contains($prestiti, 'sendLoanRenewedNotification($id, $maxRenewals)');

// 14. app.timezone is a real setting: ConfigStore default, per-locale installer
//     seed, loans-tab select, validated save.
$configStore = (string) file_get_contents($root . '/app/Support/ConfigStore.php');
$loansTab = (string) file_get_contents($root . '/app/Views/settings/loans-tab.php');
$settingsCtrl = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$seededEverywhere = true;
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $seedLocale) {
    $seededEverywhere = $seededEverywhere
        && str_contains((string) file_get_contents($root . "/installer/database/data_{$seedLocale}.sql"), "('app', 'timezone'");
}
$checks['app.timezone has a ConfigStore default and is seeded in all 5 installers'] =
    str_contains($configStore, "'timezone' => 'Europe/Rome'") && $seededEverywhere;
$checks['loans settings expose and validate the timezone'] =
    str_contains($loansTab, 'name="app_timezone"')
    && str_contains($settingsCtrl, 'DateTimeZone::listIdentifiers()')
    && str_contains($settingsCtrl, "ConfigStore::set('app.timezone'");

// 15. Book badge: the unavailable state is a TODAY snapshot — the copy must say
//     so, or it reads as contradicting the calendar's free future days.
$bookDetail = (string) file_get_contents($root . '/app/Views/frontend/book-detail.php');
$checks['book badge says "Non disponibile oggi" (today snapshot)'] =
    str_contains($bookDetail, '__("Non disponibile oggi")');

// 16. i18n parity: the 5 locales share the same key count and all carry the
//     new keys introduced by these fixes.
$parityOk = true;
$counts = [];
$mustHave = ['Fuso orario', 'Prestito rinnovato', 'Non disponibile oggi'];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $l10n) {
    $decoded = json_decode((string) file_get_contents($root . "/locale/{$l10n}.json"), true);
    if (!is_array($decoded)) {
        $parityOk = false;
        break;
    }
    $counts[] = count($decoded);
    foreach ($mustHave as $mk) {
        $parityOk = $parityOk && array_key_exists($mk, $decoded);
    }
}
$checks['i18n parity: 5 locales aligned and carrying the new keys'] =
    $parityOk && count(array_unique($counts)) === 1;

// ── Adversarial-review findings (multi-agent workflow on this branch) ────────

// 17. ConfigStore must MAP the timezone row back from the DB: without the
//     loadDatabaseSettings() entry the seeds + save wrote a row that was never
//     read, and get('app.timezone') always returned the hardcoded default.
$checks['ConfigStore maps the app.timezone DB row into the cache'] =
    str_contains($configStore, "raw['app']['timezone']");

// 18. Both availability labels on the book page must use the today-snapshot
//     copy — the sidebar "Stato" said "Non Disponibile" while the hero badge
//     said "Non disponibile oggi" for the same condition.
$checks['book page has no leftover "Non Disponibile" label'] =
    !str_contains($bookDetail, '__("Non Disponibile")')
    && substr_count($bookDetail, '__("Non disponibile oggi")') >= 2;

// 19. The reservation modal must branch on the auto-approve response: with the
//     option on, the loan is already approved and "waiting for approval" copy
//     would be false (the user would wait for an approval that already happened).
$checks['reservation modal branches on result.auto_approved'] =
    str_contains($bookDetail, 'result.auto_approved === true')
    && str_contains($bookDetail, 'approvedFootnote');

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
