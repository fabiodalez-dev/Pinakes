<?php
declare(strict_types=1);

/**
 * Mobile API 1.7.0 — circulation desk, the parts that need no database:
 *
 *   A. Every `?error=<code>` literal the delegated core controllers can emit
 *      (PrestitiController store/update/processReturn/renew/bulk*,
 *      ReservationsAdminController store/update, LoanEligibility codes) is
 *      mapped by a CirculationOutcome table or explicitly listed as not
 *      reachable from the API. Read from the core SOURCE TEXT: a new core
 *      code fails here instead of silently becoming a 500.
 *   B. The frozen mapping of the contract (§2.1 ①–⑥) for the codes the app
 *      branches on.
 *   C. `actions` hints per loan state and per reservation state.
 *   D. The approval passthrough (③) status mapping.
 *   E. /health staff_* flags come from StaffAreas: an area whose routes are
 *      not mounted is never advertised, whatever its prerequisite says.
 *
 * Run: php tests/mobile-api-circulation.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src';
require_once $plugin . '/Support/ResponseEnvelope.php';
require_once $plugin . '/Support/CirculationOutcome.php';
require_once $plugin . '/Support/StaffAreas.php';

use App\Plugins\MobileApi\Support\CirculationOutcome;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. Core codes pinned against the source ──────────────────────────────────
echo "A. Every core ?error= code is mapped\n";
$core = __DIR__ . '/../app';
$codesIn = static function (string $file) use ($core): array {
    $src = (string) file_get_contents($core . '/' . $file);
    preg_match_all("/error=([a-z_]+)/", $src, $m);
    return array_values(array_unique($m[1]));
};
$prestiti = $codesIn('Controllers/PrestitiController.php');
$reservations = $codesIn('Controllers/ReservationsAdminController.php');
$check(count($prestiti) >= 30, 'found the PrestitiController codes (' . count($prestiti) . ')');
$check(count($reservations) >= 8, 'found the ReservationsAdminController codes (' . count($reservations) . ')');

// Emitted only by actions the API never delegates to.
$notDelegated = [
    'loan_not_closable', // legacy close() — the API returns through processReturn()
    'pdf_failed',        // downloadPdf() — the API calls LoanPdfGenerator directly
];
$loanTables = CirculationOutcome::CREATE + CirculationOutcome::UPDATE + CirculationOutcome::RETURN
    + CirculationOutcome::RENEW + CirculationOutcome::BULK;
$unmapped = array_values(array_filter(
    $prestiti,
    static fn (string $c): bool => !isset($loanTables[$c]) && !in_array($c, $notDelegated, true)
));
$check($unmapped === [], 'PrestitiController codes all mapped (unmapped: ' . implode(', ', $unmapped) . ')');
$unmappedRes = array_values(array_filter($reservations, static fn (string $c): bool => !isset(CirculationOutcome::RESERVATION[$c])));
$check($unmappedRes === [], 'ReservationsAdminController codes all mapped (unmapped: ' . implode(', ', $unmappedRes) . ')');

// Eligibility codes travel as `?error=' . $eligibilityError`: read them from LoanEligibility.
$elig = (string) file_get_contents($core . '/Support/LoanEligibility.php');
preg_match_all("/return\\s+'([a-z_]+)'/", $elig, $em);
preg_match_all("/\\?\\s*'([a-z_]+)'\\s*:\\s*'([a-z_]+)'/", $elig, $tern);
$eligCodes = array_values(array_unique(array_merge($em[1], $tern[1], $tern[2])));
$check(in_array('user_suspended', $eligCodes, true) && in_array('card_expired', $eligCodes, true), 'eligibility codes read from LoanEligibility (' . implode(', ', $eligCodes) . ')');
foreach (['CREATE', 'UPDATE', 'RENEW', 'RESERVATION'] as $table) {
    $map = constant(CirculationOutcome::class . '::' . $table);
    $missing = array_values(array_filter($eligCodes, static fn (string $c): bool => !isset($map[$c])));
    $check($missing === [], "eligibility codes mapped in {$table} (missing: " . implode(', ', $missing) . ')');
}

// The processReturn whitelist is the API outcome enum.
$prestitiSrc = (string) file_get_contents($core . '/Controllers/PrestitiController.php');
$check(
    str_contains($prestitiSrc, "\$allowed_status = ['" . implode("', '", CirculationOutcome::RETURN_OUTCOMES) . "'];"),
    'RETURN_OUTCOMES equals the processReturn() whitelist'
);

// ── B. Contract mapping ──────────────────────────────────────────────────────
echo "\nB. Frozen mapping\n";
$decode = static function (\Psr\Http\Message\ResponseInterface $r): array {
    return [$r->getStatusCode(), json_decode((string) $r->getBody(), true)];
};
$expect = [
    ['CREATE', 'user_suspended', 409, 'not_eligible', null, 'user_suspended'],
    ['CREATE', 'card_expired', 409, 'not_eligible', null, 'card_expired'],
    ['CREATE', 'expired_window', 422, 'past_date', 'due_at', null],
    ['CREATE', 'book_not_found', 404, 'not_found', 'book_id', null],
    ['CREATE', 'user_not_found', 404, 'not_found', 'user_id', null],
    ['CREATE', 'copy_not_found', 404, 'not_found', 'copy_code', null],
    ['CREATE', 'copy_wrong_book', 422, 'validation_error', 'copy_code', null],
    ['CREATE', 'duplicate_reservation', 409, 'duplicate', null, 'duplicate_reservation'],
    ['CREATE', 'max_loans_reached', 409, 'max_loans_reached', null, null],
    ['CREATE', 'media_limit_reached', 409, 'media_limit_reached', null, null],
    ['CREATE', 'not_loanable', 409, 'not_loanable', null, null],
    ['UPDATE', 'media_limit_reached', 409, 'media_limit_reached', null, null],
    ['CREATE', 'libro_in_prestito', 409, 'not_available', null, 'libro_in_prestito'],
    ['CREATE', 'duplicate_submission', 500, 'internal_error', null, null],
    ['UPDATE', 'loan_closed', 409, 'loan_closed', null, null],
    ['UPDATE', 'concurrent_retry', 409, 'concurrent_retry', null, null],
    ['UPDATE', 'loan_copy_conflict', 409, 'not_available', null, 'loan_copy_conflict'],
    ['RETURN', 'invalid_penalty', 422, 'validation_error', 'penalty', null],
    ['RETURN', 'not_returnable', 409, 'not_returnable', null, null],
    ['RENEW', 'loan_not_picked_up', 409, 'loan_not_active', null, 'loan_not_picked_up'],
    ['RENEW', 'loan_overdue', 409, 'loan_overdue', null, null],
    ['RENEW', 'max_renewals', 409, 'max_renewals', null, null],
    ['RENEW', 'invalid_date_format', 422, 'validation_error', 'new_due_date', null],
    ['RENEW', 'invalid_renewal_date', 422, 'validation_error', 'new_due_date', null],
    ['RENEW', 'extension_conflicts', 409, 'not_available', null, 'extension_conflicts'],
    ['BULK', 'bulk_extend_conflict', 409, 'not_available', null, 'bulk_extend_conflict'],
    ['BULK', 'bulk_extend_invalid', 422, 'validation_error', 'ids', 'bulk_extend_invalid'],
    ['RESERVATION', 'duplicate', 409, 'duplicate', null, null],
    ['RESERVATION', 'capacity_full', 409, 'not_available', null, 'capacity_full'],
    ['RESERVATION', 'promoted_loan_active', 409, 'conflict', null, 'promoted_loan_active'],
    ['RESERVATION', 'not_found', 404, 'not_found', null, null],
    ['RESERVATION', 'not_loanable', 409, 'not_loanable', null, null],
    ['RESERVATION', 'reservations_disabled', 409, 'reservations_disabled', null, null],
    ['RESERVATION', 'reservation_limit_reached', 409, 'reservation_limit_reached', null, null],
];
foreach ($expect as [$table, $code, $status, $apiCode, $field, $reason]) {
    [$got, $body] = $decode(CirculationOutcome::error(new Response(), constant(CirculationOutcome::class . '::' . $table), $code, 'unit'));
    $ok = $got === $status
        && ($body['error']['code'] ?? null) === $apiCode
        && ($body['meta']['field'] ?? null) === $field
        && ($body['meta']['reason'] ?? null) === $reason
        && is_string($body['error']['message'] ?? null) && $body['error']['message'] !== '';
    $check($ok, "{$table}.{$code} → {$status} {$apiCode}" . ($field ? " field={$field}" : '') . ($reason ? " reason={$reason}" : ''));
}
[$got, $body] = $decode(CirculationOutcome::error(new Response(), CirculationOutcome::CREATE, 'brand_new_core_code', 'unit'));
$check($got === 500 && ($body['error']['code'] ?? null) === 'internal_error', 'an unknown core code is a 500 internal_error (never leaked)');

// ── C. actions hints ─────────────────────────────────────────────────────────
echo "\nC. actions per state\n";
$today = '2026-05-10';
$cases = [
    [['stato' => 'pendente'], ['approve', 'reject', 'edit']],
    [['stato' => 'da_ritirare', 'data_prestito' => '2026-05-10'], ['confirm_pickup', 'cancel_pickup', 'edit', 'receipt']],
    [['stato' => 'prenotato', 'data_prestito' => '2026-05-09'], ['confirm_pickup', 'cancel_pickup', 'edit', 'receipt']],
    [['stato' => 'prenotato', 'data_prestito' => '2026-06-01'], ['edit', 'receipt']],
    [['stato' => 'in_corso', 'data_scadenza' => '2026-05-20', 'renewals' => 0], ['return', 'renew', 'edit', 'receipt', 'email_receipt']],
    [['stato' => 'in_corso', 'data_scadenza' => '2026-05-20', 'renewals' => 3], ['return', 'edit', 'receipt', 'email_receipt']],
    [['stato' => 'in_corso', 'data_scadenza' => '2026-05-01', 'renewals' => 0], ['return', 'edit', 'receipt', 'email_receipt']],
    [['stato' => 'in_ritardo'], ['return', 'edit', 'recall', 'receipt', 'email_receipt']],
];
foreach (['restituito', 'perso', 'danneggiato', 'annullato', 'scaduto'] as $closed) {
    $cases[] = [['stato' => $closed], ['receipt']];
}
foreach ($cases as [$row, $want]) {
    $got = CirculationOutcome::actionsFor($row, 3, $today);
    $check($got === $want, json_encode($row) . ' → ' . implode(',', $got));
}
$check(CirculationOutcome::reservationActionsFor('attiva') === ['edit', 'cancel'], 'reservation attiva → edit, cancel');
$check(CirculationOutcome::reservationActionsFor('annullata') === ['reactivate'], 'reservation annullata → reactivate');
$check(CirculationOutcome::reservationActionsFor('completata') === [], 'reservation completata → none');

// ── D. Approval passthrough ──────────────────────────────────────────────────
echo "\nD. LoanApprovalController status mapping (③)\n";
foreach ([[400, 409, 'conflict'], [409, 409, 'conflict'], [403, 409, 'not_eligible'], [404, 404, 'not_found'], [500, 500, 'internal_error']] as [$core, $status, $code]) {
    [$got, $body] = $decode(CirculationOutcome::approvalError(new Response(), $core, 'Messaggio del core', 'unit'));
    $check($got === $status && ($body['error']['code'] ?? null) === $code, "core {$core} → {$status} {$code}");
    if ($status === 409) {
        $check(($body['error']['message'] ?? null) === 'Messaggio del core', "core {$core} keeps the server message");
    }
}

// The reader's request (POST /reservations → UserActionsController::loan()):
// the status the app gets is the persisted state, not "approved = da_ritirare".
echo "\nD2. Reader request state from the loan() redirect\n";
$check(CirculationOutcome::requestState(['loan_request_success' => '1', 'auto_approved' => '0', 'loan_state' => '']) === 'pendente', 'not auto-approved → pendente');
$check(CirculationOutcome::requestState(['loan_request_success' => '1']) === 'pendente', 'no auto_approved key → pendente');
$check(CirculationOutcome::requestState(['auto_approved' => '1', 'loan_state' => 'da_ritirare']) === 'da_ritirare', 'approved, starts today → da_ritirare');
$check(CirculationOutcome::requestState(['auto_approved' => '1', 'loan_state' => 'prenotato']) === 'prenotato', 'approved, library closed today → prenotato (starts on the next open day)');
$check(CirculationOutcome::requestState(['auto_approved' => '1', 'loan_state' => '']) === 'da_ritirare', 'approved without a state → da_ritirare (fallback)');
$check(CirculationOutcome::requestState(['auto_approved' => '1', 'loan_state' => 'in_corso']) === 'da_ritirare', 'approved with an unexpected state → da_ritirare');
$check(CirculationOutcome::requestState(['auto_approved' => '0', 'loan_state' => 'prenotato']) === 'pendente', 'a state without approval is still pendente');
$openApi = (string) file_get_contents(__DIR__ . '/../storage/plugins/mobile-api/src/Controllers/OpenApiController.php');
$check(str_contains($openApi, "'enum' => ['pendente', 'da_ritirare', 'prenotato']"), 'the OpenAPI status enum of POST /reservations lists prenotato');

// ── E. Health flags ──────────────────────────────────────────────────────────
echo "\nE. staff_* flags only for mounted areas\n";
$Areas = \App\Plugins\MobileApi\Support\StaffAreas::class;
$allTrue = array_fill_keys($Areas::ALL, true);
$flags = $Areas::flags($allTrue);
$check(count($flags) === count($Areas::ALL), 'one flag per contract area');
foreach ($Areas::ALL as $area) {
    $check($flags['staff_' . $area] === in_array($area, $Areas::IMPLEMENTED, true), "staff_{$area} = " . var_export($flags['staff_' . $area], true) . ' (mounted: ' . var_export(in_array($area, $Areas::IMPLEMENTED, true), true) . ')');
}
$check($Areas::flags(['circulation' => false])['staff_circulation'] === false, 'a failed prerequisite (catalogue mode) turns a mounted area off');
$check(array_diff($Areas::IMPLEMENTED, $Areas::ALL) === [], 'every mounted area is a contract area');
$plugin = (string) file_get_contents(__DIR__ . '/../storage/plugins/mobile-api/MobileApiPlugin.php');
$check(substr_count($plugin, "StaffAreas::implemented('circulation')") === 1, 'the circulation routes (loans + reservations) are mounted behind ONE StaffAreas::implemented() check');
$check(str_contains($plugin, 'StaffAreas::implemented($area)'), 'the content areas are mounted through StaffAreas::implemented()');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
