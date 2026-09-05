<?php
declare(strict_types=1);

/** Static regression contract for the 0.7.80 circulation edge-case pass. */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$method = static function (string $source, string $name): string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\n    public function ", $start + 10);
    $nextPrivate = strpos($source, "\n    private function ", $start + 10);
    $ends = array_filter([$next, $nextPrivate], static fn ($v): bool => $v !== false);
    $end = $ends === [] ? strlen($source) : min($ends);
    return substr($source, $start, $end - $start);
};

$approval = file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
$approve = $method((string) $approval, 'approveLoan');
$pickup = $method((string) $approval, 'confirmPickup');
$check(str_contains($approve, 'if ($isFutureLoan)') && str_contains($approve, 'sendPickupReadyNotification($loanId)'),
    'immediate manual and automatic approvals use the deadline-bearing pickup email');
$check(!str_contains($approve, '$isFutureLoan || $automaticApproval'),
    'automatic approval no longer diverts immediate loans to the generic approval email');
$check(str_contains($pickup, 'LoanEligibility::checkUser') && str_contains($pickup, "withStatus(403)"),
    'physical pickup rechecks mutable borrower eligibility');

$maintenance = file_get_contents($root . '/app/Support/MaintenanceService.php');
$expire = $method((string) $maintenance, 'checkExpiredReservations');
$activate = $method((string) $maintenance, 'activateScheduledLoans');
$check(str_contains($expire, "stato = 'pendente'") && str_contains($expire, "origine = 'prenotazione'"),
    'promoted but unapproved reservation loans expire when their window passes');
$check(str_contains($activate, 'LoanEligibility::checkUser'),
    'scheduled activation rechecks borrower eligibility');
$check(str_contains((string) $maintenance, 'retryQueuedEmailDeliveries'),
    'full maintenance retries the durable email outbox');

$capacity = file_get_contents($root . '/app/Services/CapacityService.php');
$check(str_contains((string) $capacity, "JOIN utenti u ON u.id = r.utente_id")
    && str_contains((string) $capacity, "eligibleUserWhere('u')"),
    'ineligible reservations are paused instead of consuming capacity');
$check(str_contains((string) $capacity, 'maxActiveLoansPerUser') && str_contains((string) $capacity, 'FROM prestiti cap'),
    'reservations at the active-loan limit are paused in capacity calculations');

$reservationManager = file_get_contents($root . '/app/Controllers/ReservationManager.php');
$promote = $method((string) $reservationManager, 'processBookAvailability');
$check(str_contains($promote, 'max_active_loans_per_user') && str_contains($promote, 'SELECT COUNT(*) AS c'),
    'promotion enforces the active-loan cap both in selection and under lock');

$notifications = file_get_contents($root . '/app/Support/NotificationService.php');
$check(str_contains((string) $notifications, "'reservation_awaiting_approval'")
    && str_contains((string) $notifications, 'sendLoanCopyOutcomeNotification'),
    'promotion and lost/damaged outcomes have truthful dedicated notifications');
$check(str_contains((string) $notifications, 'INSERT INTO email_delivery_outbox')
    && str_contains((string) $notifications, 'claim_token = ?')
    && str_contains((string) $notifications, 'retryQueuedEmailDeliveries'),
    'terminal emails are persisted and retried through leased claims');
$check(str_contains(file_get_contents($root . '/cron/automatic-notifications.php'), 'retryQueuedEmailDeliveries'),
    'hourly notification cron retries queued emails');

$templates = \App\Support\SettingsMailTemplates::all();
$check(isset($templates['reservation_awaiting_approval'], $templates['loan_copy_outcome']),
    'Italian defaults expose both new circulation templates');
foreach (['en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $localized = \App\Support\SettingsMailTemplates::all($locale);
    $check(isset($localized['reservation_awaiting_approval'], $localized['loan_copy_outcome']),
        "{$locale} ships both new circulation templates");
}

$returns = file_get_contents($root . '/app/Controllers/PrestitiController.php');
$returnByCode = $method((string) $returns, 'returnByCode');
$check(!str_contains($returnByCode, 'deleted_at IS NULL'),
    'barcode return accepts active loans on archived titles');
$check(str_contains((string) $returns, 'invalid_penalty')
    && str_contains((string) $returns, 'sendLoanCopyOutcomeNotification'),
    'lost/damaged closure validates the assessed amount and notifies the borrower');

$schema = file_get_contents($root . '/installer/database/schema.sql');
$migration = file_get_contents($root . '/installer/database/migrations/migrate_0.7.80.sql');
$triggers = file_get_contents($root . '/installer/database/triggers.sql');
$check(str_contains((string) $schema, 'email_delivery_outbox') && str_contains((string) $migration, 'email_delivery_outbox'),
    'fresh installs and upgrades create the durable outbox');
$check(str_contains((string) $triggers, 'trg_check_prenotazione_before_insert')
    && str_contains((string) $triggers, 'Posizione in coda già occupata'),
    'database triggers reject malformed and duplicate active queue positions');
$version = json_decode((string) file_get_contents($root . '/version.json'), true);
$check(version_compare((string) ($version['version'] ?? '0'), '0.7.80', '>='),
    'release version includes migration 0.7.80');

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
