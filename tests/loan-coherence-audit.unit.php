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
 * 11. Every peripheral physical-copy writer must derive the book projection
 *     inside the same transaction instead of forcing or repairing it later.
 * 12. Every first-party circulation connection must publish DateHelper::today()
 *     to the overlap triggers, which retain CURRENT_DATE() for direct SQL only.
 */

$root = dirname(__DIR__);

$readSource = static function (string $path) use ($root): string {
    $fullPath = $root . $path;
    if (!is_file($fullPath)) {
        fwrite(STDERR, "FAIL: file sorgente mancante: {$path}\n");
        exit(1);
    }
    $source = file_get_contents($fullPath);
    if ($source === false) {
        fwrite(STDERR, "FAIL: impossibile leggere il file sorgente: {$path}\n");
        exit(1);
    }
    return $source;
};

$extractMethod = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $remaining = substr($source, $start + strlen($signature));
    if (!preg_match('/\n    (?:public|protected|private) function /', $remaining, $match, PREG_OFFSET_CAPTURE)) {
        return substr($source, $start);
    }
    $end = $start + strlen($signature) + (int) $match[0][1];
    return substr($source, $start, $end - $start);
};

$extractSection = static function (string $source, string $startMarker, string $endMarker): string {
    $start = strpos($source, $startMarker);
    if ($start === false) {
        return '';
    }
    $end = strpos($source, $endMarker, $start + strlen($startMarker));
    return $end === false ? '' : substr($source, $start, $end - $start);
};

$reservations = $readSource('/app/Controllers/ReservationsController.php');
$web          = $readSource('/app/Routes/web.php');
$dashboard    = $readSource('/app/Models/DashboardStats.php');
$loanRepo     = $readSource('/app/Models/LoanRepository.php');
$approval     = $readSource('/app/Controllers/LoanApprovalController.php');
$integrity    = $readSource('/app/Support/DataIntegrity.php');
$prestiti     = $readSource('/app/Controllers/PrestitiController.php');
$frontend     = $readSource('/app/Controllers/FrontendController.php');
$prenotView   = $readSource('/app/Views/user_dashboard/prenotazioni.php');
$indexView    = $readSource('/app/Views/user_dashboard/index.php');
$pendingView  = $readSource('/app/Views/admin/pending_loans.php');
$loanCreateView = $readSource('/app/Views/prestiti/crea_prestito.php');
$loanEditView = $readSource('/app/Views/prestiti/modifica_prestito.php');
$mobileActions = $readSource('/storage/plugins/mobile-api/src/Controllers/ActionsController.php');
$expiredCron = $readSource('/scripts/check-expired-reservations.php');
$demoSeed = $readSource('/scripts/seed-demo-catalog.php');
$bookClubRepo = $readSource('/storage/plugins/book-club/src/Repo.php');
$manualUpgrade = $readSource('/scripts/manual-upgrade.php');
$dateHelper = $readSource('/app/Support/DateHelper.php');
$containerConfig = $readSource('/config/container.php');
$triggerSql = $readSource('/installer/database/triggers.sql');
$maintenanceService = $readSource('/app/Support/MaintenanceService.php');
$automaticNotificationsCron = $readSource('/cron/automatic-notifications.php');
$fullMaintenanceCron = $readSource('/cron/full-maintenance.php');
$legacyMaintenanceScript = $readSource('/scripts/maintenance.php');
$cliDbBootstrap = $readSource('/scripts/_db_bootstrap.php');

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
$nextDueBody = $extractSection($web, 'AS next_due FROM prestiti', 'SELECT COUNT(*) AS c FROM prenotazioni');
$checks['next_due filters holding states and >= today'] =
    $nextDueBody !== ''
    && str_contains($nextDueBody, "stato IN ('in_corso','in_ritardo','da_ritirare','prenotato')")
    && str_contains($nextDueBody, 'data_scadenza >= ?');

// 4. DashboardStats: one clock (DateHelper), no CURDATE()/process date().
$checks['DashboardStats uses only the app clock'] =
    !str_contains($dashboard, 'CURDATE()')
    && !str_contains($dashboard, "date('Y-m-d')")
    && substr_count($dashboard, 'DateHelper::today()') >= 3;

// 4b. DashboardStats::counts() soft-delete parity (CLAUDE.md rule 2): every
//     `FROM prestiti` sub-count in counts() must reach `libri` through a join
//     that excludes soft-deleted books, so no dashboard badge can exceed the
//     rows /admin/loans/pending can render. Source-invariant: count the
//     `FROM prestiti` occurrences and require an equal number of guarded joins.
$countsBody = $extractMethod($dashboard, 'public function counts(');
$checks['DashboardStats::counts() guards every prestiti sub-count with a soft-delete join'] =
    $countsBody !== ''
    && substr_count($countsBody, 'FROM prestiti')
        === substr_count($countsBody, 'JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL');

