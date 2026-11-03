<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\ArchiveValidator;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Archives editing for the staff app (Mobile API 1.7.0): units, authority
 * records, attachments and publication.
 *
 * Every write is delegated to the Archives plugin's own admin actions
 * (storeAction, updateAction, destroyAction, upload*, attach/detach,
 * authority*). Those answer 303 on success and re-render an HTML form on a
 * validation error, so the plugin first runs the Archives plugin's OWN
 * validators (ArchiveValidator calls them, nothing is copied) to answer 422
 * with the field. Uploads always 303: success is detected by re-reading the
 * row. Reads use CollectionsController in operator view (unpublished units
 * included). Every route answers 404 `feature_disabled` while the archives
 * plugin is inactive.
 */
final class StaffArchivesController
{
    public const UNIT_KEYS = [
        'reference_code', 'institution_code', 'level', 'formal_title', 'constructed_title', 'date_start', 'date_end',
        'extent', 'scope_content', 'language_codes', 'parent_id', 'specific_material', 'dimensions', 'color_mode',
        'photographer', 'publisher', 'collection_name', 'local_classification', 'iiif_manifest_url',
        'rights_statement_url', 'ark_identifier', 'version_note', 'published',
    ];
    public const AUTHORITY_KEYS = [
        'type', 'authorised_form', 'parallel_forms', 'other_forms', 'identifiers', 'dates_of_existence', 'history',
        'places', 'legal_status', 'functions', 'mandates', 'internal_structure', 'general_context',
    ];
    private const COVER_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const COVER_MAX = 5 * 1024 * 1024;
    private const DOCUMENT_MAX = 200 * 1024 * 1024;

    private ?ArchiveValidator $validator = null;

    public function __construct(private mysqli $db, private HookManager $hooks)
    {
    }

    // ─── Reads ──────────────────────────────────────────────────────────────

    public function lookups(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $plugin = \App\Plugins\Archives\ArchivesPlugin::class;
        $this->validator();
        $options = static function (array $map, bool $keysOnly = false): array {
            $out = [];
            foreach ($map as $value => $label) {
                $out[] = ['value' => (string) $value, 'label' => $keysOnly ? ucfirst((string) $value) : (string) $label];
            }
            return $out;
        };

        return HttpCache::envelope($request, $response, 'staff-archive-lookups', [
            'levels'             => [
                ['value' => 'fonds', 'label' => __('Fondo')],
                ['value' => 'series', 'label' => __('Serie')],
                ['value' => 'file', 'label' => __('Fascicolo')],
                ['value' => 'item', 'label' => __('Unità')],
            ],
            'specific_materials' => $options($plugin::SPECIFIC_MATERIALS),
            'color_modes'        => $options($plugin::COLOR_MODES),
            'authority_types'    => $options($plugin::AUTHORITY_TYPES),
            'authority_roles'    => $options($plugin::AUTHORITY_ROLES),
        ]);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $published = StaffPaging::query($request, 'published');
        $published = $published === '' ? 'all' : $published;
        if (!in_array($published, ['all', '0', '1'], true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'published']);
        }
        $collections = new CollectionsController($this->db, $this->hooks, true, $published);
        if (!$collections->available('archives')) {
            return $this->disabled($response);
        }

