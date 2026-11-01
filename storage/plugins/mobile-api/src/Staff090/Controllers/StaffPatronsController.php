<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\PatronCardsController;
use App\Controllers\UsersController;
use App\Plugins\MobileApi\Staff090\Support\PatronPayload;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Services\CirculationPolicy;
use App\Support\PatronCardSettings;
use App\Support\PatronTypeAssignment;
use App\Support\Permissions;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Patron record tools of the companion app (Mobile API 1.10.0): patron types,
 * card renewal, library card PDFs and the patron photo.
 *
 * Card renewal, card PDFs and the photo are DELEGATED to the web controllers
 * (UsersController::renewCard, PatronCardsController), so their permission
 * rules, image re-encoding, storage and logs are the website's. The patron
 * type change applies the web user editor's rule (users.manage +
 * UserRoleAssignment::canEdit: an administrator edits every account, a Staff
 * account only its own) and the same PatronTypeAssignment helpers, without
 * re-posting the whole user form.
 */
final class StaffPatronsController
{
    public function __construct(private mysqli $db)
    {
    }

    // ─── Patron types ───────────────────────────────────────────────────────

    public function patronTypes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $policy = new CirculationPolicy($this->db);
            $default = $policy->defaultTypeId();
            $items = [];
            foreach ($policy->types() as $id => $type) {
                $items[] = [
                    'id'                 => (int) $id,
                    'code'               => (string) ($type['codice'] ?? ''),
                    'name'               => (string) ($type['nome'] ?? ''),
                    'description'        => self::nullable($type['descrizione'] ?? null),
                    'is_default'         => $default !== null && (int) $id === $default,
                    'active'             => (int) ($type['attiva'] ?? 1) === 1,
                    'card_duration_days' => isset($type['durata_tessera_giorni']) ? (int) $type['durata_tessera_giorni'] : null,
                ];
            }

