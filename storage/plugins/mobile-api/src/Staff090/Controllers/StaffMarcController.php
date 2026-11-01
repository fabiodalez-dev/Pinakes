<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\MarcExportController;
use App\Controllers\MarcImportController;
use App\Plugins\MobileApi\Staff090\Support\MarcJobMapper;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use App\Support\Features;
use App\Support\Marc\GeneratorStream;
use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\MarcBookImporter;
use App\Support\Marc\MarcFormat;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * MARC bulk import and export for the staff app (Pinakes 0.9.0, module
 * `marc`).
 *
 * The import is the web's (MarcImportController: upload + preview, then
 * chunks of at most 10 records, import history type `marc`). What the web
 * keeps in the browser session (`marc_import_data`) is kept in
 * ImportJobStore as a job of kind `marc`, exactly like the CSV import of
 * StaffImportsController, with the same chunk contract: `start` must equal
 * `processed` (409 out_of_order otherwise), one chunk at a time per job
 * (409 job_busy), 410 gone once the job is completed, cancelled or expired.
 *
 * The export streams the records MarcExportController::generate() writes.
 */
final class StaffMarcController
{
    public const KIND = 'marc';
    private const SESSION_KEY = 'marc_import_data';
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    private const EXTENSIONS = ['mrc', 'marc', 'iso', 'dat', 'xml', 'marcxml'];
    private const MAX_EXPORT_IDS = 1000;
    private const CANCEL_LOCK_WAIT = 3;

    private ImportJobStore $store;

    public function __construct(private mysqli $db)
    {
        $this->store = new ImportJobStore($db);
    }

    // ─── Options ────────────────────────────────────────────────────────────

    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $suppliers = null;
            if (Features::enabled('suppliers')) {
                $suppliers = [];
                foreach ((new \App\Models\SupplierRepository($this->db))->options() as $id => $name) {
                    $suppliers[] = ['id' => $id, 'name' => $name];
                }
            }
            $copy = StaffCopyCatalogingController::availability($this->db);

            return ResponseEnvelope::success($response, [
                'flavours' => [
                    ['value' => MarcFormat::AUTO, 'label' => __('Riconoscimento automatico')],
                    ['value' => MarcFormat::MARC21, 'label' => 'MARC 21'],
                    ['value' => MarcFormat::UNIMARC, 'label' => 'UNIMARC'],
                    ['value' => MarcFormat::DANMARC2, 'label' => 'danMARC2'],
                ],
                'encodings' => [
                    ['value' => Iso2709Reader::ENCODING_AUTO, 'label' => __('Dal leader del record (posizione 9)')],
                    ['value' => Iso2709Reader::ENCODING_UTF8, 'label' => 'UTF-8'],
                    ['value' => Iso2709Reader::ENCODING_MARC8, 'label' => 'MARC-8'],
                ],
                'duplicate_modes' => self::duplicateModes(),
                'export_formats' => [
                    ['value' => 'marc21', 'label' => __('MARC 21 (.mrc)')],
                    ['value' => 'marcxml', 'label' => __('MARCXML')],
                    ['value' => 'unimarc', 'label' => __('UNIMARC (.mrc)')],
                ],
                'extensions' => self::EXTENSIONS,
                'max_file_bytes' => self::MAX_UPLOAD_BYTES,
                'max_records' => 20000,
                'max_export_ids' => self::MAX_EXPORT_IDS,
                'suppliers' => $suppliers,
                'copy_cataloging' => $copy,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'marc options', $e);
        }
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function duplicateModes(): array
    {
        return [
            ['value' => MarcBookImporter::MODE_SKIP, 'label' => __('Salta'), 'description' => __('Il libro già in catalogo resta com\'è')],
            ['value' => MarcBookImporter::MODE_FILL, 'label' => __('Completa i campi vuoti'), 'description' => __('Solo i campi vuoti del libro vengono riempiti dal record')],
            ['value' => MarcBookImporter::MODE_OVERWRITE, 'label' => __('Sovrascrivi'), 'description' => __('I campi presenti nel record sostituiscono quelli del libro; gli altri restano')],
        ];
    }