        return $collections->handle('archives', $request, $response, 'archives');
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $detail = $this->load($request, $id);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive detail', $e);
        }
    }

    // ─── Units: create / update / delete ────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $form = $this->unitForm(JsonBody::parse($request), null);
            if (isset($form['error_field'])) {
                return $this->invalid($response, (string) $form['error_field'], (string) $form['error_message']);
            }
            $errors = $this->validator()->unit($form, null);
            if ($errors !== []) {
                return $this->validationErrors($response, $errors);
            }
            $values = $this->validator()->unitValues($form);
            $existing = $this->unitByReference((string) $values['institution_code'], (string) $values['reference_code']);
            if ($existing !== null) {
                return $this->duplicate($response, $existing);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->storeAction(
                Delegation::request('POST', '/admin/archives/new', $form),
                new SlimResponse()
            ));
            $created = $this->unitByReference((string) $values['institution_code'], (string) $values['reference_code']);
            if ($outcome->status !== 303 || $created === null) {
                $existing = $this->unitByReference((string) $values['institution_code'], (string) $values['reference_code']);
                return $existing !== null
                    ? $this->duplicate($response, $existing)
                    : ResponseEnvelope::error($response, 'validation_error', __('Salvataggio non riuscito.'), 422);
            }
            $detail = $this->load($request, $created['id']);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail, [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $row = $this->unitRow($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $form = $this->unitForm(JsonBody::parse($request), $row);
            if (isset($form['error_field'])) {
                return $this->invalid($response, (string) $form['error_field'], (string) $form['error_message']);
            }
            $errors = $this->validator()->unit($form, $id);
            if ($errors !== []) {
                return $this->validationErrors($response, $errors);
            }
            $values = $this->validator()->unitValues($form);
            $existing = $this->unitByReference((string) $values['institution_code'], (string) $values['reference_code']);
            if ($existing !== null && $existing['id'] !== $id) {
                return $this->duplicate($response, $existing);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->updateAction(
                Delegation::request('POST', '/admin/archives/' . $id . '/edit', $form),
                new SlimResponse(),
                $id
            ));
            if ($outcome->status !== 303) {
                return ResponseEnvelope::error($response, 'validation_error', __('Salvataggio non riuscito.'), 422);
            }

            return $this->detailOr404($request, $response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive update', $e);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ($this->unitRow($id) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->destroyAction(
                Delegation::request('POST', '/admin/archives/' . $id . '/delete'),
                new SlimResponse(),
                $id
            ));
            if ($this->stillLive('archival_units', $id)) {
                throw new \RuntimeException('archival unit still present after destroyAction');
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive delete', $e);
        }
    }

    // ─── Attachments ────────────────────────────────────────────────────────

    public function uploadCover(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->upload($request, $response, $id, 'cover');
    }

    public function uploadDocument(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->upload($request, $response, $id, 'document');
    }

    private function upload(ServerRequestInterface $request, ResponseInterface $response, int $id, string $kind): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $row = $this->unitRow($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $file = $request->getUploadedFiles()[$kind] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                return $this->rejected($response, $kind);
            }
            $size = (int) ($file->getSize() ?? 0);
            if ($size <= 0 || $size > ($kind === 'cover' ? self::COVER_MAX : self::DOCUMENT_MAX)) {
                return $this->rejected($response, $kind);
            }
            if ($kind === 'cover') {
                $uri = $file->getStream()->getMetadata('uri');
                $mime = is_string($uri) && is_file($uri) && function_exists('mime_content_type') ? (string) mime_content_type($uri) : '';
                if (!in_array($mime, self::COVER_MIMES, true)) {
                    return $this->rejected($response, $kind);
                }
            }
            $beforeCover = (string) ($row['cover_image_path'] ?? '');
            $beforeFiles = $this->fileIds($id);
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $kind === 'cover'
                ? $p->uploadCoverAction(Delegation::request('POST', '/admin/archives/' . $id . '/upload-cover')->withUploadedFiles(['cover' => $file]), new SlimResponse(), $id)
                : $p->uploadDocumentAction(Delegation::request('POST', '/admin/archives/' . $id . '/upload-document')->withUploadedFiles(['document' => $file]), new SlimResponse(), $id));
            // The action always 303s: success is a changed row.
            $after = $this->unitRow($id);
            $changed = $kind === 'cover'
                ? (string) ($after['cover_image_path'] ?? '') !== $beforeCover && (string) ($after['cover_image_path'] ?? '') !== ''
                : count(array_diff($this->fileIds($id), $beforeFiles)) > 0;
            if (!$changed) {
                SecureLogger::warning('[MobileApi] archive ' . $kind . ' upload refused (HTTP ' . $outcome->status . ')');
                return $this->rejected($response, $kind);
            }
            $detail = $this->load($request, $id);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail, [], $kind === 'cover' ? 200 : 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive upload', $e);
        }
    }

    public function removeCover(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ($this->unitRow($id) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->removeAssetAction(
                Delegation::request('POST', '/admin/archives/' . $id . '/remove-asset', ['type' => 'cover']),
                new SlimResponse(),
                $id
            ));

            return $this->detailOr404($request, $response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive cover removal', $e);
        }
    }

    public function removeDocument(ServerRequestInterface $request, ResponseInterface $response, int $id, int $fileId): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $row = $this->unitRow($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            if ($fileId === 0) {
                if ((string) ($row['document_path'] ?? '') === '') {
                    return $this->notFound($response, __('Documento non trovato.'));
                }
                $this->delegate(static fn ($p): ResponseInterface => $p->removeAssetAction(
                    Delegation::request('POST', '/admin/archives/' . $id . '/remove-asset', ['type' => 'document']),
                    new SlimResponse(),
                    $id
                ));
            } else {
                if (!in_array($fileId, $this->fileIds($id), true)) {
                    return $this->notFound($response, __('Documento non trovato.'));
                }
                $this->delegate(static fn ($p): ResponseInterface => $p->removeFileAction(
                    Delegation::request('POST', '/admin/archives/' . $id . '/files/' . $fileId . '/delete'),
                    new SlimResponse(),
                    $id,
                    $fileId
                ));
            }

            return $this->detailOr404($request, $response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive document removal', $e);
        }
    }

    public function attachAuthority(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $json = JsonBody::parse($request);
        $authorityId = StaffPaging::id($json['authority_id'] ?? null);
        $role = is_string($json['role'] ?? null) ? (string) $json['role'] : '';
        if ($authorityId <= 0) {
            return $this->invalid($response, 'authority_id', __('Valore non valido.'));
        }
        if (!array_key_exists($role, \App\Plugins\Archives\ArchivesPlugin::AUTHORITY_ROLES)) {
            return $this->invalid($response, 'role', __('Valore non valido.'));
        }
        try {
            if ($this->unitRow($id) === null || $this->authorityRow($authorityId) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->attachAuthorityAction(
                Delegation::request('POST', '/admin/archives/' . $id . '/authorities/attach', ['authority_id' => (string) $authorityId, 'role' => $role]),
                new SlimResponse(),
                $id
            ));

            return $this->detailOr404($request, $response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive authority attach', $e);
        }
    }

    public function detachAuthority(ServerRequestInterface $request, ResponseInterface $response, int $id, int $authorityId): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ($this->unitRow($id) === null || $this->authorityRow($authorityId) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->detachAuthorityAction(
                Delegation::request('POST', '/admin/archives/' . $id . '/authorities/' . $authorityId . '/detach'),
                new SlimResponse(),
                $id,
                $authorityId
            ));

            return $this->detailOr404($request, $response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'archive authority detach', $e);
        }
    }

    // ─── Authority records ──────────────────────────────────────────────────

    public function authorities(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $type = StaffPaging::query($request, 'type');
        if ($type !== '' && !array_key_exists($type, \App\Plugins\Archives\ArchivesPlugin::AUTHORITY_TYPES)) {
            return $this->invalid($response, 'type', __('Valore non valido.'));
        }
        $q = mb_substr(StaffPaging::query($request, 'q'), 0, 200);
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        try {
            $where = ['ar.deleted_at IS NULL'];
            $types = '';
            $params = [];
            if ($type !== '') {
                $where[] = 'ar.type = ?';
                $types .= 's';
                $params[] = $type;
            }
            $search = \App\Support\ExactSearch::filterCondition(['ar.authorised_form', 'ar.parallel_forms', 'ar.other_forms'], $q);
            if ($search !== null) {
                $where[] = $search['sql'];
                $types .= $search['types'];
                array_push($params, ...$search['params']);
            }
            $sql = 'SELECT ar.id, ar.type, ar.authorised_form, ar.dates_of_existence,
                           (SELECT COUNT(DISTINCT aua.archival_unit_id) FROM archival_unit_authority aua
                              JOIN archival_units u ON u.id = aua.archival_unit_id AND u.deleted_at IS NULL
                             WHERE aua.authority_id = ar.id) AS units_count
                      FROM authority_records ar WHERE ' . implode(' AND ', $where) . '
                     ORDER BY ar.authorised_form, ar.id LIMIT ? OFFSET ?';
            $rows = $this->rows($sql, $types . 'ii', array_merge($params, [$limit + 1, $offset]));
            $hasMore = count($rows) > $limit;
            $items = array_map(static fn (array $r): array => [
                'id'          => (int) $r['id'],
                'type'        => (string) $r['type'],
                'name'        => (string) $r['authorised_form'],
                'dates'       => $r['dates_of_existence'] !== null && $r['dates_of_existence'] !== '' ? (string) $r['dates_of_existence'] : null,
                'units_count' => (int) $r['units_count'],
            ], array_slice($rows, 0, $limit));

            return ResponseEnvelope::success($response, $items, StaffPaging::meta(count($items), $limit, $offset, $hasMore));
        } catch (\Throwable $e) {
            return $this->fail($response, 'authority list', $e);
        }
    }

    public function authority(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $detail = $this->loadAuthority($id);

            return $detail === null ? $this->notFound($response, __('Autorità non trovata.')) : ResponseEnvelope::success($response, $detail);
        } catch (\Throwable $e) {
            return $this->fail($response, 'authority detail', $e);
        }
    }

    public function createAuthority(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $form = $this->authorityForm(JsonBody::parse($request), null);
            $errors = $this->validator()->authority($form);
            if ($errors !== []) {
                return $this->validationErrors($response, $errors);
            }
            // storeAction redirects to the list: resolve the new id under a
            // lock, above the high-water mark, by type + authorised form.
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('archives-authority-create');
            if (!$locks->lock($lock)) {
                return ResponseEnvelope::error($response, 'concurrent_retry', __('Operazione in conflitto con un\'altra in corso. Riprova.'), 409);
            }
            try {
                $before = (int) ($this->rows('SELECT COALESCE(MAX(id), 0) AS m FROM authority_records', '', [])[0]['m'] ?? 0);
                $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->authorityStoreAction(
                    Delegation::request('POST', '/admin/archives/authorities/new', $form),
                    new SlimResponse()
                ));
                $rows = $this->rows(
                    'SELECT id FROM authority_records WHERE id > ? AND type = ? AND authorised_form = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 2',
                    'iss',
                    [$before, trim($form['type']), trim($form['authorised_form'])]
                );
            } finally {
                $locks->unlock($lock);
            }
            if ($outcome->status !== 303 || count($rows) !== 1) {
                return ResponseEnvelope::error($response, 'validation_error', __('Salvataggio non riuscito.'), 422);
            }
            $detail = $this->loadAuthority((int) $rows[0]['id']);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail, [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'authority create', $e);
        }
    }

    public function updateAuthority(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $row = $this->authorityRow($id);
            if ($row === null) {
                return $this->notFound($response, __('Autorità non trovata.'));
            }
            $form = $this->authorityForm(JsonBody::parse($request), $row);
            $errors = $this->validator()->authority($form);
            if ($errors !== []) {
                return $this->validationErrors($response, $errors);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->authorityUpdateAction(
                Delegation::request('POST', '/admin/archives/authorities/' . $id . '/edit', $form),
                new SlimResponse(),
                $id
            ));
            if ($outcome->status !== 303) {
                return ResponseEnvelope::error($response, 'validation_error', __('Salvataggio non riuscito.'), 422);
            }
            $detail = $this->loadAuthority($id);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail);
        } catch (\Throwable $e) {
            return $this->fail($response, 'authority update', $e);
        }
    }

    public function deleteAuthority(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ($this->authorityRow($id) === null) {
                return $this->notFound($response, __('Autorità non trovata.'));
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->authorityDestroyAction(
                Delegation::request('POST', '/admin/archives/authorities/' . $id . '/delete'),
                new SlimResponse(),
                $id
            ));
            if ($this->stillLive('authority_records', $id)) {
                throw new \RuntimeException('authority still present after authorityDestroyAction');
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'authority delete', $e);
        }
    }

    // ─── Forms ──────────────────────────────────────────────────────────────

    /**
     * The archival-unit form the admin page posts: the JSON keys are the
     * form keys; on PUT every absent key keeps the stored value.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $row
     * @return array<string, string>|array{error_field:string, error_message:string}
     */
    private function unitForm(array $json, ?array $row): array
    {
        $form = [];
        foreach (self::UNIT_KEYS as $key) {
            if (array_key_exists($key, $json)) {
                $value = $json[$key];
                if ($key === 'published') {
                    if (!is_bool($value)) {
                        return ['error_field' => 'published', 'error_message' => __('Valore non valido.')];
                    }
                    $form[$key] = $value ? '1' : '0';
                    continue;
                }
                if ($value !== null && !is_scalar($value)) {
                    return ['error_field' => $key, 'error_message' => __('Valore non valido.')];
                }
                if (in_array($key, ['date_start', 'date_end', 'parent_id'], true) && $value !== null && $value !== '' && !is_int($value)) {
                    return ['error_field' => $key, 'error_message' => __('Valore non valido.')];
                }
                $form[$key] = $value === null ? '' : (string) $value;
            } elseif ($row !== null) {
                $stored = $row[$key] ?? null;
                $form[$key] = $key === 'published' ? ((int) ($stored ?? 1) === 1 ? '1' : '0') : ($stored === null ? '' : (string) $stored);
            }
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $row
     * @return array<string, string>
     */
    private function authorityForm(array $json, ?array $row): array
    {
        $form = [];
        foreach (self::AUTHORITY_KEYS as $key) {
            if (array_key_exists($key, $json)) {
                $form[$key] = is_scalar($json[$key]) ? (string) $json[$key] : '';
            } else {
                $form[$key] = $row !== null && $row[$key] !== null ? (string) $row[$key] : '';
            }
        }

        return $form;
    }

    // ─── Read model ─────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function load(ServerRequestInterface $request, int $id): ?array
    {
        $inner = (new CollectionsController($this->db, $this->hooks, true, 'all'))
            ->handle('archives', $request->withQueryParams([]), new SlimResponse(), 'archive', $id);
        if ($inner->getStatusCode() !== 200) {
            return null;
        }
        $decoded = json_decode((string) $inner->getBody(), true);
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
        $row = $this->unitRow($id);
        if ($data === null || $row === null) {
            return null;
        }
        $fields = [];
        foreach ($row as $column => $value) {
            if ($value !== null && $value !== '') {
                $fields[$column] = is_numeric($value) && in_array($column, ['id', 'parent_id', 'date_start', 'date_end', 'published'], true) ? (int) $value : $value;
            }
        }
        $files = [];
        foreach ($this->rows('SELECT id, file_path, file_mime, original_filename FROM archival_unit_files WHERE unit_id = ? ORDER BY sort_order, id', 'i', [$id]) as $f) {
            $files[] = ['id' => (int) $f['id'], 'url' => self::media((string) $f['file_path']), 'mime' => (string) $f['file_mime'], 'label' => (string) $f['original_filename']];
        }
        if ($files === [] && (string) ($row['document_path'] ?? '') !== '') {
            $files[] = ['id' => 0, 'url' => self::media((string) $row['document_path']), 'mime' => (string) ($row['document_mime'] ?? ''), 'label' => (string) ($row['document_filename'] ?? '')];
        }
        $authorities = array_map(static fn (array $a): array => [
            'id' => (int) $a['id'], 'type' => (string) $a['type'], 'name' => (string) $a['name'], 'role' => (string) $a['role'],
        ], $this->rows('SELECT ar.id, ar.type, ar.authorised_form AS name, aua.role FROM archival_unit_authority aua
            JOIN authority_records ar ON ar.id = aua.authority_id WHERE aua.archival_unit_id = ? AND ar.deleted_at IS NULL
            ORDER BY aua.role, ar.authorised_form, ar.id', 'i', [$id]));

        return array_merge($data, [
            'published'   => array_key_exists('published', $row) ? (int) $row['published'] === 1 : true,
            'fields'      => (object) $fields,
            'files'       => $files,
            'authorities' => $authorities,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function loadAuthority(int $id): ?array
    {
        $row = $this->authorityRow($id);
        if ($row === null) {
            return null;
        }
        $units = array_map(static fn (array $u): array => [
            'id' => (int) $u['id'], 'title' => (string) ($u['constructed_title'] ?? ''), 'role' => (string) $u['role'],
        ], $this->rows('SELECT u.id, u.constructed_title, aua.role FROM archival_unit_authority aua
            JOIN archival_units u ON u.id = aua.archival_unit_id AND u.deleted_at IS NULL
            WHERE aua.authority_id = ? ORDER BY u.reference_code, u.id', 'i', [$id]));
        $authors = [];
        $plugin = $this->plugin();
        foreach ($plugin->fetchAutoriForAuthority($id) as $a) {
            $authors[] = ['id' => (int) $a['id'], 'name' => \App\Support\HtmlHelper::decode((string) $a['nome'])];
        }
        $out = [];
        foreach ($row as $column => $value) {
            $out[$column] = $column === 'id' ? (int) $value : $value;
        }

        return $out + ['units' => $units, 'authors' => $authors];
    }

    /** @return array<string, mixed>|null */
    private function unitRow(int $id): ?array
    {
        return $this->rows('SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL', 'i', [$id])[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function authorityRow(int $id): ?array
    {
        return $this->rows('SELECT * FROM authority_records WHERE id = ? AND deleted_at IS NULL', 'i', [$id])[0] ?? null;
    }

    /** @return array{id:int, reference_code:string}|null */
    private function unitByReference(string $institution, string $reference): ?array
    {
        $row = $this->rows('SELECT id, reference_code FROM archival_units WHERE institution_code = ? AND reference_code = ? AND deleted_at IS NULL', 'ss', [$institution, $reference])[0] ?? null;

        return $row === null ? null : ['id' => (int) $row['id'], 'reference_code' => (string) $row['reference_code']];
    }

    /** A fresh probe after a delegated delete (never a cached row). */
    private function stillLive(string $table, int $id): bool
    {
        $sql = $table === 'authority_records'
            ? 'SELECT id FROM authority_records WHERE id = ? AND deleted_at IS NULL'
            : 'SELECT id FROM archival_units WHERE id = ? AND deleted_at IS NULL';

        return $this->rows($sql, 'i', [$id]) !== [];
    }

    /** @return list<int> */
    private function fileIds(int $unitId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->rows('SELECT id FROM archival_unit_files WHERE unit_id = ?', 'i', [$unitId]));
    }

    private static function media(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '//')) {
            return null;
        }

        return preg_match('#^https?://#i', $path) === 1 ? $path : absoluteUrl('/' . ltrim($path, '/'));
    }

    // ─── Plumbing ───────────────────────────────────────────────────────────

    private function plugin(): \App\Plugins\Archives\ArchivesPlugin
    {
        if (!class_exists(\App\Plugins\Archives\ArchivesPlugin::class, false)) {
            require_once dirname(__DIR__, 3) . '/archives/ArchivesPlugin.php';
        }

        return new \App\Plugins\Archives\ArchivesPlugin($this->db, $this->hooks);
    }

    private function validator(): ArchiveValidator
    {
        return $this->validator ??= new ArchiveValidator($this->plugin());
    }

    /**
     * @param callable(\App\Plugins\Archives\ArchivesPlugin): ResponseInterface $action
     */
    private function delegate(callable $action): DelegationOutcome
    {
        $plugin = $this->plugin();

        return Delegation::run(static fn (): ResponseInterface => $action($plugin));
    }

    private function active(): bool
    {
        return (new CollectionsController($this->db, $this->hooks))->available('archives');
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = $r;
        }
        $stmt->close();

        return $rows;
    }

    /** @param array<string, string> $errors field => core message */
    private function validationErrors(ResponseInterface $response, array $errors): ResponseInterface
    {
        $field = (string) array_key_first($errors);

        return ResponseEnvelope::error($response, 'validation_error', (string) $errors[$field], 422, ['field' => $field, 'errors' => $errors]);
    }

    /** @param array{id:int, reference_code:string} $existing */
    private function duplicate(ResponseInterface $response, array $existing): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'duplicate', __('Esiste già un\'unità con questo codice di riferimento.'), 409, ['existing' => $existing, 'field' => 'reference_code']);
    }

    private function rejected(ResponseInterface $response, string $kind): ResponseInterface
    {
        return ResponseEnvelope::error(
            $response,
            'upload_rejected',
            $kind === 'cover' ? __('Immagine non valida: JPEG, PNG o WebP, massimo 5 MB.') : __('Documento non accettato: formato non ammesso o file troppo grande.'),
            422,
            ['field' => $kind]
        );
    }

    private function detailOr404(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $detail = $this->load($request, $id);

        return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail);
    }

    private function invalid(ResponseInterface $response, string $field, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation_error', $message, 422, ['field' => $field]);
    }

    private function disabled(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
    }

    private function notFound(ResponseInterface $response, ?string $message = null): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', $message ?? __('Documento non trovato.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
