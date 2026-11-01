<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\AttendanceController;
use App\Models\AttendanceRepository;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Staff090\Support\AttendancePayload;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Visitor log (Pinakes 0.9.0 /admin/attendance) for the app: the phone scans
 * the patron card at the entrance.
 *
 * Reads use AttendanceRepository, the web kiosk's repository. A check-in
 * applies the kiosk's own steps (AttendanceRepository::findMemberByCard with
 * the operator's account scope, recordMember, recordGuest) instead of
 * delegating, because the kiosk answers with a flash message only and the
 * app needs the visit it recorded; the outcome is the kiosk's: an unknown
 * card records nothing, a card whose account is suspended or expired is
 * recorded with a warning. Export and the retention setting are delegated to
 * AttendanceController; the purge runs the same repository call after the
 * same administrator check, so the app gets the number of deleted visits.
 */
final class StaffAttendanceController
{
    public const PERIODS = [7, 30, 90, 365];
    public const RECENT = 10;

    public function __construct(private mysqli $db)
    {
    }

    /** GET /staff/attendance?days= */
    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $days = $this->period($request);
        if ($days === null) {
            return $this->invalid($response, ['days' => __('Valore non valido.')]);
        }
        try {
            $repo = new AttendanceRepository($this->db);
            if (!$repo->isAvailable()) {
                return $this->unavailable($response);
            }
            $branches = [];
            foreach ($repo->branches() as $id => $name) {
                $branches[] = ['id' => (int) $id, 'name' => $name];
            }
            $hourly = [];
            foreach ($repo->hourlyCounts($days) as $hour => $total) {
                $hourly[] = ['hour' => (int) $hour, 'total' => (int) $total];
            }
            $recent = [];
            foreach ($repo->recent(self::RECENT) as $row) {
                $recent[] = [
                    'created_at' => $row['created_at'],
                    'type'       => AttendancePayload::type($row['tipo']),
                    'label'      => $row['etichetta'],
                ];
            }

            return ResponseEnvelope::success($response, [
                'days'          => $days,
                'periods'       => self::PERIODS,
                'today'         => AttendancePayload::totals($repo->totals(1)),
                'totals'        => AttendancePayload::totals($repo->totals($days)),
                'daily'         => array_map([AttendancePayload::class, 'day'], $repo->dailyCounts($days)),
                'hourly'        => $hourly,
                'recent'        => $recent,
                'branches'      => $branches,
                'retention'     => $this->retention(),
                'can_configure' => StaffRoleMiddleware::isAdmin($request),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'attendance overview', $e);
        }
    }