// 5. LoanRepository::update() fallback honours the configured duration.
$checks['LoanRepository::update fallback reads loan_duration_days'] =
    !str_contains($loanRepo, "+14 days")
    && str_contains($loanRepo, 'loanDurationDays()');

// 6. rejectLoan promotes the waitlist + flushes deferred notifications.
$rejectBody = $extractMethod($approval, 'public function rejectLoan(');
$checks['rejectLoan promotes waitlist and flushes notifications'] =
    $rejectBody !== ''
    && str_contains($rejectBody, 'processBookAvailability($bookId)')
    && str_contains($rejectBody, 'flushDeferredNotifications()');

// 7. DataIntegrity expires prenotazioni on the app clock.
$checks['DataIntegrity uses app clock for reservation expiry'] =
    !preg_match('/data_scadenza_prenotazione < NOW\(\)/', $integrity)
    && substr_count($integrity, 'data_scadenza_prenotazione < ?') >= 2;

// 8. store() strict-validates both dates (same rule as update()).
$storeBody = $extractMethod($prestiti, 'public function store(');
$checks['store() ISO-validates data_prestito and data_scadenza'] =
    $storeBody !== ''
    && substr_count($storeBody, 'DateHelper::isISODateFormat') >= 2
    && !str_contains($storeBody, 'strtotime($data_scadenza) <= strtotime($data_prestito)');

// 9. occupied_ranges: pendente-with-copy arm + reservation queue included.
$rangesBody = $extractSection($web, 'Intervalli occupati', '// first_available / is_available_now');
$checks['occupied_ranges includes pendente-with-copy and prenotazioni'] =
    $rangesBody !== ''
    && str_contains($rangesBody, "stato = 'pendente' AND copia_id IS NOT NULL")
    && str_contains($rangesBody, "stato = 'in_ritardo'")
    && str_contains($rangesBody, "stato = 'in_corso' AND data_scadenza < ?")
    && str_contains($rangesBody, "THEN '9999-12-31'")
    && str_contains($rangesBody, "bind_param('si', \$today, \$libroId)")
    && str_contains($rangesBody, "'to' => \$row['occupied_until']")
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
    str_contains($reservations, 'autoApproveLoanRequest($request, $loanRequestId, true)')
    && str_contains($reservations, "'automatic_loan_approval'");

// 12. rejectLoan must keep an audit row (annullato), never DELETE it.
$checks['rejectLoan cancels with audit instead of deleting'] =
    !str_contains($rejectBody, 'DELETE FROM prestiti')
    && str_contains($rejectBody, "SET stato = 'annullato'")
    && str_contains($rejectBody, 'processed_by');

// 13. Renewal confirmation email: template in base + all 4 locale overrides,
//     NotificationService sender, and the post-commit call in renew().
$mailBase = $readSource('/app/Support/SettingsMailTemplates.php');
$notif = $readSource('/app/Support/NotificationService.php');
$renewedEverywhere = str_contains($mailBase, "'loan_renewed'");
foreach (['da_DK', 'de_DE', 'en_US', 'fr_FR'] as $mailLocale) {
    $renewedEverywhere = $renewedEverywhere
        && str_contains($readSource("/app/Support/mail_templates/{$mailLocale}.php"), "'loan_renewed'");
}
$checks['loan_renewed template exists in base + 4 locale overrides'] = $renewedEverywhere;
$checks['renew() sends the renewal confirmation post-commit'] =
    str_contains($notif, 'function sendLoanRenewedNotification')
    && str_contains($prestiti, 'sendLoanRenewedNotification($id, $maxRenewals)');

// 14. app.timezone is a real setting: ConfigStore default, per-locale installer
//     seed, loans-tab select, validated save.
$configStore = $readSource('/app/Support/ConfigStore.php');
$loansTab = $readSource('/app/Views/settings/loans-tab.php');
$settingsCtrl = $readSource('/app/Controllers/SettingsController.php');
$seededEverywhere = true;
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $seedLocale) {
    $seededEverywhere = $seededEverywhere
        && str_contains($readSource("/installer/database/data_{$seedLocale}.sql"), "('app', 'timezone'");
}
$checks['app.timezone has a ConfigStore default and is seeded in all 5 installers'] =
    str_contains($configStore, "'timezone' => 'Europe/Rome'") && $seededEverywhere;
