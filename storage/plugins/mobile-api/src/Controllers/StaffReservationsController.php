<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\LoanApprovalController;
use App\Controllers\ReservationsAdminController;
use App\Plugins\MobileApi\Support\CirculationOutcome;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Staff reservation queue (Mobile API 1.7.0): /staff/reservations*.
 *
 * Create and edit are delegated to ReservationsAdminController::store() /
 * update() (the admin form), cancel to LoanApprovalController::cancelReservation()
 * — so eligibility, capacity, queue positions, promotion and the cancellation
 * email are exactly those of the website.
 */
final class StaffReservationsController
{
    private StaffLoanReader $reader;

    public function __construct(private mysqli $db)
    {
        $this->reader = new StaffLoanReader($db);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = StaffPaging::query($request, 'status');
        $status = $status === '' ? 'attiva' : $status;
        if (!in_array($status, StaffLoanReader::RESERVATION_STATES, true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'status']);
        }
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        try {
            $page = $this->reader->reservations($status, [
                'q'       => mb_substr(StaffPaging::query($request, 'q'), 0, 100),
                'user_id' => StaffPaging::id(StaffPaging::query($request, 'user_id')),
                'book_id' => StaffPaging::id(StaffPaging::query($request, 'book_id')),
            ], $offset, $limit);

            return ResponseEnvelope::success(
                $response,
                $page['items'],
                StaffPaging::meta(count($page['items']), $limit, $offset, $page['has_more'], $page['total'])
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'reservation list', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $row = $this->reader->reservation($id);

            return $row === null ? $this->notFound($response) : ResponseEnvelope::success($response, $row);
        } catch (\Throwable $e) {
            return $this->fail($response, 'reservation detail', $e);
        }
    }

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
        $dates = $this->dates($json);
        if (is_string($dates)) {
            return $this->invalid($response, $dates, CirculationOutcome::message('invalid_date'));
        }
        $form = [
            'libro_id'              => (string) $bookId,
            'utente_id'             => (string) $userId,
            'data_prenotazione'     => $dates['reserved_at'] ?? '',
            'data_scadenza'         => $dates['expires_at'] ?? '',
            'data_inizio_richiesta' => $dates['requested_from'] ?? '',
            'data_fine_richiesta'   => $dates['requested_to'] ?? '',
            // Staff may override the patron type's reservation limits
            // explicitly (Pinakes 0.9.0), as the admin form does.
            'force_limits'          => ($json['override_limits'] ?? false) === true ? '1' : '',
        ];
        $db = $this->db;
        $reader = $this->reader;
        try {
            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'reservation',
                (int) (StaffPaging::user($request)['id'] ?? 0),
                $bookId,
                $form,
                static function () use ($db, $reader, $response, $form, $userId, $bookId): ResponseInterface|int {
                    // High-water mark under this title's create lock.
                    $before = $reader->maxId('prenotazioni');
                    $outcome = Delegation::run(static fn (): ResponseInterface => (new ReservationsAdminController())->store(
                        Delegation::request('POST', '/admin/reservations/create', $form),
                        new SlimResponse(),
                        $db
                    ));
                    $error = $outcome->errorCode();
                    if ($error !== null) {
                        return CirculationOutcome::error($response, CirculationOutcome::RESERVATION, $error, 'staff reservation create');
                    }
                    $id = $outcome->queryValue('created') === '1'
                        ? $reader->resolveNewReservationId($before, $userId, $bookId)
                        : null;
                    if ($id === null) {
                        throw new \RuntimeException('new reservation id not resolvable (HTTP ' . $outcome->status . ')');
                    }

                    return $id;
                },
                static fn (int $id): ?array => $reader->reservation($id)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'reservation create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $dates = $this->dates($json);
        if (is_string($dates)) {
            return $this->invalid($response, $dates, CirculationOutcome::message('invalid_date'));
        }
        if (array_key_exists('status', $json) && (!is_string($json['status']) || !in_array($json['status'], ['attiva', 'completata', 'annullata'], true))) {
            return $this->invalid($response, 'status', __('Stato non valido.'));
        }
        try {
            $current = $this->reader->reservationRow($id);
            if ($current === null) {
                return $this->notFound($response);
            }
            // Full form server-side: merge over the stored row.
            $form = [
                'stato'                      => is_string($json['status'] ?? null) ? $json['status'] : (string) $current['stato'],
                'data_prenotazione'          => $dates['reserved_at'] ?? (StaffLoanReader::date($current['data_prenotazione'] ?? null) ?? ''),
                'data_scadenza_prenotazione' => $dates['expires_at'] ?? (StaffLoanReader::date($current['data_scadenza_prenotazione'] ?? null) ?? ''),
                'data_inizio_richiesta'      => $dates['requested_from'] ?? (StaffLoanReader::date($current['data_inizio_richiesta'] ?? null) ?? ''),
                'data_fine_richiesta'        => $dates['requested_to'] ?? (StaffLoanReader::date($current['data_fine_richiesta'] ?? null) ?? ''),
            ];
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new ReservationsAdminController())->update(
                Delegation::request('POST', '/admin/reservations/update/' . $id, $form),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->errorCode();
            if ($error !== null) {
                return CirculationOutcome::error($response, CirculationOutcome::RESERVATION, $error, 'staff reservation update');
            }
            $row = $this->reader->reservation($id);

            return $row === null ? $this->notFound($response) : ResponseEnvelope::success($response, $row);
        } catch (\Throwable $e) {
            return $this->fail($response, 'reservation update', $e);
        }
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $reason = StaffPaging::text(JsonBody::parse($request)['reason'] ?? null, 500) ?? '';
        try {
            if ($this->reader->reservationRow($id) === null) {
                return $this->notFound($response);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LoanApprovalController())->cancelReservation(
                Delegation::request('POST', '/admin/loans/cancel-reservation', ['reservation_id' => (string) $id, 'reason' => $reason]),
                new SlimResponse(),
                $db
            ));
            $message = is_string($outcome->json['message'] ?? null) ? (string) $outcome->json['message'] : '';
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                return CirculationOutcome::approvalError($response, $outcome->status === 200 ? 400 : $outcome->status, $message, 'staff reservation cancel');
            }
            $row = $this->reader->reservation($id);

            return $row === null
                ? $this->notFound($response)
                : ResponseEnvelope::success($response, $row, $message !== '' ? ['message' => $message] : []);
        } catch (\Throwable $e) {
            return $this->fail($response, 'reservation cancel', $e);
        }
    }

    /**
     * Optional Y-m-d dates of the request; the offending key on error.
     *
     * @param array<string, mixed> $json
     * @return array<string, string>|string
     */
    private function dates(array $json): array|string
    {
        $out = [];
        foreach (['reserved_at', 'expires_at', 'requested_from', 'requested_to'] as $key) {
            if (!array_key_exists($key, $json)) {
                continue;
            }
            $date = StaffPaging::isoDate($json[$key]);
            if ($date === null) {
                return $key;
            }
            if ($date !== '') {
                $out[$key] = $date;
            }
        }

        return $out;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Prenotazione non trovata.'), 404);
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