            return ResponseEnvelope::success($response, $items, [
                'count'      => count($items),
                'default_id' => $default,
                'can_assign' => Permissions::can('users.manage'),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'patron types', $e);
        }
    }

    public function updatePatronType(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (!array_key_exists('patron_type_id', $json)) {
            return $this->invalid($response, 'patron_type_id', __('Tipologia lettore non trovata.'));
        }
        $raw = $json['patron_type_id'];
        $typeId = null;
        if ($raw !== null && $raw !== 0 && $raw !== '' && $raw !== '0') {
            if (is_string($raw) && preg_match('/^[0-9]{1,9}$/D', $raw) === 1) {
                $raw = (int) $raw;
            }
            if (!is_int($raw) || $raw <= 0) {
                return $this->invalid($response, 'patron_type_id', __('Tipologia lettore non trovata.'));
            }
            $typeId = $raw;
        }
        $recompute = filter_var($json['recompute_card_expiry'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($recompute === null) {
            return $this->invalid($response, 'recompute_card_expiry', __('Valore non valido.'));
        }

        try {
            $target = $this->userRow($id);
            if ($target === null) {
                return $this->userNotFound($response);
            }
            if (!PatronPayload::canEdit($target)) {
                return $this->forbidden($response);
            }
            $current = isset($target['tipologia_id']) ? (int) $target['tipologia_id'] : null;
            // Active types plus the one the account already has (the web picker).
            if ($typeId !== null && !isset(PatronTypeAssignment::choices($this->db, $current)[$typeId])) {
                return $this->invalid($response, 'patron_type_id', __('Tipologia lettore non trovata.'));
            }
            if ($typeId !== $current) {
                PatronTypeAssignment::save($this->db, $id, $typeId);
            }
            // The web form's "card_auto": expiry from the type's card duration,
            // only for reader accounts and only when the type sets one.
            $recomputed = false;
            if ($recompute && in_array((string) $target['tipo_utente'], ['standard', 'premium'], true)) {
                $expiry = PatronTypeAssignment::automaticCardExpiry($this->db, $typeId);
                if ($expiry !== null) {
                    $stmt = $this->db->prepare('UPDATE utenti SET data_scadenza_tessera = ? WHERE id = ?');
                    if ($stmt === false) {
                        throw new \RuntimeException('card expiry update prepare failed');
                    }
                    $stmt->bind_param('si', $expiry, $id);
                    $stmt->execute();
                    $stmt->close();
                    $recomputed = true;
                }
            }
            if ($typeId !== $current) {
                SecureLogger::info('Patron type changed', ['user_id' => $id, 'from' => $current, 'to' => $typeId, 'by' => $_SESSION['user']['id'] ?? null, 'source' => 'mobile_api']);
            }
            $user = (new StaffLoanReader($this->db))->user($id);
            if ($user === null) {
                return $this->userNotFound($response);
            }

            return ResponseEnvelope::success($response, $user, ['card_expiry_recomputed' => $recomputed]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'patron type update', $e);
        }
    }

    // ─── Card renewal ───────────────────────────────────────────────────────

    public function renewCard(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (StaffIdempotency::keyFrom($request, $json) === false) {
            return $this->invalid($response, 'idempotency_key', __('Chiave di idempotenza non valida.'));
        }
        try {
            if ($this->userRow($id) === null) {
                return $this->userNotFound($response);
            }
            $db = $this->db;
            $reader = new StaffLoanReader($db);
            $staffId = (int) (StaffPaging::user($request)['id'] ?? 0);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'card_renewal',
                $staffId,
                $id,
                ['user_id' => $id],
                function () use ($db, $response, $id): ResponseInterface|int {
                    $outcome = Delegation::run(static fn (): ResponseInterface => (new UsersController())->renewCard(
                        Delegation::request('POST', '/admin/users/' . $id . '/renew-card'),
                        new SlimResponse(),
                        $db,
                        $id
                    ));
                    if ($outcome->status === 403) {
                        return $this->forbidden($response);
                    }
                    if ($outcome->queryValue('error') === 'user_not_found') {
                        return $this->userNotFound($response);
                    }
                    $error = $outcome->flash('error_message');
                    if ($error !== null) {
                        return ResponseEnvelope::error($response, 'card_not_renewable', $error, 409);
                    }
                    if ($outcome->flash('success_message') === null) {
                        throw new \RuntimeException('unexpected renewal outcome HTTP ' . $outcome->status);
                    }

                    return $id;
                },
                static fn (int $userId): ?array => $reader->user($userId)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'card renewal', $e);
        }
    }

    // ─── Card PDFs ──────────────────────────────────────────────────────────

    public function cardPdf(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $format = $this->format($request);
        if ($format === false) {
            return $this->invalid($response, 'format', __('Valore non valido.'));
        }
        try {
            $db = $this->db;
            $query = $format !== null ? ['format' => $format] : [];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronCardsController())->cardPdf(
                Delegation::request('GET', '/admin/users/' . $id . '/card-pdf', [], $query),
                new SlimResponse(),
                $db,
                $id
            ));

            return $this->pdf($response, $outcome, 'card pdf');
        } catch (\Throwable $e) {
            return $this->fail($response, 'card pdf', $e);
        }
    }

    public function bulkCardPdf(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $format = $this->format($request);
        if ($format === false) {
            return $this->invalid($response, 'format', __('Valore non valido.'));
        }
        $ids = [];
        foreach (explode(',', StaffPaging::query($request, 'ids')) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[0-9]{1,10}$/D', $part) !== 1 || (int) $part <= 0) {
                return $this->invalid($response, 'ids', __('Valore non valido.'));
            }
            $ids[(int) $part] = (int) $part;
        }
        if ($ids === []) {
            return $this->invalid($response, 'ids', __('Valore non valido.'));
        }
        if (count($ids) > PatronCardsController::MAX_BULK) {
            return $this->invalid($response, 'ids', sprintf(__('Si possono stampare al massimo %d tessere alla volta: selezionane di meno.'), PatronCardsController::MAX_BULK));
        }
        try {
            $db = $this->db;
            $query = ['ids' => implode(',', $ids)] + ($format !== null ? ['format' => $format] : []);
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronCardsController())->bulkPdf(
                Delegation::request('GET', '/admin/users/cards-pdf', [], $query),
                new SlimResponse(),
                $db
            ));

            return $this->pdf($response, $outcome, 'bulk card pdf');
        } catch (\Throwable $e) {
            return $this->fail($response, 'bulk card pdf', $e);
        }
    }

    // ─── Photo ──────────────────────────────────────────────────────────────

    public function photo(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronCardsController())->photo(
                Delegation::request('GET', '/admin/users/' . $id . '/photo'),
                new SlimResponse(),
                $db,
                $id
            ));
            if ($outcome->status === 403) {
                return $this->forbidden($response);
            }
            if ($outcome->status === 404) {
                return ResponseEnvelope::error($response, 'not_found', $this->userRow($id) === null ? __('Utente non trovato') : __('Foto non trovata.'), 404);
            }
            if ($outcome->status !== 200 || $outcome->body === '') {
                throw new \RuntimeException('photo HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'image/jpeg')
                ->withHeader('Content-Length', (string) strlen($outcome->body))
                ->withHeader('Content-Disposition', 'inline; filename="patron_' . $id . '.jpg"')
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\Throwable $e) {
            return $this->fail($response, 'photo read', $e);
        }
    }

    public function uploadPhoto(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $file = $request->getUploadedFiles()['photo'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->invalid($response, 'photo', __('Nessuna foto ricevuta.'));
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronCardsController())->uploadPhoto(
                Delegation::request('POST', '/admin/users/' . $id . '/photo')->withUploadedFiles(['photo' => $file]),
                new SlimResponse(),
                $db,
                $id
            ));
            if ($outcome->status === 200 && ($outcome->json['success'] ?? false) === true) {
                return ResponseEnvelope::success($response, $this->photoState($id));
            }

            return $this->photoError($response, $outcome, 'photo upload');
        } catch (\Throwable $e) {
            return $this->fail($response, 'photo upload', $e);
        }
    }

    public function deletePhoto(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new PatronCardsController())->deletePhoto(
                Delegation::request('POST', '/admin/users/' . $id . '/photo/delete')->withHeader('Accept', 'application/json'),
                new SlimResponse(),
                $db,
                $id
            ));
            if ($outcome->status === 200 && ($outcome->json['success'] ?? false) === true) {
                return ResponseEnvelope::success($response, $this->photoState($id));
            }

            return $this->photoError($response, $outcome, 'photo delete');
        } catch (\Throwable $e) {
            return $this->fail($response, 'photo delete', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** @return array{user_id:int, has_photo:bool, photo_url:?string} */
    private function photoState(int $id): array
    {
        $url = PatronPayload::photoUrl($id, \App\Support\PatronPhoto::storedFor($this->db, $id));

        return ['user_id' => $id, 'has_photo' => $url !== null, 'photo_url' => $url];
    }

    private function photoError(ResponseInterface $response, DelegationOutcome $outcome, string $what): ResponseInterface
    {
        $message = is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : '';

        return match (true) {
            $outcome->status === 403 => $this->forbidden($response),
            $outcome->status === 404 => $this->userNotFound($response),
            $outcome->status === 400, $outcome->status === 422 => $this->invalid($response, 'photo', $message !== '' ? $message : __('Nessuna foto ricevuta.')),
            default => $this->fail($response, $what, new \RuntimeException('HTTP ' . $outcome->status . ' ' . $message)),
        };
    }

    /** A delegated card PDF as a download (the web answers inline). */
    private function pdf(ResponseInterface $response, DelegationOutcome $outcome, string $what): ResponseInterface
    {
        if ($outcome->status === 403) {
            return $this->forbidden($response);
        }
        if ($outcome->status === 404) {
            return $this->userNotFound($response);
        }
        if ($outcome->status === 400 || $outcome->status === 422) {
            return $this->invalid($response, 'ids', trim($outcome->body) !== '' ? trim($outcome->body) : __('Valore non valido.'));
        }
        if ($outcome->status !== 200 || $outcome->body === '') {
            throw new \RuntimeException($what . ' HTTP ' . $outcome->status);
        }
        $filename = 'tessere.pdf';
        if (preg_match('/filename="([^"]+)"/', $outcome->response->getHeaderLine('Content-Disposition'), $m) === 1) {
            $filename = $m[1];
        }
        $response->getBody()->write($outcome->body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($outcome->body))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** null = settings default, false = invalid. */
    private function format(ServerRequestInterface $request): string|false|null
    {
        $format = StaffPaging::query($request, 'format');
        if ($format === '') {
            return null;
        }

        return in_array($format, PatronCardSettings::FORMATS, true) ? $format : false;
    }

    /** @return array<string, mixed>|null */
    private function userRow(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, tipo_utente, ruolo_id, accesso_digitale, tipologia_id, data_scadenza_tessera FROM utenti WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('user lookup prepare failed');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    private static function nullable(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $s = trim((string) $raw);

        return $s !== '' ? $s : null;
    }

    private function forbidden(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_permitted', __('Accesso negato.'), 403);
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