$checks['loans settings expose and validate the timezone'] =
    str_contains($loansTab, 'name="app_timezone"')
    && str_contains($settingsCtrl, 'DateTimeZone::listIdentifiers()')
    && str_contains($settingsCtrl, "ConfigStore::set('app.timezone'");

// 15. Book badge: the unavailable state is a TODAY snapshot — the copy must say
//     so, or it reads as contradicting the calendar's free future days.
$bookDetail = $readSource('/app/Views/frontend/book-detail.php');
$checks['book badge says "Non disponibile oggi" (today snapshot)'] =
    str_contains($bookDetail, '__("Non disponibile oggi")');

// 16. i18n parity: the 5 locales share the exact, case-sensitive key set and
//     all carry the new keys introduced by these fixes.
$parityOk = true;
$referenceKeys = null;
$mustHave = ['Fuso orario', 'Prestito rinnovato', 'Non disponibile oggi'];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $l10n) {
    $decoded = json_decode($readSource("/locale/{$l10n}.json"), true);
    if (!is_array($decoded)) {
        $parityOk = false;
        break;
    }
    $keys = array_keys($decoded);
    sort($keys, SORT_STRING);
    $referenceKeys ??= $keys;
    $parityOk = $parityOk && $keys === $referenceKeys;
    foreach ($mustHave as $mk) {
        $parityOk = $parityOk && array_key_exists($mk, $decoded);
    }
}
$checks['i18n parity: 5 locales aligned and carrying the new keys'] =
    $parityOk;

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

// 20. Every client-side "today" in the two changed loan calendars comes from
//     DateHelper, not the browser timezone. Availability requests are ordered so
//     an older borrower response cannot repaint a newer selection.
$checks['loan calendars use app today and ignore stale availability responses'] =
    str_contains($loanCreateView, 'const appToday =')
    && str_contains($bookDetail, 'const appToday =')
    && !str_contains($loanCreateView, 'formatDate(new Date())')
    && !str_contains($loanCreateView, "minDate: 'today'")
    && !str_contains($bookDetail, "minDate: 'today'")
    && str_contains($loanCreateView, 'const requestId = ++availabilityRequestId')
    && preg_match('/if\s*\(\s*requestId\s*!==\s*availabilityRequestId\s*\)\s*(?:\{\s*)?return\s*;/', $loanCreateView) === 1
    && preg_match_all('/availabilityByDate\s*=\s*\{\s*\}\s*;/', $loanCreateView) >= 3
    && str_contains($loanCreateView, 'blockedByReservation: blockedByReservation')
    && str_contains($loanCreateView, 'borrowerAlreadyReserved');

$checks['loan calendars use the configured duration without local-time date arithmetic'] =
    str_contains($loanCreateView, 'const defaultLoanDays =')
    && str_contains($loanCreateView, 'addDaysToIso(dateStr, defaultLoanDays)')
    && !str_contains($loanCreateView, 'setMonth(endDate.getMonth() + 1)')
    && str_contains($frontend, '$defaultRequestLoanDays = min(')
    && str_contains($bookDetail, 'addDaysToIso(dateStr, defaultRequestLoanDays)')
    && !str_contains($bookDetail, 'setMonth(endDate.getMonth() + 1)');

// 21. Server-rendered edit fallback follows the same application clock.
$checks['loan edit fallback uses DateHelper::today()'] =
    str_contains($loanEditView, "prestito['data_prestito'] ?? \\App\\Support\\DateHelper::today()")
    && !str_contains($loanEditView, "prestito['data_prestito'] ?? date('Y-m-d')");

// 22. Historical mobile loans retain the due date consumed by mapLoan().
$mobileHistory = $extractMethod($mobileActions, 'public function myLoans(');
$mobileMapLoan = $extractMethod($mobileActions, 'private function mapLoan(');
$checks['mobile loan history selects data_scadenza for due_at'] =
    $mobileHistory !== ''
    && $mobileMapLoan !== ''
    && str_contains($mobileHistory, 'pr.data_scadenza')
    && str_contains($mobileMapLoan, "'due_at'")
    && str_contains($mobileMapLoan, "['data_scadenza']");

// 23. The authenticated availability endpoint must preserve the nullable
//     soft-delete contract instead of converting a concurrent delete to a
//     successful empty payload.
$disponibilitaSection = $extractSection($web, "'/api/libri/{id}/disponibilita'", "'/api/search/collocazione'");
$checks['disponibilita endpoint 404s a concurrent soft-delete'] =
    $disponibilitaSection !== ''
    && str_contains($disponibilitaSection, 'if ($availability === null)')
    && !str_contains($disponibilitaSection, 'getBookAvailabilityData($libroId, $today, 180) ?? []');

