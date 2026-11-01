<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\BookCirculationCheck;
use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffBookPayload;
use App\Plugins\MobileApi\Support\StaffBookReader;
use App\Plugins\MobileApi\Support\StaffCoverStore;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

/**
 * Staff book management for the companion app (/api/v1/staff/books, lookups,
 * ISBN import). Every route is behind AppAuth + TokenQuota + StaffRole.
 *
 * Create and update are DELEGATED in-process to LibriController::store() /
 * update() with a mock form request — exactly what the web form posts — so the
 * app inherits the whole admin pipeline unchanged: the identifier advisory
 * locks and duplicate check, author/publisher/genre find-or-create, collocation,
 * the atomic book + copies transaction, every `book.*` plugin hook, covers,
 * series, the search index, cache invalidation and the activity log. This
 * controller only maps the English JSON contract to those form keys and maps
 * the controller's outcome (302 / 409 / 422 / 400 / 503) back to the API
 * envelope.
 */
final class StaffBooksController
{
    public function __construct(private mysqli $db)
    {
    }

    // ─── GET /staff/lookups ──────────────────────────────────────────────────

    public function lookups(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $mediaTypes = [];
            foreach (array_keys(\App\Support\MediaLabels::allTypes()) as $type) {
                $mediaTypes[] = ['value' => $type, 'label' => \App\Support\MediaLabels::tipoMediaDisplayName($type)];
            }

            $roles = [];
            foreach (['principale', 'co-autore', 'traduttore', 'illustratore', 'curatore', 'colorista'] as $role) {
                $roles[] = ['value' => $role, 'label' => \App\Support\ContributorRoles::label($role)];
            }

            $copyStates = [];
            $copyLabels = [
                'disponibile'      => __('Disponibile'),
                'prestato'         => __('Prestato'),
                'prenotato'        => __('Prenotato'),
                'manutenzione'     => __('Manutenzione'),
                'in_restauro'      => __('In restauro'),
                'perso'            => __('Perso'),
                'danneggiato'      => __('Danneggiato'),
                'in_trasferimento' => __('In trasferimento'),
            ];
            foreach ($copyLabels as $value => $label) {
                $creatable = in_array($value, self::CREATABLE_COPY_STATES, true);
                // `editable` (1.7.0): a copy can be MOVED to this state with
                // PUT /staff/copies/{id}; loan states belong to circulation.
                $state = ['value' => $value, 'label' => $label, 'creatable' => $creatable];
                if (\App\Plugins\MobileApi\Support\StaffAreas::implemented('copies')) {
                    $state['editable'] = $creatable;
                }
                $copyStates[] = $state;
            }

            $languages = array_map(
                static fn (string $v): array => ['code' => $v, 'label' => $v],
                $this->distinctValues('lingua', 100)
            );
            $formats = array_map(
                static fn (string $v): array => ['value' => $v, 'label' => \App\Support\MediaLabels::formatDisplayName($v)],
                $this->distinctValues('formato', 100)
            );
            $acquisition = array_values(array_unique(array_merge(
                ['acquisto', 'donazione', 'prestito'],
                $this->distinctValues('tipo_acquisizione', 50)
            )));
            $acquisitionTypes = array_map(
                static fn (string $v): array => ['value' => $v, 'label' => ucfirst($v)],
                $acquisition
            );

            $data = [
                'languages'         => $languages,
                'media_types'       => $mediaTypes,
                'formats'           => $formats,
                'acquisition_types' => $acquisitionTypes,
                'contributor_roles' => $roles,
                'copy_states'       => $copyStates,
            ];
            // Mobile API 1.7.0 additions, each only when its area is mounted.
            if (\App\Plugins\MobileApi\Support\StaffAreas::implemented('series')) {
                $data['series_types'] = array_map(
                    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                    array_keys(\App\Support\SeriesLabels::types()),
                    array_values(\App\Support\SeriesLabels::types())
                );
            }
            // Pinakes 0.9.0 (Mobile API 1.10.0): each list only while its module is on.
            if (\App\Support\Features::enabled('suppliers')) {
                $data['suppliers'] = [];
                foreach ((new \App\Models\SupplierRepository($this->db))->options() as $id => $name) {
                    $data['suppliers'][] = ['id' => $id, 'name' => $name];
                }
            }
            if (\App\Support\Features::enabled('rda_types')) {
                foreach (\App\Support\RdaTypes::COLUMNS as $kind => $column) {
                    $values = [];
                    foreach (\App\Support\RdaTypes::options($kind) as $code => $label) {
                        $values[] = ['value' => $code, 'label' => __($label)];
                    }
                    $data[$column . 's'] = $values;
                }
            }
            if (\App\Plugins\MobileApi\Support\StaffAreas::implemented('circulation')) {
                $data['return_outcomes'] = [
                    ['value' => 'restituito', 'label' => __('Restituito')],
                    ['value' => 'manutenzione', 'label' => __('Manutenzione')],
                    ['value' => 'in_restauro', 'label' => __('In restauro')],
                    ['value' => 'perso', 'label' => __('Perso')],
                    ['value' => 'danneggiato', 'label' => __('Danneggiato')],
                ];
            }

            return HttpCache::envelope($request, $response, 'staff-lookups', $data);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff lookups failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    /** Copy states a NEW copy may be created in (loan states are derived). */
    public const CREATABLE_COPY_STATES = ['disponibile', 'manutenzione', 'in_restauro', 'perso', 'danneggiato', 'in_trasferimento'];

    // ─── GET /staff/isbn/{isbn} ──────────────────────────────────────────────

    public function isbnLookup(ServerRequestInterface $request, ResponseInterface $response, string $raw): ResponseInterface
    {
        $identifier = self::parseIdentifier($raw);
        if ($identifier === null) {
            return ResponseEnvelope::error(
                $response,
                'validation_error',
                __('Codice non valido: serve un ISBN-10, ISBN-13 o EAN-13 con cifra di controllo corretta.'),
                422,
                ['field' => 'isbn']
            );
        }

        try {
            $reader = new StaffBookReader($this->db);
            $existing = $reader->findByIdentifiers(array_values(array_filter([
                $identifier['isbn10'], $identifier['isbn13'], $identifier['ean'],
            ], static fn (?string $c): bool => $c !== null)));

            $query = $identifier['kind'] === 'isbn' ? (string) $identifier['isbn13'] : (string) $identifier['ean'];
            $mock = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/scrape/isbn')
                ->withQueryParams(['isbn' => $query]);
            // No `scrape.http_entry` attribute: the in-process call keeps the
            // session open and runs the full normalised pipeline + hooks.
            $scrape = (new \App\Controllers\ScrapeController())->byIsbn($mock, new SlimResponse());
            $status = $scrape->getStatusCode();
            $body = json_decode((string) $scrape->getBody(), true);
            $body = is_array($body) ? $body : [];

            if ($status === 503) {
                return ResponseEnvelope::error(
                    $response,
                    'scrape_unavailable',
                    __('Nessuna fonte di ricerca ISBN disponibile su questa istanza.'),
                    503
                );
            }
            if ($status === 400 && $identifier['kind'] === 'isbn') {
                return ResponseEnvelope::error($response, 'validation_error', __('Formato ISBN non valido.'), 422, ['field' => 'isbn']);
            }

            $found = $status === 200 && trim((string) ($body['title'] ?? '')) !== '';
            if (!$found && $status !== 404 && $status !== 400 && $status !== 200) {
                SecureLogger::warning('[MobileApi] staff ISBN lookup: scraper returned HTTP ' . $status);
                return ResponseEnvelope::error($response, 'scrape_unavailable', __('Ricerca ISBN non disponibile, riprova più tardi.'), 503);
            }

            $sources = [];
            foreach ((array) ($body['sources_checked'] ?? []) as $source) {
                if (is_scalar($source)) {
                    $sources[] = (string) $source;
                }
            }

            return ResponseEnvelope::success($response, [
                'identifier'      => $identifier,
                'found'           => $found,
                'existing'        => $existing,
                'preview'         => $found ? self::mapPreview($body, $identifier) : null,
                'sources_checked' => $sources,
            ]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff ISBN lookup failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    /**
     * Validate a scanned / typed code. ISBN-10 (mod 11), ISBN-13 (978/979) and
     * any other EAN-13 (mod 10) are accepted; dashes and spaces are ignored.
     *
     * @return array{input:string, isbn10:?string, isbn13:?string, ean:?string, kind:string}|null
     */
    public static function parseIdentifier(string $raw): ?array
    {
        $input = trim(rawurldecode($raw));
        $clean = StaffBookPayload::cleanCode($input);
        if (strlen($clean) === 10 && \App\Support\IsbnFormatter::isValidIsbn10($clean)) {
            $isbn13 = \App\Support\IsbnFormatter::isbn10ToIsbn13($clean);
            return ['input' => $input, 'isbn10' => $clean, 'isbn13' => $isbn13, 'ean' => $isbn13, 'kind' => 'isbn'];
        }
        if (strlen($clean) === 13 && \App\Support\IsbnFormatter::isValidIsbn13($clean)) {
            if (str_starts_with($clean, '978') || str_starts_with($clean, '979')) {
                $isbn10 = \App\Support\IsbnFormatter::isbn13ToIsbn10($clean);
                return ['input' => $input, 'isbn10' => $isbn10, 'isbn13' => $clean, 'ean' => $clean, 'kind' => 'isbn'];
            }
            // Same mod-10 checksum: a non-book EAN-13 (music, film, …).
            return ['input' => $input, 'isbn10' => null, 'isbn13' => null, 'ean' => $clean, 'kind' => 'ean'];
        }

        return null;
    }

    /**
     * Scraper payload (Italian/legacy keys) → English preview contract.
     *
     * @param array<string, mixed> $p
     * @param array{input:string, isbn10:?string, isbn13:?string, ean:?string, kind:string} $identifier
     * @return array<string, mixed>
     */
    public static function mapPreview(array $p, array $identifier): array
    {
        $str = static function (mixed $v): ?string {
            if (is_array($v)) {
                $parts = array_filter(array_map(
                    static fn (mixed $x): string => is_scalar($x) ? trim((string) $x) : '',
                    $v
                ), static fn (string $x): bool => $x !== '');
                $v = implode(', ', $parts);
            }
            if (!is_scalar($v)) {
                return null;
            }
            $s = trim((string) $v);
            return $s !== '' ? $s : null;
        };
        $int = static function (mixed $v): ?int {
            if (is_int($v)) {
                return $v;
            }
            if (is_string($v) && preg_match('/^\s*(-?\d+)/', $v, $m) === 1) {
                return (int) $m[1];
            }
            return is_float($v) ? (int) $v : null;
        };

        // Sources often return the same person twice ("Primo Levi" and
        // "Levi, Primo"); the save path merges them through the canonical
        // name, so the preview shows each person once, in that same form.
        $authors = [];
        $rawAuthors = $p['authors'] ?? ($p['author'] ?? []);
        foreach (is_array($rawAuthors) ? $rawAuthors : [$rawAuthors] as $a) {
            $name = is_array($a) ? ($a['name'] ?? ($a['nome'] ?? '')) : $a;
            if (!is_scalar($name) || trim((string) $name) === '') {
                continue;
            }
            $canonical = \App\Support\AuthorNormalizer::normalize(trim((string) $name));
            if ($canonical !== '') {
                $authors[mb_strtolower($canonical)] ??= $canonical;
            }
        }
        $authors = array_values($authors);

        $year = $int($p['year'] ?? null);
        $pubDate = $str($p['pubDate'] ?? ($p['pub_date'] ?? null));
        if ($year === null && $pubDate !== null && preg_match('/\b(\d{4})\b/', $pubDate, $m) === 1) {
            $year = (int) $m[1];
        }

        $cover = $str($p['image'] ?? ($p['cover'] ?? null));
        if ($cover !== null && preg_match('#^https?://#i', $cover) !== 1) {
            $cover = str_starts_with($cover, '/') ? absoluteUrl($cover) : null;
        }

        $isbn13 = $str($p['isbn13'] ?? null) ?? $identifier['isbn13'];
        $isbn10 = $str($p['isbn10'] ?? null) ?? $identifier['isbn10'];

        return [
            'title'       => (string) $str($p['title'] ?? ''),
            'subtitle'    => $str($p['subtitle'] ?? null),
            'authors'     => $authors,
            'publisher'   => $str($p['publisher'] ?? null),
            'year'        => $year,
            'pub_date'    => $pubDate,
            'pages'       => $int($p['pages'] ?? null),
            'language'    => $str($p['language'] ?? null),
            'description' => $str($p['description'] ?? null),
            'cover_url'   => $cover,
            'isbn10'      => $isbn10,
            'isbn13'      => $isbn13,
            'ean'         => $str($p['ean'] ?? null) ?? $identifier['ean'],
            'series'      => $str($p['series'] ?? null),
            'edition'     => $str($p['edition'] ?? null),
            'place'       => $str($p['place'] ?? null),
            'keywords'    => $str($p['keywords'] ?? null),
            'price'       => $str($p['price'] ?? null),
            'format'      => $str($p['format'] ?? null),
            'media_type'  => $str($p['tipo_media'] ?? ($p['media_type'] ?? null)),
            'dewey'       => $str($p['classificazione_dewey'] ?? ($p['dewey'] ?? null)),
            'translator'  => $str($p['translator'] ?? null),
            'illustrator' => $str($p['illustrator'] ?? null),
            'author_bio'  => $str($p['author_bio'] ?? null),
            'source'      => $str($p['_primary_source'] ?? ($p['source'] ?? null)),
            // Extra, not in the base contract: curators returned by some sources.
            'editors'     => array_values(array_filter(array_map(
                static fn (mixed $x): string => is_scalar($x) ? trim((string) $x) : '',
                is_array($p['editor'] ?? null) ? $p['editor'] : (is_scalar($p['editor'] ?? null) ? [$p['editor']] : [])
            ), static fn (string $x): bool => $x !== '')),
        ];
    }

    // ─── GET /staff/books ────────────────────────────────────────────────────

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return (new CatalogController($this->db, true))->search($request, $response);
    }

    // ─── GET /staff/books/{id} ───────────────────────────────────────────────

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $book = (new StaffBookReader($this->db))->load($bookId);
            if ($book === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            return HttpCache::envelope($request, $response, 'staff-book', $book);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff book detail failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── POST /staff/books ───────────────────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);

        try {
            $invalid = StaffBookPayload::validate($json, true) ?? $this->validateCataloguing($json) ?? $this->validateReferences($json);
            if ($invalid !== null) {
                return ResponseEnvelope::error($response, 'validation_error', $invalid['message'], 422, ['field' => $invalid['field']]);
            }
            if (isset($json['cover_url']) && is_string($json['cover_url'])) {
                $json['cover_url'] = StaffCoverStore::localPathFromUrl(trim($json['cover_url']));
            }

            $post = StaffBookPayload::toCreatePost($json);
            StaffBookPayload::applyCataloguing($post, $json, self::suppliersOn(), \App\Support\Features::enabled('rda_types'));
            $outcome = $this->delegate(function () use ($post): ResponseInterface {
                $mock = (new ServerRequestFactory())
                    ->createServerRequest('POST', '/admin/books/create')
                    ->withParsedBody($post);
                return (new \App\Controllers\LibriController())->store($mock, new SlimResponse(), $this->db);
            });

            if ($outcome['id'] !== null) {
                $book = (new StaffBookReader($this->db))->load($outcome['id']);
                if ($book === null) {
                    return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
                }
                return ResponseEnvelope::success($response, $book, $outcome['meta'], 201);
            }

            return $this->delegationError($response, $outcome);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff book create failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── PUT /staff/books/{id} ───────────────────────────────────────────────

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $reader = new StaffBookReader($this->db);
            $current = $reader->currentState($bookId);
            if ($current === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $invalid = StaffBookPayload::validate($json, false) ?? $this->validateCataloguing($json) ?? $this->validateReferences($json);
            if ($invalid !== null) {
                return ResponseEnvelope::error($response, 'validation_error', $invalid['message'], 422, ['field' => $invalid['field']]);
            }
            if (isset($json['cover_url']) && is_string($json['cover_url'])) {
                $json['cover_url'] = StaffCoverStore::localPathFromUrl(trim($json['cover_url']));
            }

            $post = StaffBookPayload::toUpdatePost($json, $current);
            StaffBookPayload::applyCataloguing($post, $json, self::suppliersOn(), \App\Support\Features::enabled('rda_types'));
            $outcome = $this->delegate(function () use ($post, $bookId): ResponseInterface {
                $mock = (new ServerRequestFactory())
                    ->createServerRequest('POST', '/admin/books/update/' . $bookId)
                    ->withParsedBody($post);
                return (new \App\Controllers\LibriController())->update($mock, new SlimResponse(), $this->db, $bookId);
            });

            if ($outcome['id'] === $bookId) {
                $book = $reader->load($bookId);
                if ($book === null) {
                    return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
                }
                return ResponseEnvelope::success($response, $book, $outcome['meta'], 200);
            }

            return $this->delegationError($response, $outcome);
        } catch (\Slim\Exception\HttpNotFoundException $e) {
            return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff book update failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── DELETE /staff/books/{id} ────────────────────────────────────────────

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $reader = new StaffBookReader($this->db);
            if ($reader->row($bookId) === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            if (BookCirculationCheck::hasActiveCirculation($this->db, $bookId)) {
                return ResponseEnvelope::error(
                    $response,
                    'conflict',
                    __('Impossibile eliminare il libro: ci sono prestiti o prenotazioni attive. Termina prima i prestiti/prenotazioni.'),
                    409,
                    ['reason' => 'active_circulation']
                );
            }

            $snapshot = \App\Support\ActivityLog::loadBookSnapshot($this->db, $bookId);
            if (!(new \App\Models\BookRepository($this->db))->delete($bookId)) {
                return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
            }
            \App\Support\ContentCache::booksChanged();
            \App\Support\ActivityLog::recordBookEvent(
                $this->db,
                $bookId,
                'cancellazione',
                'edit',
                'book.deleted',
                $snapshot,
                [],
                bookTitle: (string) ($snapshot['titolo'] ?? ''),
                source: 'mobile'
            );

            return ResponseEnvelope::success($response, ['id' => $bookId, 'deleted' => true]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff book delete failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── POST /staff/books/{id}/copies ───────────────────────────────────────

    public function addCopies(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $quantity = StaffBookPayload::intOrNull($json['quantity'] ?? null);
        if ($quantity === null || $quantity < 1 || $quantity > StaffBookPayload::MAX_COPIES) {
            return ResponseEnvelope::error($response, 'validation_error', __('Numero di copie non valido.'), 422, ['field' => 'quantity']);
        }
        $status = $json['status'] ?? 'disponibile';
        if (!is_string($status) || !in_array($status, self::CREATABLE_COPY_STATES, true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Stato non valido.'), 422, ['field' => 'status']);
        }
        $notesRaw = $json['notes'] ?? null;
        if ($notesRaw !== null && !is_string($notesRaw)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'notes']);
        }
        $notes = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $notesRaw));
        if (mb_strlen($notes) > 500) {
            $notes = mb_substr($notes, 0, 500);
        }
        // 1.7.0: one copy with an explicit inventory code (the barcode the
        // operator scanned or typed), same rules as CopyController::createCopy().
        $codeRaw = $json['inventory_number'] ?? null;
        if ($codeRaw !== null && !is_string($codeRaw)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'inventory_number']);
        }
        $code = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $codeRaw));
        if (mb_strlen($code) > 100) {
            return ResponseEnvelope::error($response, 'validation_error', __('Numero di inventario troppo lungo (massimo 100 caratteri).'), 422, ['field' => 'inventory_number']);
        }
        if ($code !== '' && $quantity !== 1) {
            return ResponseEnvelope::error(
                $response,
                'validation_error',
                __('Per aggiungere più copie, lascia vuoto il numero di inventario: verrà assegnato automaticamente a ogni copia.'),
                422,
                ['field' => 'inventory_number']
            );
        }
        if ($code !== '') {
            $existing = $this->copyByCode($code);
            if ($existing !== null) {
                return $this->duplicateCopy($response, $existing);
            }
        }

        $reassign = null;
        $manager = null;
        $inTx = false;
        try {
            $book = (new StaffBookReader($this->db))->row($bookId);
            if ($book === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            $before = \App\Support\ActivityLog::loadBookSnapshot($this->db, $bookId);
            $base = \App\Models\CopyRepository::inventoryBase($bookId, $book['numero_inventario'] ?? null);

            // Same steps as POST /api/libri/{id}/increase-copies: one
            // transaction, book row locked first, every new copy first repairs
            // copy-less holdings, then promotes the wait list.
            if (!$this->db->begin_transaction()) {
                throw new \RuntimeException('Unable to begin the add-copies transaction.');
            }
            $inTx = true;
            $lock = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            if ($lock === false) {
                throw new \RuntimeException('lock prepare failed');
            }
            $lock->bind_param('i', $bookId);
            $lock->execute();
            $lockResult = $lock->get_result();
            $exists = $lockResult !== false && $lockResult->fetch_assoc() !== null;
            $lock->close();
            if (!$exists) {
                $this->db->rollback();
                $inTx = false;
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $copyRepo = new \App\Models\CopyRepository($this->db);
            if ($code !== '') {
                if ($copyRepo->inventoryCodeExists($code)) {
                    $this->db->rollback();
                    $inTx = false;
                    $existing = $this->copyByCode($code);
                    return $existing !== null
                        ? $this->duplicateCopy($response, $existing)
                        : ResponseEnvelope::error($response, 'duplicate', __('Esiste già una copia con questo numero di inventario.'), 409);
                }
                $newId = $copyRepo->create($bookId, $code, $status, $notes !== '' ? $notes : null);
                $ids = $newId > 0 ? [$newId] : [];
            } else {
                $ids = $notes !== ''
                    ? $copyRepo->createManyForBookWithIdsAndNote($bookId, $base, $quantity, $status, $notes)
                    : $copyRepo->createManyForBookWithIds($bookId, $base, $quantity, $status, __('Copia %d di %d'));
            }
            if (count($ids) !== $quantity) {
                throw new \RuntimeException('Unable to create the requested physical copies.');
            }

            if ($status === 'disponibile') {
                $reassign = new \App\Services\ReservationReassignmentService($this->db);
                $reassign->setExternalTransaction(true);
                foreach ($ids as $copyId) {
                    $reassign->reassignOnNewCopy($bookId, $copyId);
                }
                $manager = new \App\Controllers\ReservationManager($this->db);
                $manager->setExternalTransaction(true);
                for ($guard = 0; $guard < 1000 && $manager->processBookAvailability($bookId); $guard++) {
                    // promote the next eligible reservation
                }
            }

            if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($bookId, true)) {
                throw new \RuntimeException('Unable to recalculate book availability.');
            }
            if (!$this->db->commit()) {
                throw new \RuntimeException('Unable to commit the add-copies transaction.');
            }
            $inTx = false;
        } catch (\Throwable $e) {
            if ($inTx) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    // keep the original failure
                }
            }
            if ($code !== '' && (int) $e->getCode() === 1062) {
                $existing = $this->copyByCode($code);
                if ($existing !== null) {
                    return $this->duplicateCopy($response, $existing);
                }
            }
            SecureLogger::error('[MobileApi] staff add copies failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Impossibile aggiungere le copie.'), 500);
        }

        try {
            $reassign?->flushDeferredNotifications();
            $manager?->flushDeferredNotifications();
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] staff add copies: deferred notification failed: ' . $e->getMessage());
        }

        try {
            \App\Support\ContentCache::booksChanged();
            $after = \App\Support\ActivityLog::loadBookSnapshot($this->db, $bookId);
            \App\Support\ActivityLog::recordBookEvent(
                $this->db,
                $bookId,
                'aggiornamento',
                'edit',
                'book.updated',
                $before,
                $after,
                bookTitle: (string) ($after['titolo'] ?? ''),
                source: 'mobile'
            );

            $created = [];
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id, numero_inventario FROM copie WHERE id IN ({$in}) ORDER BY id");
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
                    $created[] = ['id' => (int) $r['id'], 'inventory_number' => (string) $r['numero_inventario']];
                }
                $stmt->close();
            }
            $row = (new StaffBookReader($this->db))->row($bookId);

            return ResponseEnvelope::success($response, [
                'copies_total'     => (int) ($row['copie_totali'] ?? 0),
                'copies_available' => (int) ($row['copie_disponibili'] ?? 0),
                'created'          => $created,
            ], [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff add copies response failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    /**
     * The copy that already carries an inventory code (any title, the index is global).
     *
     * @return array{id:int, inventory_number:string, book_id:int}|null
     */
    private function copyByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT id, numero_inventario, libro_id FROM copie WHERE numero_inventario = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row === null ? null : [
            'id'               => (int) $row['id'],
            'inventory_number' => (string) $row['numero_inventario'],
            'book_id'          => (int) $row['libro_id'],
        ];
    }

    /**
     * @param array{id:int, inventory_number:string, book_id:int} $existing
     */
    private function duplicateCopy(ResponseInterface $response, array $existing): ResponseInterface
    {
        return ResponseEnvelope::error(
            $response,
            'duplicate',
            __('Esiste già una copia con questo numero di inventario.'),
            409,
            ['existing' => $existing]
        );
    }

    // ─── POST /staff/books/{id}/cover ────────────────────────────────────────

    public function cover(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            if ((new StaffBookReader($this->db))->row($bookId) === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            $store = new StaffCoverStore($this->db);

            $files = $request->getUploadedFiles();
            $upload = $files['cover'] ?? null;
            if ($upload instanceof \Psr\Http\Message\UploadedFileInterface) {
                if ($upload->getError() !== UPLOAD_ERR_OK) {
                    return ResponseEnvelope::error(
                        $response,
                        'validation_error',
                        __('Immagine non valida: JPEG o PNG, massimo 2 MB.'),
                        422,
                        ['field' => 'cover']
                    );
                }
                $size = (int) ($upload->getSize() ?? 0);
                if ($size <= 0 || $size > StaffCoverStore::MAX_UPLOAD_BYTES) {
                    return ResponseEnvelope::error(
                        $response,
                        'validation_error',
                        __('Immagine non valida: JPEG o PNG, massimo 2 MB.'),
                        422,
                        ['field' => 'cover']
                    );
                }
                $path = $store->storeBytes($bookId, (string) $upload->getStream());
            } else {
                $json = JsonBody::parse($request);
                $url = is_string($json['cover_url'] ?? null) ? trim($json['cover_url']) : '';
                if ($url === '') {
                    return ResponseEnvelope::error(
                        $response,
                        'validation_error',
                        __('Invia un file "cover" (multipart) oppure "cover_url".'),
                        422,
                        ['field' => 'cover']
                    );
                }
                $local = StaffCoverStore::localPathFromUrl($url);
                if (str_starts_with($local, '/uploads/copertine/')) {
                    $path = $store->storeLocal($bookId, $local);
                } elseif (preg_match('#^https://#i', $local) === 1 && filter_var($local, FILTER_VALIDATE_URL) !== false) {
                    try {
                        $path = $store->storeRemote($bookId, $local);
                    } catch (\Throwable $e) {
                        SecureLogger::warning('[MobileApi] staff cover download failed: ' . $e->getMessage());
                        return ResponseEnvelope::error(
                            $response,
                            'cover_download_failed',
                            __('Impossibile scaricare la copertina da questo indirizzo.'),
                            502
                        );
                    }
                } else {
                    return ResponseEnvelope::error($response, 'validation_error', __('URL non valido.'), 422, ['field' => 'cover_url']);
                }
            }

            \App\Support\ContentCache::booksChanged();

            return ResponseEnvelope::success($response, ['cover_url' => absoluteUrl($path)]);
        } catch (\InvalidArgumentException $e) {
            return ResponseEnvelope::error($response, 'validation_error', $e->getMessage(), 422, ['field' => 'cover']);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] staff cover failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    // ─── Delegation ──────────────────────────────────────────────────────────

    /**
     * Run a LibriController action and normalise its outcome. The flash keys
     * the controller writes are captured and removed (and any value a
     * concurrent web session held before is restored), so nothing leaks into
     * a later page view.
     *
     * @param callable(): ResponseInterface $action
     * @return array{id:?int, status:int, body:array<string, mixed>, error:?string, meta:array<string, mixed>}
     */
    private function delegate(callable $action): array
    {
        $outcome = \App\Plugins\MobileApi\Support\Delegation::run($action, ['success_message', 'error_message']);
        $error = $outcome->flash('error_message');

        $status = $outcome->status;
        $body = $outcome->json;
        $id = null;
        $meta = [];
        if ($status === 302) {
            $id = $outcome->locationId('#/admin/books/(\d+)$#');
            if ($id !== null && $error !== null) {
                // Saved, but a secondary step (e.g. a cover download) failed.
                $meta['warning'] = $error;
            }
        }

        return ['id' => $id, 'status' => $status, 'body' => $body, 'error' => $error, 'meta' => $meta];
    }

    /**
     * @param array{id:?int, status:int, body:array<string, mixed>, error:?string, meta:array<string, mixed>} $outcome
     */
    private function delegationError(ResponseInterface $response, array $outcome): ResponseInterface
    {
        $status = $outcome['status'];
        $body = $outcome['body'];
        $message = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : null;

        if ($status === 409) {
            $existing = is_array($body['existing_book'] ?? null) ? $body['existing_book'] : [];
            $existingId = (int) ($existing['id'] ?? 0);
            $copies = 0;
            if ($existingId > 0) {
                $row = (new StaffBookReader($this->db))->row($existingId);
                $copies = (int) ($row['copie_totali'] ?? 0);
            }
            $nullable = static fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

            return ResponseEnvelope::error(
                $response,
                'duplicate',
                $message ?? __('Esiste già un libro con lo stesso identificatore (ISBN/EAN).'),
                409,
                ['existing' => [
                    'id'           => $existingId,
                    'title'        => (string) ($existing['title'] ?? ''),
                    'isbn10'       => $nullable($existing['isbn10'] ?? null),
                    'isbn13'       => $nullable($existing['isbn13'] ?? null),
                    'ean'          => $nullable($existing['ean'] ?? null),
                    'copies_total' => $copies,
                    'location'     => $nullable($existing['location'] ?? null),
                ]]
            );
        }
        if ($status === 503) {
            return ResponseEnvelope::error($response, 'lock_error', __('Errore del server. Riprova.'), 503);
        }
        if ($status === 422 || $status === 400) {
            return ResponseEnvelope::error($response, 'validation_error', $message ?? __('Valore non valido.'), 422);
        }
        if ($status === 302) {
            return ResponseEnvelope::error($response, 'validation_error', $outcome['error'] ?? __('Valore non valido.'), 422);
        }

        SecureLogger::error('[MobileApi] staff book delegation: unexpected HTTP ' . $status);
        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }

    /** The suppliers module is on and its schema is in place. */
    private static function suppliersOn(): bool
    {
        return \App\Support\Features::enabled('suppliers');
    }

    /**
     * The 0.9.0 cataloguing keys: well-formed, and a supplier that exists
     * (the web form would silently keep the old one; the app gets a 422).
     *
     * @param array<string, mixed> $json
     * @return array{field:string, message:string}|null
     */
    private function validateCataloguing(array $json): ?array
    {
        $suppliersOn = self::suppliersOn() && (new \App\Models\SupplierRepository($this->db))->isAvailable();
        $invalid = StaffBookPayload::validateCataloguing($json, $suppliersOn, \App\Support\Features::enabled('rda_types'));
        if ($invalid !== null) {
            return $invalid;
        }
        $supplierId = $suppliersOn ? StaffBookPayload::intOrNull($json['supplier_id'] ?? null) : null;
        if ($supplierId !== null && $supplierId > 0 && \App\Support\CataloguingExtras::existingSupplierId($this->db, $supplierId) === null) {
            return ['field' => 'supplier_id', 'message' => __('Riferimento inesistente.')];
        }

        return null;
    }

    /**
     * Foreign references must exist: an unknown author id would otherwise
     * fail a foreign key deep inside the save (500), and an unknown publisher
     * id would be dropped silently.
     *
     * @param array<string, mixed> $json
     * @return array{field:string, message:string}|null
     */
    private function validateReferences(array $json): ?array
    {
        $checks = [
            'author_ids'    => ['autori', StaffBookPayload::idList($json['author_ids'] ?? []) ?? []],
            'publisher_ids' => ['editori', StaffBookPayload::idList($json['publisher_ids'] ?? []) ?? []],
        ];
        if (isset($json['contributors']) && is_array($json['contributors'])) {
            foreach (array_keys(StaffBookPayload::CONTRIBUTOR_ROLES) as $role) {
                $checks['contributors.' . $role . '_ids'] = ['autori', StaffBookPayload::idList($json['contributors'][$role . '_ids'] ?? []) ?? []];
            }
        }
        foreach (['genre_id', 'subgenre_id'] as $key) {
            $id = StaffBookPayload::intOrNull($json[$key] ?? null);
            if ($id !== null && $id > 0) {
                $checks[$key] = ['generi', [$id]];
            }
        }
        foreach ($checks as $field => [$table, $ids]) {
            if ($ids !== [] && $this->countExisting($table, $ids) !== count($ids)) {
                return ['field' => $field, 'message' => __('Riferimento inesistente.')];
            }
        }

        if (array_key_exists('shelf_id', $json) || array_key_exists('level_id', $json)) {
            $shelf = StaffBookPayload::intOrNull($json['shelf_id'] ?? null) ?? 0;
            $level = StaffBookPayload::intOrNull($json['level_id'] ?? null) ?? 0;
            if (($shelf > 0) !== ($level > 0)) {
                return ['field' => $shelf > 0 ? 'level_id' : 'shelf_id', 'message' => __('Scaffale e ripiano vanno indicati insieme.')];
            }
            if ($shelf > 0) {
                $stmt = $this->db->prepare('SELECT 1 FROM mensole WHERE id = ? AND scaffale_id = ? LIMIT 1');
                if ($stmt === false) {
                    throw new \RuntimeException('level probe prepare failed');
                }
                $stmt->bind_param('ii', $level, $shelf);
                $stmt->execute();
                $result = $stmt->get_result();
                $ok = $result !== false && $result->fetch_row() !== null;
                $stmt->close();
                if (!$ok) {
                    return ['field' => 'level_id', 'message' => __('Il ripiano non appartiene allo scaffale indicato.')];
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $ids
     */
    private function countExisting(string $table, array $ids): int
    {
        if (!in_array($table, ['autori', 'editori', 'generi'], true) || $ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$in})");
        if ($stmt === false) {
            throw new \RuntimeException('reference probe prepare failed');
        }
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return (int) ($row[0] ?? 0);
    }

    /**
     * Distinct non-empty values of a free-text libri column, most used first.
     *
     * @return list<string>
     */
    private function distinctValues(string $column, int $limit): array
    {
        if (!in_array($column, ['lingua', 'formato', 'tipo_acquisizione'], true)) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT TRIM({$column}) AS v, COUNT(*) AS n FROM libri
              WHERE deleted_at IS NULL AND {$column} IS NOT NULL AND TRIM({$column}) <> ''
              GROUP BY TRIM({$column}) ORDER BY n DESC, v ASC LIMIT ?"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $out[] = (string) $r['v'];
        }
        $stmt->close();

        return $out;
    }
}
