<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\PatronLedgerController;
use App\Plugins\MobileApi\Staff090\Support\LedgerMovementPayload;
use App\Plugins\MobileApi\Staff090\Support\LedgerOutcome;
use App\Plugins\MobileApi\Support\CursorCodec;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Support\Features;
use App\Support\Permissions;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Staff side of the patron ledger (Mobile API 1.10.0, feature `fines`):
 * GET /staff/users/{id}/ledger, POST …/ledger/payments|charges|waivers and
 * GET …/ledger/movements/{movement}/receipt.pdf.
 *
 * Reads come from PatronLedger, the service of the reader record's
 * "Movimenti" section. Writes and the receipt are DELEGATED to
 * PatronLedgerController (the web desk forms), so the permission
 * (loans.manage + borrower scope), the amount rules, the balance check and
 * the idempotent ledger row are the website's. The Idempotency-Key the app
 * sends is mandatory, as the web form's request_key is: it is remembered by
 * StaffIdempotency (replay answers the same movement) and turned into the
 * form's request_key (LedgerOutcome::requestKey), so the ledger itself also
 * refuses to record a retried payment twice.
 */
final class StaffLedgerController
{
    private const PAGE_DEFAULT = 50;
    private const PAGE_MAX = 200;

    public function __construct(private mysqli $db)
    {
    }

    // ─── GET /staff/users/{id}/ledger ───────────────────────────────────────

