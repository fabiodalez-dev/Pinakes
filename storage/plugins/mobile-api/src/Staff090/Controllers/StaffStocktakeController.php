<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\InventoryController;
use App\Plugins\MobileApi\Staff090\Support\StocktakeVocabulary as V;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffCopyReader;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use App\Services\StocktakeService;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Inventory stocktake from the app (Mobile API 1.10.0, Pinakes 0.9.0): the
 * phone is the scanner of a revision session opened on the web or here.
 *
 * Writes that the web performs in InventoryController (open, scan, close,
 * cancel, the CSV/PDF reports) are delegated to it in-process, so the feature
 * check, the admin-only "mark the missing copies as lost", the error messages,
 * the activity log and the logging stay the web's. The file upload calls
 * StocktakeService directly with the controller's own checks (extension,
 * 2 MB, the same parser), because the web answers it with a flash sentence
 * while the app needs the count of every outcome. Reads use StocktakeService,
 * the class the web pages read.
 */
final class StaffStocktakeController
{
    /** InventoryController::UPLOAD_MAX_BYTES (private there). */
    public const UPLOAD_MAX_BYTES = 2_097_152;
    public const UPLOAD_EXTENSIONS = ['txt', 'csv'];
    /** InventoryController::show() shows the 50 most recent scans. */
    private const RECENT_SCANS = 50;

    private StocktakeService $service;

    public function __construct(private mysqli $db)
    {
        $this->service = new StocktakeService($db);
    }

    // ─── Reads ───────────────────────────────────────────────────────────

    /** GET /staff/stocktake/options — everything the create form and the scan screen need. */
    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $lookups = (new InventoryController())->lookups($this->db);
            $levels = [];
            foreach ($lookups['mensole'] as $level) {
                $levels[(int) $level['scaffale_id']][] = ['id' => (int) $level['id'], 'level' => (int) $level['numero_livello']];
            }
            $shelves = [];
            foreach ($lookups['scaffali'] as $shelf) {
                $id = (int) $shelf['id'];
                $shelves[] = [
                    'id'     => $id,
                    'code'   => (string) ($shelf['codice'] ?? ''),
                    'name'   => (string) ($shelf['nome'] ?? ''),
                    'label'  => $this->service->shelfLabel($id),
                    'levels' => $levels[$id] ?? [],
                ];
            }
            $labels = InventoryController::outcomeLabels();
            $outcomes = [];
            foreach (V::OUTCOMES as $value => $code) {
                $outcomes[] = [
                    'code'        => $code,
                    'label'       => $labels[$value] ?? $value,
                    'color'       => V::outcomeColor($value),
                    'tone'        => V::tone($value),
                    'scan_result' => $value !== 'mancante',
                ];
            }
            $statuses = [];
            foreach (V::STATUSES as $value => $code) {
                $statuses[] = ['code' => $code, 'label' => $this->statusLabel($value), 'color' => V::statusColor($value)];
            }
            $media = [];
            foreach (StocktakeService::MEDIA_TYPES as $type) {
                $media[] = ['code' => $type, 'label' => \App\Support\MediaLabels::tipoMediaDisplayName($type)];
            }
            $isAdmin = StaffRoleMiddleware::isAdmin($request);

