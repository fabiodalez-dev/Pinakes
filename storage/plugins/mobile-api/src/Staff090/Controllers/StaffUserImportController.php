<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\UserImportController;
use App\Plugins\MobileApi\Staff090\Support\UserImportJob;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use App\Support\SecureLogger;
use App\Support\UserImport;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Patron CSV import (Pinakes 0.9.0 /admin/users/import) for the app.
 *
 * Upload, start, every chunk, cancel, the example file and the error report
 * are DELEGATED to UserImportController: same parser, column recognition,
 * validation, duplicate rule (email or card code already present = skipped,
 * never merged), patron type assignment, invitations and import history
 * (import_logs, type `users`). Passwords are never imported, as on the web.
 *
 * The web keeps the run in `$_SESSION['user_import']`; a bearer token has no
 * session, so that array lives in ImportJobStore (kind `users`) between
 * requests and is put back into the session around each delegated call
 * (Delegation isolates the key). The stored state is
 * {core: <the web session array>, headers, preview, fields, suggested, notice}.
 * Chunks follow the book import contract: 409 out_of_order (meta.processed),
 * 409 job_busy, 410 gone.
 */
final class StaffUserImportController
{
    public const KIND = 'users';
    public const DELIMITERS = ['' => '', 'auto' => '', ';' => ';', ',' => ',', 'tab' => 'tab'];
    /** Seconds DELETE waits for a chunk in flight. */
    private const CANCEL_LOCK_WAIT = 3;

    private ImportJobStore $store;

    public function __construct(private mysqli $db)
    {
        $this->store = new ImportJobStore($db);
    }

    /** POST /staff/user-imports (multipart: file, delimiter?) */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $delimiter = $body['delimiter'] ?? '';
        if (!is_string($delimiter) || !array_key_exists($delimiter, self::DELIMITERS)) {
            return $this->invalid($response, 'delimiter', __('Valore non valido.'));
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->invalid($response, 'file', __('Nessun file caricato'));
        }
        $name = basename((string) ($file->getClientFilename() ?? ''));
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        try {
            $this->store->purgeExpired();
            $db = $this->db;
            $form = ['delimiter' => self::DELIMITERS[$delimiter]];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new UserImportController())->upload(
                Delegation::request('POST', '/admin/users/import/upload', $form)->withUploadedFiles(['csv_file' => $file]),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status === 403) {
                return $this->forbidden($response);
            }
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                $message = is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('File CSV vuoto o formato non valido');
                if ($outcome->status >= 500) {
                    throw new \RuntimeException('user import upload HTTP ' . $outcome->status . ': ' . $message);
                }

