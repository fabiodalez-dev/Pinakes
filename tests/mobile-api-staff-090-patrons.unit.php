<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 — pure logic of the patron tools / staff ledger area.
 *
 *   A. LedgerOutcome::requestKey(): the web form's request_key derived from
 *      the app's Idempotency-Key is 32 lowercase hex (what
 *      PatronLedgerController accepts), deterministic, and distinct per
 *      action, operator, patron and client key.
 *   B. LedgerOutcome::ERRORS maps every ledger error code the core can put in
 *      `?ledger_error=` (PatronLedgerController::errorMessage() arms and the
 *      codes PatronLedger throws), so none becomes an unmapped 500.
 *   C. LedgerMovementPayload: the staff item is the reader item (/me/movements)
 *      plus the desk fields, with the signed cents and the receipt flag.
 *
 * Run: php tests/mobile-api-staff-090-patrons.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src/Staff090/Support';
require_once $plugin . '/LedgerOutcome.php';
require_once $plugin . '/LedgerMovementPayload.php';

use App\Plugins\MobileApi\Staff090\Support\LedgerMovementPayload;
use App\Plugins\MobileApi\Staff090\Support\LedgerOutcome;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. Request key ────────────────────────────────────────────────────────────
echo "A. Derived request_key\n";
$key = LedgerOutcome::requestKey('abc-123', 7, 42, 'payment');
$check(preg_match('/^[a-f0-9]{32}$/', $key) === 1, 'request_key is 32 lowercase hex (PatronLedgerController::requestKey accepts it)');
$check($key === LedgerOutcome::requestKey('abc-123', 7, 42, 'payment'), 'deterministic: a retry derives the same key');
$check($key !== LedgerOutcome::requestKey('abc-123', 7, 42, 'charge'), 'differs per action');
$check($key !== LedgerOutcome::requestKey('abc-123', 8, 42, 'payment'), 'differs per operator');
$check($key !== LedgerOutcome::requestKey('abc-123', 7, 43, 'payment'), 'differs per patron');
$check($key !== LedgerOutcome::requestKey('abc-124', 7, 42, 'payment'), 'differs per client key');
$threw = false;
try {
    LedgerOutcome::requestKey('abc', 7, 42, 'refund');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
$check($threw, 'an unknown action is refused');

// ── B. Error mapping ─────────────────────────────────────────────────────────
echo "B. Every core ledger error code is mapped\n";
$controller = (string) file_get_contents(__DIR__ . '/../app/Controllers/PatronLedgerController.php');
$service = (string) file_get_contents(__DIR__ . '/../app/Services/PatronLedger.php');
preg_match('/function errorMessage\(.*?\n    \}/s', $controller, $fn);
preg_match_all("/'([a-z_]+)' =>/", $fn[0] ?? '', $arms);
preg_match_all("/InvalidArgumentException\('([a-z_]+)'\)/", $service, $thrown);
preg_match_all("/'ledger_error' => '([a-z_]+)'/", $controller, $literal);
$codes = array_unique(array_merge($arms[1], $thrown[1], $literal[1], ['save_failed']));
$check(count($arms[1]) >= 8, 'errorMessage() arms found in the controller (' . count($arms[1]) . ')');
foreach ($codes as $code) {
    if ($code === 'invalid_type') {
        continue; // internal: the API never sends a movement type
    }
    $check(isset(LedgerOutcome::ERRORS[$code]), "mapped: {$code}");
}
$check(LedgerOutcome::error('amount_exceeds_balance') === [409, 'amount_exceeds_balance', null], 'over the balance is a 409 state conflict');
$check(LedgerOutcome::error('duplicate_request')[1] === 'idempotency_conflict', 'same key, other values → idempotency_conflict');
$check(LedgerOutcome::error('something_new') === [500, 'internal_error', null], 'an unknown code is a 500');
foreach (LedgerOutcome::ERRORS as $code => [$status, $api, $field]) {
    if ($status === 422) {
        $check($field !== null, "422 {$code} names its field");
    }
}

// ── C. Movement payload ──────────────────────────────────────────────────────
echo "C. Movement payload\n";
$row = [
    'id' => '15', 'utente_id' => '3', 'prestito_id' => null, 'tipo' => 'pagamento', 'importo' => '-12.50',
    'descrizione' => ' Desk ', 'metodo_pagamento' => 'pos', 'creato_da' => '9', 'operatore' => 'Ada Lovelace',
    'created_at' => '2026-11-01 10:00:00', 'libro_titolo' => null,
];
$item = LedgerMovementPayload::item($row);
$check(array_keys($item) === ['id', 'type', 'type_label', 'amount_cents', 'amount', 'description', 'payment_method', 'loan_id', 'book_title', 'created_at'], 'reader item keys (the /me/movements shape)');
$check($item['id'] === 15 && $item['amount_cents'] === -1250 && $item['amount'] === '-12.50', 'signed cents and decimal');
$check($item['payment_method'] === 'pos' && $item['loan_id'] === null && $item['book_title'] === null, 'nullable fields');
$staff = LedgerMovementPayload::staffItem($row, true);
$check(array_slice($staff, 0, count($item), true) === $item, 'staff item starts with the reader item');
$check($staff['operator'] === ['id' => 9, 'name' => 'Ada Lovelace'], 'operator id and name');
$check($staff['receipt_available'] === true && is_string($staff['payment_method_label']) && $staff['payment_method_label'] !== '', 'a payment has a receipt and a method label');
$check(LedgerMovementPayload::staffItem($row, false)['receipt_available'] === false, 'no receipt without loans.manage');
$charge = LedgerMovementPayload::staffItem(['tipo' => 'multa_ritardo', 'descrizione' => '4|late', 'importo' => '2.00', 'prestito_id' => '8', 'creato_da' => null] + $row, true);
$check($charge['receipt_available'] === false && $charge['operator'] === null && $charge['loan_id'] === 8 && $charge['payment_method_label'] === 'POS', 'a charge: no receipt, no operator, loan id');
$check(str_contains($charge['description'], '4'), 'a late fine is described by its days (PatronLedger::describe)');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
