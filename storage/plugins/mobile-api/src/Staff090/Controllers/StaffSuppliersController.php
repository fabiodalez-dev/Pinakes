<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Models\SupplierRepository;
use App\Plugins\MobileApi\Staff090\Support\SupplierPayload;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\Permissions;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Suppliers (`fornitori`, Pinakes 0.9.0) for the staff app: the CRUD of
 * /admin/suppliers on the same repository and with the same validation.
 * Deleting a supplier unlinks its books (FK ON DELETE SET NULL), as on the
 * web.
 */
final class StaffSuppliersController
{
    private SupplierRepository $repo;

    public function __construct(private mysqli $db)
    {
        $this->repo = new SupplierRepository($db);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($missing = $this->schemaMissing($response)) !== null) {
            return $missing;
        }
        try {
            $canManage = $this->canManage($request);
            $q = mb_strtolower(StaffPaging::query($request, 'q'));
            $rows = $this->repo->listWithCounts();
            if ($q !== '') {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => str_contains(mb_strtolower((string) $r['nome']), $q)));
            }
            $items = array_map(static fn (array $r): array => SupplierPayload::map($r, (int) ($r['libri_count'] ?? 0), $canManage), $rows);

            return ResponseEnvelope::success($response, $items, ['count' => count($items), 'can_create' => $canManage]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'list', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (($missing = $this->schemaMissing($response)) !== null) {
            return $missing;
        }
        try {
            $resource = $this->load($id, $this->canManage($request));

            return $resource === null ? $this->notFound($response) : ResponseEnvelope::success($response, $resource);
        } catch (\Throwable $e) {
            return $this->fail($response, 'detail', $e);
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($missing = $this->schemaMissing($response)) !== null) {
            return $missing;
        }
        $json = JsonBody::parse($request);
        $checked = SupplierPayload::validate($json);
        if ($checked['errors'] !== []) {
            return $this->invalid($response, $checked['errors']);
        }
        $data = $checked['data'];
        $canManage = $this->canManage($request);
        try {
            return (new StaffIdempotency($this->db))->run(
                $request,
                $response,
                $json,
                'supplier_create',
                (int) (StaffPaging::user($request)['id'] ?? 0),
                0,
                $data,
                fn (): int => $this->repo->create($data),
                fn (int $id): ?array => $this->load($id, $canManage)
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (($missing = $this->schemaMissing($response)) !== null) {
            return $missing;
        }
        $json = JsonBody::parse($request);
        try {
            $current = $this->repo->getById($id);
            if ($current === null) {
                return $this->notFound($response);
            }
            $checked = SupplierPayload::validate($json, $current);
            if ($checked['errors'] !== []) {
                return $this->invalid($response, $checked['errors']);
            }
            if (!$this->repo->update($id, $checked['data'])) {
                return $this->notFound($response);
            }
            $resource = $this->load($id, $this->canManage($request));

            return $resource === null ? $this->notFound($response) : ResponseEnvelope::success($response, $resource);
        } catch (\Throwable $e) {
            return $this->fail($response, 'update', $e);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (($missing = $this->schemaMissing($response)) !== null) {
            return $missing;
        }
        try {
            $books = $this->bookCount($id);
            if (!$this->repo->delete($id)) {
                return $this->notFound($response);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true, 'books_unlinked' => $books]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'delete', $e);
        }
    }

    /** @return array<string, mixed>|null */
    private function load(int $id, bool $canManage): ?array
    {
        $row = $this->repo->getById($id);

        return $row === null ? null : SupplierPayload::map($row, $this->bookCount($id), $canManage);
    }

    /** Non-deleted books linked to the supplier (the count of the web list). */
    private function bookCount(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM libri WHERE fornitore_id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('supplier book count prepare failed');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return (int) ($row[0] ?? 0);
    }

    /** The web's gate: the catalogue permission (the route already requires it). */
    private function canManage(ServerRequestInterface $request): bool
    {
        return Permissions::can('catalog.manage', StaffPaging::user($request));
    }

    /**
     * Files updated before the 0.9.0 migration ran: the web answers with a
     * message instead of a 500, the app gets a 409 it can show.
     */
    private function schemaMissing(ResponseInterface $response): ?ResponseInterface
    {
        if ($this->repo->isAvailable()) {
            return null;
        }

        return ResponseEnvelope::error($response, 'schema_missing', __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.'), 409);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation', (string) reset($fields), 422, ['fields' => $fields, 'field' => (string) array_key_first($fields)]);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Fornitore non trovato.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff supplier ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
