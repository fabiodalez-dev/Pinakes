<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\Input;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffBookPayload;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Staff management of the entities a book points at: authors, publishers,
 * genres and shelving (bookcases + levels). Writes go through the core
 * repositories so search-index rebuilds, cache invalidation and plugin hooks
 * behave exactly as from the web back office.
 *
 * PUT is a merge: the repositories' update() rewrites every column, so the
 * current record is loaded and only the keys present in the JSON body are
 * replaced (an explicit null clears a field).
 */
final class StaffEntitiesController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(private mysqli $db)
    {
    }

    // ─── Authors ─────────────────────────────────────────────────────────────

    public function searchAuthors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $params = $request->getQueryParams();
            $q = trim(Input::str($params['q'] ?? ''));
            $limit = $this->limit($params['limit'] ?? null);

            $label = \App\Support\AuthorName::displaySql('autori');
            $order = \App\Support\AuthorName::preferredSql('autori');
            [$where, $types, $bind] = $this->wordsWhere($q, ['nome', 'pseudonimo']);
            $sql = "SELECT id, nome, pseudonimo, data_nascita, data_morte, {$label} AS label
                      FROM autori {$where} ORDER BY {$order}, id LIMIT ?";
            $bind[] = $limit;
            $types .= 'i';
            $rows = $this->fetchAll($sql, $types, $bind);

            $items = array_map(static fn (array $r): array => [
                'id'        => (int) $r['id'],
                'name'      => \App\Support\HtmlHelper::decode((string) $r['nome']),
                'pseudonym' => self::nullable($r['pseudonimo'] ?? null),
                'label'     => \App\Support\HtmlHelper::decode((string) $r['label']),
                'born'      => self::nullable($r['data_nascita'] ?? null),
                'died'      => self::nullable($r['data_morte'] ?? null),
            ], $rows);

            return ResponseEnvelope::success($response, $items, ['count' => count($items), 'limit' => $limit]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff author search failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function author(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $author = $this->loadAuthor($id);
            if ($author === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Autore non trovato.'), 404);
            }

            return ResponseEnvelope::success($response, $this->authorPayload($author));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff author read failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function createAuthor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $data = $this->authorData($json, null);
            if (isset($data['error'])) {
                return $this->invalid($response, $data['error']);
            }
            $repo = new \App\Models\AuthorRepository($this->db);
            $existingId = $repo->findByCanonicalName((string) $data['nome']);
            if ($existingId !== null) {
                $existing = $this->loadAuthor($existingId);
                return ResponseEnvelope::error(
                    $response,
                    'duplicate',
                    __('Esiste già un autore con questo nome.'),
                    409,
                    ['existing' => ['id' => $existingId, 'name' => (string) ($existing['nome'] ?? $data['nome'])]]
                );
            }
            $id = $repo->create($data);
            \App\Support\ContentCache::flushDeferred();
            $author = $this->loadAuthor($id);
            if ($author === null) {
                throw new \RuntimeException('created author not readable');
            }

            return ResponseEnvelope::success($response, $this->authorPayload($author), [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff author create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function updateAuthor(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $current = $this->loadAuthor($id);
            if ($current === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Autore non trovato.'), 404);
            }
            $data = $this->authorData($json, $current);
            if (isset($data['error'])) {
                return $this->invalid($response, $data['error']);
            }
            if (!(new \App\Models\AuthorRepository($this->db))->update($id, $data)) {
                throw new \RuntimeException('author update returned false');
            }
            \App\Support\ContentCache::flushDeferred();
            $author = $this->loadAuthor($id);

            return ResponseEnvelope::success($response, $this->authorPayload($author ?? $current));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff author update failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── Publishers ──────────────────────────────────────────────────────────

    public function searchPublishers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $params = $request->getQueryParams();
            $q = trim(Input::str($params['q'] ?? ''));
            $limit = $this->limit($params['limit'] ?? null);
            [$where, $types, $bind] = $this->wordsWhere($q, ['nome']);
            $bind[] = $limit;
            $types .= 'i';
            $rows = $this->fetchAll("SELECT id, nome FROM editori {$where} ORDER BY nome, id LIMIT ?", $types, $bind);
            $items = array_map(static fn (array $r): array => [
                'id'   => (int) $r['id'],
                'name' => \App\Support\HtmlHelper::decode((string) $r['nome']),
            ], $rows);

            return ResponseEnvelope::success($response, $items, ['count' => count($items), 'limit' => $limit]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff publisher search failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function publisher(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $publisher = $this->loadPublisher($id);
            if ($publisher === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Editore non trovato.'), 404);
            }

            return ResponseEnvelope::success($response, $this->publisherPayload($publisher));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff publisher read failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function createPublisher(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $data = $this->publisherData($json, null);
            if (isset($data['error'])) {
                return $this->invalid($response, $data['error']);
            }
            $repo = new \App\Models\PublisherRepository($this->db);
            $existingId = $repo->findByName((string) $data['nome']);
            if ($existingId !== null) {
                $existing = $this->loadPublisher($existingId);
                return ResponseEnvelope::error(
                    $response,
                    'duplicate',
                    __('Esiste già un editore con questo nome.'),
                    409,
                    ['existing' => ['id' => $existingId, 'name' => (string) ($existing['nome'] ?? $data['nome'])]]
                );
            }
            $id = $repo->create($data);
            if ($id <= 0) {
                throw new \RuntimeException('publisher insert failed: ' . $this->db->error);
            }
            \App\Support\ContentCache::flushDeferred();
            $publisher = $this->loadPublisher($id);
            if ($publisher === null) {
                throw new \RuntimeException('created publisher not readable');
            }

            return ResponseEnvelope::success($response, $this->publisherPayload($publisher), [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff publisher create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function updatePublisher(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $current = $this->loadPublisher($id);
            if ($current === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Editore non trovato.'), 404);
            }
            $data = $this->publisherData($json, $current);
            if (isset($data['error'])) {
                return $this->invalid($response, $data['error']);
            }
            if (!(new \App\Models\PublisherRepository($this->db))->update($id, $data)) {
                throw new \RuntimeException('publisher update returned false');
            }
            \App\Support\ContentCache::flushDeferred();
            $publisher = $this->loadPublisher($id);

            return ResponseEnvelope::success($response, $this->publisherPayload($publisher ?? $current));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff publisher update failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── Genres ──────────────────────────────────────────────────────────────

    public function genres(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $flat = (new \App\Models\GenereRepository($this->db))->getAllFlat();
            $byParent = [];
            foreach ($flat as $g) {
                $parent = (int) ($g['parent_id'] ?? 0);
                $byParent[$parent][] = $g;
            }
            $tree = $this->genreBranch($byParent, 0, 0);

            return HttpCache::envelope($request, $response, 'staff-genres', $tree);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff genres failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function createGenre(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $name = is_string($json['name'] ?? null) ? trim($json['name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            return $this->invalid($response, ['field' => 'name', 'message' => __('Il nome è obbligatorio.')]);
        }
        $parentId = null;
        if (array_key_exists('parent_id', $json) && $json['parent_id'] !== null) {
            $parentId = StaffBookPayload::intOrNull($json['parent_id']);
            if ($parentId === null || $parentId <= 0) {
                return $this->invalid($response, ['field' => 'parent_id', 'message' => __('Valore non valido.')]);
            }
        }

        try {
            $repo = new \App\Models\GenereRepository($this->db);
            if ($parentId !== null && $repo->getById($parentId) === null) {
                return $this->invalid($response, ['field' => 'parent_id', 'message' => __('Genere non trovato.')]);
            }
            $decoded = \App\Support\HtmlHelper::decode($name);
            $existing = $repo->findByName($decoded, $parentId);
            $id = $existing ?? $repo->create(['nome' => $name, 'parent_id' => $parentId]);
            \App\Support\ContentCache::flushDeferred();

            return ResponseEnvelope::success($response, [
                'id'        => $id,
                'name'      => $decoded,
                'parent_id' => $parentId,
                'created'   => $existing === null,
            ], [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff genre create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── Shelving ────────────────────────────────────────────────────────────

    public function shelves(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $shelves = [];
            foreach ($this->fetchAll('SELECT id, codice, nome, COALESCE(ordine, 0) AS ordine FROM scaffali ORDER BY ordine, codice, id', '', []) as $s) {
                $shelves[(int) $s['id']] = $this->shelfPayload($s);
            }
            $levels = $this->fetchAll(
                'SELECT id, scaffale_id, numero_livello, descrizione, COALESCE(ordine, 0) AS ordine
                   FROM mensole ORDER BY scaffale_id, numero_livello, id',
                '',
                []
            );
            foreach ($levels as $l) {
                $shelfId = (int) $l['scaffale_id'];
                if (isset($shelves[$shelfId])) {
                    $shelves[$shelfId]['levels'][] = $this->levelPayload($l);
                }
            }

            return ResponseEnvelope::success($response, array_values($shelves), ['count' => count($shelves)]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff shelves failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function createShelf(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $code = is_scalar($json['code'] ?? null) ? strtoupper(trim((string) $json['code'])) : '';
        if ($code === '' || mb_strlen($code) > 20) {
            return $this->invalid($response, ['field' => 'code', 'message' => __('Il codice dello scaffale è obbligatorio (massimo 20 caratteri).')]);
        }
        $name = $json['name'] ?? null;
        if ($name !== null && (!is_string($name) || mb_strlen(trim($name)) > 255)) {
            return $this->invalid($response, ['field' => 'name', 'message' => __('Valore non valido.')]);
        }
        $order = array_key_exists('order', $json) && $json['order'] !== null ? StaffBookPayload::intOrNull($json['order']) : 0;
        if ($order === null) {
            return $this->invalid($response, ['field' => 'order', 'message' => __('Valore non valido.')]);
        }

        try {
            $existing = $this->fetchAll('SELECT id, codice FROM scaffali WHERE codice = ? LIMIT 1', 's', [$code]);
            if ($existing !== []) {
                return ResponseEnvelope::error(
                    $response,
                    'duplicate',
                    sprintf(__('Il codice scaffale "%s" esiste già. Usa un codice diverso.'), $code),
                    409,
                    ['existing' => ['id' => (int) $existing[0]['id'], 'code' => (string) $existing[0]['codice']]]
                );
            }
            try {
                $id = (new \App\Models\CollocationRepository($this->db))->createScaffale([
                    'codice' => $code,
                    'nome'   => is_string($name) ? trim($name) : '',
                    'ordine' => $order,
                ]);
            } catch (\RuntimeException $e) {
                // Lost a race with a concurrent create of the same code.
                return ResponseEnvelope::error($response, 'duplicate', $e->getMessage(), 409);
            }
            $rows = $this->fetchAll('SELECT id, codice, nome, COALESCE(ordine, 0) AS ordine FROM scaffali WHERE id = ?', 'i', [$id]);
            if ($rows === []) {
                throw new \RuntimeException('created shelf not readable');
            }

            return ResponseEnvelope::success($response, $this->shelfPayload($rows[0]), [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff shelf create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function createLevel(ServerRequestInterface $request, ResponseInterface $response, int $shelfId): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $number = StaffBookPayload::intOrNull($json['number'] ?? null);
        if ($number === null || $number < 1 || $number > 1000) {
            return $this->invalid($response, ['field' => 'number', 'message' => __('Valore non valido.')]);
        }
        $description = $json['description'] ?? null;
        if ($description !== null && (!is_string($description) || mb_strlen(trim($description)) > 255)) {
            return $this->invalid($response, ['field' => 'description', 'message' => __('Valore non valido.')]);
        }
        $order = array_key_exists('order', $json) && $json['order'] !== null ? StaffBookPayload::intOrNull($json['order']) : 0;
        if ($order === null) {
            return $this->invalid($response, ['field' => 'order', 'message' => __('Valore non valido.')]);
        }

        try {
            if ($this->fetchAll('SELECT id FROM scaffali WHERE id = ?', 'i', [$shelfId]) === []) {
                return ResponseEnvelope::error($response, 'not_found', __('Scaffale non trovato.'), 404);
            }
            $existing = $this->fetchAll(
                'SELECT id FROM mensole WHERE scaffale_id = ? AND numero_livello = ? LIMIT 1',
                'ii',
                [$shelfId, $number]
            );
            if ($existing !== []) {
                return ResponseEnvelope::error(
                    $response,
                    'duplicate',
                    __('Questo ripiano esiste già nello scaffale.'),
                    409,
                    ['existing' => ['id' => (int) $existing[0]['id'], 'number' => $number]]
                );
            }
            try {
                $id = (new \App\Models\CollocationRepository($this->db))->createMensola([
                    'scaffale_id'    => $shelfId,
                    'numero_livello' => $number,
                    'ordine'         => $order,
                    'descrizione'    => is_string($description) ? $description : '',
                ]);
            } catch (\mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {
                    return ResponseEnvelope::error($response, 'duplicate', __('Questo ripiano esiste già nello scaffale.'), 409);
                }
                throw $e;
            }
            if ($id <= 0) {
                return ResponseEnvelope::error($response, 'duplicate', __('Questo ripiano esiste già nello scaffale.'), 409);
            }
            $rows = $this->fetchAll(
                'SELECT id, scaffale_id, numero_livello, descrizione, COALESCE(ordine, 0) AS ordine FROM mensole WHERE id = ?',
                'i',
                [$id]
            );
            if ($rows === []) {
                throw new \RuntimeException('created level not readable');
            }

            return ResponseEnvelope::success($response, $this->levelPayload($rows[0]), [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff level create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    /**
     * GET /staff/shelves/next-position — same rule as the web form's
     * /api/collocazione/next: keep the book's current slot when it is already
     * on that level, otherwise the first position after the last one used.
     */
    public function nextPosition(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $shelfId = Input::int($params['shelf_id'] ?? 0);
        $levelId = Input::int($params['level_id'] ?? 0);
        $bookId = Input::int($params['book_id'] ?? 0);
        if ($shelfId <= 0 || $levelId <= 0) {
            return $this->invalid($response, ['field' => $shelfId <= 0 ? 'shelf_id' : 'level_id', 'message' => __('Parametri non validi')]);
        }

        try {
            $level = $this->fetchAll(
                'SELECT m.numero_livello, s.codice FROM mensole m JOIN scaffali s ON s.id = m.scaffale_id
                  WHERE m.id = ? AND m.scaffale_id = ? LIMIT 1',
                'ii',
                [$levelId, $shelfId]
            );
            if ($level === []) {
                return $this->invalid($response, ['field' => 'level_id', 'message' => __('Il ripiano non appartiene allo scaffale indicato.')]);
            }

            $repo = new \App\Models\CollocationRepository($this->db);
            $exclude = $bookId > 0 ? $bookId : null;
            $position = 0;
            if ($exclude !== null) {
                $current = $this->fetchAll(
                    'SELECT scaffale_id, mensola_id, posizione_progressiva FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                    'i',
                    [$exclude]
                );
                if ($current !== [] && (int) $current[0]['scaffale_id'] === $shelfId && (int) $current[0]['mensola_id'] === $levelId) {
                    $position = (int) ($current[0]['posizione_progressiva'] ?? 0);
                }
            }
            if ($position <= 0 || $repo->isProgressivaOccupied($shelfId, $levelId, $position, $exclude)) {
                $position = $repo->computeNextProgressiva($shelfId, $levelId, $exclude);
            }

            return ResponseEnvelope::success($response, [
                'position'     => $position,
                'label'        => $repo->buildCollocazioneString($shelfId, $levelId, $position),
                'level_number' => (int) $level[0]['numero_livello'],
                'shelf_code'   => (string) $level[0]['codice'],
            ]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff next position failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Repository input for create/update, merged over $current on update.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current
     * @return array<string, mixed> repository data, or ['error' => [...]]
     */
    private function authorData(array $json, ?array $current): array
    {
        $map = [
            'name'        => ['nome', 255],
            'pseudonym'   => ['pseudonimo', 255],
            'nationality' => ['nazionalita', 100],
            'biography'   => ['biografia', 0],
            'website'     => ['sito_web', 255],
        ];
        $data = [
            'nome'         => (string) ($current['nome'] ?? ''),
            'pseudonimo'   => (string) ($current['pseudonimo'] ?? ''),
            'data_nascita' => $current['data_nascita'] ?? null,
            'data_morte'   => $current['data_morte'] ?? null,
            'nazionalita'  => (string) ($current['nazionalità'] ?? ''),
            'biografia'    => (string) ($current['biografia'] ?? ''),
            'sito_web'     => (string) ($current['sito_web'] ?? ''),
            // Not editable from the app, but update() rewrites them: keep them.
            'foto'         => (string) ($current['foto'] ?? ''),
            'collegamenti' => (string) ($current['collegamenti'] ?? ''),
        ];
        if ($current !== null && array_key_exists('gnd_id', $current)) {
            $data['gnd_id'] = $current['gnd_id'];
        }

        foreach ($map as $key => [$column, $max]) {
            if (!array_key_exists($key, $json)) {
                continue;
            }
            $value = $json[$key];
            if ($value !== null && !is_string($value)) {
                return ['error' => ['field' => $key, 'message' => __('Valore non valido.')]];
            }
            $value = trim((string) $value);
            if ($max > 0 && mb_strlen($value) > $max) {
                return ['error' => ['field' => $key, 'message' => sprintf(__('Valore troppo lungo (massimo %d caratteri).'), $max)]];
            }
            $data[$column] = $value;
        }
        if (trim((string) $data['nome']) === '') {
            return ['error' => ['field' => 'name', 'message' => __('Il nome è obbligatorio.')]];
        }
        // Same sanitising as the web form: plain-text biography, URL website.
        $data['biografia'] = trim(strip_tags((string) $data['biografia']));
        $data['sito_web'] = self::normalizeWebsite((string) $data['sito_web']);

        foreach (['born' => 'data_nascita', 'died' => 'data_morte'] as $key => $column) {
            if (!array_key_exists($key, $json)) {
                continue;
            }
            $value = $json[$key];
            if ($value === null || $value === '') {
                $data[$column] = null;
                continue;
            }
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                return ['error' => ['field' => $key, 'message' => __('Data non valida (formato AAAA-MM-GG).')]];
            }
            $data[$column] = $value;
        }

        if (array_key_exists('gnd_id', $json)) {
            try {
                $data['gnd_id'] = \App\Support\GndIdentifier::normalize($json['gnd_id']);
            } catch (\InvalidArgumentException $e) {
                return ['error' => ['field' => 'gnd_id', 'message' => __('Identificativo GND non valido.')]];
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function publisherData(array $json, ?array $current): array
    {
        $map = [
            'name'    => ['nome', 255],
            'website' => ['sito_web', 255],
            'address' => ['indirizzo', 0],
            'phone'   => ['telefono', 20],
            'email'   => ['email', 255],
        ];
        $data = [];
        foreach (['nome', 'sito_web', 'indirizzo', 'telefono', 'email', 'referente_nome',
                  'referente_telefono', 'referente_email', 'codice_fiscale'] as $column) {
            $data[$column] = (string) ($current[$column] ?? '');
        }
        foreach ($map as $key => [$column, $max]) {
            if (!array_key_exists($key, $json)) {
                continue;
            }
            $value = $json[$key];
            if ($value !== null && !is_string($value)) {
                return ['error' => ['field' => $key, 'message' => __('Valore non valido.')]];
            }
            $value = trim((string) $value);
            if ($max > 0 && mb_strlen($value) > $max) {
                return ['error' => ['field' => $key, 'message' => sprintf(__('Valore troppo lungo (massimo %d caratteri).'), $max)]];
            }
            $data[$column] = $value;
        }
        if (trim($data['nome']) === '') {
            return ['error' => ['field' => 'name', 'message' => __('Il nome è obbligatorio.')]];
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => ['field' => 'email', 'message' => __('Valore non valido.')]];
        }
        $data['sito_web'] = self::normalizeWebsite($data['sito_web']);

        return $data;
    }

    private static function normalizeWebsite(string $url): string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }
        $url = 'https://' . ltrim($url, '/');

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /** @return array<string, mixed>|null */
    private function loadAuthor(int $id): ?array
    {
        $rows = $this->fetchAll('SELECT * FROM autori WHERE id = ? LIMIT 1', 'i', [$id]);

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function loadPublisher(int $id): ?array
    {
        $rows = $this->fetchAll('SELECT * FROM editori WHERE id = ? LIMIT 1', 'i', [$id]);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function authorPayload(array $a): array
    {
        $photo = trim((string) ($a['foto'] ?? ''));
        if ($photo !== '' && preg_match('#^https?://#i', $photo) !== 1) {
            $photo = absoluteUrl($photo);
        }

        return [
            'id'          => (int) $a['id'],
            'name'        => \App\Support\HtmlHelper::decode((string) ($a['nome'] ?? '')),
            'pseudonym'   => self::nullable($a['pseudonimo'] ?? null),
            'born'        => self::nullable($a['data_nascita'] ?? null),
            'died'        => self::nullable($a['data_morte'] ?? null),
            'nationality' => self::nullable($a['nazionalità'] ?? null),
            'biography'   => self::nullable($a['biografia'] ?? null),
            'website'     => self::nullable($a['sito_web'] ?? null),
            'photo_url'   => $photo !== '' ? $photo : null,
            'gnd_id'      => self::nullable($a['gnd_id'] ?? null),
            // Same figures as the web author page: non-deleted linked books.
            'books_count' => count(\App\Support\SearchIndexBuilder::bookIdsForAuthor($this->db, (int) $a['id'])),
            'created_at'  => self::nullable($a['created_at'] ?? null),
            'updated_at'  => self::nullable($a['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function publisherPayload(array $p): array
    {
        return [
            'id'      => (int) $p['id'],
            'name'    => \App\Support\HtmlHelper::decode((string) ($p['nome'] ?? '')),
            'website' => self::nullable($p['sito_web'] ?? null),
            'address' => self::nullable($p['indirizzo'] ?? null),
            'phone'   => self::nullable($p['telefono'] ?? null),
            'email'   => self::nullable($p['email'] ?? null),
            // Same figures as the web publisher page: non-deleted linked books,
            // through the primary publisher or the secondary links.
            'books_count' => count(\App\Support\SearchIndexBuilder::bookIdsForPublisher($this->db, (int) $p['id'])),
            'created_at'  => self::nullable($p['created_at'] ?? null),
            'updated_at'  => self::nullable($p['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function shelfPayload(array $s): array
    {
        return [
            'id'     => (int) $s['id'],
            'code'   => (string) ($s['codice'] ?? ''),
            'name'   => self::nullable($s['nome'] ?? null),
            'order'  => (int) ($s['ordine'] ?? 0),
            'levels' => [],
        ];
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private function levelPayload(array $l): array
    {
        return [
            'id'          => (int) $l['id'],
            'number'      => (int) $l['numero_livello'],
            'description' => self::nullable($l['descrizione'] ?? null),
            'order'       => (int) ($l['ordine'] ?? 0),
        ];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     * @return list<array<string, mixed>>
     */
    private function genreBranch(array $byParent, int $parentId, int $depth): array
    {
        if ($depth > 10) {
            return [];
        }
        $branch = [];
        foreach ($byParent[$parentId] ?? [] as $g) {
            $id = (int) $g['id'];
            $branch[] = [
                'id'        => $id,
                'name'      => \App\Support\HtmlHelper::decode((string) ($g['nome'] ?? '')),
                'parent_id' => $parentId > 0 ? $parentId : null,
                'children'  => $this->genreBranch($byParent, $id, $depth + 1),
            ];
        }

        return $branch;
    }

    /**
     * AND of per-word LIKE matches over the given columns.
     *
     * @param list<string> $columns
     * @return array{0:string, 1:string, 2:list<int|string>}
     */
    private function wordsWhere(string $q, array $columns): array
    {
        if ($q === '') {
            return ['', '', []];
        }
        $condition = \App\Support\ExactSearch::wordsCondition($columns, mb_substr($q, 0, 200), 8);
        return $condition === null
            ? ['', '', []]
            : ['WHERE ' . $condition['sql'], $condition['types'], $condition['params']];
    }

    /**
     * @param list<int|string> $bind
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types, array $bind): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$bind);
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

    private function limit(mixed $raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @param array{field:string, message:string} $error
     */
    private function invalid(ResponseInterface $response, array $error): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation_error', $error['message'], 422, ['field' => $error['field']]);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s !== '' ? $s : null;
    }
}
