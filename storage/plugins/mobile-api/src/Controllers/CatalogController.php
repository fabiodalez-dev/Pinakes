<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\CursorCodec;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Catalog read endpoints for the Mobile API (slice: Catalog).
 *
 *   GET /api/v1/catalog/search          — filtered, cursor-paginated search.
 *   GET /api/v1/catalog/books/{id}      — full book detail + personal history.
 *   GET /api/v1/catalog/genres          — genre cascade tree for the filter UI.
 *
 * Design notes:
 *   - Soft-delete is non-negotiable: EVERY query on `libri` carries
 *     `AND l.deleted_at IS NULL` (CLAUDE.md rule 2 / spec §Security).
 *   - The same filter columns and JOIN shape as the public web catalog
 *     (FrontendController::buildWhereConditions) are reused so the app and the
 *     web agree on results; we only swap OFFSET paging for keyset/cursor paging
 *     on `l.id` (spec §Pagination → opaque `next_cursor`).
 *   - Search ranges over PUBLIC catalog data only — no per-user rows leak here.
 *     Personal history (read/reserved/wishlisted) is attached ONLY on book
 *     detail and ONLY for the authenticated user resolved by AppAuthMiddleware
 *     (never a client-supplied user_id; spec §Data isolation).
 *   - ETag / Last-Modified + If-None-Match → 304 on the read endpoints
 *     (spec §Caching).
 *   - Cover URLs are returned ABSOLUTE (spec §Book detail) via absoluteUrl().
 *   - Error bodies never leak internals; \Throwable is caught and logged via
 *     SecureLogger, the client sees a stable code + safe message.
 */
final class CatalogController
{
    /** Default / maximum page size for cursor pagination. */
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    /** Placeholder cover when a title has none (kept relative for the app). */
    private const PLACEHOLDER_COVER = '/uploads/copertine/placeholder.jpg';

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ─── GET /catalog/search ─────────────────────────────────────────────────

    public function search(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $params = $request->getQueryParams();

            $limit  = $this->clampLimit($params['limit'] ?? null);

            // Sort order — mirrors the web catalog's options. Keyset pagination
            // stays stable by always tie-breaking on the unique id in the same
            // direction as the chosen sort column.
            $sort = $this->sortSpec(isset($params['sort']) ? (string) $params['sort'] : null);

            $cursor = CursorCodec::decode(isset($params['cursor']) ? (string) $params['cursor'] : null);
            // The cursor is opaque to clients and only carries the last row's sort
            // anchor (value + id). It is NEVER trusted as authorization input. A
            // cursor minted under a different sort is ignored so switching the sort
            // order restarts cleanly from the first page.
            $anchor = null;
            if (is_array($cursor)
                && ($cursor['sort'] ?? null) === $sort['key']
                && isset($cursor['last_id']) && is_numeric($cursor['last_id'])
                && array_key_exists('last_val', $cursor)
            ) {
                $anchor = ['id' => (int) $cursor['last_id'], 'val' => $cursor['last_val']];
            }

            [$conditions, $bindParams, $bindTypes] = $this->buildFilters($params);

            // Keyset anchor in the chosen sort direction; id breaks ties so no row
            // is skipped or repeated across pages. Null anchor == first page.
            if ($anchor !== null) {
                $cmp = $sort['dir'] === 'ASC' ? '>' : '<';
                if ($sort['col'] === 'l.id') {
                    $conditions[]  = "l.id {$cmp} ?";
                    $bindParams[]  = $anchor['id'];
                    $bindTypes    .= 'i';
                } else {
                    $conditions[]  = "({$sort['col']} {$cmp} ? OR ({$sort['col']} = ? AND l.id {$cmp} ?))";
                    $bindParams[]  = (string) $anchor['val'];
                    $bindParams[]  = (string) $anchor['val'];
                    $bindParams[]  = $anchor['id'];
                    $bindTypes    .= 'ssi';
                }
            }

            $where = 'l.deleted_at IS NULL';
            if ($conditions !== []) {
                $where .= ' AND ' . implode(' AND ', $conditions);
            }

            // Fetch limit+1 to know whether a further page exists without a
            // second COUNT round-trip.
            $fetch = $limit + 1;
            $authorDisplaySql = \App\Support\AuthorName::displaySql('a');

            $sql = "
                SELECT
                    l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.lingua,
                    l.copertina_url, l.copie_totali, l.copie_disponibili,
                    l.isbn13, l.isbn10, l.ean, l.tipo_media,
                    (SELECT {$authorDisplaySql} FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                     WHERE la.libro_id = l.id AND la.ruolo IN ('principale', 'co-autore')
                     ORDER BY (la.ruolo = 'principale') DESC, la.ordine_credito, la.autore_id LIMIT 1) AS autore,
                    e.nome AS editore,
                    g.nome AS genere
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                LEFT JOIN generi g  ON l.genere_id  = g.id
                LEFT JOIN generi gp ON g.parent_id  = gp.id
                LEFT JOIN generi gpp ON gp.parent_id = gpp.id
                LEFT JOIN generi sg ON l.sottogenere_id = sg.id
                WHERE {$where}
                GROUP BY l.id
                ORDER BY {$sort['order']}
                LIMIT ?
            ";

            $bindTypes  .= 'i';
            $bindParams[] = $fetch;

            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[MobileApi] catalog search prepare failed: ' . $this->db->error);
                return ResponseEnvelope::error($response, 'internal_error', __('Ricerca non disponibile.'), 500);
            }
            // $bindParams always contains at least the LIMIT placeholder bound
            // above, so the bind is unconditional.
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $res = $stmt->get_result();

