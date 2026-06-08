<?php
declare(strict_types=1);

// Single, intentional exit point: any assertion failure throws, and this
// handler reports it to STDERR and exits non-zero for CI. Keeps exit() out of
// the helpers (PHPMD ExitExpression) while preserving the fail-fast behaviour.
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});

$root = dirname(__DIR__);

function readFileOrFail(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        // Throw instead of exit(): exit() in helpers trips static quality gates
        // (PHPMD ExitExpression) and makes the helpers non-composable. The
        // top-level catch turns this into a non-zero exit for CI.
        throw new \RuntimeException("Cannot read {$path}");
    }

    return $contents;
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException("FAIL: {$message} (missing: {$needle})");
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new \RuntimeException("FAIL: {$message} (unexpected: {$needle})");
    }
}

$nonLendableSql = "'perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'";
$filesThatCountLoanableCopies = [
    'app/Support/DataIntegrity.php',
    'app/Controllers/ReservationManager.php',
    'app/Controllers/ReservationsController.php',
    'app/Controllers/PrestitiController.php',
    'app/Controllers/LoanApprovalController.php',
    'app/Models/CopyRepository.php',
    'app/Support/NotificationService.php',
    'installer/database/triggers.sql',
];

foreach ($filesThatCountLoanableCopies as $relativePath) {
    $contents = readFileOrFail($root . '/' . $relativePath);
    assertContainsText($nonLendableSql, $contents, "{$relativePath} must exclude every non-lendable copy state");
}

$loanApproval = readFileOrFail($root . '/app/Controllers/LoanApprovalController.php');
assertContainsText(
    "['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento']",
    $loanApproval,
    'LoanApprovalController must reject every non-lendable copy state before status updates'
);

$triggers = readFileOrFail($root . '/installer/database/triggers.sql');
assertContainsText(
    "p.stato IN ('in_corso','in_ritardo','prenotato','da_ritirare')",
    $triggers,
    'prestiti triggers must treat da_ritirare as an occupied copy state'
);
assertNotContainsText(
    "p.stato IN ('in_corso','in_ritardo','prenotato','pendente')",
    $triggers,
    'prestiti triggers must not treat inactive pending requests as active copy occupancy'
);

$integrity = readFileOrFail($root . '/app/Support/DataIntegrity.php');
assertContainsText('overbooked_circulation_period', $integrity, 'DataIntegrity must report capacity-aware overbooking');
assertContainsText('duplicate_user_circulation_request', $integrity, 'DataIntegrity must report cross-table user/book duplicates');
assertNotContainsText('overlap_reservation_loan', $integrity, 'DataIntegrity must not flag any reservation/loan overlap without checking capacity');
assertNotContainsText('overlap_reservation_reservation', $integrity, 'DataIntegrity must not flag reservation/reservation overlap without checking capacity');
assertNotContainsText('UPDATE prenotazioni pr', $integrity, 'Automatic fixes must not cancel all reservations overlapping loans');

$maintenance = readFileOrFail($root . '/app/Support/MaintenanceService.php');
assertContainsText('expired_waitlist_reservations', $maintenance, 'MaintenanceService must report expired waitlist reservations');
$cancelPos = strpos($maintenance, 'cancelExpiredReservations');
$processPos = strpos($maintenance, 'processScheduledReservations');
if ($cancelPos === false || $processPos === false || $cancelPos > $processPos) {
    throw new \RuntimeException('FAIL: MaintenanceService must cancel expired waitlist reservations before converting scheduled reservations');
}

echo "Loan/reservation consistency unit checks passed.\n";