    public function show(ServerRequestInterface $request, ResponseInterface $response, int $userId): ResponseInterface
    {
        try {
            if (!$this->userInScope($userId)) {
                return $this->userNotFound($response);
            }
            $ledger = PatronLedger::forDb($this->db);
            $params = FineParameters::forUser($this->db, $userId);
            $balance = $ledger->balanceCents($userId);
            $canManage = Permissions::can('loans.manage');

            // Paged like /me/movements: the cursor pins the newest row of the
            // first page (`u`), so movements recorded meanwhile never shift
            // the offsets of the following pages.
            $limit = StaffPaging::limit($request, self::PAGE_DEFAULT, self::PAGE_MAX);
            $offset = StaffPaging::offset($request);
            $rawCursor = $request->getQueryParams()['cursor'] ?? null;
            $cursor = CursorCodec::decode(is_string($rawCursor) ? $rawCursor : null);
            $upTo = is_array($cursor) && is_int($cursor['u'] ?? null) && $cursor['u'] >= 0
                ? $cursor['u']
                : $ledger->latestMovementId($userId);
            $rows = $ledger->movements($userId, $limit + 1, $offset, $upTo);
            $hasMore = count($rows) > $limit;
            $items = array_map(
                static fn (array $row): array => LedgerMovementPayload::staffItem($row, $canManage),
                array_slice($rows, 0, $limit)
            );
            $accrued = array_map(static fn (array $a): array => [
                'loan_id'      => $a['loan_id'],
                'title'        => $a['titolo'],
                'due_at'       => $a['data_scadenza'],
                'days_late'    => $a['days'],
                'amount_cents' => $a['cents'],
            ], $ledger->accruedFines($userId));
            $threshold = Features::enabled('debt_block') ? $params->debtBlockThresholdCents : null;

            $meta = StaffPaging::meta(count($items), $limit, $offset, $hasMore, $ledger->movementCount($userId, $upTo));
            $meta['next_cursor'] = $hasMore ? CursorCodec::encode(['o' => $offset + $limit, 'u' => $upTo]) : null;

            return ResponseEnvelope::success($response, [
                'user_id'                    => $userId,
                'currency'                   => $params->currency,
                'balance_cents'              => $balance,
                'balance'                    => FineParameters::centsToDecimal($balance),
                'accrued_cents'              => array_sum(array_column($accrued, 'amount_cents')),
                'accrued'                    => $accrued,
                'debt_block_threshold_cents' => $threshold,
                'debt_blocked'               => PatronLedger::isDebtBlocked($this->db, $userId),
                'automatic_fines'            => Features::enabled('automatic_fines'),
                'payment_methods'            => array_map(static fn (string $m): array => [
                    'code' => $m, 'label' => PatronLedger::methodLabel($m),
                ], PatronLedger::PAYMENT_METHODS),
                'max_amount_cents'           => PatronLedger::MAX_AMOUNT_CENTS,
                'can_record_payment'         => $canManage,
                'can_charge'                 => $canManage,
                'can_waive'                  => $canManage,
                'items'                      => $items,
            ], $meta)->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'ledger read', $e);
        }
    }

    // ─── Writes ─────────────────────────────────────────────────────────────

    public function payment(ServerRequestInterface $request, ResponseInterface $response, int $userId): ResponseInterface
    {
        return $this->write($request, $response, $userId, 'payment');
    }

    public function charge(ServerRequestInterface $request, ResponseInterface $response, int $userId): ResponseInterface
    {
        return $this->write($request, $response, $userId, 'charge');
    }

    public function waiver(ServerRequestInterface $request, ResponseInterface $response, int $userId): ResponseInterface
    {
        return $this->write($request, $response, $userId, 'waiver');
    }

    private function write(ServerRequestInterface $request, ResponseInterface $response, int $userId, string $action): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $key = StaffIdempotency::keyFrom($request, $json);
        if ($key === false) {
            return $this->invalid($response, 'idempotency_key', __('Chiave di idempotenza non valida.'));
        }
        if ($key === null) {
            return $this->invalid($response, 'idempotency_key', __('Chiave di idempotenza obbligatoria: invia l\'header Idempotency-Key.'));
        }
        $cents = self::cents($json['amount_cents'] ?? null);
        if ($cents === null) {
            return $this->invalid($response, 'amount_cents', PatronLedgerController::errorMessage('invalid_amount'));
        }

        $form = ['importo' => FineParameters::centsToDecimal($cents)];
        $payload = ['user_id' => $userId, 'action' => $action, 'amount_cents' => $cents];
        if ($action === 'payment') {
            $method = $json['method'] ?? null;
            if (!is_string($method) || !in_array($method, PatronLedger::PAYMENT_METHODS, true)) {
                return $this->invalid($response, 'method', PatronLedgerController::errorMessage('invalid_method'));
            }
            $note = StaffPaging::text($json['note'] ?? null, 255) ?? '';
            $form += ['metodo_pagamento' => $method, 'note' => $note];
            $payload += ['method' => $method, 'note' => $note];
        } elseif ($action === 'charge') {
            $description = StaffPaging::text($json['description'] ?? null, 255) ?? '';
            if ($description === '') {
                return $this->invalid($response, 'description', PatronLedgerController::errorMessage('description_required'));
            }
            $form['descrizione'] = $description;
            $payload['description'] = $description;
        } else {
            $reason = StaffPaging::text($json['reason'] ?? null, 255) ?? '';
            if ($reason === '') {
                return $this->invalid($response, 'reason', PatronLedgerController::errorMessage('reason_required'));
            }
            $form['motivazione'] = $reason;
            $payload['reason'] = $reason;
        }

        $staffId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $form['request_key'] = LedgerOutcome::requestKey($key, $staffId, $userId, $action);

        try {
            if (!$this->userInScope($userId)) {
                return $this->userNotFound($response);
            }
            $db = $this->db;

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'ledger_' . $action,
                $staffId,
                $userId,
                $payload,
                function () use ($db, $response, $userId, $action, $form): ResponseInterface|int {
                    $outcome = Delegation::run(static function () use ($db, $userId, $action, $form): ResponseInterface {
                        $path = '/admin/users/' . $userId . '/ledger/' . match ($action) {
                            'payment' => 'payment',
                            'charge'  => 'charge',
                            default   => 'waive',
                        };
                        $request = Delegation::request('POST', $path, $form);
                        $controller = new PatronLedgerController();

                        return match ($action) {
                            'payment' => $controller->payment($request, new SlimResponse(), $db, $userId),
                            'charge'  => $controller->charge($request, new SlimResponse(), $db, $userId),
                            default   => $controller->waive($request, new SlimResponse(), $db, $userId),
                        };
                    });
                    if ($outcome->status === 403) {
                        return ResponseEnvelope::error($response, 'forbidden', __('Accesso negato.'), 403);
                    }
                    $error = $outcome->queryValue('ledger_error');
                    if ($error !== null) {
                        return $this->ledgerError($response, $error, $userId);
                    }
                    $movementId = (int) ($outcome->queryValue('movement') ?? 0);
                    if ($outcome->queryValue('ledger') === null || $movementId <= 0) {
                        throw new \RuntimeException('unexpected ledger outcome HTTP ' . $outcome->status . ' ' . $outcome->location);
                    }

                    return $movementId;
                },
                fn (int $movementId): ?array => $this->writeResult($userId, $movementId)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'ledger ' . $action, $e);
        }
    }

    // ─── GET …/ledger/movements/{movement}/receipt.pdf ─────────────────────

    public function receipt(ServerRequestInterface $request, ResponseInterface $response, int $userId, int $movementId): ResponseInterface
    {
        try {
            if (!$this->userInScope($userId)) {
                return $this->userNotFound($response);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronLedgerController())->receipt(
                Delegation::request('GET', '/admin/users/' . $userId . '/ledger/receipt/' . $movementId),
                new SlimResponse(),
                $db,
                $userId,
                $movementId
            ));
            if ($outcome->status === 403) {
                return ResponseEnvelope::error($response, 'forbidden', __('Accesso negato.'), 403);
            }
            if ($outcome->status === 404) {
                return ResponseEnvelope::error($response, 'not_found', __('Pagamento non trovato.'), 404);
            }
            if ($outcome->status !== 200 || $outcome->body === '') {
                throw new \RuntimeException('receipt HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition'))
                ->withHeader('Content-Length', (string) strlen($outcome->body))
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'ledger receipt', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * The movement just written (or replayed) and the patron's balance after it.
     *
     * @return array<string, mixed>|null
     */
    private function writeResult(int $userId, int $movementId): ?array
    {
        $ledger = PatronLedger::forDb($this->db);
        $row = $ledger->movement($movementId);
        if ($row === null || (int) $row['utente_id'] !== $userId) {
            return null;
        }
        $balance = $ledger->balanceCents($userId);

        return [
            'movement'      => LedgerMovementPayload::staffItem($row, Permissions::can('loans.manage')),
            'user_id'       => $userId,
            'currency'      => FineParameters::forUser($this->db, $userId)->currency,
            'balance_cents' => $balance,
            'balance'       => FineParameters::centsToDecimal($balance),
            'debt_blocked'  => PatronLedger::isDebtBlocked($this->db, $userId),
        ];
    }

    private function ledgerError(ResponseInterface $response, string $code, int $userId): ResponseInterface
    {
        [$status, $apiCode, $field] = LedgerOutcome::error($code);
        $message = PatronLedgerController::errorMessage($code);
        if ($status >= 500) {
            SecureLogger::error('[MobileApi] staff ledger write: core outcome "' . $code . '"');
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
        $meta = ['reason' => $code];
        if ($field !== null) {
            $meta['field'] = $field;
            $meta['fields'] = [$field => $message];
        }
        if ($code === 'amount_exceeds_balance') {
            $meta['balance_cents'] = PatronLedger::forDb($this->db)->balanceCents($userId);
        }

        return ResponseEnvelope::error($response, $apiCode, $message, $status, $meta);
    }

    /** A positive whole number of cents up to PatronLedger::MAX_AMOUNT_CENTS, else null. */
    private static function cents(mixed $raw): ?int
    {
        if (is_string($raw) && preg_match('/^[0-9]{1,9}$/D', $raw) === 1) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw <= 0 || $raw > PatronLedger::MAX_AMOUNT_CENTS) {
            return null;
        }

        return $raw;
    }

    /**
     * The account exists and is one the loan desk may act on (the borrower
     * scope of PatronLedgerController::mayActOn(); Admin and Staff reach all).
     */
    private function userInScope(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM utenti u WHERE u.id = ?'
            . Permissions::accountScopeSql('u', Permissions::SCOPE_BORROWER) . ' LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('user probe prepare failed');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    private function userNotFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Utente non trovato'), 404);
    }

    private function invalid(ResponseInterface $response, string $field, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation', $message, 422, ['field' => $field, 'fields' => [$field => $message]]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
