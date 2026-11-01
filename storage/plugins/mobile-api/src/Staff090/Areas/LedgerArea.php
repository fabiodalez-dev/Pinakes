<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffLedgerController;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * Staff side of the patron ledger (fines module): balance, movements,
 * payments, manual charges, waivers and payment receipts. Health flag
 * `staff_ledger` = features.fines.
 */
final class LedgerArea implements StaffArea
{
    public static function area(): string
    {
        return 'ledger';
    }

    public static function feature(): string
    {
        return 'fines';
    }

    public static function circulation(): bool
    {
        return true;
    }

    public static function routes(): array
    {
        $c = static fn (\mysqli $db): StaffLedgerController => new StaffLedgerController($db);

        return [
            ['GET', '/staff/users/{id:[0-9]+}/ledger', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->show($q, $r, (int) $a['id'])],
            ['POST', '/staff/users/{id:[0-9]+}/ledger/payments', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->payment($q, $r, (int) $a['id'])],
            ['POST', '/staff/users/{id:[0-9]+}/ledger/charges', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->charge($q, $r, (int) $a['id'])],
            ['POST', '/staff/users/{id:[0-9]+}/ledger/waivers', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->waiver($q, $r, (int) $a['id'])],
            ['GET', '/staff/users/{id:[0-9]+}/ledger/movements/{movement:[0-9]+}/receipt.pdf', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->receipt($q, $r, (int) $a['id'], (int) $a['movement']), 'limit' => [30, 60, 'staff_ledger_receipt']],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-ledger';
        $doc['tags'][] = ['name' => $t, 'description' => 'Patron ledger at the desk (fines module): balance, movements, payments, manual charges, waivers, payment receipts. Every route answers 404 feature_disabled while features.fines is off, and in catalogue mode.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_ledger'] = [
            'type' => 'boolean', 'description' => 'The /staff/users/{id}/ledger routes are available (= features.fines; Mobile API 1.10.0).',
        ];

        $i = ['type' => 'integer'];
        $s = ['type' => 'string'];
        $b = ['type' => 'boolean'];
        $ns = O::nullable('string');
        $doc['components']['schemas']['LedgerMovement'] = O::object([
            'id' => $i,
            'type' => ['type' => 'string', 'enum' => ['multa_ritardo', 'addebito_perdita', 'addebito_danno', 'addebito_manuale', 'pagamento', 'condono', 'storno']],
            'type_label' => $s,
            'amount_cents' => ['type' => 'integer', 'description' => 'Signed: positive = charge (the patron owes more), negative = payment / waiver / reversal.'],
            'amount' => ['type' => 'string', 'description' => 'Same amount as a decimal string ("-12.50").'],
            'description' => $s,
            'payment_method' => ['type' => ['string', 'null'], 'enum' => ['contanti', 'pos', 'bonifico', 'altro', null]],
            'loan_id' => O::nullable('integer'),
            'book_title' => $ns,
            'created_at' => ['type' => 'string', 'description' => 'Y-m-d H:i:s, library time.'],
        ]);
        $doc['components']['schemas']['StaffLedgerMovement'] = ['allOf' => [O::ref('LedgerMovement'), O::object([
            'payment_method_label' => $ns,
            'operator' => ['oneOf' => [['type' => 'null'], O::object(['id' => $i, 'name' => $s])]],
            'receipt_available' => ['type' => 'boolean', 'description' => 'A payment: GET …/ledger/movements/{id}/receipt.pdf answers its receipt.'],
        ])], 'description' => 'LedgerMovement (the /me/movements item) plus the desk-only fields.'];
        $doc['components']['schemas']['StaffLedgerWriteResult'] = O::object([
            'movement' => O::ref('StaffLedgerMovement'),
            'user_id' => $i, 'currency' => $s, 'balance_cents' => $i, 'balance' => $s, 'debt_blocked' => $b,
        ]);

        $userId = O::path('id', 'User id');
        $idem = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
            'description' => 'REQUIRED (1..128 of A-Z a-z 0-9 . _ : -), stable across retries of the same desk operation; also accepted as body `idempotency_key`. Same key + same body → 200 with the original movement (meta.replayed=true), nothing is recorded twice; same key + different body → 422 `idempotency_conflict`.',
            'schema' => ['type' => 'string', 'maxLength' => 128]];
        $writeResponses = [
            '201' => O::ok('Recorded. The movement and the balance after it.', O::ref('StaffLedgerWriteResult')),
            '200' => O::ok('Replay of the same Idempotency-Key and body (meta.replayed=true): the original movement, balance as it is now.', O::ref('StaffLedgerWriteResult')),
            '422' => O::err('`validation` (meta.field / meta.fields: amount_cents, method, description, reason, idempotency_key) or `idempotency_conflict`.'),
        ] + O::notFound();
        $amount = ['type' => 'integer', 'minimum' => 1, 'maximum' => 99999999, 'description' => 'Whole cents, > 0.'];
        $idemBody = ['type' => 'string', 'maxLength' => 128, 'description' => 'Alternative to the Idempotency-Key header.'];

        $doc['paths']['/staff/users/{id}/ledger'] = ['get' => O::op($t, 'staffUserLedger', 'Balance, accruing fines and movements of a patron (newest first)', [
            '200' => O::ok('Ledger. meta = {count, limit, next_cursor, has_more, total_count}; the cursor pins the first page\'s newest row, so later pages never repeat or skip a movement.', O::object([
                'user_id' => $i, 'currency' => $s,
                'balance_cents' => ['type' => 'integer', 'description' => 'Positive = the patron owes money.'], 'balance' => $s,
                'accrued_cents' => ['type' => 'integer', 'description' => 'Sum of `accrued`.'],
                'accrued' => ['type' => 'array', 'description' => 'Late fines accruing on open overdue loans, computed for today and NOT yet written (they are charged at return). Empty when automatic fines are off.',
                    'items' => O::object(['loan_id' => $i, 'title' => $s, 'due_at' => ['type' => 'string', 'format' => 'date'], 'days_late' => $i, 'amount_cents' => $i])],
                'debt_block_threshold_cents' => ['type' => ['integer', 'null'], 'description' => 'Null when the debt block is off or the patron type sets no threshold.'],
                'debt_blocked' => ['type' => 'boolean', 'description' => 'Loans and reservations are refused for debt (the circulation rule).'],
                'automatic_fines' => $b,
                'payment_methods' => ['type' => 'array', 'items' => O::object(['code' => ['type' => 'string', 'enum' => ['contanti', 'pos', 'bonifico', 'altro']], 'label' => $s])],
                'max_amount_cents' => $i,
                'can_record_payment' => $b, 'can_charge' => $b, 'can_waive' => $b,
                'items' => O::listOf('StaffLedgerMovement'),
            ])),
        ] + O::notFound(), ['parameters' => array_merge([$userId], [
            O::query('cursor', 'string', 'Opaque cursor from meta.next_cursor.'),
            O::query('limit', 'integer', '1..200, default 50.'),
        ])])];
        $doc['paths']['/staff/users/{id}/ledger/payments'] = ['post' => O::op($t, 'staffLedgerPayment', 'Record a payment received at the desk', $writeResponses + O::conflict('`amount_exceeds_balance`: more than the patron owes (meta.balance_cents); `concurrent_retry` (retry).'), [
            'parameters' => [$userId, $idem],
            'requestBody' => O::inlineBody(['amount_cents' => $amount, 'method' => ['type' => 'string', 'enum' => ['contanti', 'pos', 'bonifico', 'altro']], 'note' => ['type' => 'string', 'maxLength' => 255], 'idempotency_key' => $idemBody], ['amount_cents', 'method']),
            'description' => 'Same rules as the reader record form: never more than the current balance.',
        ])];
        $doc['paths']['/staff/users/{id}/ledger/charges'] = ['post' => O::op($t, 'staffLedgerCharge', 'Add a manual charge', $writeResponses + O::conflict('`concurrent_retry` (retry).'), [
            'parameters' => [$userId, $idem],
            'requestBody' => O::inlineBody(['amount_cents' => $amount, 'description' => ['type' => 'string', 'maxLength' => 255], 'idempotency_key' => $idemBody], ['amount_cents', 'description']),
        ])];
        $doc['paths']['/staff/users/{id}/ledger/waivers'] = ['post' => O::op($t, 'staffLedgerWaiver', 'Waive part or all of the debt', $writeResponses + O::conflict('`amount_exceeds_balance` (meta.balance_cents); `concurrent_retry` (retry).'), [
            'parameters' => [$userId, $idem],
            'requestBody' => O::inlineBody(['amount_cents' => $amount, 'reason' => ['type' => 'string', 'maxLength' => 255], 'idempotency_key' => $idemBody], ['amount_cents', 'reason']),
        ])];
        $doc['paths']['/staff/users/{id}/ledger/movements/{movement}/receipt.pdf'] = ['get' => O::op($t, 'staffLedgerReceipt', 'Receipt PDF of a payment (30/min)', [
            '200' => O::file('application/pdf', 'Content-Disposition: attachment; filename="ricevuta_pagamento_{movement, 6 digits}.pdf".'),
        ] + O::notFound(), ['parameters' => [$userId, O::path('movement', 'Movement id (a payment of this patron; anything else is 404)')]])];

        return $doc;
    }
}
