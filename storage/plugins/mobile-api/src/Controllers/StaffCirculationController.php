<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Plugins\MobileApi\Support\CirculationOutcome;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffCopyReader;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Staff circulation desk for the companion app (Mobile API 1.7.0):
 * /staff/circulation/summary, /staff/loans*, /staff/users*,
 * /staff/copies/by-code, /staff/books/{id}/queue|availability.
 *
 * Every write is DELEGATED in-process to the web controllers
 * (PrestitiController, LoanApprovalController) with the same form body the
 * admin pages post, so locks, eligibility, capacity, queue promotion, the
 * activity log (operator = the token user, mirrored into the session by
 * AppAuthMiddleware) and the emails are exactly those of the website. The
 * core outcome (302 `?error=<code>` or JSON + status) is mapped by
 * CirculationOutcome; reads come from StaffLoanReader.
 */
final class StaffCirculationController
{
    private StaffLoanReader $reader;

    public function __construct(private mysqli $db)
    {
        $this->reader = new StaffLoanReader($db);
    }

    // ─── Summary / lists ────────────────────────────────────────────────────

    public function summary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return ResponseEnvelope::success($response, $this->reader->summary())
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'circulation summary', $e);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $bucket = StaffPaging::query($request, 'bucket');
        $bucket = $bucket === '' ? 'all' : $bucket;
        if (!in_array($bucket, StaffLoanReader::BUCKETS, true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'bucket']);
        }
        $filters = [
            'q'       => mb_substr(StaffPaging::query($request, 'q'), 0, 100),
            'user_id' => StaffPaging::id(StaffPaging::query($request, 'user_id')),
            'book_id' => StaffPaging::id(StaffPaging::query($request, 'book_id')),
        ];
        foreach (['from', 'to'] as $key) {
            $date = StaffPaging::isoDate(StaffPaging::query($request, $key));
            if ($date === null) {
                return ResponseEnvelope::error($response, 'validation_error', CirculationOutcome::message('invalid_date_format'), 422, ['field' => $key]);
            }
            $filters[$key] = $date;
        }
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        try {
            $page = $this->reader->loans($bucket, $filters, $offset, $limit);

            return ResponseEnvelope::success(
                $response,
                $page['items'],
                StaffPaging::meta(count($page['items']), $limit, $offset, $page['has_more'], $page['total'])
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan list', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $loan = $this->reader->loan($id);

            return $loan === null ? $this->notFound($response) : ResponseEnvelope::success($response, $this->withLateFine($loan));
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan detail', $e);
        }
    }

    // ─── Create / edit ──────────────────────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $userId = StaffPaging::id($json['user_id'] ?? null);
        $bookId = StaffPaging::id($json['book_id'] ?? null);
        if ($userId <= 0) {
            return $this->invalid($response, 'user_id', __('Utente e libro sono obbligatori.'));
        }
        if ($bookId <= 0) {
            return $this->invalid($response, 'book_id', __('Utente e libro sono obbligatori.'));
        }
        $dates = [];
        foreach (['loaned_at', 'due_at'] as $key) {
            $date = StaffPaging::isoDate($json[$key] ?? null);
            if ($date === null) {
                return $this->invalid($response, $key, CirculationOutcome::message('invalid_date_format'));
            }
            $dates[$key] = $date;
        }
        $copyCode = StaffPaging::text($json['copy_code'] ?? null, 100) ?? '';
        $notes = StaffPaging::text($json['notes'] ?? null, 2000) ?? '';
        $immediate = filter_var($json['immediate'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $staffId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $payload = [
            'user_id' => $userId, 'book_id' => $bookId, 'copy_code' => $copyCode, 'loaned_at' => $dates['loaned_at'],
            'due_at' => $dates['due_at'], 'notes' => $notes, 'immediate' => $immediate,
        ];
        $db = $this->db;
        $reader = $this->reader;

        try {
            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'loan',
                $staffId,
                $bookId,
                $payload,
                static function () use ($db, $reader, $response, $staffId, $userId, $bookId, $dates, $copyCode, $notes, $immediate): ResponseInterface|int {
                    // High-water mark taken under this title's create lock, so
                    // the only staff-made row above it for this borrower is ours.
                    $before = $reader->maxId('prestiti');
                    $outcome = Delegation::run(static function () use ($db, $userId, $bookId, $dates, $copyCode, $notes, $immediate): ResponseInterface {
                        $token = \App\Support\OneTimeFormToken::issue('loan.create');
                        $request = Delegation::request('POST', '/admin/loans/create', [
                            'utente_id'             => (string) $userId,
                            'libro_id'              => (string) $bookId,
                            'copy_code'             => $copyCode,
                            'data_prestito'         => $dates['loaned_at'],
                            'data_scadenza'         => $dates['due_at'],
                            'note'                  => $notes,
                            'consegna_immediata'    => $immediate ? '1' : '0',
                            'scarica_pdf'           => '0',
                            'save_and_new'          => '0',
                            'loan_submission_token' => $token,
                        ]);

                        return (new PrestitiController())->store($request, new SlimResponse(), $db);
                    });

                    $error = $outcome->errorCode();
                    if ($error !== null) {
                        return CirculationOutcome::error($response, CirculationOutcome::CREATE, $error, 'staff loan create');
                    }
                    if ($outcome->queryValue('created') !== '1') {
                        throw new \RuntimeException('unexpected outcome HTTP ' . $outcome->status);
                    }
                    $loanId = (int) ($outcome->queryValue('loan') ?? $outcome->queryValue('pdf') ?? 0);
                    if ($loanId <= 0) {
                        $loanId = (int) ($reader->resolveNewLoanId($before, $userId, $bookId, $staffId) ?? 0);
                    }
                    if ($loanId <= 0) {
                        // Ambiguous or missing: never answer with someone else's loan.
                        throw new \RuntimeException('new loan id not resolvable');
                    }

                    return $loanId;
                },
                static fn (int $id): ?array => $reader->loan($id)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $current = $this->reader->loanRow($id);
            if ($current === null) {
                return $this->notFound($response);
            }
            // The core form is a FULL form: always re-post the current values
            // for what the client did not send.
            $form = [
                'utente_id'     => (string) $current['utente_id'],
                'data_prestito' => (string) $current['data_prestito'],
                'data_scadenza' => (string) $current['data_scadenza'],
            ];
            if (array_key_exists('user_id', $json)) {
                $userId = StaffPaging::id($json['user_id']);
                if ($userId <= 0) {
                    return $this->invalid($response, 'user_id', __('Valore non valido.'));
                }
                $form['utente_id'] = (string) $userId;
            }
            foreach (['loaned_at' => 'data_prestito', 'due_at' => 'data_scadenza'] as $key => $column) {
                if (!array_key_exists($key, $json)) {
                    continue;
                }
                $date = StaffPaging::isoDate($json[$key]);
                if ($date === null || $date === '') {
                    return $this->invalid($response, $key, CirculationOutcome::message('invalid_date_format'));
                }
                $form[$column] = $date;
            }

            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PrestitiController())->update(
                Delegation::request('POST', '/admin/loans/update/' . $id, $form),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->errorCode();
            if ($error !== null) {
                return CirculationOutcome::error($response, CirculationOutcome::UPDATE, $error, 'staff loan update');
            }

            return $this->loanOr404($response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan update', $e);
        }
    }

    // ─── Approval desk (LoanApprovalController, JSON) ───────────────────────

    public function approve(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->approval($response, $id, 'approveLoan', []);
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $reason = StaffPaging::text(JsonBody::parse($request)['reason'] ?? null, 500) ?? '';

        return $this->approval($response, $id, 'rejectLoan', ['reason' => $reason]);
    }

    public function pickup(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->approval($response, $id, 'confirmPickup', []);
    }

    public function cancelPickup(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $reason = StaffPaging::text(JsonBody::parse($request)['reason'] ?? null, 500) ?? '';

        return $this->approval($response, $id, 'cancelPickup', ['reason' => $reason]);
    }

    /**
     * @param array<string, string> $extra
     */
    private function approval(ResponseInterface $response, int $id, string $method, array $extra): ResponseInterface
    {
        try {
            if ($this->reader->loanRow($id) === null) {
                return $this->notFound($response);
            }
            $db = $this->db;
            $body = ['loan_id' => (string) $id] + $extra;
            $outcome = Delegation::run(static function () use ($db, $method, $body): ResponseInterface {
                $controller = new LoanApprovalController();
                $request = Delegation::request('POST', '/admin/loans/' . $method, $body);

                return match ($method) {
                    'approveLoan'   => $controller->approveLoan($request, new SlimResponse(), $db),
                    'rejectLoan'    => $controller->rejectLoan($request, new SlimResponse(), $db),
                    'confirmPickup' => $controller->confirmPickup($request, new SlimResponse(), $db),
                    default         => $controller->cancelPickup($request, new SlimResponse(), $db),
                };
            });
            $message = is_string($outcome->json['message'] ?? null) ? (string) $outcome->json['message'] : '';
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                return CirculationOutcome::approvalError($response, $outcome->status === 200 ? 400 : $outcome->status, $message, 'staff ' . $method);
            }
            $loan = $this->reader->loan($id);
            if ($loan === null) {
                return $this->notFound($response);
            }

            return ResponseEnvelope::success($response, $loan, $message !== '' ? ['message' => $message] : []);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan ' . $method, $e);
        }
    }

    // ─── Returns ────────────────────────────────────────────────────────────

    public function returnLoan(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $parsed = $this->parseReturn($json);
        if (isset($parsed['error_field'])) {
            return $this->invalid($response, $parsed['error_field'], $parsed['error_message']);
        }
        try {
            if ($this->reader->loanRow($id) === null) {
                return $this->notFound($response);
            }

            return $this->delegateReturn($response, $id, $parsed);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan return', $e);
        }
    }

    public function returnByCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $code = StaffPaging::text($json['code'] ?? null, 100) ?? '';
        if ($code === '') {
            return $this->invalid($response, 'code', __('Inserisci un codice inventario.'));
        }
        $parsed = $this->parseReturn($json);
        if (isset($parsed['error_field'])) {
            return $this->invalid($response, $parsed['error_field'], $parsed['error_message']);
        }
        try {
            $ids = $this->reader->activeLoanIdsByCopyCode($code);
            if ($ids === []) {
                return ResponseEnvelope::error($response, 'not_found', __('Nessun prestito attivo trovato per questo codice copia.'), 404, ['field' => 'code']);
            }
            if (count($ids) > 1) {
                return ResponseEnvelope::error($response, 'ambiguous', __('Trovati più prestiti attivi per questo codice: gestiscili manualmente.'), 409);
            }

            return $this->delegateReturn($response, $ids[0], $parsed);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan return by code', $e);
        }
    }

    /**
     * @param array<string, mixed> $json
     * @return array{stato:string, note:string, sanzione:string, waive_fine:string, waive_reason:string}|array{error_field:string, error_message:string}
     */
    private function parseReturn(array $json): array
    {
        $outcome = $json['outcome'] ?? 'restituito';
        if (!is_string($outcome) || !in_array($outcome, CirculationOutcome::RETURN_OUTCOMES, true)) {
            return ['error_field' => 'outcome', 'error_message' => __('Stato non valido.')];
        }
        // Mobile API 1.10.0: waive the late fine at the desk, as the web return
        // screen does (a reason is mandatory; the core re-checks it).
        $waive = filter_var($json['waive_fine'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($waive === null) {
            return ['error_field' => 'waive_fine', 'error_message' => __('Valore non valido.')];
        }
        $waiveReason = StaffPaging::text($json['waive_reason'] ?? null, 255) ?? '';
        if ($waive && $waiveReason === '') {
            return ['error_field' => 'waive_reason', 'error_message' => CirculationOutcome::message('waiver_reason_required')];
        }
        $penalty = '0';
        if (array_key_exists('penalty', $json) && $json['penalty'] !== null) {
            $raw = $json['penalty'];
            $value = is_int($raw) || is_float($raw) ? (float) $raw : (is_string($raw) && is_numeric(str_replace(',', '.', trim($raw))) ? (float) str_replace(',', '.', trim($raw)) : -1.0);
            if ($value < 0 || $value > 99999999.99) {
                return ['error_field' => 'penalty', 'error_message' => CirculationOutcome::message('invalid_penalty')];
            }
            $penalty = number_format($value, 2, '.', '');
        }

        return [
            'stato'        => $outcome,
            'note'         => StaffPaging::text($json['notes'] ?? null, 2000) ?? '',
            'sanzione'     => in_array($outcome, ['perso', 'danneggiato'], true) ? $penalty : '0',
            'waive_fine'   => $waive ? '1' : '0',
            'waive_reason' => $waive ? $waiveReason : '',
        ];
    }

    /**
     * @param array{stato:string, note:string, sanzione:string, waive_fine:string, waive_reason:string} $form
     */
    private function delegateReturn(ResponseInterface $response, int $id, array $form): ResponseInterface
    {
        $db = $this->db;
        if ($form['note'] === '') {
            // processReturn() overwrites prestiti.note with what it receives:
            // keep the loan's own note when the operator typed none.
            $form['note'] = (string) ($this->reader->loanRow($id)['note'] ?? '');
        }
        $outcome = Delegation::run(static fn (): ResponseInterface => (new PrestitiController())->processReturn(
            Delegation::request('POST', '/admin/loans/returned/' . $id, $form),
            new SlimResponse(),
            $db,
            $id
        ));
        $error = $outcome->errorCode();
        if ($error !== null) {
            return CirculationOutcome::error($response, CirculationOutcome::RETURN, $error, 'staff loan return');
        }
        $loan = $this->reader->loan($id);

        return $loan === null ? $this->notFound($response) : ResponseEnvelope::success($response, $this->withLateFine($loan));
    }

    /**
     * Mobile API 1.10.0: the late fine of a loan, beside the StaffLoan fields
     * (detail and return answers). Null while the fines module is off.
     *   accruing_*  what a return today would charge (open overdue loans with
     *               automatic fines on; PatronLedger::previewLateFine(), the
     *               figure the web return screen shows before confirming);
     *   charged_cents the late fine already written for this loan, net of
     *               waivers and reversals (PatronLedger::lateFineNetCents()).
     *
     * @param array<string, mixed> $loan
     * @return array<string, mixed>
     */
    private function withLateFine(array $loan): array
    {
        if (!\App\Services\PatronLedger::active($this->db)) {
            $loan['late_fine'] = null;

            return $loan;
        }
        $id = (int) $loan['id'];
        $ledger = \App\Services\PatronLedger::forDb($this->db);
        $open = in_array((string) $loan['status'], ['in_corso', 'in_ritardo'], true) && ($loan['returned_at'] ?? null) === null;
        $preview = $open ? $ledger->previewLateFine($id) : ['days' => 0, 'cents' => 0, 'enabled' => false];
        $loan['late_fine'] = [
            'currency'         => \App\Services\FineParameters::forLoan($this->db, $id)->currency,
            'automatic_fines'  => \App\Support\Features::enabled('automatic_fines'),
            'accruing_days'    => (int) $preview['days'],
            'accruing_cents'   => (int) $preview['cents'],
            'charged_cents'    => $ledger->lateFineNetCents($id),
        ];

        return $loan;
    }

    // ─── Renew / bulk ───────────────────────────────────────────────────────

    public function renew(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if ($this->reader->loanRow($id) === null) {
                return $this->notFound($response);
            }
            // Optional shorter renewal (Mobile API 1.8.2): the day before a later
            // commitment, as a not_available refusal proposes in its meta. The
            // core bounds it (after the current due date, within the standard
            // renewal).
            $json = JsonBody::parse($request);
            $rawDue = $json['new_due_date'] ?? '';
            if (!is_string($rawDue)) {
                return $this->invalid($response, 'new_due_date', __('Data richiesta non valida.'));
            }
            $newDue = trim($rawDue);
            if ($newDue !== '' && !\App\Support\DateHelper::isISODateFormat($newDue)) {
                return $this->invalid($response, 'new_due_date', __('Data richiesta non valida.'));
            }
            $body = $newDue !== '' ? ['new_due_date' => $newDue] : [];
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PrestitiController())->renew(
                Delegation::request('POST', '/admin/loans/renew/' . $id, $body),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->errorCode();
            if ($error !== null) {
                return CirculationOutcome::error($response, CirculationOutcome::RENEW, $error, 'staff loan renew', self::windowDetails($outcome));
            }
            if ($outcome->queryValue('renewed') !== '1') {
                SecureLogger::error('[MobileApi] staff loan renew: unexpected outcome HTTP ' . $outcome->status);
                return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
            }

            return $this->loanOr404($response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan renew', $e);
        }
    }

    public function bulkExtend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $ids = StaffPaging::ids($json['ids'] ?? null);
        if ($ids === null || $ids === [] || count($ids) > 500) {
            return $this->invalid($response, 'ids', CirculationOutcome::message('bulk_extend_invalid'));
        }
        $days = StaffPaging::id($json['days'] ?? null);
        if ($days < 1 || $days > 365) {
            return $this->invalid($response, 'days', __('Valore non valido.'));
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PrestitiController())->bulkExtend(
                Delegation::request('POST', '/admin/loans/bulk-extend', [
                    'ids'  => array_map('strval', $ids),
                    'days' => (string) $days,
                ]),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->errorCode();
            if ($error !== null) {
                return CirculationOutcome::error($response, CirculationOutcome::BULK, $error, 'staff bulk extend', self::windowDetails($outcome));
            }

            return ResponseEnvelope::success($response, [
                'extended' => (int) ($outcome->queryValue('bulk_extended') ?? 0),
                'days'     => (int) ($outcome->queryValue('days') ?? $days),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'bulk extend', $e);
        }
    }

    public function recall(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->jsonSend($response, $id, 'sendRecall');
    }

    public function emailReceipt(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->jsonSend($response, $id, 'emailPdf');
    }

    private function jsonSend(ResponseInterface $response, int $id, string $method): ResponseInterface
    {
        try {
            if ($this->reader->loanRow($id) === null) {
                return $this->notFound($response);
            }
            $db = $this->db;
            $outcome = Delegation::run(static function () use ($db, $id, $method): ResponseInterface {
                $controller = new PrestitiController();
                $request = Delegation::request('POST', '/admin/loans/' . $id . '/' . $method, []);

                return $method === 'sendRecall'
                    ? $controller->sendRecall($request, new SlimResponse(), $db, $id)
                    : $controller->emailPdf($request, new SlimResponse(), $db, $id);
            });
            if ($outcome->status === 403) {
                return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile in modalità catalogo.'), 404);
            }
            if ($outcome->status !== 200 || !array_key_exists('success', $outcome->json)) {
                SecureLogger::error('[MobileApi] staff ' . $method . ': unexpected outcome HTTP ' . $outcome->status);
                return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
            }

            return ResponseEnvelope::success($response, [
                'sent'    => (bool) $outcome->json['success'],
                'message' => (string) ($outcome->json['message'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan ' . $method, $e);
        }
    }

    public function bulkRecall(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ids = StaffPaging::ids(JsonBody::parse($request)['ids'] ?? null);
        if ($ids === null || $ids === [] || count($ids) > 50) {
            return $this->invalid($response, 'ids', CirculationOutcome::message('bulk_recall_invalid'));
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PrestitiController())->bulkRecall(
                Delegation::request('POST', '/admin/loans/bulk-recall', ['ids' => array_map('strval', $ids)]),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->errorCode();
            if ($error !== null) {
                return CirculationOutcome::error($response, CirculationOutcome::BULK, $error, 'staff bulk recall');
            }

            return ResponseEnvelope::success($response, [
                'sent'    => (int) ($outcome->queryValue('bulk_recalled') ?? 0),
                'skipped' => (int) ($outcome->queryValue('bulk_recall_skipped') ?? 0),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'bulk recall', $e);
        }
    }

    public function receipt(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if ($this->reader->loanRow($id) === null) {
                return $this->notFound($response);
            }
            $pdf = (new \App\Support\LoanPdfGenerator($this->db))->generate($id);
            $filename = 'prestito_' . $id . '_' . date('Ymd') . '.pdf';
            $response->getBody()->write($pdf);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) strlen($pdf))
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'loan receipt', $e);
        }
    }

    // ─── Users / copies / book queue ────────────────────────────────────────

    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = mb_substr(StaffPaging::query($request, 'q'), 0, 100);
        // Mobile API 1.10.0: `patron_type_id` narrows the search to one patron
        // type (the web users list filter); with it the text is optional.
        $rawType = StaffPaging::query($request, 'patron_type_id');
        $patronTypeId = null;
        if ($rawType !== '') {
            $types = (new \App\Services\CirculationPolicy($this->db))->types();
            if (preg_match('/^[0-9]+$/D', $rawType) !== 1 || !isset($types[(int) $rawType])) {
                return ResponseEnvelope::error($response, 'validation_error', __('Tipologia lettore non trovata.'), 422, ['field' => 'patron_type_id']);
            }
            $patronTypeId = (int) $rawType;
        }
        if (mb_strlen($q) < 2 && ($patronTypeId === null || $q !== '')) {
            return ResponseEnvelope::error($response, 'validation_error', __('Inserisci almeno 2 caratteri.'), 422, ['field' => 'q']);
        }
        $limit = StaffPaging::limit($request, 20, 50);
        try {
            $users = $this->reader->users($q, $limit, $patronTypeId);

            return ResponseEnvelope::success($response, $users, ['count' => count($users), 'limit' => $limit, 'next_cursor' => null, 'has_more' => false]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'user search', $e);
        }
    }

    public function user(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $user = $this->reader->user($id);
            if ($user === null) {
                return $this->notFound($response);
            }
            $user['loans'] = $this->reader->openLoans('user', $id);
            $user['reservations'] = $this->reader->activeReservations('user', $id);

            return ResponseEnvelope::success($response, $user);
        } catch (\Throwable $e) {
            return $this->fail($response, 'user detail', $e);
        }
    }

    public function copyByCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $code = StaffPaging::text(StaffPaging::query($request, 'code'), 100) ?? '';
        if ($code === '') {
            return $this->invalid($response, 'code', __('Inserisci un codice inventario.'));
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new \App\Controllers\CopyController())->byCode(
                Delegation::request('GET', '/admin/copies/by-code', [], ['code' => $code]),
                new SlimResponse(),
                $db
            ));
            if (($outcome->json['found'] ?? false) !== true) {
                return ResponseEnvelope::success($response, ['found' => false, 'copy' => null]);
            }
            $copyId = (int) ($outcome->json['copy_id'] ?? 0);
            $copy = (new StaffCopyReader($this->db))->copy($copyId);
            if ($copy === null) {
                return ResponseEnvelope::success($response, ['found' => false, 'copy' => null]);
            }
            $copy['available'] = (bool) ($outcome->json['available'] ?? false);
            $copy['loan'] = null;
            foreach ($this->reader->openLoans('book', (int) $copy['book']['id']) as $loan) {
                if (($loan['copy']['id'] ?? null) === $copyId && $loan['status'] !== 'pendente') {
                    $copy['loan'] = $loan;
                    break;
                }
            }

            return ResponseEnvelope::success($response, ['found' => true, 'copy' => $copy]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy by code', $e);
        }
    }

    public function queue(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            if (!$this->bookExists($bookId)) {
                return $this->notFound($response, __('Libro non trovato.'));
            }

            return ResponseEnvelope::success($response, [
                'reservations' => $this->reader->activeReservations('book', $bookId),
                'loans'        => $this->reader->openLoans('book', $bookId),
                'copies'       => (new StaffCopyReader($this->db))->forBook($bookId),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'book queue', $e);
        }
    }

    public function availability(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        $userId = StaffPaging::id(StaffPaging::query($request, 'user_id'));
        $daysRaw = StaffPaging::query($request, 'days');
        $days = $daysRaw === '' ? 180 : (int) $daysRaw;
        if ($days < 1 || $days > 365) {
            return $this->invalid($response, 'days', __('Valore non valido.'));
        }
        try {
            if (!$this->bookExists($bookId)) {
                return $this->notFound($response, __('Libro non trovato.'));
            }
            $avail = (new \App\Controllers\ReservationsController($this->db))
                ->getBookAvailabilityData($bookId, null, $days, $userId > 0 ? $userId : null);
            if ($avail === null) {
                return $this->notFound($response, __('Libro non trovato.'));
            }

            return ResponseEnvelope::success($response, CatalogController::mapAvailability($avail));
        } catch (\Throwable $e) {
            return $this->fail($response, 'staff availability', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function bookExists(int $bookId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('book probe prepare failed');
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    private function loanOr404(ResponseInterface $response, int $id): ResponseInterface
    {
        $loan = $this->reader->loan($id);

        return $loan === null ? $this->notFound($response) : ResponseEnvelope::success($response, $loan);
    }

    private function notFound(ResponseInterface $response, ?string $message = null): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', $message ?? __('Prestito non trovato.'), 404);
    }

    /**
     * The renewal-window figures a core redirect may carry beside its error
     * code (LoanWindow::renewalRedirectParams(), plus the loan they are about).
     *
     * @return array<string, string>
     */
    private static function windowDetails(\App\Plugins\MobileApi\Support\DelegationOutcome $outcome): array
    {
        $details = [];
        foreach (['max_extension_days', 'max_due_date', 'blocked_from', 'loan_id'] as $key) {
            $value = $outcome->queryValue($key);
            if ($value !== null) {
                $details[$key] = $value;
            }
        }
        return $details;
    }

    private function invalid(ResponseInterface $response, string $field, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation_error', $message, 422, ['field' => $field]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