// 24. The global repair must calculate the read model only after it finishes
//     repairing loans/reservations; a preliminary/direct book-state UPDATE can
//     neither represent prenotato correctly nor be committed independently.
$fixIntegrity = $extractMethod($integrity, 'public function fixDataInconsistencies(');
$lastReservationRepair = strrpos($fixIntegrity, 'UPDATE prenotazioni');
$finalAvailability = strrpos($fixIntegrity, 'recalculateAllBookAvailability(insideTransaction: true)');
$checks['integrity repair has one final canonical availability write'] =
    $fixIntegrity !== ''
    && !str_contains($fixIntegrity, 'UPDATE libri SET stato')
    && $lastReservationRepair !== false
    && $finalAvailability !== false
    && $lastReservationRepair < $finalAvailability;

// 25. Peripheral writers that bypass the main controllers still keep
//     copies/commitments and the derived libri projection in one transaction.
// Il cron non duplica più la logica: DELEGA a MaintenanceService::
// checkExpiredReservations(), che è l'unico percorso (email+audit garantiti).
// L'invariante "recalc prima del commit" ora vive nel metodo delegato.
$expiredSweepMethod = $extractMethod($maintenanceService, 'public function checkExpiredReservations(');
$checks['expired-reservation cron delegates to the single MaintenanceService sweep'] =
    str_contains($expiredCron, '->checkExpiredReservations()')
    && !str_contains($expiredCron, 'UPDATE prestiti')
    && !str_contains($expiredCron, 'recalculateBookAvailability');
$checks['expired-reservation sweep recalculates before commit'] =
    $expiredSweepMethod !== ''
    && str_contains($expiredSweepMethod, 'recalculateBookAvailability($libroId, true)')
    && strpos($expiredSweepMethod, 'recalculateBookAvailability($libroId, true)')
        < strrpos($expiredSweepMethod, '$this->db->commit()');
$checks['demo seed never authors book state and recalculates before commit'] =
    !str_contains($demoSeed, "genere_id=?, stato='disponibile'")
    && str_contains($demoSeed, 'recalculateBookAvailability($bookId, insideTransaction: true)')
    && strpos($demoSeed, 'recalculateBookAvailability($bookId, insideTransaction: true)')
        < strpos($demoSeed, '$db->commit()');
$createCatalogueBook = $extractMethod($bookClubRepo, 'private function createCatalogueBookFromExternal(');
$checks['Book Club acquisition derives availability inside its transaction'] =
    str_contains($createCatalogueBook, 'recalculateBookAvailability($libroId, insideTransaction: true)')
    && strpos($bookClubRepo, '$this->createCatalogueBookFromExternal(')
        < strpos($bookClubRepo, '$this->db->commit()', strpos($bookClubRepo, 'public function acquireExternalBook('));
$migrationLoopPos = strpos($manualUpgrade, 'foreach ($migrationFiles as $migFile)');
$checks['manual upgrader performs the post-migration availability pass'] =
    str_contains($manualUpgrade, 'recalculateAllBookAvailability()')
    && $migrationLoopPos !== false
    && strpos($manualUpgrade, 'recalculateAllBookAvailability()') > $migrationLoopPos;

// 26. The trigger clock follows app.timezone on every first-party circulation
//     connection. CURRENT_DATE() appears only in the two trigger-local fallback
//     assignments used by uninitialized/direct SQL clients.
$checks['circulation triggers share the configured application day on every first-party connection'] =
    str_contains($dateHelper, 'function synchronizeDatabaseSession(')
    && str_contains($containerConfig, 'DateHelper::synchronizeDatabaseSession($mysqli)')
    && str_contains($reservations, 'DateHelper::synchronizeDatabaseSession($this->db)')
    && str_contains($maintenanceService, 'DateHelper::synchronizeDatabaseSession($db)')
    && str_contains($automaticNotificationsCron, 'DateHelper::synchronizeDatabaseSession($db)')
    && str_contains($fullMaintenanceCron, 'DateHelper::synchronizeDatabaseSession($db)')
    && str_contains($legacyMaintenanceScript, 'DateHelper::synchronizeDatabaseSession($db)')
    && str_contains($cliDbBootstrap, 'DateHelper::synchronizeDatabaseSession($db)')
    && str_contains($expiredCron, 'pinakes_db_from_env()')
    && substr_count(
        $triggerSql,
        'SET application_today = COALESCE(@pinakes_application_date, CURRENT_DATE())'
    ) === 2
    && !str_contains($triggerSql, 'data_scadenza < CURRENT_DATE()');

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
