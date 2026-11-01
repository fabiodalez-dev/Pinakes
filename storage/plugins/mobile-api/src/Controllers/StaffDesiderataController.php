<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\HookManager;
use App\Support\HtmlHelper;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Desiderata staff workflow for the app (Mobile API 1.7.0):
 * /staff/desiderata/offers*, /staff/desiderata/wanted*.
 *
 * Accept / reject / receive / delete and the direct receipt are delegated to
 * DesiderataPlugin::manage() / receiveDirect() — the copy, the cleared
 * request flag, the availability recount and the operators' notification
 * stay the plugin's. Those actions answer 303 or a 422 HTML page, so the
 * plugin checks first what the app needs a stable code for (offer_closed,
 * open_offers, not_wanted). Every route answers 404 `feature_disabled` while
 * the desiderata plugin is inactive.
 */
final class StaffDesiderataController
{
    public const STATUSES = ['pending', 'accepted', 'received', 'rejected'];

    public function __construct(private mysqli $db, private HookManager $hooks)
    {
    }

    public function offers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $status = StaffPaging::query($request, 'status');
        $status = $status === '' ? 'pending' : $status;
        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'status']);
        }
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        $q = mb_substr(StaffPaging::query($request, 'q'), 0, 100);
        try {
            $where = [];
            $types = '';
            $params = [];
            if ($status !== 'all') {
                $where[] = 'o.status = ?';
                $types .= 's';
                $params[] = $status;
            }
            $search = \App\Support\ExactSearch::filterCondition(['o.title', 'o.author', 'o.donor_name', 'o.donor_email', 'o.isbn'], $q);
            if ($search !== null) {
                $where[] = $search['sql'];
                $types .= $search['types'];
                array_push($params, ...$search['params']);
            }
            $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            $total = (int) ($this->rows('SELECT COUNT(*) AS n FROM desiderata_offers o' . $whereSql, $types, $params)[0]['n'] ?? 0);
            $rows = $this->rows(self::OFFER_SELECT . $whereSql . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$limit + 1, $offset]));
            $hasMore = count($rows) > $limit;
            $items = array_map(fn (array $r): array => $this->mapOffer($r), array_slice($rows, 0, $limit));

            return ResponseEnvelope::success($response, $items, StaffPaging::meta(count($items), $limit, $offset, $hasMore, $total));
        } catch (\Throwable $e) {
            return $this->fail($response, 'desiderata offers', $e);
        }
    }

    public function accept(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->manage($response, $id, 'accepted', []);
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        return $this->manage($response, $id, 'rejected', []);
    }

    public function receive(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $bookId = array_key_exists('received_book_id', $json) && $json['received_book_id'] !== null ? StaffPaging::id($json['received_book_id']) : 0;
        if (array_key_exists('received_book_id', $json) && $json['received_book_id'] !== null && $bookId <= 0) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'received_book_id']);
        }

        return $this->manage($response, $id, 'received', $bookId > 0 ? ['received_book_id' => (string) $bookId] : []);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ($this->offerRow($id) === null) {
                return $this->notFound($response);
            }
            $outcome = $this->delegate(static fn (\DesiderataPlugin $p): ResponseInterface => $p->manage(
                Delegation::request('POST', '/admin/desiderata/offers/' . $id, ['action' => 'delete']),
                new SlimResponse(),
                $id
            ));
            if ($outcome->status !== 303) {
                // Only the refusal the pre-check knows keeps its code (the
                // offer vanished meanwhile); anything else — the plugin also
                // answers 422 for a failed, rolled-back DELETE — is a 500.
                if ($this->offerRow($id) === null) {
                    return $this->notFound($response);
                }
                throw new \RuntimeException('desiderata delete answered HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'desiderata delete', $e);
        }
    }

    /**
     * @param array<string, string> $extra
     */
    private function manage(ResponseInterface $response, int $id, string $action, array $extra): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $offer = $this->offerRow($id);
            if ($offer === null) {
                return $this->notFound($response);
            }
            if (in_array($offer['status'], ['received', 'rejected'], true)) {
                return ResponseEnvelope::error($response, 'offer_closed', __('Proposta già chiusa o non trovata.'), 409, ['status' => (string) $offer['status']]);
            }
            if ($action === 'received') {
                $bookId = (int) ($offer['book_id'] ?? 0) > 0 ? (int) $offer['book_id'] : (int) ($extra['received_book_id'] ?? 0);
                if ($bookId <= 0) {
                    return ResponseEnvelope::error($response, 'validation_error', __('Seleziona la scheda del libro ricevuto.'), 422, ['field' => 'received_book_id']);
                }
                if ($this->rows('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]) === []) {
                    return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404, ['field' => 'received_book_id']);
                }
            }
            $body = ['action' => $action] + $extra;
            $outcome = $this->delegate(static fn (\DesiderataPlugin $p): ResponseInterface => $p->manage(
                Delegation::request('POST', '/admin/desiderata/offers/' . $id, $body),
                new SlimResponse(),
                $id
            ));
            if ($outcome->status !== 303) {
                // 422 HTML page: a race with the web desk (closed meanwhile).
                $after = $this->offerRow($id);
                if ($after === null) {
                    return $this->notFound($response);
                }
                if (in_array($after['status'], ['received', 'rejected'], true)) {
                    return ResponseEnvelope::error($response, 'offer_closed', __('Proposta già chiusa o non trovata.'), 409, ['status' => (string) $after['status']]);
                }
                throw new \RuntimeException('manage answered HTTP ' . $outcome->status);
            }
            $row = $this->offerRow($id);

            return $row === null ? $this->notFound($response) : ResponseEnvelope::success($response, $this->mapOffer($row));
        } catch (\Throwable $e) {
            return $this->fail($response, 'desiderata ' . $action, $e);
        }
    }

    public function wanted(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $public = (new CollectionsController($this->db, $this->hooks))->handle('desiderata', $request, new SlimResponse(), 'wanted');
            $decoded = json_decode((string) $public->getBody(), true);
            if ($public->getStatusCode() !== 200 || !is_array($decoded)) {
                return $this->passthrough($response, $public);
            }
            $items = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            $ids = array_values(array_filter(array_map(static fn ($i): int => (int) ($i['id'] ?? 0), $items)));
            $open = [];
            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                foreach ($this->rows("SELECT book_id, COUNT(*) AS n FROM desiderata_offers WHERE book_id IN ({$in}) AND status IN ('pending','accepted') GROUP BY book_id", str_repeat('i', count($ids)), $ids) as $r) {
                    $open[(int) $r['book_id']] = (int) $r['n'];
                }
            }
            foreach ($items as &$item) {
                $item['open_offers'] = $open[(int) ($item['id'] ?? 0)] ?? 0;
            }
            unset($item);

            return ResponseEnvelope::success($response, $items, is_array($decoded['meta'] ?? null) ? $decoded['meta'] : []);
        } catch (\Throwable $e) {
            return $this->fail($response, 'desiderata wanted', $e);
        }
    }

    public function receiveDirect(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $refused = $this->receiveDirectRefusal($response, $bookId);
            if ($refused !== null) {
                return $refused;
            }
            $outcome = $this->delegate(static fn (\DesiderataPlugin $p): ResponseInterface => $p->receiveDirect(
                Delegation::request('POST', '/admin/desiderata/books/' . $bookId . '/received', []),
                new SlimResponse(),
                $bookId
            ));
            if ($outcome->status !== 303) {
                // A race with the web desk shows up in the same checks; any
                // other answer (the plugin's 422 for a rolled-back failure
                // included) is a server error, not a refusal.
                $refused = $this->receiveDirectRefusal($response, $bookId);
                if ($refused !== null) {
                    return $refused;
                }
                throw new \RuntimeException('desiderata receive direct answered HTTP ' . $outcome->status);
            }
            $offer = $this->rows("SELECT id, copy_id FROM desiderata_offers WHERE book_id = ? AND status = 'received' ORDER BY id DESC LIMIT 1", 'i', [$bookId]);

            return ResponseEnvelope::success($response, [
                'book_id'  => $bookId,
                'copy_id'  => isset($offer[0]['copy_id']) ? (int) $offer[0]['copy_id'] : null,
                'offer_id' => isset($offer[0]['id']) ? (int) $offer[0]['id'] : null,
            ], [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'desiderata receive direct', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** The refusals of a direct receipt the API can name, or null when none applies. */
    private function receiveDirectRefusal(ResponseInterface $response, int $bookId): ?ResponseInterface
    {
        $book = $this->rows('SELECT id, is_desiderata, (SELECT COUNT(*) FROM copie c WHERE c.libro_id = l.id) AS copies FROM libri l WHERE l.id = ? AND l.deleted_at IS NULL', 'i', [$bookId]);
        if ($book === []) {
            return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
        }
        if ((int) $book[0]['is_desiderata'] !== 1 || (int) $book[0]['copies'] > 0) {
            return ResponseEnvelope::error($response, 'conflict', __('Il libro non è più una richiesta aperta.'), 409, ['reason' => 'not_wanted']);
        }
        if ((int) ($this->rows("SELECT COUNT(*) AS n FROM desiderata_offers WHERE book_id = ? AND status IN ('pending','accepted')", 'i', [$bookId])[0]['n'] ?? 0) > 0) {
            return ResponseEnvelope::error($response, 'conflict', __('Questo libro ha proposte aperte: registra la ricezione dalla proposta.'), 409, ['reason' => 'open_offers']);
        }

        return null;
    }

    private const OFFER_SELECT = 'SELECT o.*, b.titolo AS book_title, rb.titolo AS received_title
          FROM desiderata_offers o
          LEFT JOIN libri b ON b.id = o.book_id AND b.deleted_at IS NULL
          LEFT JOIN libri rb ON rb.id = o.received_book_id AND rb.deleted_at IS NULL';

    /**
     * A fresh read every call (re-checked after a delegated action).
     *
     * @phpstan-impure
     * @return array<string, mixed>|null
     */
    private function offerRow(int $id): ?array
    {
        return $this->rows(self::OFFER_SELECT . ' WHERE o.id = ?', 'i', [$id])[0] ?? null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapOffer(array $r): array
    {
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? HtmlHelper::decode(trim($v)) : null;

        return [
            'id'            => (int) $r['id'],
            'status'        => (string) $r['status'],
            'donor_name'    => $text($r['donor_name'] ?? null),
            'donor_email'   => $text($r['donor_email'] ?? null),
            'title'         => (string) ($text($r['title'] ?? null) ?? ''),
            'author'        => $text($r['author'] ?? null),
            'publisher'     => $text($r['publisher'] ?? null),
            'isbn'          => $text($r['isbn'] ?? null),
            'notes'         => $text($r['notes'] ?? null),
            'book'          => isset($r['book_id']) && $r['book_title'] !== null ? ['id' => (int) $r['book_id'], 'title' => HtmlHelper::decode((string) $r['book_title'])] : null,
            'received_book' => isset($r['received_book_id']) && $r['received_title'] !== null ? ['id' => (int) $r['received_book_id'], 'title' => HtmlHelper::decode((string) $r['received_title'])] : null,
            'copy_id'       => isset($r['copy_id']) ? (int) $r['copy_id'] : null,
            'source'        => isset($r['mobile_user_id']) ? 'mobile' : 'web',
            'created_at'    => (string) $r['created_at'],
            'received_at'   => isset($r['received_at']) ? (string) $r['received_at'] : null,
        ];
    }

    /**
     * @param callable(\DesiderataPlugin): ResponseInterface $action
     */
    private function delegate(callable $action): \App\Plugins\MobileApi\Support\DelegationOutcome
    {
        if (!class_exists('DesiderataPlugin', false)) {
            require_once dirname(__DIR__, 3) . '/desiderata/DesiderataPlugin.php';
        }
        $plugin = new \DesiderataPlugin($this->db, $this->hooks);

        return Delegation::run(static fn (): ResponseInterface => $action($plugin));
    }

    private function active(): bool
    {
        return (new CollectionsController($this->db, $this->hooks))->available('desiderata');
    }

    private function passthrough(ResponseInterface $response, ResponseInterface $inner): ResponseInterface
    {
        $response->getBody()->write((string) $inner->getBody());

        return $response->withStatus($inner->getStatusCode())->withHeader('Content-Type', 'application/json; charset=utf-8');
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

    private function disabled(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Proposta non trovata.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
