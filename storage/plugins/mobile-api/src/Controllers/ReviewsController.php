<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\CursorCodec;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Book reviews for the mobile app (stars + text), backed by the core
 * `recensioni` table and its ALWAYS-ON moderation model:
 *
 *   - new/edited reviews are stored with stato='pendente' and become visible
 *     to OTHER users only after an admin approves them (stato='approvata');
 *   - aggregate average/count/distribution are computed over approved only;
 *   - `mine` is returned regardless of stato so the author always sees their
 *     own review (the extra `status` field lets future app versions surface
 *     "pending approval"; current clients ignore unknown fields);
 *   - one review per (utente, libro) — UNIQUE unique_user_book_review — hence
 *     PUT is a TRUE UPSERT (update the existing row, else insert). Do NOT
 *     reuse the web's canUserReview() as the write guard: it returns false
 *     once a review exists, which would break editing;
 *   - eligibility (`can_review` and the PUT 403 guard) is ONLY "has borrowed
 *     the title" (prestiti stato IN ('restituito','in_corso'));
 *   - DELETE is user-scoped by (utente_id, libro_id) and idempotent.
 *
 * Contract: app repo `_contract/openapi.json` — GET/PUT/DELETE
 * /catalog/books/{id}/reviews and GET /me/reviews, Envelope + cursor paging.
 * On GET book reviews, `meta.next_cursor` pages the nested `data.items`.
 */
class ReviewsController
{
    private const MAX_TEXT_LEN = 2000;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(private mysqli $db)
    {
    }

    /* ---------------------------------------------------------------------
     * GET /api/v1/catalog/books/{id}/reviews
     * ------------------------------------------------------------------- */
    public function bookReviews(Request $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
            $userId = (int) ($user['id'] ?? 0);

            if (!$this->bookExists($bookId)) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato'), 404);
            }

            $query = $request->getQueryParams();
            $limit = $this->clampLimit($query['limit'] ?? null);
            $afterId = $this->decodeCursor($query['cursor'] ?? null);

