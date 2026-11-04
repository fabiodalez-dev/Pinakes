<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

/**
 * Pure helpers of the staff ledger writes (Mobile API 1.10.0).
 *
 * requestKey(): the web desk forms carry a one-time `request_key` (32 hex)
 * that PatronLedger stores as `form:<key>` (unique), so a resent form never
 * moves money twice. The app sends an Idempotency-Key instead; the request
 * key is derived from it, the operator, the patron and the action, so the
 * same retry always lands on the same ledger row even after the 24 h
 * StaffIdempotency memory has expired, while two operators (or two patrons)
 * using the same client key never collide.
 *
 * error(): PatronLedgerController's `?ledger_error=<code>` mapped to the API
 * envelope: [http status, api code, field|null].
 */
final class LedgerOutcome
{
    public const ACTIONS = ['payment', 'charge', 'waiver'];

    /** @var array<string, array{0:int, 1:string, 2:?string}> */
    public const ERRORS = [
        'invalid_amount'         => [422, 'validation', 'amount_cents'],
        'invalid_method'         => [422, 'validation', 'method'],
        'reason_required'        => [422, 'validation', 'reason'],
        'description_required'   => [422, 'validation', 'description'],
        'duplicate_request'      => [422, 'idempotency_conflict', 'idempotency_key'],
        'missing_request_key'    => [422, 'validation', 'idempotency_key'],
        'amount_exceeds_balance' => [409, 'amount_exceeds_balance', null],
        'user_not_found'         => [404, 'not_found', null],
        'save_failed'            => [500, 'internal_error', null],
    ];

    private function __construct()
    {
    }

    public static function requestKey(string $idempotencyKey, int $operatorId, int $userId, string $action): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('unknown ledger action ' . $action);
        }

        return substr(hash('sha256', 'mobile-api|ledger|' . $action . '|' . $operatorId . '|' . $userId . '|' . $idempotencyKey), 0, 32);
    }

    /** @return array{0:int, 1:string, 2:?string} */
    public static function error(string $coreCode): array
    {
        return self::ERRORS[$coreCode] ?? [500, 'internal_error', null];
    }
}