                return $this->invalid($response, 'file', $message);
            }
            $core = $outcome->session['user_import'] ?? null;
            if (!is_array($core)) {
                throw new \RuntimeException('user import state not captured');
            }
            $json = $outcome->json;
            $state = [
                'core'      => $core,
                'headers'   => array_values(array_map('strval', (array) ($json['headers'] ?? []))),
                'preview'   => array_values((array) ($json['preview'] ?? [])),
                'fields'    => (array) ($json['fields'] ?? []),
                'suggested' => (array) ($json['mapping'] ?? []),
                'notice'    => is_string($json['notice'] ?? null) ? $json['notice'] : null,
            ];
            $id = $this->store->create(
                $userId,
                is_int($tokenId) ? $tokenId : null,
                self::KIND,
                $name !== '' ? $name : (string) ($core['name'] ?? ''),
                (int) ($json['total_rows'] ?? 0),
                UserImport::CHUNK_SIZE,
                false,
                $state
            );

            return ResponseEnvelope::success($response, UserImportJob::map((array) $this->store->find($id, $userId, true)), [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import upload', $e);
        }
    }

    /** GET /staff/user-imports/{id} */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $job = $this->find($request, $id);

            return $job === null ? $this->notFound($response) : ResponseEnvelope::success($response, UserImportJob::map($job));
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import detail', $e);
        }
    }

    /** POST /staff/user-imports/{id}/start {mapping?, send_invites?} */
    public function start(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $invites = filter_var($json['send_invites'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($invites === null) {
            return $this->invalid($response, 'send_invites', __('Valore non valido.'));
        }
        if (array_key_exists('mapping', $json) && !is_array($json['mapping'])) {
            return $this->invalid($response, 'mapping', __('Mappatura delle colonne non valida.'));
        }
        try {
            $job = $this->find($request, $id);
            if ($job === null) {
                return $this->notFound($response);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('user_import', (string) $job['id']);
            if (!$locks->lock($lock, 0)) {
                return $this->busy($response);
            }
            try {
                $job = (array) $this->find($request, $id);
                if (($gone = $this->gone($response, $job)) !== null) {
                    return $gone;
                }
                $state = $job['state'];
                $core = is_array($state['core'] ?? null) ? $state['core'] : [];
                if (!empty($core['started'])) {
                    return ResponseEnvelope::error($response, 'already_started', __('Import già avviato: attendi la fine o ricarica il file.'), 409, ['processed' => (int) $job['processed']]);
                }
                $mapping = array_key_exists('mapping', $json) ? $json['mapping'] : ($state['suggested'] ?? []);
                $payload = (string) json_encode([
                    'preview_id'   => (string) ($core['preview_id'] ?? ''),
                    'mapping'      => $mapping,
                    'send_invites' => $invites,
                ]);
                $db = $this->db;
                $outcome = Delegation::run(static function () use ($db, $core, $payload): ResponseInterface {
                    $_SESSION['user_import'] = $core;

                    return (new UserImportController())->start(
                        Delegation::request('POST', '/admin/users/import/start', [], [], $payload),
                        new SlimResponse(),
                        $db
                    );
                });
                if ($outcome->status === 403) {
                    return $this->forbidden($response);
                }
                if ($outcome->status === 422) {
                    return $this->invalid($response, 'mapping', is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('Mappatura delle colonne non valida.'));
                }
                if ($outcome->status === 400) {
                    // The staged file is gone (swept after an hour unused).
                    return $this->fileMissing($response, $job, null, null);
                }
                $after = $outcome->session['user_import'] ?? null;
                if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true || !is_array($after)) {
                    throw new \RuntimeException('user import start HTTP ' . $outcome->status);
                }
                $state['core'] = $after;
                // Kept beside the web state, which the last chunk replaces
                // with its final answer.
                $state['started'] = true;
                $state['mapping'] = is_array($after['mapping'] ?? null) ? $after['mapping'] : [];
                $state['send_invites'] = (bool) ($after['send_invites'] ?? false);
                $this->save($job, $state, 0, 'ready', []);

                return ResponseEnvelope::success($response, UserImportJob::map((array) $this->find($request, $id)));
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import start', $e);
        }
    }

    /** POST /staff/user-imports/{id}/chunk {start?} */
    public function chunk(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (array_key_exists('start', $json) && (!is_int($json['start']) || $json['start'] < 0)) {
            return $this->invalid($response, 'start', __('Valore non valido.'));
        }
        try {
            $job = $this->find($request, $id);
            if ($job === null) {
                return $this->notFound($response);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('user_import', (string) $job['id']);
            if (!$locks->lock($lock, 0)) {
                return $this->busy($response);
            }
            try {
                // Re-read under the lock: another chunk may just have finished.
                $job = (array) $this->find($request, $id);
                if (($gone = $this->gone($response, $job)) !== null) {
                    return $gone;
                }
                $state = $job['state'];
                $core = is_array($state['core'] ?? null) ? $state['core'] : [];
                if (empty($core['started'])) {
                    return ResponseEnvelope::error($response, 'not_started', __('Avvia l\'importazione prima di elaborare le righe.'), 409, ['processed' => 0]);
                }
                $processed = (int) $job['processed'];
                $start = array_key_exists('start', $json) ? (int) $json['start'] : $processed;
                if ($start !== $processed) {
                    return ResponseEnvelope::error($response, 'out_of_order', __('Blocco fuori sequenza: riprendi dalla riga indicata.'), 409, ['processed' => $processed]);
                }
                $payload = (string) json_encode(['preview_id' => (string) ($core['preview_id'] ?? ''), 'start' => $start]);
                $db = $this->db;
                $this->store->setStatus((string) $job['id'], 'running');
                $outcome = Delegation::run(static function () use ($db, $core, $payload): ResponseInterface {
                    $_SESSION['user_import'] = $core;

                    return (new UserImportController())->chunk(
                        Delegation::request('POST', '/admin/users/import/chunk', [], [], $payload),
                        new SlimResponse(),
                        $db
                    );
                });
                $after = $outcome->session['user_import'] ?? null;
                if ($outcome->status === 200 && ($outcome->json['success'] ?? false) === true && is_array($after)) {
                    $state['core'] = $after;
                    $done = ($outcome->json['done'] ?? false) === true;
                    $now = (int) ($outcome->json['processed'] ?? $processed);
                    if (!$this->store->saveChunk((string) $job['id'], $state, $now, $done ? 'completed' : 'ready', $outcome->json)) {
                        return $this->cancelledMeanwhile($response, $now);
                    }

                    return ResponseEnvelope::success($response, UserImportJob::map((array) $this->find($request, $id)));
                }
                if ($outcome->status === 403) {
                    $this->store->saveChunk((string) $job['id'], null, $processed, 'ready', $job['summary'] ?? []);

                    return $this->forbidden($response);
                }
                if ($outcome->status === 409 && array_key_exists('import_id', $outcome->json)) {
                    // The staged file vanished: the web closed the run into the
                    // import history as far as it got.
                    $importId = is_string($outcome->json['import_id']) ? $outcome->json['import_id'] : null;

                    return $this->fileMissing($response, $job, $importId, is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : null);
                }
                // Not completed: keep the job resumable from where the web
                // state says it is (a chunk that died mid-way still saved
                // its position row by row).
                if (is_array($after)) {
                    $processed = (int) ($after['next'] ?? $processed);
                }
                if (!$this->store->saveChunk((string) $job['id'], is_array($after) ? array_replace($state, ['core' => $after]) : null, $processed, 'ready', $job['summary'] ?? [])) {
                    return $this->cancelledMeanwhile($response, $processed);
                }
                if ($outcome->status === 409 && isset($outcome->json['processed'])) {
                    return ResponseEnvelope::error($response, 'out_of_order', __('Blocco fuori sequenza: riprendi dalla riga indicata.'), 409, ['processed' => $processed]);
                }
                SecureLogger::warning('[MobileApi] staff user import chunk failed with HTTP ' . $outcome->status);

                return ResponseEnvelope::error($response, 'internal_error', is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('Errore interno.'), 500);
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import chunk', $e);
        }
    }

    /** DELETE /staff/user-imports/{id} — idempotent. */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $job = $this->find($request, $id);
            if ($job === null) {
                return $this->notFound($response);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('user_import', (string) $job['id']);
            if (!$locks->lock($lock, self::CANCEL_LOCK_WAIT)) {
                return $this->busy($response);
            }
            try {
                $job = (array) $this->find($request, $id);
                $importId = UserImportJob::importId($job);
                $warning = null;
                if (!in_array($job['status'], ['completed', 'cancelled'], true)) {
                    $state = $job['state'];
                    $core = is_array($state['core'] ?? null) ? $state['core'] : [];
                    if (!empty($core['started']) && empty($core['finished'])) {
                        // A run started and interrupted: the web cancel writes the
                        // rows already done into the import history and releases
                        // the staged file. No chunk is in flight (the lock), so
                        // the run is not "still active" for the web check.
                        $core['active_at'] = 0;
                        $db = $this->db;
                        $payload = (string) json_encode(['preview_id' => (string) ($core['preview_id'] ?? '')]);
                        $outcome = Delegation::run(static function () use ($db, $core, $payload): ResponseInterface {
                            $_SESSION['user_import'] = $core;

                            return (new UserImportController())->cancel(
                                Delegation::request('POST', '/admin/users/import/cancel', [], [], $payload),
                                new SlimResponse(),
                                $db
                            );
                        });
                        if ($outcome->status === 403) {
                            return $this->forbidden($response);
                        }
                        if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                            throw new \RuntimeException('user import cancel HTTP ' . $outcome->status);
                        }
                        $importId = is_string($outcome->json['import_id'] ?? null) ? $outcome->json['import_id'] : null;
                        $warning = is_string($outcome->json['history_warning'] ?? null) ? $outcome->json['history_warning'] : null;
                        $state['core'] = ['cancelled' => true, 'preview_id' => (string) ($core['preview_id'] ?? '')];
                    } else {
                        // Never started: only the staged upload to release.
                        UserImportJob::unlinkUpload($core);
                    }
                    $summary = (is_array($job['summary'] ?? null) ? $job['summary'] : []) + ['import_id' => $importId !== '' ? $importId : null];
                    $this->save($job, $state, (int) $job['processed'], 'cancelled', $summary);
                }

                return ResponseEnvelope::success($response, [
                    'id'              => (string) $job['id'],
                    'cancelled'       => true,
                    'status'          => $job['status'] === 'completed' ? 'completed' : 'cancelled',
                    'import_id'       => $importId !== '' ? $importId : null,
                    'history_warning' => $warning,
                ]);
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import cancel', $e);
        }
    }

    /** GET /staff/user-imports/{id}/errors.csv */
    public function errorsCsv(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $job = $this->find($request, $id);
            if ($job === null) {
                return $this->notFound($response);
            }
            if (!in_array($job['status'], ['completed', 'cancelled', 'failed'], true)) {
                return ResponseEnvelope::error($response, 'import_not_finished', __('Il report sarà disponibile al termine dell\'importazione.'), 409, ['processed' => (int) $job['processed']]);
            }
            $importId = UserImportJob::importId($job);
            if ($importId === null || $importId === '') {
                return ResponseEnvelope::error($response, 'not_found', __('Report degli errori non disponibile per questa importazione.'), 404);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new UserImportController())->downloadErrors(
                Delegation::request('GET', '/admin/users/import/errors', [], ['import_id' => $importId]),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status === 403) {
                return $this->forbidden($response);
            }
            if ($outcome->status === 404 || $outcome->status === 400) {
                return ResponseEnvelope::error($response, 'not_found', __('Report degli errori non disponibile per questa importazione.'), 404);
            }
            if ($outcome->status !== 200) {
                throw new \RuntimeException('user import errors HTTP ' . $outcome->status);
            }

            return $this->csv($response, $outcome, 'import_errors.csv');
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import errors csv', $e);
        }
    }

    /** GET /staff/user-imports/example.csv */
    public function example(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new UserImportController())->example(
                Delegation::request('GET', '/admin/users/import/example'),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status === 403) {
                return $this->forbidden($response);
            }
            if ($outcome->status !== 200 || $outcome->body === '') {
                throw new \RuntimeException('user import example HTTP ' . $outcome->status);
            }

            return $this->csv($response, $outcome, 'esempio_import_utenti.csv');
        } catch (\Throwable $e) {
            return $this->fail($response, 'user import example', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null a `users` job visible to the caller (own; every job for an admin) */
    private function find(ServerRequestInterface $request, string $id): ?array
    {
        $job = $this->store->find($id, (int) (StaffPaging::user($request)['id'] ?? 0), StaffRoleMiddleware::isAdmin($request));

        return $job !== null && ($job['kind'] ?? '') === self::KIND ? $job : null;
    }

    /** 410 for a job that can take no more steps. @param array<string, mixed> $job */
    private function gone(ResponseInterface $response, array $job): ?ResponseInterface
    {
        if ($job['expired'] === true || in_array($job['status'], ['completed', 'cancelled', 'failed'], true)) {
            return ResponseEnvelope::error($response, 'gone', __('Importazione conclusa, annullata o scaduta.'), 410, ['status' => $job['expired'] ? 'expired' : $job['status']]);
        }

        return null;
    }

    /**
     * Persist a state change outside a chunk (saveChunk is a compare-and-set
     * on `running`).
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     */
    private function save(array $job, array $state, int $processed, string $status, array $summary): void
    {
        $this->store->setStatus((string) $job['id'], 'running');
        $this->store->saveChunk((string) $job['id'], $state, $processed, $status, $summary);
    }

    /**
     * The staged upload is gone (swept after STALE_UPLOAD_SECONDS unused):
     * the job fails; the rows already imported stay and are in the history.
     *
     * @param array<string, mixed> $job
     */
    private function fileMissing(ResponseInterface $response, array $job, ?string $importId, ?string $message): ResponseInterface
    {
        $state = $job['state'];
        $state['core'] = ['failed' => true, 'preview_id' => (string) ($state['core']['preview_id'] ?? '')];
        $summary = (is_array($job['summary'] ?? null) ? $job['summary'] : []) + ['import_id' => $importId !== '' ? $importId : null];
        $this->save($job, $state, (int) $job['processed'], 'failed', $summary);

        return ResponseEnvelope::error(
            $response,
            'file_missing',
            $message ?? __('Il file dell\'import non è più disponibile: carica di nuovo il file per completarlo.'),
            409,
            ['processed' => (int) $job['processed'], 'import_id' => $importId !== '' ? $importId : null]
        );
    }

    private function cancelledMeanwhile(ResponseInterface $response, int $processed): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'job_cancelled', __('Importazione annullata mentre il blocco era in corso.'), 409, ['processed' => $processed]);
    }

    private function csv(ResponseInterface $response, DelegationOutcome $outcome, string $fallback): ResponseInterface
    {
        $response->getBody()->write($outcome->body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition') ?: 'attachment; filename="' . $fallback . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function busy(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'job_busy', __('Un altro blocco di questa importazione è in corso.'), 409);
    }

    private function forbidden(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_permitted', __('Accesso negato.'), 403);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Importazione non trovata.'), 404);
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