            // Aggregate over APPROVED reviews only (mirrors the web's stats).
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c, COALESCE(AVG(stelle), 0) AS a,
                        SUM(stelle = 1) AS s1, SUM(stelle = 2) AS s2, SUM(stelle = 3) AS s3,
                        SUM(stelle = 4) AS s4, SUM(stelle = 5) AS s5
                 FROM recensioni WHERE libro_id = ? AND stato = 'approvata'"
            );
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $agg = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            // The caller's own review, regardless of moderation state.
            $mine = null;
            if ($userId > 0) {
                $stmt = $this->db->prepare(
                    "SELECT r.id, r.stelle, r.descrizione, r.stato, r.created_at, r.updated_at,
                            CONCAT(u.nome, ' ', u.cognome) AS user_name
                     FROM recensioni r JOIN utenti u ON u.id = r.utente_id
                     WHERE r.libro_id = ? AND r.utente_id = ? LIMIT 1"
                );
                $stmt->bind_param('ii', $bookId, $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $mine = $this->mapReview($row, true);
                }
            }

            // Other users' APPROVED reviews, keyset-paginated on id DESC.
            $sql = "SELECT r.id, r.stelle, r.descrizione, r.stato, r.created_at, r.updated_at,
                           CONCAT(u.nome, ' ', u.cognome) AS user_name
                    FROM recensioni r JOIN utenti u ON u.id = r.utente_id
                    WHERE r.libro_id = ? AND r.stato = 'approvata' AND r.utente_id <> ?";
            $types = 'ii';
            $params = [$bookId, $userId];
            if ($afterId !== null) {
                $sql .= ' AND r.id < ?';
                $types .= 'i';
                $params[] = $afterId;
            }
            $sql .= ' ORDER BY r.id DESC LIMIT ?';
            $types .= 'i';
            $fetch = $limit + 1; // +1 to detect the next page
            $params[] = $fetch;

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            $items = array_map(fn(array $r): array => $this->mapReview($r, false), $rows);
            $nextCursor = null;
            if ($hasMore && $rows !== []) {
                $nextCursor = CursorCodec::encode(['last_id' => (int) end($rows)['id']]);
            }

            $data = [
                'average'      => round((float) ($agg['a'] ?? 0), 2),
                'count'        => (int) ($agg['c'] ?? 0),
                'distribution' => [
                    '1' => (int) ($agg['s1'] ?? 0),
                    '2' => (int) ($agg['s2'] ?? 0),
                    '3' => (int) ($agg['s3'] ?? 0),
                    '4' => (int) ($agg['s4'] ?? 0),
                    '5' => (int) ($agg['s5'] ?? 0),
                ],
                'can_review'   => $userId > 0 && $this->hasBorrowed($userId, $bookId),
                'mine'         => $mine,
                'items'        => $items,
            ];

            return ResponseEnvelope::success($response, $data, ['next_cursor' => $nextCursor], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] bookReviews failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'server_error', __('Operazione non disponibile.'), 500);
        }
    }

    /* ---------------------------------------------------------------------
     * PUT /api/v1/catalog/books/{id}/reviews — idempotent upsert
     * ------------------------------------------------------------------- */
    public function submitReview(Request $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
            $userId = (int) ($user['id'] ?? 0);

            if (!$this->bookExists($bookId)) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato'), 404);
            }

            $body = JsonBody::parse($request);
            $rating = isset($body['rating']) && is_numeric($body['rating']) ? (int) $body['rating'] : 0;
            if ($rating < 1 || $rating > 5) {
                return ResponseEnvelope::error($response, 'validation_error', __('La valutazione deve essere tra 1 e 5 stelle.'), 422);
            }
            $rawText = $body['text'] ?? null;
            if ($rawText !== null && !is_scalar($rawText)) {
                return ResponseEnvelope::error($response, 'validation_error', __('Testo della recensione non valido.'), 422);
            }
            $text = trim((string) ($rawText ?? ''));
            if (mb_strlen($text) > self::MAX_TEXT_LEN) {
                return ResponseEnvelope::error($response, 'validation_error', sprintf(__('Il testo della recensione non può superare %d caratteri.'), self::MAX_TEXT_LEN), 422);
            }
            $textOrNull = $text === '' ? null : $text;

            // Eligibility: ONLY "has borrowed the title". Editing an existing
            // review must stay possible, so "already reviewed" is NOT a block.
            if (!$this->hasBorrowed($userId, $bookId)) {
                return ResponseEnvelope::error($response, 'not_eligible', __('Puoi recensire solo i titoli che hai preso in prestito.'), 403);
            }

            $existingId = $this->findOwnReviewId($userId, $bookId);
            if ($existingId !== null) {
                // Edit → back to moderation (the changed text needs re-approval).
                $stmt = $this->db->prepare(
                    "UPDATE recensioni
                     SET stelle = ?, descrizione = ?, stato = 'pendente',
                         approved_by = NULL, approved_at = NULL
                     WHERE id = ? AND utente_id = ?"
                );
                $stmt->bind_param('isii', $rating, $textOrNull, $existingId, $userId);
                $stmt->execute();
                $stmt->close();
                $reviewId = $existingId;
                $created = false;
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato)
                     VALUES (?, ?, ?, '', ?, 'pendente')"
                );
                $stmt->bind_param('iiis', $bookId, $userId, $rating, $textOrNull);
                $stmt->execute();
                $reviewId = (int) $this->db->insert_id;
                $stmt->close();
                $created = true;
            }

            // Best-effort admin notification on first submission (same as web).
            if ($created) {
                try {
                    $notifier = new \App\Support\NotificationService($this->db);
                    $notifier->notifyNewReview($reviewId);
                } catch (\Throwable $e) {
                    SecureLogger::warning('[MobileApi] notifyNewReview failed: ' . $e->getMessage());
                }
            }

            $stmt = $this->db->prepare(
                "SELECT r.id, r.stelle, r.descrizione, r.stato, r.created_at, r.updated_at,
                        CONCAT(u.nome, ' ', u.cognome) AS user_name
                 FROM recensioni r JOIN utenti u ON u.id = r.utente_id
                 WHERE r.id = ? LIMIT 1"
            );
            $stmt->bind_param('i', $reviewId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            // meta.pending tells (future) clients the review awaits moderation.
            return ResponseEnvelope::success($response, $this->mapReview($row, true), ['pending' => true], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] submitReview failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'server_error', __('Operazione non disponibile.'), 500);
        }
    }

    /* ---------------------------------------------------------------------
     * DELETE /api/v1/catalog/books/{id}/reviews — own review, idempotent
     * ------------------------------------------------------------------- */
    public function deleteReview(Request $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
            $userId = (int) ($user['id'] ?? 0);

            // User-scoped by design: never accept a review id from the client.
            // 200 when a review was removed, 404 when there was none — the same
            // "gone" semantics as the sibling DELETE endpoints (wishlist,
            // devices, reservations): a second delete of the same review is 404.
            $stmt = $this->db->prepare('DELETE FROM recensioni WHERE libro_id = ? AND utente_id = ?');
            $stmt->bind_param('ii', $bookId, $userId);
            $stmt->execute();
            $removed = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$removed) {
                return ResponseEnvelope::error($response, 'not_found', __('Nessuna recensione da eliminare.'), 404);
            }

            return ResponseEnvelope::success($response, null, [], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] deleteReview failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'server_error', __('Operazione non disponibile.'), 500);
        }
    }

    /* ---------------------------------------------------------------------
     * GET /api/v1/me/reviews
     * ------------------------------------------------------------------- */
    public function myReviews(Request $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
            $userId = (int) ($user['id'] ?? 0);

            $query = $request->getQueryParams();
            $limit = $this->clampLimit($query['limit'] ?? null);
            $afterId = $this->decodeCursor($query['cursor'] ?? null);

            // All of the user's reviews (any stato) on non-deleted books, with
            // the principal author resolved for display.
            $authorDisplaySql = \App\Support\AuthorName::displaySql('a');
            $sql = "SELECT r.id, r.libro_id, r.stelle, r.descrizione, r.stato, r.created_at, r.updated_at,
                           l.titolo AS book_title, l.copertina_url,
                           (SELECT {$authorDisplaySql}
                            FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                            WHERE la.libro_id = l.id AND la.ruolo IN ('principale', 'co-autore')
                            ORDER BY (la.ruolo = 'principale') DESC, la.ordine_credito ASC, la.autore_id ASC
                            LIMIT 1) AS book_author
                    FROM recensioni r
                    JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
                    WHERE r.utente_id = ?";
            $types = 'i';
            $params = [$userId];
            if ($afterId !== null) {
                $sql .= ' AND r.id < ?';
                $types .= 'i';
                $params[] = $afterId;
            }
            $sql .= ' ORDER BY r.id DESC LIMIT ?';
            $types .= 'i';
            $fetch = $limit + 1;
            $params[] = $fetch;

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }

            $items = array_map(function (array $r): array {
                return [
                    'id'          => (int) $r['id'],
                    'book_id'     => (int) $r['libro_id'],
                    'book_title'  => (string) $r['book_title'],
                    'book_author' => $r['book_author'] !== null ? (string) $r['book_author'] : null,
                    'cover_url'   => $this->absoluteCover($r['copertina_url'] ?? null),
                    'rating'      => (int) $r['stelle'],
                    'text'        => $r['descrizione'] !== null && $r['descrizione'] !== '' ? (string) $r['descrizione'] : null,
                    'status'      => (string) $r['stato'],
                    'created_at'  => self::isoUtc($r['created_at'] ?? null),
                    'updated_at'  => self::isoUtc($r['updated_at'] ?? null),
                ];
            }, $rows);

            $nextCursor = null;
            if ($hasMore && $rows !== []) {
                $nextCursor = CursorCodec::encode(['last_id' => (int) end($rows)['id']]);
            }

            return ResponseEnvelope::success($response, $items, ['next_cursor' => $nextCursor], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] myReviews failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'server_error', __('Operazione non disponibile.'), 500);
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /** Book exists and is not soft-deleted (project rule: deleted_at IS NULL). */
    private function bookExists(int $bookId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }

    /**
     * Eligibility = has (or had) the book on loan. Deliberately SEPARATE from
     * "has already reviewed": the latter must not block PUT-as-edit.
     * Mirrors the web rule (prestiti stato IN ('restituito','in_corso')).
     */
    private function hasBorrowed(int $userId, int $bookId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM prestiti
             WHERE utente_id = ? AND libro_id = ? AND stato IN ('restituito', 'in_corso')
             LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $bookId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }

    private function findOwnReviewId(int $userId, int $bookId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM recensioni WHERE libro_id = ? AND utente_id = ? LIMIT 1');
        $stmt->bind_param('ii', $bookId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? (int) $row[0] : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapReview(array $row, bool $isMine): array
    {
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'rating'     => (int) ($row['stelle'] ?? 0),
            'text'       => isset($row['descrizione']) && $row['descrizione'] !== ''
                ? (string) $row['descrizione'] : null,
            'user_name'  => (string) ($row['user_name'] ?? ''),
            'is_mine'    => $isMine,
            // Extra vs the app contract (clients ignore unknown fields): lets
            // future app versions surface "pending approval" without an API bump.
            'status'     => (string) ($row['stato'] ?? ''),
            'created_at' => self::isoUtc($row['created_at'] ?? null) ?? '',
            'updated_at' => self::isoUtc($row['updated_at'] ?? null) ?? '',
        ];
    }

    /**
     * MySQL DATETIME → ISO-8601 UTC with Z suffix (MOBILE_API_SPEC: "dates
     * ISO-8601 UTC; the app formats locally"). Interprets the wall-clock
     * value in the current PHP timezone, same as gmdate elsewhere in the
     * plugin (see ActionsController::notifications generated_at).
     */
    private static function isoUtc(mixed $datetime): ?string
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** Absolute cover URL: pass through externals, resolve local paths. */
    private function absoluteCover(?string $raw): ?string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }
        if (!str_starts_with($v, '/')) {
            $v = '/uploads/copertine/' . $v;
        }
        return absoluteUrl($v);
    }

    private function clampLimit(mixed $raw): int
    {
        $n = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;
        return max(1, min(self::MAX_LIMIT, $n));
    }

    private function decodeCursor(mixed $raw): ?int
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = CursorCodec::decode($raw);
            $id = (int) ($decoded['last_id'] ?? 0);
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