    /** POST /staff/attendance/check-ins {card_code, branch_id?} — Idempotency-Key. */
    public function checkIn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (StaffIdempotency::keyFrom($request, $json) === false) {
            return $this->invalid($response, ['idempotency_key' => __('Chiave di idempotenza non valida.')]);
        }
        $card = is_string($json['card_code'] ?? null) ? trim($json['card_code']) : '';
        $errors = [];
        if ($card === '') {
            $errors['card_code'] = __('Inserisci il codice della tessera.');
        }
        try {
            $repo = new AttendanceRepository($this->db);
            if (!$repo->isAvailable()) {
                return $this->unavailable($response);
            }
            $branch = $this->branch($repo, $json, $errors);
            if ($errors !== []) {
                return $this->invalid($response, $errors);
            }
            $db = $this->db;
            $staffId = (int) (StaffPaging::user($request)['id'] ?? 0);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'attendance_check_in',
                $staffId,
                0,
                ['card_code' => $card, 'branch_id' => $branch],
                function () use ($repo, $card, $branch, $response): ResponseInterface|int {
                    $member = $repo->findMemberByCard($card);
                    if ($member === null) {
                        return ResponseEnvelope::error(
                            $response,
                            'card_not_found',
                            __('Tessera non riconosciuta. Controlla il codice o registra la visita come ospite.'),
                            404
                        );
                    }

                    return $repo->recordMember($member['id'], $branch);
                },
                fn (int $id): ?array => AttendancePayload::visit($db, $id)
            );
        } catch (\Throwable $e) {
            SecureLogger::error('Attendance scan failed', ['exception' => get_class($e), 'code' => $e->getCode(), 'source' => 'mobile_api']);

            return ResponseEnvelope::error($response, 'internal_error', __('Impossibile registrare la presenza. Riprova.'), 500);
        }
    }

    /** POST /staff/attendance/guests {name?, reason?, branch_id?} — Idempotency-Key. */
    public function guest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (StaffIdempotency::keyFrom($request, $json) === false) {
            return $this->invalid($response, ['idempotency_key' => __('Chiave di idempotenza non valida.')]);
        }
        $errors = [];
        $name = $this->optionalText($json, 'name', $errors);
        $reason = $this->optionalText($json, 'reason', $errors);
        try {
            $repo = new AttendanceRepository($this->db);
            if (!$repo->isAvailable()) {
                return $this->unavailable($response);
            }
            $branch = $this->branch($repo, $json, $errors);
            if ($errors !== []) {
                return $this->invalid($response, $errors);
            }
            $db = $this->db;
            $staffId = (int) (StaffPaging::user($request)['id'] ?? 0);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'attendance_guest',
                $staffId,
                0,
                ['name' => $name, 'reason' => $reason, 'branch_id' => $branch],
                static fn (): int => $repo->recordGuest($name, $reason, $branch),
                fn (int $id): ?array => AttendancePayload::visit($db, $id)
            );
        } catch (\Throwable $e) {
            SecureLogger::error('Attendance guest failed', ['exception' => get_class($e), 'code' => $e->getCode(), 'source' => 'mobile_api']);

            return ResponseEnvelope::error($response, 'internal_error', __('Impossibile registrare la presenza. Riprova.'), 500);
        }
    }

    /** GET /staff/attendance/export.csv?days= — delegated to the kiosk export. */
    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $days = $this->period($request);
        if ($days === null) {
            return $this->invalid($response, ['days' => __('Valore non valido.')]);
        }
        try {
            if (!(new AttendanceRepository($this->db))->isAvailable()) {
                return $this->unavailable($response);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new AttendanceController())->exportCsv(
                Delegation::request('GET', '/admin/attendance/export.csv', [], ['days' => (string) $days]),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200 || $outcome->body === '') {
                throw new \RuntimeException('attendance export HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition') ?: 'attachment; filename="presenze_' . $days . 'g.csv"')
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'attendance export', $e);
        }
    }

    /** PUT /staff/attendance/settings {retention_days} — administrators only. */
    public function saveSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $raw = $json['retention_days'] ?? null;
        if (is_string($raw) && preg_match('/^[0-9]{1,5}$/D', $raw) === 1) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw < 0 || $raw > AttendanceRepository::MAX_RETENTION_DAYS) {
            return $this->invalid($response, ['retention_days' => sprintf(__('Indica un numero di giorni tra 0 e %d.'), AttendanceRepository::MAX_RETENTION_DAYS)]);
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new AttendanceController())->saveSettings(
                Delegation::request('POST', '/admin/attendance/settings', ['retention_days' => (string) $raw]),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return ResponseEnvelope::error($response, 'forbidden', $error, 403);
            }
            $message = $outcome->flash('success_message');
            if ($message === null) {
                throw new \RuntimeException('unexpected retention outcome HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::success($response, ['retention' => $this->retention(), 'message' => $message]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'attendance settings', $e);
        }
    }

    /** POST /staff/attendance/purge — administrators only: apply the retention now. */
    public function purge(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The kiosk's rule (AttendanceController::purge): AdminAuthMiddleware
        // admits Staff, the purge itself is for administrators.
        if (!StaffRoleMiddleware::isAdmin($request)) {
            return ResponseEnvelope::error($response, 'forbidden', __('Operazione riservata agli amministratori.'), 403);
        }
        try {
            $repo = new AttendanceRepository($this->db);
            if (!$repo->isAvailable()) {
                return $this->unavailable($response);
            }
            $days = AttendanceRepository::retentionDays(new SettingsRepository($this->db));
            $deleted = $repo->purge($days);
            SecureLogger::info('Attendance purged', ['deleted' => $deleted, 'retention_days' => $days, 'by' => $_SESSION['user']['id'] ?? null, 'source' => 'mobile_api']);

            return ResponseEnvelope::success($response, [
                'deleted'   => $deleted,
                'retention' => $this->retention(),
                'message'   => sprintf(__('Presenze cancellate: %d.'), $deleted),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'attendance purge', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** @return array{days:int, max_days:int, default_days:int, continues_when_off:bool} */
    private function retention(): array
    {
        return [
            'days'               => AttendanceRepository::retentionDays(new SettingsRepository($this->db)),
            'max_days'           => AttendanceRepository::MAX_RETENTION_DAYS,
            'default_days'       => AttendanceRepository::DEFAULT_RETENTION_DAYS,
            'continues_when_off' => \App\Support\Features::enabled('attendance_retention'),
        ];
    }

    /** ?days= among PERIODS (default 30); null = invalid. */
    private function period(ServerRequestInterface $request): ?int
    {
        $raw = StaffPaging::query($request, 'days');
        if ($raw === '') {
            return 30;
        }
        if (preg_match('/^[0-9]{1,3}$/D', $raw) !== 1 || !in_array((int) $raw, self::PERIODS, true)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Optional branch: absent/null = none; otherwise an active branch.
     *
     * @param array<string, mixed> $json
     * @param array<string, string> $errors
     */
    private function branch(AttendanceRepository $repo, array $json, array &$errors): ?int
    {
        $raw = $json['branch_id'] ?? null;
        if ($raw === null || $raw === '' || $raw === 0) {
            return null;
        }
        $id = $repo->validBranchId($raw);
        if ($id === null) {
            $errors['branch_id'] = __('Sede non trovata.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $errors
     */
    private function optionalText(array $json, string $field, array &$errors): ?string
    {
        $raw = $json[$field] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            $errors[$field] = __('Valore non valido.');
            return null;
        }
        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    private function unavailable(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_available', __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.'), 503);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        $first = (string) array_key_first($fields);

        return ResponseEnvelope::error($response, 'validation', $fields[$first], 422, ['field' => $first, 'fields' => $fields]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