    // ─── Upload ─────────────────────────────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->invalid($response, ['file' => __('Nessun file caricato')]);
        }
        $name = basename((string) ($file->getClientFilename() ?? ''));
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return $this->invalid($response, ['file' => __('Il file deve essere un file MARC (.mrc, .marc, .iso) o MARCXML (.xml)')]);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return $this->invalid($response, ['file' => __('File troppo grande. Dimensione massima %d MB.', (int) (self::MAX_UPLOAD_BYTES / 1048576))]);
        }

        $errors = [];
        $flavour = self::choice($body, 'flavour', array_merge([MarcFormat::AUTO], MarcFormat::FLAVOURS), MarcFormat::AUTO, $errors);
        $encoding = self::choice($body, 'encoding', [Iso2709Reader::ENCODING_AUTO, Iso2709Reader::ENCODING_UTF8, Iso2709Reader::ENCODING_MARC8], Iso2709Reader::ENCODING_AUTO, $errors);
        $mode = self::choice($body, 'duplicates', MarcBookImporter::MODES, MarcBookImporter::MODE_SKIP, $errors);
        $covers = filter_var($body['download_covers'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $supplierId = null;
        $rawSupplier = $body['supplier_id'] ?? null;
        if (Features::enabled('suppliers') && is_scalar($rawSupplier) && trim((string) $rawSupplier) !== '') {
            $supplierId = StaffPaging::id($rawSupplier);
            if ($supplierId <= 0) {
                $errors['supplier_id'] = __('Valore non valido.');
            } elseif (\App\Support\CataloguingExtras::existingSupplierId($this->db, $supplierId) === null) {
                $errors['supplier_id'] = __('Riferimento inesistente.');
            }
        }
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }

        $user = StaffPaging::user($request);
        $userId = (int) ($user['id'] ?? 0);
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        $db = $this->db;
        // The core state once the upload staged its file, until a job row owns it.
        $staged = null;
        try {
            $this->store->purgeExpired();
            // Widen `kind` before the core stages the file, so a failing ALTER
            // refuses the upload instead of stranding a file no job points to.
            if (!ImportJobStore::widenKind($db)) {
                throw new \RuntimeException('import job kind column could not be widened');
            }
            $form = ['flavour' => $flavour, 'encoding' => $encoding, 'duplicates' => $mode];
            if ($covers) {
                $form['download_covers'] = '1';
            }
            if ($supplierId !== null) {
                $form['fornitore_id'] = (string) $supplierId;
            }
            $outcome = Delegation::run(static function () use ($db, $form, $file): ResponseInterface {
                $request = Delegation::request('POST', '/admin/books/import/marc/upload', $form)
                    ->withUploadedFiles(['marc_file' => $file]);

                return (new MarcImportController())->upload($request, new SlimResponse(), $db);
            });
            if ($outcome->status >= 500) {
                SecureLogger::warning('[MobileApi] staff MARC upload refused with HTTP ' . $outcome->status);

                return ResponseEnvelope::error($response, 'internal_error', is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('Errore interno.'), 500);
            }
            if ($outcome->isRedirect() || ($outcome->json['success'] ?? false) !== true) {
                return $this->invalid($response, ['file' => is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('File non valido.')]);
            }
            $state = $outcome->session[self::SESSION_KEY] ?? null;
            if (!is_array($state)) {
                throw new \RuntimeException('MARC import state not captured');
            }
            $staged = $state;
            // What the upload answered besides the core state: kept with the
            // job so GET /staff/marc/imports/{id} shows the preview again.
            $state[MarcJobMapper::UPLOAD_KEY] = [
                'unreadable' => (int) ($outcome->json['unreadable'] ?? 0),
                'truncated'  => is_string($outcome->json['truncated'] ?? null) ? (string) $outcome->json['truncated'] : null,
                'container'  => (string) ($outcome->json['container'] ?? ''),
                'flavour'    => $flavour,
                'encoding'   => $encoding,
                'preview'    => is_array($outcome->json['preview'] ?? null) ? array_values($outcome->json['preview']) : [],
            ];
            $id = $this->store->create(
                $userId,
                is_int($tokenId) ? $tokenId : null,
                self::KIND,
                $name,
                (int) ($outcome->json['total_rows'] ?? 0),
                max(1, (int) ($outcome->json['chunk_size'] ?? 10)),
                $covers,
                $state
            );
            $staged = null;

            return ResponseEnvelope::success($response, MarcJobMapper::map((array) $this->store->find($id, $userId, true)), [], 201);
        } catch (\Throwable $e) {
            // No job row: purgeExpired() would never find the staged file.
            if (is_array($staged)) {
                ImportJobStore::unlinkUpload($staged);
            }
            return $this->fail($response, 'marc upload', $e);
        }
    }

    // ─── Chunks ─────────────────────────────────────────────────────────────

    public function chunk(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        $json = JsonBody::parse($request);
        try {
            $job = $this->job($id, $userId, $isAdmin);
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
                $job = (array) $this->job($id, $userId, $isAdmin);
                if ($job['expired'] === true || in_array($job['status'], ['completed', 'cancelled', 'failed'], true)) {
                    return ResponseEnvelope::error($response, 'gone', __('Importazione conclusa, annullata o scaduta.'), 410, ['status' => $job['expired'] ? 'expired' : $job['status']]);
                }
                $processed = (int) $job['processed'];
                if (array_key_exists('start', $json) && !is_int($json['start'])) {
                    return $this->invalid($response, ['start' => __('Valore non valido.')]);
                }
                $start = array_key_exists('start', $json) ? StaffPaging::id($json['start']) : $processed;
                if ($start !== $processed) {
                    return ResponseEnvelope::error($response, 'out_of_order', __('Blocco fuori sequenza: riprendi dalla riga indicata.'), 409, ['processed' => $processed]);
                }
                $chunkSize = (int) $job['chunk_size'];
                $size = array_key_exists('size', $json) ? max(1, min($chunkSize, StaffPaging::id($json['size']))) : $chunkSize;

                $state = $job['state'];
                $payload = ['staging_id' => (string) ($state['staging_id'] ?? ''), 'start' => $start, 'size' => $size];
                $db = $this->db;
                $this->store->setStatus((string) $job['id'], 'running');
                $outcome = Delegation::run(static function () use ($db, $state, $payload): ResponseInterface {
                    $_SESSION[self::SESSION_KEY] = $state;

                    return (new MarcImportController())->chunk(
                        Delegation::request('POST', '/admin/books/import/marc/chunk', [], [], (string) json_encode($payload)),
                        new SlimResponse(),
                        $db
                    );
                });
                $after = $outcome->session[self::SESSION_KEY] ?? null;
                if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                    // The job stays resumable from where it was.
                    if (!$this->store->saveChunk((string) $job['id'], is_array($after) ? $after : null, $processed, 'ready', $job['summary'] ?? [])) {
                        return $this->cancelledMeanwhile($response, $processed);
                    }
                    SecureLogger::warning('[MobileApi] staff MARC chunk failed with HTTP ' . $outcome->status);

                    return ResponseEnvelope::error($response, 'internal_error', is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : __('Errore interno.'), 500);
                }
                // The MARC chunk answers the absolute position, not a delta.
                $done = (int) ($outcome->json['processed'] ?? $processed);
                $complete = ($outcome->json['complete'] ?? false) === true;
                if (!$this->store->saveChunk((string) $job['id'], is_array($after) ? $after : null, $done, $complete ? 'completed' : 'ready', $outcome->json)) {
                    return $this->cancelledMeanwhile($response, $done);
                }

                return ResponseEnvelope::success($response, MarcJobMapper::map((array) $this->store->find((string) $job['id'], $userId, true)));
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'marc chunk', $e);
        }
    }

    // ─── Reads / cancel ─────────────────────────────────────────────────────

    /**
     * GET /staff/marc/imports: the caller's unexpired MARC jobs, newest first,
     * so an import left running on another device or after the app lost its
     * job id can be found and resumed.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $limit = StaffPaging::limit($request, 30, 100);
        $offset = StaffPaging::offset($request);
        try {
            $this->store->purgeExpired();
            $rows = $this->store->listFor((int) (StaffPaging::user($request)['id'] ?? 0), $offset, $limit, [self::KIND]);
            $hasMore = count($rows) > $limit;
            $jobs = array_map(static fn (array $r): array => MarcJobMapper::map($r), array_slice($rows, 0, $limit));

            return ResponseEnvelope::success($response, $jobs, StaffPaging::meta(count($jobs), $limit, $offset, $hasMore));
        } catch (\Throwable $e) {
            return $this->fail($response, 'marc list', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $job = $this->job($id, (int) (StaffPaging::user($request)['id'] ?? 0), StaffRoleMiddleware::isAdmin($request));

            return $job === null ? $this->notFound($response) : ResponseEnvelope::success($response, MarcJobMapper::map($job));
        } catch (\Throwable $e) {
            return $this->fail($response, 'marc detail', $e);
        }
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        try {
            $job = $this->job($id, $userId, $isAdmin);
            if ($job === null) {
                return $this->notFound($response);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('import', (string) $job['id']);
            if (!$locks->lock($lock, self::CANCEL_LOCK_WAIT)) {
                return ResponseEnvelope::error($response, 'job_busy', __('Un altro blocco di questa importazione è in corso.'), 409);
            }
            try {
                $job = (array) $this->job($id, $userId, $isAdmin);
                if (!in_array($job['status'], ['completed', 'cancelled'], true)) {
                    // Drops the staged records too (the web's "discard").
                    $this->store->cancel($job);
                }
            } finally {
                $locks->unlock($lock);
            }

            return ResponseEnvelope::success($response, ['id' => (string) $job['id'], 'cancelled' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'marc cancel', $e);
        }
    }

    // ─── Export ─────────────────────────────────────────────────────────────

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $format = is_string($params['format'] ?? null) && $params['format'] !== '' ? (string) $params['format'] : 'marc21';
        if (!in_array($format, MarcExportController::FORMATS, true)) {
            return $this->invalid($response, ['format' => __('Valore non valido.')]);
        }
        $ids = null;
        if (array_key_exists('ids', $params)) {
            if (!is_string($params['ids'])) {
                return $this->invalid($response, ['ids' => __('Selezione non valida per l\'esportazione: riprova dalla lista dei libri.')]);
            }
            $ids = MarcExportController::parseIds($params['ids']);
            if ($ids === []) {
                return $this->invalid($response, ['ids' => __('Selezione non valida per l\'esportazione: riprova dalla lista dei libri.')]);
            }
            if (count($ids) > self::MAX_EXPORT_IDS) {
                return $this->invalid($response, ['ids' => sprintf(__('Selezione troppo grande per l\'esportazione: %d libri (massimo 1000). Riduci la selezione e riprova.'), count($ids))]);
            }
        }
        $stamp = date('Y-m-d');
        [$contentType, $filename] = match ($format) {
            'marcxml' => ['application/marcxml+xml; charset=UTF-8', "catalogo_marcxml_{$stamp}.xml"],
            'unimarc' => ['application/marc', "catalogo_unimarc_{$stamp}.mrc"],
            default => ['application/marc', "catalogo_marc21_{$stamp}.mrc"],
        };
        SecureLogger::info('[MARC export]', [
            'format' => $format,
            'selected' => $ids === null ? null : count($ids),
            'user_id' => StaffPaging::user($request)['id'] ?? null,
            'source' => 'mobile-api',
        ]);

        return $response
            ->withStatus(200)
            ->withBody(new GeneratorStream((new MarcExportController())->generate($this->db, $format, $ids)))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Accel-Buffering', 'no');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * A MARC job visible to this operator (own jobs; an admin sees every
     * job). A CSV or other job id is a 404 here.
     *
     * @return array<string, mixed>|null
     */
    private function job(string $id, int $userId, bool $isAdmin): ?array
    {
        $job = $this->store->find($id, $userId, $isAdmin);

        return $job !== null && $job['kind'] === self::KIND ? $job : null;
    }

    /**
     * A multipart choice: absent/empty = default, unknown = error.
     *
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     * @param array<string, string> $errors
     */
    private static function choice(array $body, string $key, array $allowed, string $default, array &$errors): string
    {
        $raw = $body[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            $errors[$key] = __('Valore non valido.');

            return $default;
        }

        return $raw;
    }

    private function cancelledMeanwhile(ResponseInterface $response, int $processed): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'job_cancelled', __('Importazione annullata mentre il blocco era in corso.'), 409, ['processed' => $processed]);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation', (string) reset($fields), 422, ['fields' => $fields, 'field' => (string) array_key_first($fields)]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Importazione non trovata.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