            return ResponseEnvelope::success($response, [
                'branches'        => array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['nome']], $lookups['sedi']),
                'shelves'         => $shelves,
                'genres'          => array_map(static fn (array $g): array => ['id' => (int) $g['id'], 'label' => (string) $g['label']], $lookups['generi']),
                'media_types'     => $media,
                'outcomes'        => $outcomes,
                'statuses'        => $statuses,
                'missing_actions' => [
                    ['code' => 'none', 'label' => __('Lascia le copie mancanti come sono'), 'admin_only' => false],
                    ['code' => 'lost', 'label' => __('Segna come perse le copie mancanti'), 'admin_only' => true],
                ],
                'can_mark_lost'   => $isAdmin,
                'limits'          => [
                    'max_code_length'        => StocktakeService::MAX_CODE_LENGTH,
                    'max_upload_codes'       => StocktakeService::MAX_UPLOAD_CODES,
                    'max_upload_bytes'       => self::UPLOAD_MAX_BYTES,
                    'upload_extensions'      => self::UPLOAD_EXTENSIONS,
                    'max_name_length'        => 255,
                    'max_collocation_length' => 50,
                    'scans_per_minute'       => 600,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'options', $e);
        }
    }

    /** GET /staff/stocktake/sessions — open sessions first, then the history, with their counters. */
    public function sessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $statusCode = StaffPaging::query($request, 'status');
        $status = null;
        if ($statusCode !== '' && $statusCode !== 'all') {
            $status = V::statusValue($statusCode);
            if ($status === null) {
                return $this->invalid($response, ['status' => __('Filtri non validi.')]);
            }
        }
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        try {
            // The web list (every open session plus the latest closed ones),
            // filtered and paged here: the same order the admin page shows.
            $rows = $this->service->listSessions(500);
            if ($status !== null) {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) $r['stato'] === $status));
            }
            $total = count($rows);
            $items = [];
            foreach (array_slice($rows, $offset, $limit) as $row) {
                $session = $this->service->find((int) $row['id']);
                if ($session !== null) {
                    $items[] = $this->sessionPayload($request, $session, false, false);
                }
            }

            return ResponseEnvelope::success($response, $items, StaffPaging::meta(count($items), $limit, $offset, $offset + $limit < $total, $total));
        } catch (\Throwable $e) {
            return $this->fail($response, 'sessions', $e);
        }
    }

    /** GET /staff/stocktake/sessions/{id} — detail, counters, latest scans, history. */
    public function show(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }

            return ResponseEnvelope::success($response, $this->sessionPayload($request, $session, true, $this->mine($request)));
        } catch (\Throwable $e) {
            return $this->fail($response, 'session', $e);
        }
    }

    /** GET /staff/stocktake/sessions/{id}/state — light polling (the web scan page polls /state). */
    public function state(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }
            $operator = $this->operatorId($request);
            $mine = $this->mine($request);

            return ResponseEnvelope::success($response, [
                'id'           => $id,
                'status'       => V::status((string) $session['stato']),
                'summary'      => V::summary($this->service->summary($id, $operator)),
                'recent_scans' => array_map(fn (array $r): array => $this->scanItem($r), $this->service->scans($id, $mine ? $operator : null, self::RECENT_SCANS)),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'state', $e);
        }
    }

    /** GET /staff/stocktake/sessions/{id}/scans — every scan, newest first, paged. */
    public function scans(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $outcomeCode = StaffPaging::query($request, 'outcome');
        $outcome = null;
        if ($outcomeCode !== '') {
            $outcome = V::outcomeValue($outcomeCode);
            if ($outcome === null || !in_array($outcome, StocktakeService::OUTCOMES, true)) {
                return $this->invalid($response, ['outcome' => __('Filtri non validi.')]);
            }
        }
        $limit = StaffPaging::limit($request, 50, 200);
        $offset = StaffPaging::offset($request);
        try {
            if ($this->service->find($id) === null) {
                return $this->notFound($response);
            }
            $operator = $this->mine($request) ? $this->operatorId($request) : null;
            $total = $this->service->scanCount($id, $operator, $outcome);
            $rows = $this->service->scans($id, $operator, $limit, $offset, $outcome);

            return ResponseEnvelope::success(
                $response,
                array_map(fn (array $r): array => $this->scanItem($r), $rows),
                StaffPaging::meta(count($rows), $limit, $offset, $offset + $limit < $total, $total)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'scans', $e);
        }
    }

    /**
     * GET /staff/stocktake/sessions/{id}/report — the report rows (every
     * expected copy with its bucket, then every scan not about an expected
     * copy), as the web final report and the CSV list them.
     */
    public function report(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $categoryCode = StaffPaging::query($request, 'category');
        $category = null;
        if ($categoryCode !== '') {
            $category = V::outcomeValue($categoryCode);
            if ($category === null) {
                return $this->invalid($response, ['category' => __('Filtri non validi.')]);
            }
        }
        $limit = StaffPaging::limit($request, 50, 200);
        $offset = StaffPaging::offset($request);
        try {
            if ($this->service->find($id) === null) {
                return $this->notFound($response);
            }
            $labels = InventoryController::outcomeLabels();
            $items = [];
            $seen = 0;
            $hasMore = false;
            foreach ($this->service->eachReportRow($id) as $row) {
                if ($category !== null && $row['categoria'] !== $category) {
                    continue;
                }
                if ($seen++ < $offset) {
                    continue;
                }
                if (count($items) >= $limit) {
                    $hasMore = true;
                    break;
                }
                $items[] = [
                    'category'          => V::outcome($row['categoria']),
                    'category_label'    => $labels[$row['categoria']] ?? $row['categoria'],
                    'color'             => V::outcomeColor($row['categoria']),
                    'code'              => $row['codice'],
                    'title'             => $row['titolo'],
                    'media_type'        => $row['tipo_media'] !== '' ? $row['tipo_media'] : null,
                    'collocation'       => $row['collocazione'] !== '' ? $row['collocazione'] : null,
                    'expected_shelf'    => $row['scaffale_atteso'] !== '' ? $row['scaffale_atteso'] : null,
                    'copy_status'       => $row['stato_copia'] !== '' ? $row['stato_copia'] : null,
                    'copy_status_label' => $row['stato_copia'] !== '' ? (StaffCopyReader::labels()[$row['stato_copia']] ?? $row['stato_copia']) : null,
                    'operator_name'     => $row['operatore'] !== '' ? $row['operatore'] : null,
                    'scanned_at'        => V::datetime($row['quando']),
                ];
            }
            $meta = StaffPaging::meta(count($items), $limit, $offset, $hasMore);
            if ($category === null) {
                $meta['total_count'] = $this->service->reportRowCount($id);
            }

            return ResponseEnvelope::success($response, $items, $meta);
        } catch (\Throwable $e) {
            return $this->fail($response, 'report', $e);
        }
    }

    // ─── Writes ──────────────────────────────────────────────────────────

    /** POST /staff/stocktake/sessions — open a session and freeze its expected copies. */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $name = StaffPaging::text($json['name'] ?? null, 255) ?? '';
        $note = StaffPaging::text($json['note'] ?? null, 5000) ?? '';
        $fields = [];
        if ($name === '') {
            $fields['name'] = __('Indica un nome per la revisione.');
        }
        $branch = $json['branch_id'] ?? null;
        $branchId = null;
        if ($branch !== null && $branch !== '' && $branch !== 0) {
            $branchId = StaffPaging::id($branch);
            if ($branchId <= 0 || !$this->branchExists($branchId)) {
                $fields['branch_id'] = __('Valore non valido.');
            }
        }
        $filterInput = V::filterInput($json);
        foreach ($filterInput as $key => $value) {
            // One key at a time, so the error names the field that is wrong.
            try {
                StocktakeService::normalizeFilter([$key => is_int($value) ? (string) $value : $value]);
            } catch (\InvalidArgumentException) {
                $fields[(string) array_search($key, V::FILTER_KEYS, true)] = __('Filtri non validi.');
            }
        }
        if ($fields !== []) {
            return $this->invalid($response, $fields);
        }
        $form = ['nome' => $name, 'note' => $note, 'sede_id' => $branchId !== null ? (string) $branchId : ''];
        foreach ($filterInput as $key => $value) {
            $form[$key] = is_int($value) ? (string) $value : $value;
        }
        $staffId = $this->operatorId($request) ?? 0;
        $db = $this->db;
        try {
            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'stocktake_open',
                $staffId,
                0,
                ['name' => $name, 'note' => $note, 'branch_id' => $branchId, 'filter' => StocktakeService::normalizeFilter($form)],
                function () use ($db, $form, $response): ResponseInterface|int {
                    $outcome = Delegation::run(static fn (): ResponseInterface => (new InventoryController())->store(
                        Delegation::request('POST', '/admin/inventory', $form),
                        new SlimResponse(),
                        $db
                    ));
                    $id = $outcome->locationId('#/admin/inventory/(\d+)$#');
                    if ($id !== null && $id > 0) {
                        return $id;
                    }

                    return $this->delegationError($response, $outcome, 'store');
                },
                function (int $id) use ($request): ?array {
                    $session = $this->service->find($id);

                    return $session === null ? null : $this->sessionPayload($request, $session, true, false);
                }
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'create', $e);
        }
    }

    /**
     * POST /staff/stocktake/sessions/{id}/scans — record one scanned code.
     * A retry with the same Idempotency-Key replays the first answer; the
     * same code scanned again without a key is a new scan, recorded as a
     * repeat ("duplicate") exactly as the web records it.
     */
    public function scan(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $code = is_string($json['code'] ?? null) ? StocktakeService::normalizeCode($json['code']) : '';
        $shelf = $json['shelf_id'] ?? null;
        $shelfId = null;
        $fields = [];
        if ($code === '') {
            $fields['code'] = __('Codice mancante.');
        } elseif (mb_strlen($code) > StocktakeService::MAX_CODE_LENGTH) {
            $fields['code'] = sprintf(__('Codice troppo lungo: al massimo %d caratteri.'), StocktakeService::MAX_CODE_LENGTH);
        }
        if ($shelf !== null && $shelf !== '') {
            $shelfId = StaffPaging::id($shelf);
            if ($shelfId <= 0) {
                $fields['shelf_id'] = __('Valore non valido.');
            }
        }
        if ($fields !== []) {
            return $this->invalid($response, $fields);
        }
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }
            if (($conflict = $this->notOpen($response, $session)) !== null) {
                return $conflict;
            }
            $staffId = $this->operatorId($request) ?? 0;
            $db = $this->db;
            $form = ['codice' => $code, 'scaffale_corrente' => $shelfId !== null ? (string) $shelfId : ''];

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $json,
                'stocktake_scan',
                $staffId,
                $id,
                ['session_id' => $id, 'code' => $code, 'shelf_id' => $shelfId],
                function () use ($db, $form, $id, $response): ResponseInterface|int {
                    $outcome = Delegation::run(static fn (): ResponseInterface => (new InventoryController())->scan(
                        Delegation::request('POST', '/admin/inventory/' . $id . '/scan', $form),
                        new SlimResponse(),
                        $db,
                        $id
                    ));
                    $scanId = (int) ($outcome->json['result']['scansione_id'] ?? 0);
                    if ($outcome->status === 200 && ($outcome->json['success'] ?? false) === true && $scanId > 0) {
                        return $scanId;
                    }

                    return $this->scanError($response, $outcome, $id);
                },
                fn (int $scanId): ?array => $this->scanResult($request, $id, $scanId)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'scan', $e);
        }
    }

    /** POST /staff/stocktake/sessions/{id}/upload — a .txt/.csv list of codes, one scan each. */
    public function upload(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->invalid($response, ['file' => __('Seleziona un file .txt o .csv con un codice per riga.')]);
        }
        $extension = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::UPLOAD_EXTENSIONS, true) || (int) $file->getSize() > self::UPLOAD_MAX_BYTES) {
            return $this->invalid($response, ['file' => __('Il file deve essere un .txt o .csv di al massimo 2 MB.')]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $shelf = $body['shelf_id'] ?? null;
        $shelfId = null;
        if ($shelf !== null && $shelf !== '') {
            $shelfId = StaffPaging::id($shelf);
            if ($shelfId <= 0) {
                return $this->invalid($response, ['shelf_id' => __('Valore non valido.')]);
            }
        }
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }
            if (($conflict = $this->notOpen($response, $session)) !== null) {
                return $conflict;
            }
            $operator = $this->operatorId($request);
            try {
                $codes = StocktakeService::parseCodeList((string) $file->getStream(), $extension === 'csv');
                $result = $this->service->importCodes($id, $codes, $operator, $shelfId);
            } catch (\InvalidArgumentException $e) {
                return $this->invalid($response, ['file' => $e->getMessage()]);
            } catch (\DomainException $e) {
                return $this->domainError($response, $e->getMessage());
            }
            $labels = InventoryController::outcomeLabels();
            $outcomes = [];
            foreach ($result['outcomes'] as $value => $count) {
                $outcomes[] = ['code' => V::outcome($value), 'label' => $labels[$value] ?? $value, 'count' => (int) $count];
            }

            return ResponseEnvelope::success($response, [
                'total'    => (int) $result['total'],
                'outcomes' => $outcomes,
                'summary'  => V::summary($this->service->summary($id, $operator)),
                'message'  => sprintf(__('Elaborati %d codici dal file.'), (int) $result['total']),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'upload', $e);
        }
    }

    /**
     * POST /staff/stocktake/sessions/{id}/close — close, leaving the missing
     * copies alone or (administrators only) marking them lost. Posting `lost`
     * on a session already closed that way finishes a marking cut short.
     */
    public function close(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $code = is_string($json['missing_action'] ?? null) ? $json['missing_action'] : 'none';
        $action = V::missingActionValue($code);
        if ($action === null) {
            return $this->invalid($response, ['missing_action' => __('Azione non valida.')]);
        }
        $markLost = $action === 'perso';
        if ($markLost && !StaffRoleMiddleware::isAdmin($request)) {
            return ResponseEnvelope::error($response, 'forbidden', __('Solo un amministratore può chiudere la revisione segnando come perse le copie mancanti.'), 403);
        }
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }
            $resume = $markLost && $session['stato'] === 'chiusa' && ($session['azione_mancanti'] ?? null) === 'perso';
            if (!$resume && ($conflict = $this->notOpen($response, $session)) !== null) {
                return $conflict;
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new InventoryController())->close(
                Delegation::request('POST', '/admin/inventory/' . $id . '/close', ['azione_mancanti' => $action]),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null && $this->isFailureMessage($error)) {
                return $this->domainError($response, $error);
            }
            $after = $this->service->find($id);
            if ($after === null) {
                return $this->notFound($response);
            }
            $payload = $this->sessionPayload($request, $after, true, false);
            $payload['close_result'] = [
                'resumed'      => $resume,
                'marked_lost'  => $payload['summary']['lost'],
                'pending_lost' => $payload['pending_lost'],
                // Some copies could not be updated: the session is closed all the same.
                'partial'      => $error !== null,
                'message'      => $error ?? $outcome->flash('success_message') ?? '',
            ];

            return ResponseEnvelope::success($response, $payload);
        } catch (\Throwable $e) {
            return $this->fail($response, 'close', $e);
        }
    }

    /** POST /staff/stocktake/sessions/{id}/cancel — cancel: no copy is changed. */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $session = $this->service->find($id);
            if ($session === null) {
                return $this->notFound($response);
            }
            if (($conflict = $this->notOpen($response, $session)) !== null) {
                return $conflict;
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new InventoryController())->cancel(
                Delegation::request('POST', '/admin/inventory/' . $id . '/cancel'),
                new SlimResponse(),
                $db,
                $id
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return $this->domainError($response, $error);
            }
            $after = $this->service->find($id);
            if ($after === null) {
                return $this->notFound($response);
            }
            $payload = $this->sessionPayload($request, $after, true, false);
            $payload['message'] = $outcome->flash('success_message') ?? '';

            return ResponseEnvelope::success($response, $payload);
        } catch (\Throwable $e) {
            return $this->fail($response, 'cancel', $e);
        }
    }

    // ─── Downloads ───────────────────────────────────────────────────────

    /** GET /staff/stocktake/sessions/{id}/report.csv */
    public function reportCsv(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->download($response, $id, 'exportCsv', 'report.csv', 'text/csv; charset=utf-8');
    }

    /** GET /staff/stocktake/sessions/{id}/report.pdf */
    public function reportPdf(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->download($response, $id, 'exportPdf', 'report.pdf', 'application/pdf');
    }

    private function download(ResponseInterface $response, int $id, string $method, string $suffix, string $type): ResponseInterface
    {
        try {
            if ($this->service->find($id) === null) {
                return $this->notFound($response);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new InventoryController())->{$method}(
                Delegation::request('GET', '/admin/inventory/' . $id . '/' . $suffix),
                new SlimResponse(),
                $db,
                $id
            ));
            if ($outcome->status === 404) {
                return $this->notFound($response);
            }
            if ($outcome->isRedirect()) {
                // The PDF refuses a report with too many rows: the CSV holds any size.
                return ResponseEnvelope::error($response, 'report_too_large', $outcome->flash('error_message') ?? __('Errore interno.'), 422);
            }
            if ($outcome->status !== 200) {
                throw new \RuntimeException($method . ' answered HTTP ' . $outcome->status);
            }
            $disposition = $outcome->response->getHeaderLine('Content-Disposition');

            return $response
                ->withBody($outcome->response->getBody())
                ->withStatus(200)
                ->withHeader('Content-Type', $type)
                ->withHeader('Content-Disposition', $disposition !== '' ? $disposition : 'attachment; filename="revisione-' . $id . '.' . pathinfo($suffix, PATHINFO_EXTENSION) . '"')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, $suffix, $e);
        }
    }

    // ─── Payloads ────────────────────────────────────────────────────────

    /**
     * A session as the app sees it. $full adds the latest scans and the history.
     *
     * @param array<string, mixed> $session StocktakeService::find()
     * @return array<string, mixed>
     */
    private function sessionPayload(ServerRequestInterface $request, array $session, bool $full, bool $mine): array
    {
        $id = (int) $session['id'];
        $state = (string) $session['stato'];
        $operator = $this->operatorId($request);
        $isAdmin = StaffRoleMiddleware::isAdmin($request);
        $open = $state === 'aperta';
        $closedLost = $state === 'chiusa' && ($session['azione_mancanti'] ?? null) === 'perso';
        $filter = is_array($session['filtro'] ?? null) ? $session['filtro'] : [];
        $pendingLost = $closedLost ? $this->service->pendingLostCount($id) : 0;
        $payload = [
            'id'             => $id,
            'name'           => (string) $session['nome'],
            'status'         => V::status($state),
            'status_label'   => $this->statusLabel($state),
            'status_color'   => V::statusColor($state),
            'branch'         => $session['sede_id'] !== null ? ['id' => (int) $session['sede_id'], 'name' => (string) ($session['sede_nome'] ?? '')] : null,
            'filter'         => V::filter($filter),
            'filter_labels'  => (new InventoryController())->filterLabels($this->db, $this->service, $filter),
            'note'           => ($session['note'] ?? null) !== null && (string) $session['note'] !== '' ? (string) $session['note'] : null,
            'expected_total' => (int) ($session['totale_attese'] ?? 0),
            'opened_at'      => V::datetime($session['aperta_il'] ?? null),
            'opened_by'      => $session['aperta_da'] !== null ? ['id' => (int) $session['aperta_da'], 'name' => (string) ($session['aperta_da_nome'] ?? '')] : null,
            'closed_at'      => V::datetime($session['chiusa_il'] ?? null),
            'closed_by'      => $session['chiusa_da'] !== null ? ['id' => (int) $session['chiusa_da'], 'name' => (string) ($session['chiusa_da_nome'] ?? '')] : null,
            'missing_action' => V::missingAction(isset($session['azione_mancanti']) ? (string) $session['azione_mancanti'] : null),
            'summary'        => V::summary($this->service->summary($id, $operator)),
            'pending_lost'   => $pendingLost,
            'can_scan'       => $open,
            'can_upload'     => $open,
            'can_close'      => $open,
            'can_cancel'     => $open,
            // The web offers "mark as lost" to administrators only, at close
            // time or to finish a mark-lost close that did not reach every copy.
            'can_mark_lost'  => $isAdmin && ($open || ($closedLost && $pendingLost > 0)),
        ];
        if ($full) {
            $payload['recent_scans'] = array_map(
                fn (array $r): array => $this->scanItem($r),
                $this->service->scans($id, $mine ? $operator : null, self::RECENT_SCANS)
            );
            $payload['history'] = array_map(static function (array $event): array {
                $who = trim((string) ($event['operator_name'] ?? ''));

                return [
                    'event'         => (string) ($event['event'] ?? ''),
                    'label'         => __(\App\Support\ActivityLog::eventLabel((string) ($event['event'] ?? ''), (string) ($event['azione'] ?? ''))),
                    'operator_name' => $who !== '' ? $who : null,
                    'at'            => V::datetime($event['data_modifica'] ?? null),
                ];
            }, \App\Support\ActivityLog::forEntity($this->db, 'inventario_sessioni', $id));
        }

        return $payload;
    }

    /**
     * One row of StocktakeService::scans().
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function scanItem(array $r): array
    {
        $value = (string) $r['esito'];
        $copyStatus = isset($r['stato_copia']) ? (string) $r['stato_copia'] : null;
        $media = isset($r['tipo_media']) && $r['tipo_media'] !== '' ? (string) $r['tipo_media'] : null;
        $operatorName = trim((string) ($r['operatore'] ?? ''));

        return [
            'id'                => (int) $r['id'],
            'code'              => (string) $r['codice_letto'],
            'inventory_number'  => (string) ($r['codice'] ?? $r['codice_letto']),
            'outcome'           => V::outcome($value),
            'outcome_label'     => InventoryController::outcomeLabels()[$value] ?? $value,
            'color'             => V::outcomeColor($value),
            'copy_id'           => $r['copia_id'] !== null ? (int) $r['copia_id'] : null,
            'book_id'           => isset($r['libro_id']) ? (int) $r['libro_id'] : null,
            'title'             => isset($r['titolo']) && $r['titolo'] !== '' ? (string) $r['titolo'] : null,
            'collocation'       => isset($r['collocazione']) && $r['collocazione'] !== '' ? (string) $r['collocazione'] : null,
            'media_type'        => $media,
            'copy_status'       => $copyStatus,
            'copy_status_label' => $copyStatus !== null ? (StaffCopyReader::labels()[$copyStatus] ?? $copyStatus) : null,
            'off_shelf'         => $copyStatus !== null && StocktakeService::isOffShelfState($copyStatus),
            'expected_shelf'    => isset($r['scaffale_atteso']) ? $this->service->shelfLabel((int) $r['scaffale_atteso']) : null,
            'operator'          => $r['scansionato_da'] !== null ? ['id' => (int) $r['scansionato_da'], 'name' => $operatorName] : null,
            'scanned_at'        => V::datetime($r['scansionato_il'] ?? null),
        ];
    }

    /**
     * The answer to a scan (and to its replay): the scan, what to tell the
     * operator, the updated counters.
     *
     * @return array<string, mixed>|null
     */
    private function scanResult(ServerRequestInterface $request, int $sessionId, int $scanId): ?array
    {
        $row = $this->service->scanRow($sessionId, $scanId);
        if ($row === null) {
            return null;
        }
        $scan = $this->scanItem($row);
        if ($scan['copy_id'] === null && $row['esito'] === 'duplicata') {
            // A repeat is stored without its copy (the first scan holds it):
            // name the copy all the same, as the web feedback does.
            $copy = $this->service->copyByCode((string) $row['codice_letto']);
            if ($copy !== null) {
                $status = (string) $copy['stato'];
                $scan['copy_id'] = (int) $copy['id'];
                $scan['book_id'] = (int) $copy['libro_id'];
                $scan['title'] = (string) $copy['titolo'];
                $scan['collocation'] = ($copy['collocazione'] ?? '') !== '' ? (string) $copy['collocazione'] : null;
                $scan['copy_status'] = $status;
                $scan['copy_status_label'] = StaffCopyReader::labels()[$status] ?? $status;
                $scan['off_shelf'] = StocktakeService::isOffShelfState($status);
            }
        }
        $session = $this->service->find($sessionId);

        return [
            'scan'           => $scan,
            'tone'           => V::tone((string) $row['esito']),
            'summary'        => V::summary($this->service->summary($sessionId, $this->operatorId($request))),
            'session_status' => V::status((string) ($session['stato'] ?? 'aperta')),
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function statusLabel(string $state): string
    {
        return match ($state) {
            'aperta'    => __('Aperta'),
            'chiusa'    => __('Chiusa'),
            'annullata' => __('Annullata'),
            default     => $state,
        };
    }

    private function operatorId(ServerRequestInterface $request): ?int
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    private function mine(ServerRequestInterface $request): bool
    {
        return in_array(StaffPaging::query($request, 'mine'), ['1', 'true'], true);
    }

    private function branchExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM sedi WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('branch probe prepare failed');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    /** @param array<string, mixed> $session */
    private function notOpen(ResponseInterface $response, array $session): ?ResponseInterface
    {
        return match ((string) $session['stato']) {
            'aperta'    => null,
            'annullata' => ResponseEnvelope::error($response, 'session_cancelled', __('La revisione non è più aperta: non si possono registrare altre scansioni.'), 409),
            default     => ResponseEnvelope::error($response, 'session_closed', __('La revisione non è più aperta: non si possono registrare altre scansioni.'), 409),
        };
    }

    /** True for the flash texts of a close or cancel that did not happen. */
    private function isFailureMessage(string $message): bool
    {
        return in_array($message, [
            __('Azione non valida.'),
            __('Solo un amministratore può chiudere la revisione segnando come perse le copie mancanti.'),
            __('Revisione non trovata.'),
            __('La revisione non è più aperta: non si possono registrare altre scansioni.'),
            __('Impossibile chiudere la revisione. Riprova più tardi.'),
        ], true);
    }

    /** A StocktakeService refusal (DomainException text) as an API error. */
    private function domainError(ResponseInterface $response, string $message): ResponseInterface
    {
        return match ($message) {
            __('Revisione non trovata.') => $this->notFound($response),
            __('La revisione non è più aperta: non si possono registrare altre scansioni.') => ResponseEnvelope::error($response, 'session_closed', $message, 409),
            __('Azione non valida.') => ResponseEnvelope::error($response, 'invalid_state', $message, 409),
            default => ResponseEnvelope::error($response, 'internal_error', $message, 500),
        };
    }

    private function scanError(ResponseInterface $response, DelegationOutcome $outcome, int $id): ResponseInterface
    {
        $message = is_string($outcome->json['message'] ?? null) ? (string) $outcome->json['message'] : '';
        if ($outcome->status === 422) {
            if (in_array($message, [__('Revisione non trovata.'), __('La revisione non è più aperta: non si possono registrare altre scansioni.')], true)) {
                return $this->domainError($response, $message);
            }

            return $this->invalid($response, ['code' => $message !== '' ? $message : __('Valore non valido.')]);
        }
        SecureLogger::error('[MobileApi] staff stocktake scan delegated failure', ['sessione_id' => $id, 'status' => $outcome->status]);

        return ResponseEnvelope::error($response, 'internal_error', $message !== '' ? $message : __('Errore interno.'), 500);
    }

    private function delegationError(ResponseInterface $response, DelegationOutcome $outcome, string $what): ResponseInterface
    {
        $message = $outcome->flash('error_message');
        if ($message === __('Indica un nome per la revisione.')) {
            return $this->invalid($response, ['name' => $message]);
        }
        if ($message === __('Filtri non validi.')) {
            return $this->invalid($response, ['filter' => $message]);
        }
        SecureLogger::error('[MobileApi] staff stocktake ' . $what . ' delegated failure', ['status' => $outcome->status]);

        return ResponseEnvelope::error($response, 'internal_error', $message ?? __('Errore interno.'), 500);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation', (string) reset($fields), 422, ['fields' => $fields]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Revisione non trovata.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff stocktake ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
