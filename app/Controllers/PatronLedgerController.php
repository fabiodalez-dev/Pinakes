<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "Movimenti" section of the reader record (plan §7): payments, manual charges,
 * waivers and the payment receipt. Same permission as circulation (loans.manage).
 */
class PatronLedgerController
{
    public function payment(Request $request, Response $response, mysqli $db, int $userId): Response
    {
        return $this->write($request, $response, $db, $userId, function (PatronLedger $ledger, array $data, int $cents, ?int $staffId) use ($userId): int {
            $method = is_scalar($data['metodo_pagamento'] ?? null) ? (string) $data['metodo_pagamento'] : '';
            $note = trim(is_scalar($data['note'] ?? null) ? (string) $data['note'] : '');
            return $ledger->recordPayment($userId, $cents, $method, $note !== '' ? $note : null, $staffId, self::requestKey($data));
        }, 'paid');
    }

    public function charge(Request $request, Response $response, mysqli $db, int $userId): Response
    {
        return $this->write($request, $response, $db, $userId, function (PatronLedger $ledger, array $data, int $cents, ?int $staffId) use ($userId): int {
            $description = is_scalar($data['descrizione'] ?? null) ? (string) $data['descrizione'] : '';
            return $ledger->manualCharge($userId, $cents, $description, $staffId, self::requestKey($data));
        }, 'charged');
    }

    public function waive(Request $request, Response $response, mysqli $db, int $userId): Response
    {
        return $this->write($request, $response, $db, $userId, function (PatronLedger $ledger, array $data, int $cents, ?int $staffId) use ($userId): int {
            $reason = is_scalar($data['motivazione'] ?? null) ? (string) $data['motivazione'] : '';
            return $ledger->waive($userId, $cents, $reason, $staffId, null, self::requestKey($data));
        }, 'waived');
    }

    public function receipt(Request $request, Response $response, mysqli $db, int $userId, int $movementId): Response
    {
        if (!\App\Support\Permissions::can('loans.manage') || !$this->mayActOn($db, $userId)) {
            return $response->withStatus(403);
        }
        try {
            $pdf = (new \App\Support\PaymentReceiptPdf($db))->generateForPayment($userId, $movementId);
        } catch (\Throwable $e) {
            // Only a payment that is not there is a 404; anything else (the
            // database, the PDF library) is a server error, logged.
            if ($e->getMessage() === 'payment_not_found') {
                return $response->withStatus(404);
            }
            SecureLogger::error('Payment receipt PDF failed', ['movement_id' => $movementId, 'error' => $e->getMessage()]);
            return $response->withStatus(500);
        }
        $filename = sprintf('ricevuta_pagamento_%06d.pdf', $movementId);
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Content-Length', (string) strlen($pdf))
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * @param callable(PatronLedger, array<string, mixed>, int, ?int): int $operation
     */
    private function write(Request $request, Response $response, mysqli $db, int $userId, callable $operation, string $okFlag): Response
    {
        if (!\App\Support\Permissions::can('loans.manage') || !$this->mayActOn($db, $userId)) {
            return $response->withStatus(403);
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        // The reader record needs users.manage: a loan-desk role without it
        // lands on the loans page, with the outcome as a flash message.
        $base = \App\Support\Permissions::can('users.manage')
            ? url('/admin/users/details/' . $userId)
            : null;
        $amountRaw = is_scalar($data['importo'] ?? null) ? (string) $data['importo'] : '';
        $cents = FineParameters::toCents($amountRaw);
        if ($cents === null || $cents <= 0) {
            return $this->back($response, $base, ['ledger_error' => 'invalid_amount']);
        }
        $staffId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
        try {
            $movementId = $operation(PatronLedger::forDb($db), $data, $cents, $staffId);
        } catch (\InvalidArgumentException $e) {
            return $this->back($response, $base, ['ledger_error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            SecureLogger::error('Patron ledger write failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->back($response, $base, ['ledger_error' => 'save_failed']);
        }
        return $this->back($response, $base, ['ledger' => $okFlag, 'movement' => (string) $movementId]);
    }

    /**
     * The idempotency key of one rendered form (hidden request_key, 32 hex),
     * or null when absent or malformed.
     *
     * @param array<string, mixed> $data
     */
    private static function requestKey(array $data): ?string
    {
        $raw = is_scalar($data['request_key'] ?? null) ? (string) $data['request_key'] : '';
        return preg_match('/^[a-f0-9]{32}$/', $raw) === 1 ? 'form:' . $raw : null;
    }

    /** @param array<string, string> $params */
    private function back(Response $response, ?string $base, array $params): Response
    {
        if ($base === null) {
            if (isset($params['ledger_error'])) {
                $_SESSION['error_message'] = self::errorMessage($params['ledger_error']);
            } else {
                $_SESSION['success_message'] = match ($params['ledger'] ?? '') {
                    'paid' => __('Pagamento registrato.'),
                    'charged' => __('Addebito registrato.'),
                    default => __('Condono registrato.'),
                };
            }
            return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
        }
        return $response->withHeader('Location', $base . '?' . http_build_query($params) . '#movimenti')->withStatus(302);
    }

    /**
     * Whether the signed-in operator may move money on $userId's account: the
     * same borrower scope as the loan desk (Permissions::accountScopeSql), so a
     * custom role reaches reader accounts only, never an administrator or a
     * Staff account. Administrators and Staff keep every account.
     */
    private function mayActOn(mysqli $db, int $userId): bool
    {
        $scope = \App\Support\Permissions::accountScopeSql('u', \App\Support\Permissions::SCOPE_BORROWER);
        if ($scope === '') {
            return true;
        }
        $stmt = $db->prepare('SELECT 1 FROM utenti u WHERE u.id = ?' . $scope);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    /** Operator-facing message for a ledger error code. */
    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'invalid_amount' => __('Importo non valido.'),
            'amount_exceeds_balance' => __("L'importo supera il saldo a debito del lettore."),
            'reason_required' => __('La motivazione del condono è obbligatoria.'),
            'description_required' => __("La descrizione dell'addebito è obbligatoria."),
            'invalid_method' => __('Metodo di pagamento non valido.'),
            'duplicate_request' => __('Richiesta già registrata: ricarica la pagina e riprova.'),
            'user_not_found' => __('Utente non trovato'),
            default => __('Operazione non riuscita. Riprova.'),
        };
    }
}
