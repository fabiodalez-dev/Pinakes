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
            return $ledger->recordPayment($userId, $cents, $method, $note !== '' ? $note : null, $staffId);
        }, 'paid');
    }

    public function charge(Request $request, Response $response, mysqli $db, int $userId): Response
    {
        return $this->write($request, $response, $db, $userId, function (PatronLedger $ledger, array $data, int $cents, ?int $staffId) use ($userId): int {
            $description = is_scalar($data['descrizione'] ?? null) ? (string) $data['descrizione'] : '';
            return $ledger->manualCharge($userId, $cents, $description, $staffId);
        }, 'charged');
    }

    public function waive(Request $request, Response $response, mysqli $db, int $userId): Response
    {
        return $this->write($request, $response, $db, $userId, function (PatronLedger $ledger, array $data, int $cents, ?int $staffId) use ($userId): int {
            $reason = is_scalar($data['motivazione'] ?? null) ? (string) $data['motivazione'] : '';
            return $ledger->waive($userId, $cents, $reason, $staffId);
        }, 'waived');
    }

    public function receipt(Request $request, Response $response, mysqli $db, int $userId, int $movementId): Response
    {
        if (!\App\Support\Permissions::can('loans.manage')) {
            return $response->withStatus(403);
        }
        try {
            $pdf = (new \App\Support\PaymentReceiptPdf($db))->generateForPayment($userId, $movementId);
        } catch (\Throwable $e) {
            if ($e->getMessage() !== 'payment_not_found') {
                SecureLogger::error('Payment receipt PDF failed', ['movement_id' => $movementId, 'error' => $e->getMessage()]);
            }
            return $response->withStatus(404);
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
        if (!\App\Support\Permissions::can('loans.manage')) {
            return $response->withStatus(403);
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $base = url('/admin/users/details/' . $userId);
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

    /** @param array<string, string> $params */
    private function back(Response $response, string $base, array $params): Response
    {
        return $response->withHeader('Location', $base . '?' . http_build_query($params) . '#movimenti')->withStatus(302);
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
            'user_not_found' => __('Utente non trovato'),
            default => __('Operazione non riuscita. Riprova.'),
        };
    }
}
