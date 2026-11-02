<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\CsvImportController;
use App\Controllers\LibraryThingImportController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * CSV / LibraryThing imports from the staff app (Mobile API 1.7.0).
 *
 * The upload and every chunk are delegated to CsvImportController /
 * LibraryThingImportController — the same parser, column mapping, duplicate
 * matching, scraping and import history as the web page. What the web keeps
 * in the browser session lives in ImportJobStore, so a job survives the app
 * going to the background and can be resumed from `processed`.
 */
final class StaffImportsController
{
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    /** Seconds DELETE /staff/imports/{id} waits for a chunk in flight. */
    private const CANCEL_LOCK_WAIT = 3;

    private ImportJobStore $store;

    public function __construct(private mysqli $db)
    {
        $this->store = new ImportJobStore($db);
    }

    // ─── Upload ─────────────────────────────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $kind = is_string($body['kind'] ?? null) && $body['kind'] !== '' ? (string) $body['kind'] : 'csv';
        if (!isset(ImportJobStore::SESSION_KEYS[$kind])) {
            return $this->invalid($response, 'kind', __('Valore non valido.'));
        }
        if ($kind === 'librarything' && !\App\Plugins\MobileApi\Support\StaffAreas::implemented('librarything')) {
            return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->invalid($response, 'file', __('Nessun file caricato'));
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return $this->invalid($response, 'file', __('Il file supera la dimensione massima consentita (20 MB).'));
        }
        $name = basename((string) ($file->getClientFilename() ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $kind === 'csv' ? ['csv'] : ['tsv', 'csv', 'txt'];
        if (!in_array($ext, $allowed, true)) {
            return $this->invalid($response, 'file', $kind === 'csv'
                ? __('Il file deve avere estensione .csv')
                : __('Il file deve avere estensione .tsv, .csv o .txt'));
        }
        $scraping = filter_var($body['enable_scraping'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $families = self::updateFamilies();
        $selected = $families;
        if ($kind === 'csv' && is_string($body['update_fields'] ?? null) && trim((string) $body['update_fields']) !== '') {
            $selected = array_values(array_filter(array_map('trim', explode(',', (string) $body['update_fields'])), static fn ($v) => $v !== ''));
            $unknown = array_diff($selected, $families);
            if ($unknown !== []) {
                return $this->invalid($response, 'update_fields', __('Valore non valido.'));
            }
        }

        $user = StaffPaging::user($request);
        $userId = (int) ($user['id'] ?? 0);
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        $sessionKey = ImportJobStore::SESSION_KEYS[$kind];
        $db = $this->db;
        try {
            $this->store->purgeExpired();
            $form = $scraping ? ['enable_scraping' => '1'] : [];
            if ($kind === 'csv') {
                foreach ($selected as $family) {
                    $form['update_' . $family] = '1';
                }
                // The core trusts the client MIME type; phones often send
                // application/octet-stream for a .csv picked from storage.
                $uri = $file->getStream()->getMetadata('uri');
                if (is_string($uri) && is_file($uri)) {
                    $file = new \Slim\Psr7\UploadedFile($uri, $name, 'text/csv', $size, UPLOAD_ERR_OK, false);
                }
            }
            $upload = $file;
            $outcome = Delegation::run(static function () use ($db, $kind, $form, $upload): ResponseInterface {
                $request = Delegation::request('POST', '/admin/books/import/upload', $form)
                    ->withUploadedFiles([$kind === 'csv' ? 'csv_file' : 'tsv_file' => $upload]);

                return $kind === 'csv'
                    ? (new CsvImportController())->processImport($request, new SlimResponse(), $db)
                    : (new LibraryThingImportController())->prepareImport($request, new SlimResponse());
            });
            if ($outcome->isRedirect() || $outcome->status >= 400 || ($outcome->json['success'] ?? false) !== true) {
                $message = $outcome->flash('error') ?? (is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('File non valido.'));
                if ($outcome->status >= 500) {
                    SecureLogger::warning('[MobileApi] staff import upload refused with HTTP ' . $outcome->status);
                }

                return $this->invalid($response, 'file', $message);
            }
            $state = $outcome->session[$sessionKey] ?? null;
            if (!is_array($state)) {
                throw new \RuntimeException('import state not captured');
            }
            $id = $this->store->create(
                $userId,
                is_int($tokenId) ? $tokenId : null,
                $kind,
                $name,
                (int) ($outcome->json['total_rows'] ?? 0),
                max(1, (int) ($outcome->json['chunk_size'] ?? 10)),
                $scraping,
                $state
            );
            $job = $this->findJob($id, $userId, true);

            return ResponseEnvelope::success($response, self::map((array) $job), [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'import upload', $e);
        }
    }

    // ─── Chunks ─────────────────────────────────────────────────────────────

    public function chunk(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        $json = JsonBody::parse($request);
        try {
            $job = $this->findJob($id, $userId, $isAdmin);
            if ($job === null) {
                return $this->notFound($response);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('import', (string) $job['id']);
            if (!$locks->lock($lock, 0)) {
                return ResponseEnvelope::error($response, 'job_busy', __('Un altro blocco di questa importazione è in corso.'), 409);
            }
            try {
                // Re-read under the lock: another chunk may just have finished.
                $job = (array) $this->findJob($id, $userId, $isAdmin);
                if ($job['expired'] === true || in_array($job['status'], ['completed', 'cancelled', 'failed'], true)) {
                    return ResponseEnvelope::error($response, 'gone', __('Importazione conclusa, annullata o scaduta.'), 410, ['status' => $job['expired'] ? 'expired' : $job['status']]);
                }
                $processed = (int) $job['processed'];
                $start = array_key_exists('start', $json) ? StaffPaging::id($json['start']) : $processed;
                if (array_key_exists('start', $json) && !is_int($json['start'])) {
                    return $this->invalid($response, 'start', __('Valore non valido.'));
                }
                if ($start !== $processed) {
                    return ResponseEnvelope::error($response, 'out_of_order', __('Blocco fuori sequenza: riprendi dalla riga indicata.'), 409, ['processed' => $processed]);
                }
                $chunkSize = (int) $job['chunk_size'];
                $size = array_key_exists('size', $json) ? max(1, min($chunkSize, StaffPaging::id($json['size']))) : $chunkSize;

                $kind = (string) $job['kind'];
                $sessionKey = ImportJobStore::SESSION_KEYS[$kind];
                $state = $job['state'];
                $payload = ['start' => $start, 'size' => $size];
                if ($kind === 'librarything') {
                    $payload['import_id'] = (string) ($state['import_id'] ?? '');
                }
                $db = $this->db;
                $this->store->setStatus((string) $job['id'], 'running');
                $outcome = Delegation::run(static function () use ($db, $kind, $sessionKey, $state, $payload): ResponseInterface {
                    $_SESSION[$sessionKey] = $state;
                    $request = Delegation::request('POST', '/admin/books/import/chunk', [], [], (string) json_encode($payload));

                    return $kind === 'csv'
                        ? (new CsvImportController())->processChunk($request, new SlimResponse(), $db)
                        : (new LibraryThingImportController())->processChunk($request, new SlimResponse(), $db);
                });
                $after = $outcome->session[$sessionKey] ?? null;
                if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                    // The rows were not advanced: keep the job resumable.
                    if (!$this->store->saveChunk((string) $job['id'], is_array($after) ? $after : null, $processed, 'ready', $job['summary'] ?? [])) {
                        return $this->cancelledMeanwhile($response, $processed);
                    }
                    SecureLogger::warning('[MobileApi] staff import chunk failed with HTTP ' . $outcome->status);

                    return ResponseEnvelope::error($response, 'internal_error', is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('Errore interno.'), 500);
                }
                $done = $processed + (int) ($outcome->json['processed'] ?? 0);
                $complete = ($outcome->json['complete'] ?? false) === true;
                $summary = $outcome->json;
                if (!$this->store->saveChunk((string) $job['id'], is_array($after) ? $after : null, $done, $complete ? 'completed' : 'ready', $summary)) {
                    // Cancelled while this chunk ran: the job stays cancelled.
                    if ($kind === 'librarything') {
                        ImportJobStore::unlinkUpload(is_array($after) ? $after : $state);
                    }

                    return $this->cancelledMeanwhile($response, $done);
                }
                if ($complete && $kind === 'librarything') {
                    // The web clears this state when its results page loads.
                    ImportJobStore::unlinkUpload(is_array($after) ? $after : $state);
                }

                return ResponseEnvelope::success($response, self::map((array) $this->findJob((string) $job['id'], $userId, true)));
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'import chunk', $e);
        }
    }

    /**
     * The job was cancelled while this chunk ran (outside the chunk lock):
     * the rows the importer already wrote stay in the catalogue, the job
     * stays cancelled and `processed` tells how far the chunk got.
     */
    private function cancelledMeanwhile(ResponseInterface $response, int $processed): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'job_cancelled', __('Importazione annullata mentre il blocco era in corso.'), 409, ['processed' => $processed]);
    }

    // ─── Reads / cancel ─────────────────────────────────────────────────────

    public function detail(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $job = $this->findJob($id, (int) (StaffPaging::user($request)['id'] ?? 0), StaffRoleMiddleware::isAdmin($request));

            return $job === null ? $this->notFound($response) : ResponseEnvelope::success($response, self::map($job));
        } catch (\Throwable $e) {
            return $this->fail($response, 'import detail', $e);
        }
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        try {
            $job = $this->findJob($id, $userId, $isAdmin);
            if ($job === null) {
                return $this->notFound($response);
            }
            // The chunk lock: a chunk in flight is never cancelled under its
            // feet (its rows would land while the job reads "cancelled", and
            // its save would resurrect the job). Wait briefly, then let the
            // client retry once the chunk is done.
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('import', (string) $job['id']);
            if (!$locks->lock($lock, self::CANCEL_LOCK_WAIT)) {
                return ResponseEnvelope::error($response, 'job_busy', __('Un altro blocco di questa importazione è in corso.'), 409);
            }
            try {
                $job = (array) $this->findJob($id, $userId, $isAdmin);
                if (!in_array($job['status'], ['completed', 'cancelled'], true)) {
                    $this->store->cancel($job);
                }
            } finally {
                $locks->unlock($lock);
            }

            return ResponseEnvelope::success($response, ['id' => (string) $job['id'], 'cancelled' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'import cancel', $e);
        }
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        $limit = StaffPaging::limit($request, 30, 100);
        $offset = StaffPaging::offset($request);
        try {
            $this->store->purgeExpired();
            $rows = $this->store->listFor($userId, $offset, $limit, array_keys(ImportJobStore::SESSION_KEYS));
            $hasMore = count($rows) > $limit;
            $jobs = array_map(static fn (array $r): array => self::map($r), array_slice($rows, 0, $limit));

            return ResponseEnvelope::success($response, [
                'jobs'    => $jobs,
                'history' => $this->history($userId, $isAdmin),
            ], StaffPaging::meta(count($jobs), $limit, $offset, $hasMore));
        } catch (\Throwable $e) {
            return $this->fail($response, 'import list', $e);
        }
    }

    public function example(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CsvImportController())->downloadExample(
                Delegation::request('GET', '/admin/books/import/example'),
                new SlimResponse()
            ));
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="esempio_import_libri.csv"')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'import example', $e);
        }
    }

    /** GET /staff/exports/librarything.tsv — the whole catalogue in LibraryThing's TSV. */
    public function exportLibraryThing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibraryThingImportController())->exportToLibraryThing(
                Delegation::request('GET', '/admin/books/export/librarything'),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200) {
                throw new \RuntimeException('exportToLibraryThing answered HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8')
                ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition') ?: 'attachment; filename="librarything_export.tsv"')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'librarything export', $e);
        }
    }

    public function errorsCsv(ServerRequestInterface $request, ResponseInterface $response, string $importId): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new \App\Controllers\ImportHistoryController())->downloadErrors(
                Delegation::request('GET', '/admin/imports/download-errors', [], ['import_id' => $importId]),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status === 403) {
                return ResponseEnvelope::error($response, 'forbidden', __('Operazione riservata allo staff.'), 403);
            }
            if ($outcome->status === 404 || $outcome->status === 400) {
                return $this->notFound($response);
            }
            if ($outcome->status !== 200) {
                throw new \RuntimeException('downloadErrors answered HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition') ?: 'attachment; filename="import_errors.csv"')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'import errors csv', $e);
        }
    }

    // ─── Mapping ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $job hydrated row
     * @return array<string, mixed> ImportJob
     */
    public static function map(array $job): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $counter = static fn (string $key): int => (int) ($summary[$key] ?? $state[$key] ?? 0);
        $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
        // The last chunk's own answer is the authority when it carries the
        // list: the CSV importer clears its session state on the final chunk,
        // so the saved state still holds the errors of the chunk BEFORE it,
        // while summary.error_details / summary.errors cover the whole run.
        if (is_array($summary['error_details'] ?? null)) {
            $details = array_values(array_map(static fn ($d): string => html_entity_decode((string) $d, ENT_QUOTES, 'UTF-8'), $summary['error_details']));
        } else {
            $details = [];
            foreach (array_slice($errors, 0, 50) as $err) {
                $details[] = is_array($err)
                    ? sprintf('Riga %d (%s): %s', (int) ($err['line'] ?? 0), (string) ($err['title'] ?? ''), (string) ($err['message'] ?? ''))
                    : (string) $err;
            }
        }
        $errorCount = isset($summary['errors']) ? (int) $summary['errors'] : count($errors);
        $total = (int) $job['total_rows'];
        $processed = (int) $job['processed'];

        return [
            'id'                 => (string) $job['id'],
            'kind'               => (string) $job['kind'],
            'file_name'          => (string) $job['file_name'],
            'status'             => (string) $job['status'],
            'total_rows'         => $total,
            'processed'          => $processed,
            'chunk_size'         => (int) $job['chunk_size'],
            'enable_scraping'    => (int) $job['enable_scraping'] === 1,
            'imported'           => $counter('imported'),
            'updated'            => $counter('updated'),
            'scraped'            => $counter('scraped'),
            'authors_created'    => $counter('authors_created'),
            'publishers_created' => $counter('publishers_created'),
            'errors'             => $errorCount,
            'error_details'      => $details,
            'complete'           => $job['status'] === 'completed',
            'created_at'         => (string) $job['created_at'],
            'updated_at'         => (string) $job['updated_at'],
            'expires_at'         => (string) $job['expires_at'],
        ];
    }

    /**
     * Import history (import_logs): own imports, every import for an admin —
     * the rule of ImportHistoryController.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $userId, bool $isAdmin): array
    {
        $sql = "SELECT il.import_id, il.import_type, il.file_name, il.user_id, il.total_rows, il.imported, il.updated,
                       il.failed, il.status, il.started_at, il.completed_at, u.nome, u.cognome
                  FROM import_logs il LEFT JOIN utenti u ON u.id = il.user_id"
            . ($isAdmin ? '' : ' WHERE il.user_id = ?')
            . ' ORDER BY il.started_at DESC LIMIT 50';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if (!$isAdmin) {
            $stmt->bind_param('i', $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = [
                'import_id'    => (string) $r['import_id'],
                'kind'         => (string) $r['import_type'],
                'file_name'    => (string) ($r['file_name'] ?? ''),
                'user'         => $r['user_id'] !== null ? ['id' => (int) $r['user_id'], 'name' => \App\Plugins\MobileApi\Support\StaffLoanReader::name($r['nome'] ?? '', $r['cognome'] ?? '')] : null,
                'total_rows'   => (int) $r['total_rows'],
                'imported'     => (int) $r['imported'],
                'updated'      => (int) $r['updated'],
                'failed'       => (int) $r['failed'],
                'status'       => (string) $r['status'],
                'started_at'   => $r['started_at'] !== null ? (string) $r['started_at'] : null,
                'completed_at' => $r['completed_at'] !== null ? (string) $r['completed_at'] : null,
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * CsvImportController::UPDATE_FIELD_FAMILIES (private in the core).
     *
     * @return list<string>
     */
    public static function updateFamilies(): array
    {
        $constant = new \ReflectionClassConstant(CsvImportController::class, 'UPDATE_FIELD_FAMILIES');
        $value = $constant->getValue();

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Importazione non trovata.'), 404);
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

    /**
     * A CSV or LibraryThing job, or null. The store also holds the 0.9.0 job
     * kinds (MARC, patron CSV, copy cataloguing), which these endpoints must
     * answer as not found rather than drive with the wrong importer.
     *
     * @return array<string, mixed>|null
     */
    private function findJob(string $id, int $userId, bool $isAdmin): ?array
    {
        $job = $this->store->find($id, $userId, $isAdmin);

        return $job !== null && isset(ImportJobStore::SESSION_KEYS[(string) ($job['kind'] ?? '')]) ? $job : null;
    }
}
