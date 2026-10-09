<?php
declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Support\HookManager;
use App\Support\SecureLogger;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Optional public collections use the same bearer identity as the catalogue. */
final class CollectionsController
{
    public function __construct(private \mysqli $db, private HookManager $hooks) {}

    public function available(string $name): bool
    {
        $rows = $this->rows('SELECT id FROM plugins WHERE name = ? AND is_active = 1 LIMIT 1', [$name]);
        return $rows !== [];
    }

    /** All failures keep the core envelope; an inactive plugin exposes no records. */
    public function handle(string $name, Request $request, Response $response, string $action, int $id = 0): Response
    {
        try {
            if (!$this->available($name)) { return ResponseEnvelope::error($response, 'not_found', __('Sezione non disponibile.'), 404); }
            return match ($action) {
                'health' => $this->health($name, $request, $response),
                'archives' => $this->archives($request, $response),
                'archive' => $this->archive($request, $response, $id),
                'wanted' => $this->wanted($request, $response),
                'wanted_detail' => $this->wantedDetail($response, $id),
                'offer' => $this->desiderata()->mobileOffer($request, $response),
                default => ResponseEnvelope::error($response, 'not_found', __('Sezione non disponibile.'), 404),
            };
        } catch (\InvalidArgumentException $e) {
            return ResponseEnvelope::error($response, 'validation', __('Filtri non validi.'), 422);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi:collections] ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Contenuti non disponibili.'), 500);
        }
    }

    private function health(string $name, Request $request, Response $response): Response
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
        $staff = is_array($user) && in_array($user['tipo_utente'] ?? '', ['admin', 'staff'], true);
        return ResponseEnvelope::success($response, [
            'status' => 'ok', 'native_offers' => $name === 'desiderata',
            'web_url' => absoluteUrl($name === 'desiderata' ? '/desiderata' : route_path('archives')),
            'manage_url' => $staff ? absoluteUrl($name === 'desiderata' ? '/admin/desiderata' : '/admin/archives') : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function offerStatus(Request $request, Response $response, string $submission): Response
    {
        try {
            if (!$this->available('desiderata')) { return ResponseEnvelope::error($response, 'not_found', __('Sezione non disponibile.'), 404); }
            $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
            if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) { return ResponseEnvelope::error($response, 'unauthorized', __('Accesso richiesto.'), 401); }
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $submission) !== 1) { return ResponseEnvelope::error($response, 'validation', __('Proposta non valida.'), 422); }
            $rows = $this->rows('SELECT id FROM desiderata_offers WHERE mobile_user_id = ? AND mobile_request_id = ?', [(int) $user['id'], strtolower($submission)]);
            if ($rows === []) { return ResponseEnvelope::error($response, 'not_found', __('Proposta non trovata.'), 404); }
            return ResponseEnvelope::success($response, ['id' => (int) $rows[0]['id'], 'status' => 'submitted'])->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi:collections] proposal lookup failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Contenuti non disponibili.'), 500);
        }
    }

    private function desiderata(): \DesiderataPlugin
    {
        require_once dirname(__DIR__, 3) . '/desiderata/DesiderataPlugin.php';
        return new \DesiderataPlugin($this->db, $this->hooks);
    }

    private function wanted(Request $request, Response $response): Response
    {
        [$query, $page, $limit] = $this->pageParams($request);
        $rows = $this->desiderata()->wanted($query, $limit + 1, ($page - 1) * $limit);
        $more = count($rows) > $limit;
        if ($more) { array_pop($rows); }
        $items = array_map($this->wantedItem(...), $rows);
        return ResponseEnvelope::success($response, $items, ['next_cursor' => $more ? (string) ($page + 1) : null])->withHeader('Cache-Control', 'no-store');
    }

    private function wantedDetail(Response $response, int $id): Response
    {
        $rows = $this->rows("SELECT l.id, l.titolo, l.sottotitolo, l.descrizione, l.anno_pubblicazione, l.isbn13, l.isbn10, l.copertina_url, e.nome AS editore,
            (SELECT GROUP_CONCAT(a.nome SEPARATOR ', ') FROM libri_autori la JOIN autori a ON a.id = la.autore_id WHERE la.libro_id = l.id) AS autore,
            (SELECT a.nome FROM libri_autori la JOIN autori a ON a.id = la.autore_id WHERE la.libro_id = l.id
             ORDER BY CASE la.ruolo WHEN 'principale' THEN 0 ELSE 1 END, la.ordine_credito LIMIT 1) AS autore_principale_nome
            FROM libri l LEFT JOIN editori e ON e.id = l.editore_id
            WHERE l.id = ? AND l.deleted_at IS NULL AND l.is_desiderata = 1 AND NOT EXISTS (SELECT 1 FROM copie c WHERE c.libro_id = l.id)", [$id]);
        if ($rows === []) { return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404); }
        $row = $rows[0];
        return ResponseEnvelope::success($response, $this->wantedItem($row) + [
            'subtitle' => $this->text($row['sottotitolo'] ?? null),
            'description' => $this->text($row['descrizione'] ?? null),
            'year' => isset($row['anno_pubblicazione']) ? (int) $row['anno_pubblicazione'] : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function wantedItem(array $row): array
    {
        return ['id' => (int) $row['id'], 'title' => $this->text($row['titolo'] ?? '') ?? '',
            'author' => $this->text($row['autore'] ?? null), 'publisher' => $this->text($row['editore'] ?? null),
            'isbn' => $this->text($row['isbn13'] ?? null) ?? $this->text($row['isbn10'] ?? null),
            'cover_url' => $this->media($row['copertina_url'] ?? null),
            'web_url' => absoluteUrl(book_url($row)), 'wanted' => true];
    }

    private function archives(Request $request, Response $response): Response
    {
        [$query, $page, $limit] = $this->pageParams($request);
        $params = $request->getQueryParams();
        $level = $params['level'] ?? '';
        if (!is_string($level) || ($level !== '' && !in_array($level, ['fonds', 'series', 'file', 'item'], true))) { throw new \InvalidArgumentException(); }
        $parent = isset($params['parent_id']) ? $this->integer($params['parent_id'], 1, 2147483647) : null;
        $from = isset($params['date_from']) && $params['date_from'] !== '' ? $this->integer($params['date_from'], -32768, 32767) : null;
        $to = isset($params['date_to']) && $params['date_to'] !== '' ? $this->integer($params['date_to'], -32768, 32767) : null;
        if ($from !== null && $to !== null && $from > $to) { throw new \InvalidArgumentException(); }
        $where = ['u.deleted_at IS NULL' . $this->publishedSql('u.')]; $values = [];
        if ($parent !== null) { $where[] = 'u.parent_id = ?'; $values[] = $parent; }
        elseif ($query === '' && $level === '' && $from === null && $to === null) { $where[] = 'u.parent_id IS NULL'; }
        if ($query !== '') {
            $where[] = "(u.reference_code LIKE ? ESCAPE '!' OR u.constructed_title LIKE ? ESCAPE '!' OR u.formal_title LIKE ? ESCAPE '!' OR u.scope_content LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM archival_unit_authority aua JOIN authority_records ar ON ar.id = aua.authority_id WHERE aua.archival_unit_id = u.id AND ar.deleted_at IS NULL AND ar.authorised_form LIKE ? ESCAPE '!'))";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            array_push($values, $pattern, $pattern, $pattern, $pattern, $pattern);
        }
        if ($level !== '') { $where[] = 'u.level = ?'; $values[] = $level; }
        if ($from !== null) { $where[] = 'u.date_start IS NOT NULL AND GREATEST(u.date_start, COALESCE(u.date_end, u.date_start)) >= ?'; $values[] = $from; }
        if ($to !== null) { $where[] = 'u.date_start IS NOT NULL AND u.date_start <= ?'; $values[] = $to; }
        $clause = implode(' AND ', $where);
        $count = $this->rows("SELECT COUNT(*) AS n FROM archival_units u WHERE $clause", $values);
        $rows = $this->rows("SELECT u.id, u.parent_id, u.reference_code, u.level, u.constructed_title, u.formal_title, u.date_start, u.date_end, u.extent, u.specific_material, u.cover_image_path
            FROM archival_units u WHERE $clause ORDER BY FIELD(u.level, 'fonds', 'series', 'file', 'item'), u.reference_code, u.id LIMIT ? OFFSET ?", [...$values, $limit + 1, ($page - 1) * $limit]);
        $more = count($rows) > $limit; if ($more) { array_pop($rows); }
        return ResponseEnvelope::success($response, array_map($this->archiveItem(...), $rows),
            ['next_cursor' => $more ? (string) ($page + 1) : null, 'total_count' => (int) ($count[0]['n'] ?? 0)])->withHeader('Cache-Control', 'no-store');
    }

    private function archive(Request $request, Response $response, int $id): Response
    {
        $rows = $this->rows('SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL' . $this->publishedSql(), [$id]);
        if ($rows === []) { return ResponseEnvelope::error($response, 'not_found', __('Documento non trovato.'), 404); }
        $row = $rows[0];
        $fields = [];
        foreach (['scope_content', 'archival_history', 'extent', 'photographer', 'language_codes', 'access_conditions', 'reproduction_rules',
            'dimensions', 'color_mode', 'publisher', 'predominant_dates', 'date_gaps', 'arrangement_system', 'finding_aids', 'originals_location', 'copies_location', 'related_units', 'institution_code', 'local_classification', 'collection_name'] as $key) {
            $value = $this->text($row[$key] ?? null);
            if ($value !== null) { $fields[$key] = $value; }
        }
        $authorities = $this->rows('SELECT ar.id, ar.type, ar.authorised_form AS name, ar.dates_of_existence AS dates, aua.role FROM archival_unit_authority aua
            JOIN authority_records ar ON ar.id = aua.authority_id WHERE aua.archival_unit_id = ? AND ar.deleted_at IS NULL ORDER BY aua.role, ar.authorised_form, ar.id', [$id]);
        foreach ($authorities as &$authority) { $authority['id'] = (int) $authority['id']; }
        unset($authority);
        $ancestors = []; $seen = [$id => true]; $parent = (int) ($row['parent_id'] ?? 0);
        while ($parent > 0 && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $parents = $this->rows('SELECT id, parent_id, constructed_title, formal_title, reference_code, level, date_start, date_end FROM archival_units WHERE id = ? AND deleted_at IS NULL' . $this->publishedSql(), [$parent]);
            if ($parents === []) { break; }
            array_unshift($ancestors, $this->archiveItem($parents[0])); $parent = (int) ($parents[0]['parent_id'] ?? 0);
        }
        $files = $this->rows('SELECT file_path, file_mime, original_filename FROM archival_unit_files WHERE unit_id = ? ORDER BY sort_order, id', [$id]);
        if ($files === [] && !empty($row['document_path'])) {
            $files = [['file_path' => $row['document_path'], 'file_mime' => $row['document_mime'] ?? '', 'original_filename' => $row['document_filename'] ?? '']];
        }
        $documents = [];
        foreach ($files as $file) {
            $url = $this->media($file['file_path']);
            if ($url !== null) { $documents[] = ['url' => $url, 'mime' => (string) $file['file_mime'], 'label' => (string) $file['original_filename']]; }
        }
        // Children use the paginated listing: a large fonds is never silently truncated.
        return ResponseEnvelope::success($response, $this->archiveItem($row) + ['fields' => (object) $fields, 'ancestors' => $ancestors,
            'authorities' => $authorities, 'documents' => $documents,
            'exports' => ['Dublin Core' => absoluteUrl('/archives/' . $id . '/dc.xml'), 'EAD' => absoluteUrl('/archives/' . $id . '/ead.xml'),
                'METS' => absoluteUrl('/archives/' . $id . '/mets.xml'), 'IIIF' => absoluteUrl('/archives/' . $id . '/manifest.json'), 'RiC-O' => absoluteUrl('/archives/' . $id . '/ric.json')]])
            ->withHeader('Cache-Control', 'no-store');
    }

    private function archiveItem(array $row): array
    {
        return ['id' => (int) $row['id'], 'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            'title' => (string) ($row['constructed_title'] ?? $row['formal_title'] ?? ''), 'formal_title' => $this->text($row['formal_title'] ?? null),
            'reference_code' => (string) ($row['reference_code'] ?? ''), 'level' => (string) $row['level'],
            'date_start' => isset($row['date_start']) ? (int) $row['date_start'] : null, 'date_end' => isset($row['date_end']) ? (int) $row['date_end'] : null,
            'extent' => $this->text($row['extent'] ?? null), 'material' => $this->text($row['specific_material'] ?? null),
            'cover_url' => $this->media($row['cover_image_path'] ?? null),
            'web_url' => absoluteUrl(route_path('archives') . '/' . (int) $row['id'])];
    }

    private function pageParams(Request $request): array
    {
        $params = $request->getQueryParams(); $q = $params['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 200) { throw new \InvalidArgumentException(); }
        $page = isset($params['cursor']) && $params['cursor'] !== '' ? $this->integer($params['cursor'], 1, 100000) : 1;
        $limit = isset($params['limit']) ? $this->integer($params['limit'], 1, 50) : 20;
        return [trim($q), $page, $limit];
    }

    private function integer(mixed $raw, int $min, int $max): int
    {
        if ((!is_int($raw) && !is_string($raw)) || preg_match('/^-?\d+$/D', (string) $raw) !== 1 || strlen((string) $raw) > 11 || (int) $raw < $min || (int) $raw > $max) { throw new \InvalidArgumentException(); }
        return (int) $raw;
    }

    private function media(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') { return null; }
        if (preg_match('#^https?://#i', $value)) { return $value; }
        if (str_starts_with($value, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) { return null; }
        return absoluteUrl('/' . ltrim($value, '/'));
    }

    private function text(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8')) : '';
        return $value !== '' ? $value : null;
    }

    /**
     * Only the units the library published on its site (Archives 1.5.2+); ''
     * on an older Archives without the flag, where every unit is public.
     */
    private function publishedSql(string $alias = ''): string
    {
        static $exists = null;
        if ($exists === null) {
            $probe = $this->rows("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units' AND COLUMN_NAME = 'published'");
            $exists = (int) ($probe[0]['c'] ?? 0) > 0;
        }
        return $exists ? ' AND ' . $alias . 'published = 1' : '';
    }

    private function rows(string $sql, array $values = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) { throw new \RuntimeException('Cannot prepare collection query'); }
        try {
            if ($values !== []) { $stmt->bind_param(implode('', array_map(static fn($v): string => is_int($v) ? 'i' : 's', $values)), ...$values); }
            if (!$stmt->execute()) { throw new \RuntimeException('Cannot query collection'); }
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally { $stmt->close(); }
    }
}