            $rows = [];
            while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
                $rows[] = $row;
            }
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
            }

            $items = array_map(fn (array $r): array => $this->mapListItem($r), $rows);

            $nextCursor = null;
            if ($hasMore && $rows !== []) {
                $last    = $rows[count($rows) - 1];
                $lastVal = $sort['col'] === 'l.id'
                    ? (int) $last['id']
                    : (string) ($last[$sort['field']] ?? '');
                $nextCursor = CursorCodec::encode([
                    'sort'     => $sort['key'],
                    'last_id'  => (int) $last['id'],
                    'last_val' => $lastVal,
                ]);
            }

            $meta = [
                'count'       => count($items),
                'limit'       => $limit,
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ];

            // Weak validator over the result set so the app can revalidate a
            // page cheaply. Tied to the exact ids + availability snapshot.
            $etag = $this->computeListEtag($items);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = ResponseEnvelope::success($response, $items, $meta, 200);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] catalog search failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Ricerca non disponibile.'), 500);
        }
    }

    // ─── GET /catalog/books/{id} ─────────────────────────────────────────────

    public function bookDetail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $bookId
    ): ResponseInterface {
        try {
            if ($bookId <= 0) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $book = $this->fetchBookCore($bookId);
            if ($book === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $authors    = $this->fetchAuthors($bookId);
            $publishers = $this->fetchPublishers($bookId, $book);
            $copies     = $this->fetchCopies($bookId);
            $related    = $this->fetchRelated($bookId, $authors);

            // Identity comes ONLY from AppAuthMiddleware (token-resolved user),
            // never from a query/body param (spec §Data isolation).
            $user   = $request->getAttribute(\App\Plugins\MobileApi\Support\AppAuthMiddleware::ATTR_USER);
            $userId = (is_array($user) && isset($user['id'])) ? (int) $user['id'] : 0;
            $history = $userId > 0 ? $this->fetchPersonalHistory($userId, $bookId) : $this->emptyHistory();

            $coverRel = $this->coverPath($book['copertina_url'] ?? null);

            // Digital assets (digital-library plugin stores them on libri.audio_url
            // / libri.file_url). Absolute URLs so the app can stream/open them; an
            // already-absolute external URL (e.g. a hosted audio) is passed through.
            $absMedia = static function ($raw): ?string {
                $v = trim((string) ($raw ?? ''));
                if ($v === '') {
                    return null;
                }
                return preg_match('#^https?://#i', $v) === 1 ? $v : absoluteUrl($v);
            };
            $audioUrl = $absMedia($book['audio_url'] ?? null);
            $ebookUrl = $absMedia($book['file_url'] ?? null);
            $ebookFormat = $ebookUrl !== null
                ? (strtolower((string) pathinfo((string) (parse_url($ebookUrl, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION)) ?: null)
                : null;

            // Availability state, so the app can colour-code WHY a title isn't free:
            // available (green) / on_loan (red, a copy is checked out) / reserved
            // (amber, held by a scheduled loan or an active reservation) / unavailable.
            $copiesAvail = (int) ($book['copie_disponibili'] ?? 0);
            $availState  = 'available';
            if ($copiesAvail <= 0) {
                $availState = 'unavailable';
                $holds = [
                    'on_loan'  => "SELECT 1 FROM prestiti WHERE libro_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare') LIMIT 1",
                    'reserved' => "SELECT 1 FROM prestiti WHERE libro_id = ? AND attivo = 1 AND stato = 'prenotato' LIMIT 1",
                    'reserved2'=> "SELECT 1 FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva' LIMIT 1",
                ];
                foreach ($holds as $state => $sql) {
                    $q = $this->db->prepare($sql);
                    if ($q === false) {
                        continue;
                    }
                    $q->bind_param('i', $bookId);
                    $q->execute();
                    $hit = ($r = $q->get_result()) !== false && $r->fetch_row() !== null;
                    $q->close();
                    if ($hit) {
                        $availState = ($state === 'reserved2') ? 'reserved' : $state;
                        break;
                    }
                }
            }

            $data = [
                'id'                 => (int) $book['id'],
                'title'              => (string) ($book['titolo'] ?? ''),
                'subtitle'           => $this->nullableString($book['sottotitolo'] ?? null),
                'year'               => $book['anno_pubblicazione'] !== null ? (int) $book['anno_pubblicazione'] : null,
                'language'           => $this->nullableString($book['lingua'] ?? null),
                'pages'              => $book['numero_pagine'] !== null ? (int) $book['numero_pagine'] : null,
                'description'        => $this->nullableString($book['descrizione'] ?? null),
                'isbn13'             => $this->nullableString($book['isbn13'] ?? null),
                'isbn10'             => $this->nullableString($book['isbn10'] ?? null),
                'ean'                => $this->nullableString($book['ean'] ?? null),
                'media_type'         => $this->nullableString($book['tipo_media'] ?? null),
                'format'             => $this->nullableString($book['formato'] ?? null),
                'series'             => $this->nullableString($book['collana'] ?? null),
                'cover_url'          => absoluteUrl($coverRel),
                'audio_url'          => $audioUrl,
                'ebook_url'          => $ebookUrl,
                'ebook_format'       => $ebookFormat,
                'has_audio'          => $audioUrl !== null,
                'has_ebook'          => $ebookUrl !== null,
                'genre'              => [
                    'id'          => isset($book['genere_id']) ? (int) $book['genere_id'] : null,
                    'name'        => $this->nullableString($book['genere'] ?? null),
                    'parent'      => $this->nullableString($book['genere_parent'] ?? null),
                    'grandparent' => $this->nullableString($book['genere_grandparent'] ?? null),
                    'subgenre'    => $this->nullableString($book['sottogenere'] ?? null),
                ],
                'publisher'          => (string) ($book['editore'] ?? ''),
                'publishers'         => $publishers,
                'authors'            => $authors,
                'availability'       => [
                    'copies_total'     => (int) ($book['copie_totali'] ?? 0),
                    'copies_available' => (int) ($book['copie_disponibili'] ?? 0),
                    'loanable_now'     => ((int) ($book['copie_disponibili'] ?? 0)) > 0,
                    'state'            => $availState, // available | on_loan | reserved | unavailable
                ],
                'copies'             => $copies,
                'location'           => $this->buildLocation($book),
                'related'            => $related,
                'personal_history'   => $history,
            ];

            $lastModified = $this->lastModified($book);
            $etag         = $this->computeDetailEtag($bookId, $book, $userId, $history);

            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag, $lastModified);
            }

            $response = ResponseEnvelope::success($response, $data, [], 200);
            $response = $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
            if ($lastModified !== null) {
                $response = $response->withHeader('Last-Modified', $lastModified);
            }

            return $response;
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] catalog book detail failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Dettaglio non disponibile.'), 500);
        }
    }

    // ─── GET /catalog/genres ─────────────────────────────────────────────────

    public function genres(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            // Reuse the core taxonomy repository for the flat list, then build the
            // cascade tree (max 3 levels in this schema) the filter UI expects.
            $repo = new \App\Models\GenereRepository($this->db);
            $flat = $repo->getAllFlat();

            $tree = $this->buildGenreTree($flat);

            $etag = $this->computeGenresEtag($flat);
            if ($this->notModified($request, $etag)) {
                return $this->notModifiedResponse($response, $etag);
            }

            $response = ResponseEnvelope::success($response, $tree, ['count' => count($flat)], 200);

            return $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] catalog genres failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Generi non disponibili.'), 500);
        }
    }

    // ─── Filters ─────────────────────────────────────────────────────────────

    /**
     * Build the WHERE fragment for the search filters supported by the spec:
     *   q, author, publisher, genre (cascade id, matched at any level),
     *   language, available (bool).
     *
     * Mirrors the public catalog filter columns so app + web agree. Uses
     * prepared placeholders throughout (no string interpolation of user input).
     *
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<int|string>, 2: string}
     */
    private function buildFilters(array $params): array
    {
        $conditions = [];
        /** @var list<int|string> $bind */
        $bind  = [];
        $types = '';

        $q = isset($params['q']) ? trim((string) $params['q']) : '';
        if ($q !== '') {
            // Cross-field LIKE match (title, subtitle, description, identifiers,
            // author, publisher). Kept simple + index-tolerant; prepared params
            // make it injection-safe regardless of content.
            $like = '%' . $q . '%';
            $conditions[] = "(
                l.titolo LIKE ?
                OR l.sottotitolo LIKE ?
                OR l.descrizione LIKE ?
                OR l.isbn13 LIKE ?
                OR l.isbn10 LIKE ?
                OR l.ean LIKE ?
                OR EXISTS (SELECT 1 FROM libri_autori la_q JOIN autori a_q ON la_q.autore_id = a_q.id
                           WHERE la_q.libro_id = l.id
                             AND la_q.ruolo IN ('principale', 'co-autore')
                             AND (a_q.nome LIKE ? OR a_q.pseudonimo LIKE ?))
                OR e.nome LIKE ?
            )";
            for ($i = 0; $i < 9; $i++) {
                $bind[]  = $like;
                $types  .= 's';
            }
        }

        $author = isset($params['author']) ? trim((string) $params['author']) : '';
        if ($author !== '') {
            if (is_numeric($author)) {
                $conditions[] = "EXISTS (SELECT 1 FROM libri_autori la_a
                                         WHERE la_a.libro_id = l.id AND la_a.autore_id = ?
                                           AND la_a.ruolo IN ('principale', 'co-autore'))";
                $bind[]  = (int) $author;
                $types  .= 'i';
            } else {
                $conditions[] = "EXISTS (SELECT 1 FROM libri_autori la_a JOIN autori a_a ON la_a.autore_id = a_a.id
                                         WHERE la_a.libro_id = l.id
                                           AND la_a.ruolo IN ('principale', 'co-autore')
                                           AND (a_a.nome LIKE ? ESCAPE '\\\\' OR a_a.pseudonimo LIKE ? ESCAPE '\\\\'))";
                $authorLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $author) . '%';
                $bind[]  = $authorLike;
                $bind[]  = $authorLike;
                $types  .= 'ss';
            }
        }

        $publisher = isset($params['publisher']) ? trim((string) $params['publisher']) : '';
        if ($publisher !== '') {
            if (is_numeric($publisher)) {
                $conditions[] = 'l.editore_id = ?';
                $bind[]  = (int) $publisher;
                $types  .= 'i';
            } else {
                $conditions[] = 'e.nome LIKE ?';
                $bind[]  = '%' . $publisher . '%';
                $types  .= 's';
            }
        }

        // Genre cascade id: match the id at ANY level of the hierarchy (same
        // semantics as the web catalog) so filtering by a top genre also returns
        // books classified under its descendants.
        $genreId = isset($params['genre']) ? (int) $params['genre'] : 0;
        if ($genreId > 0) {
            $conditions[] = '(l.genere_id = ? OR g.parent_id = ? OR gp.parent_id = ? OR l.sottogenere_id = ?)';
            $bind[] = $genreId;
            $bind[] = $genreId;
            $bind[] = $genreId;
            $bind[] = $genreId;
            $types .= 'iiii';
        }

        $language = isset($params['language']) ? trim((string) $params['language']) : '';
        if ($language !== '') {
            $conditions[] = 'l.lingua = ?';
            $bind[]  = $language;
            $types  .= 's';
        }

        // available=1/true → loanable now (at least one available copy).
        if (isset($params['available'])) {
            $av = strtolower(trim((string) $params['available']));
            if (in_array($av, ['1', 'true', 'yes'], true)) {
                $conditions[] = 'l.copie_disponibili > 0';
            } elseif (in_array($av, ['0', 'false', 'no'], true)) {
                $conditions[] = 'l.copie_disponibili <= 0';
            }
        }

        return [$conditions, $bind, $types];
    }

    // ─── Row mappers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapListItem(array $r): array
    {
        $available = (int) ($r['copie_disponibili'] ?? 0);

        return [
            'id'               => (int) $r['id'],
            'title'            => (string) ($r['titolo'] ?? ''),
            'subtitle'         => $this->nullableString($r['sottotitolo'] ?? null),
            'author'           => $this->nullableString($r['autore'] ?? null),
            'publisher'        => $this->nullableString($r['editore'] ?? null),
            'genre'            => $this->nullableString($r['genere'] ?? null),
            'year'             => $r['anno_pubblicazione'] !== null ? (int) $r['anno_pubblicazione'] : null,
            'language'         => $this->nullableString($r['lingua'] ?? null),
            'media_type'       => $this->nullableString($r['tipo_media'] ?? null),
            'isbn13'           => $this->nullableString($r['isbn13'] ?? null),
            'cover_url'        => absoluteUrl($this->coverPath($r['copertina_url'] ?? null)),
            'copies_total'     => (int) ($r['copie_totali'] ?? 0),
            'copies_available' => $available,
            'loanable_now'     => $available > 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * GET /catalog/books/{id}/availability — per-day loan availability that
     * feeds the date-picker calendar. Reuses the SAME computation as the website
     * (ReservationsController::getBookAvailabilityData) so app and web agree, and
     * excludes the requesting user's own reservations so their pending booking
     * does not read back as unavailable.
     */
    public function bookAvailability(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $bookId
    ): ResponseInterface {
        try {
            if ($bookId <= 0 || $this->fetchBookCore($bookId) === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $user   = $request->getAttribute(\App\Plugins\MobileApi\Support\AppAuthMiddleware::ATTR_USER);
            $userId = (is_array($user) && isset($user['id'])) ? (int) $user['id'] : 0;

            $avail = (new \App\Controllers\ReservationsController($this->db))
                ->getBookAvailabilityData($bookId, null, 180, $userId > 0 ? $userId : null);

            return ResponseEnvelope::success($response, [
                'total_copies'       => (int) ($avail['total_copies'] ?? 0),
                'earliest_available' => $avail['earliest_available'] ?? null,
                'unavailable_dates'  => array_values((array) ($avail['unavailable_dates'] ?? [])),
                'days'               => $this->normalizeMobileAvailabilityDays(
                    array_values((array) ($avail['days'] ?? [])),
                    (int) ($avail['total_copies'] ?? 0)
                ),
            ], []);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] book availability failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Disponibilità non disponibile.'), 500);
        }
    }

    /**
     * Convert the website availability labels (borrowed/reserved) into the
     * app calendar contract: free, partial, full.
     *
     * @param list<array<string, mixed>> $days
     * @return list<array<string, mixed>>
     */
    private function normalizeMobileAvailabilityDays(array $days, int $totalCopies): array
    {
        foreach ($days as &$day) {
            $available = (int) ($day['available'] ?? 0);
            if ($available <= 0 || $totalCopies <= 0) {
                $day['state'] = 'full';
            } elseif ($available < $totalCopies) {
                $day['state'] = 'partial';
            } else {
                $day['state'] = 'free';
            }
        }
        unset($day);

        return $days;
    }

    private function fetchBookCore(int $bookId): ?array
    {
        $sql = "
            SELECT l.*,
                   g.nome  AS genere,
                   gp.nome AS genere_parent,
                   gpp.nome AS genere_grandparent,
                   sg.nome AS sottogenere,
                   e.nome  AS editore
            FROM libri l
            LEFT JOIN generi g   ON l.genere_id = g.id
            LEFT JOIN generi gp  ON g.parent_id = gp.id
            LEFT JOIN generi gpp ON gp.parent_id = gpp.id
            LEFT JOIN generi sg  ON l.sottogenere_id = sg.id
            LEFT JOIN editori e  ON l.editore_id = e.id
            WHERE l.id = ? AND l.deleted_at IS NULL
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res !== false) ? $res->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuthors(int $bookId): array
    {
        $sql = "
            SELECT a.id, a.nome, a.pseudonimo, la.ruolo
            FROM autori a
            JOIN libri_autori la ON a.id = la.autore_id
            WHERE la.libro_id = ?
            ORDER BY CASE la.ruolo
                WHEN 'principale' THEN 1 WHEN 'co-autore' THEN 2
                WHEN 'traduttore' THEN 3 WHEN 'illustratore' THEN 4
                WHEN 'curatore' THEN 5 WHEN 'colorista' THEN 6 ELSE 7 END, a.nome
        ";
        $out  = [];
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
            $out[] = [
                'id'             => (int) $row['id'],
                'name'           => \App\Support\AuthorName::display($row),
                'canonical_name' => (string) $row['nome'],
                'pseudonym'      => $this->nullableString($row['pseudonimo'] ?? null),
                'role'           => (string) ($row['ruolo'] ?? ''),
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * @param array<string, mixed> $book
     * @return list<array<string, mixed>>
     */
    private function fetchPublishers(int $bookId, array $book): array
    {
        $out = [];
        if (\App\Support\SchemaInfo::hasLibriEditori($this->db)) {
            $stmt = $this->db->prepare(
                'SELECT e.id, e.nome FROM libri_editori le JOIN editori e ON le.editore_id = e.id
                 WHERE le.libro_id = ? ORDER BY le.ordine, e.nome'
            );
            if ($stmt !== false) {
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
                    $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['nome']];
                }
                $stmt->close();
            }
        }
        if ($out === [] && !empty($book['editore'])) {
            $out[] = ['id' => (int) ($book['editore_id'] ?? 0), 'name' => (string) $book['editore']];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCopies(int $bookId): array
    {
        $out = [];
        // copie table may be absent on minimal installs — guard gracefully.
        $stmt = $this->db->prepare(
            'SELECT id, numero_inventario, stato FROM copie WHERE libro_id = ? ORDER BY id'
        );
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('i', $bookId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $out;
        }
        $res = $stmt->get_result();
        while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
            $out[] = [
                'id'        => (int) $row['id'],
                'inventory' => $this->nullableString($row['numero_inventario'] ?? null),
                'status'    => (string) ($row['stato'] ?? ''),
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * Related titles: same author(s), newest first, soft-delete safe.
     *
     * @param list<array<string, mixed>> $authors
     * @return list<array<string, mixed>>
     */
    private function fetchRelated(int $bookId, array $authors): array
    {
        $creatorAuthors = array_values(array_filter(
            $authors,
            static fn (array $a): bool => in_array((string) ($a['role'] ?? ''), ['principale', 'co-autore'], true)
        ));
        $authorIds = array_values(array_filter(array_map(
            static fn (array $a): int => (int) ($a['id'] ?? 0),
            $creatorAuthors
        )));
        if ($authorIds === []) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($authorIds), '?'));
        $authorDisplaySql = \App\Support\AuthorName::displaySql('a');
        $sql = "
            SELECT DISTINCT l.id, l.titolo, l.copertina_url, l.anno_pubblicazione,
                   (SELECT {$authorDisplaySql} FROM libri_autori la2 JOIN autori a ON la2.autore_id = a.id
                    WHERE la2.libro_id = l.id AND la2.ruolo IN ('principale', 'co-autore')
                    ORDER BY (la2.ruolo = 'principale') DESC, la2.ordine_credito, la2.autore_id LIMIT 1) AS autore
            FROM libri l
            JOIN libri_autori la ON l.id = la.libro_id
            WHERE la.autore_id IN ($ph)
              AND la.ruolo IN ('principale', 'co-autore')
              AND l.id != ?
              AND l.deleted_at IS NULL
            GROUP BY l.id
            ORDER BY l.created_at DESC
            LIMIT 6
        ";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $types = str_repeat('i', count($authorIds)) . 'i';
        $args  = array_merge($authorIds, [$bookId]);
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
            $out[] = [
                'id'        => (int) $row['id'],
                'title'     => (string) $row['titolo'],
                'author'    => $this->nullableString($row['autore'] ?? null),
                'year'      => $row['anno_pubblicazione'] !== null ? (int) $row['anno_pubblicazione'] : null,
                'cover_url' => absoluteUrl($this->coverPath($row['copertina_url'] ?? null)),
            ];
        }
        $stmt->close();

        return $out;
    }

    // ─── Personal history (authed user only) ─────────────────────────────────

    /**
     * Whether the authenticated user has read / reserved / wishlisted this title.
     * Strictly scoped to the resolved $userId — the caller passes the
     * token-resolved id, never a client value (spec §Data isolation).
     *
     * @return array{has_read:bool, has_reserved:bool, has_wishlisted:bool, has_active_loan:bool, has_pending_request:bool}
     */
    private function fetchPersonalHistory(int $userId, int $bookId): array
    {
        return [
            // "read" = has ever had a returned loan of this title.
            'has_read'        => $this->existsScoped(
                "SELECT 1 FROM prestiti WHERE utente_id = ? AND libro_id = ?
                  AND stato IN ('restituito') LIMIT 1",
                $userId,
                $bookId
            ),
            // "reserved" = has a pending/active reservation OR a not-yet-returned loan.
            'has_reserved'    => $this->existsScoped(
                "SELECT 1 FROM prenotazioni WHERE utente_id = ? AND libro_id = ?
                  AND stato = 'attiva' LIMIT 1",
                $userId,
                $bookId
            ),
            'has_wishlisted'  => $this->existsScoped(
                'SELECT 1 FROM wishlist WHERE utente_id = ? AND libro_id = ? LIMIT 1',
                $userId,
                $bookId
            ),
            'has_active_loan' => $this->existsScoped(
                "SELECT 1 FROM prestiti WHERE utente_id = ? AND libro_id = ?
                  AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo') LIMIT 1",
                $userId,
                $bookId
            ),
            // A loan request awaiting staff approval (attivo = 0, so it is NOT an
            // active loan yet). The app uses this to block a duplicate request and
            // show "you have a pending request" instead of a generic Reserve CTA.
            'has_pending_request' => $this->existsScoped(
                "SELECT 1 FROM prestiti WHERE utente_id = ? AND libro_id = ?
                  AND stato = 'pendente' LIMIT 1",
                $userId,
                $bookId
            ),
        ];
    }

    /** @return array{has_read:bool, has_reserved:bool, has_wishlisted:bool, has_active_loan:bool, has_pending_request:bool} */
    private function emptyHistory(): array
    {
        return [
            'has_read'             => false,
            'has_reserved'         => false,
            'has_wishlisted'       => false,
            'has_active_loan'      => false,
            'has_pending_request'  => false,
        ];
    }

    private function existsScoped(string $sql, int $userId, int $bookId): bool
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $bookId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $res    = $stmt->get_result();
        $exists = ($res !== false) && $res->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    // ─── Location / shelf ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>|null
     */
    private function buildLocation(array $book): ?array
    {
        $label = isset($book['collocazione']) ? trim((string) $book['collocazione']) : '';
        $scaffaleId = isset($book['scaffale_id']) ? (int) $book['scaffale_id'] : 0;
        $mensolaId  = isset($book['mensola_id']) ? (int) $book['mensola_id'] : 0;
        $progr      = isset($book['posizione_progressiva']) ? (int) $book['posizione_progressiva'] : 0;

        if ($label === '' && $scaffaleId === 0 && $mensolaId === 0 && $progr === 0) {
            return null;
        }

        return [
            'label'        => $label !== '' ? $label : null,
            'shelf_id'     => $scaffaleId > 0 ? $scaffaleId : null,
            'shelf_unit_id'=> $mensolaId > 0 ? $mensolaId : null,
            'position'     => $progr > 0 ? $progr : null,
        ];
    }

    // ─── Genre tree ──────────────────────────────────────────────────────────

    /**
     * Build a nested cascade tree from the flat genre list. The schema supports
     * up to 3 levels (genre → subgenre → sub-subgenre via parent_id chains).
     *
     * @param list<array<string, mixed>> $flat
     * @return list<array<string, mixed>>
     */
    private function buildGenreTree(array $flat): array
    {
        /** @var array<int, list<array<string, mixed>>> $childrenByParent */
        $childrenByParent = [];
        foreach ($flat as $g) {
            $parent = isset($g['parent_id']) ? (int) $g['parent_id'] : 0;
            $childrenByParent[$parent][] = $g;
        }

        return $this->buildGenreBranch($childrenByParent, 0);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $childrenByParent
     * @return list<array<string, mixed>>
     */
    private function buildGenreBranch(array $childrenByParent, int $parentId): array
    {
        $branch = [];
        foreach ($childrenByParent[$parentId] ?? [] as $g) {
            $id = (int) ($g['id'] ?? 0);
            $branch[] = [
                'id'       => $id,
                'name'     => (string) ($g['nome'] ?? ''),
                'children' => $this->buildGenreBranch($childrenByParent, $id),
            ];
        }

        return $branch;
    }

    // ─── Caching helpers (ETag / Last-Modified / 304) ────────────────────────

    /**
     * @param list<array<string, mixed>> $items
     */
    private function computeListEtag(array $items): string
    {
        $seed = '';
        foreach ($items as $i) {
            $seed .= $i['id'] . ':' . $i['copies_available'] . '|';
        }

        return '"' . sha1('catalog-list:' . $seed) . '"';
    }

    /**
     * @param array<string, mixed> $book
     * @param array{has_read:bool, has_reserved:bool, has_wishlisted:bool, has_active_loan:bool, has_pending_request:bool} $history
     */
    private function computeDetailEtag(int $bookId, array $book, int $userId, array $history): string
    {
        $seed = implode('|', [
            'book:' . $bookId,
            'av:' . (int) ($book['copie_disponibili'] ?? 0),
            'upd:' . (string) ($book['updated_at'] ?? ''),
            'u:' . $userId,
            'h:' . (int) $history['has_read'] . (int) $history['has_reserved']
                 . (int) $history['has_wishlisted'] . (int) $history['has_active_loan']
                 . (int) $history['has_pending_request'],
        ]);

        return '"' . sha1($seed) . '"';
    }

    /**
     * @param list<array<string, mixed>> $flat
     */
    private function computeGenresEtag(array $flat): string
    {
        $seed = '';
        foreach ($flat as $g) {
            $seed .= ($g['id'] ?? '') . ':' . ($g['parent_id'] ?? '0') . ':' . ($g['nome'] ?? '') . '|';
        }

        return '"' . sha1('genres:' . $seed) . '"';
    }

    /**
     * @param array<string, mixed> $book
     */
    private function lastModified(array $book): ?string
    {
        $raw = $book['updated_at'] ?? $book['created_at'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }

    private function notModified(ServerRequestInterface $request, string $etag): bool
    {
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($ifNoneMatch === '' || $ifNoneMatch === '*') {
            // No validator, or the "*" wildcard. "*" is only meaningful for
            // conditional writes; for a GET we don't honor it (it would let any
            // client force a 304 without ever having seen the resource) — serve the
            // full representation instead.
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            // Strip a weak validator prefix before comparing.
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            // Apache mod_deflate (and some proxies) append a "-gzip"/"-br" suffix
            // to the ETag they emit when compressing the response. The client then
            // echoes that mangled value back in If-None-Match, so strip the suffix
            // before comparing — otherwise 304 revalidation never succeeds behind a
            // compressing web server (the exact production setup).
            $candidate = preg_replace('/-(gzip|br)("?)$/', '$2', $candidate) ?? $candidate;
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function notModifiedResponse(
        ResponseInterface $response,
        string $etag,
        ?string $lastModified = null
    ): ResponseInterface {
        $response = $response
            ->withStatus(304)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        if ($lastModified !== null) {
            $response = $response->withHeader('Last-Modified', $lastModified);
        }

        return $response;
    }

    // ─── Small utilities ─────────────────────────────────────────────────────

    /**
     * Resolve the `sort` query param to a keyset-safe spec. Mirrors the web
     * catalog's options. `col`/`dir` drive the keyset anchor; `order` is the full
     * ORDER BY (tie-broken on the unique id); `field` is the result-row key whose
     * value seeds the next cursor. Author sorts are intentionally omitted for now
     * (the principal author is a per-row subquery, not keyset-friendly).
     *
     * @return array{key:string, col:string, dir:string, order:string, field:string}
     */
    private function sortSpec(?string $sort): array
    {
        switch ($sort) {
            case 'oldest':
                return ['key' => 'oldest', 'col' => 'l.id', 'dir' => 'ASC',
                        'order' => 'l.id ASC', 'field' => 'id'];
            case 'title_asc':
                return ['key' => 'title_asc', 'col' => 'l.titolo', 'dir' => 'ASC',
                        'order' => 'l.titolo ASC, l.id ASC', 'field' => 'titolo'];
            case 'title_desc':
                return ['key' => 'title_desc', 'col' => 'l.titolo', 'dir' => 'DESC',
                        'order' => 'l.titolo DESC, l.id DESC', 'field' => 'titolo'];
            case 'newest':
            default:
                return ['key' => 'newest', 'col' => 'l.id', 'dir' => 'DESC',
                        'order' => 'l.id DESC', 'field' => 'id'];
        }
    }

    private function clampLimit(mixed $raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        return $limit;
    }

    private function coverPath(mixed $raw): string
    {
        $cover = is_string($raw) ? trim($raw) : '';

        return $cover !== '' ? $cover : self::PLACEHOLDER_COVER;
    }

    private function nullableString(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);

        return $s !== '' ? $s : null;
    }
}
