<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SeriesRepository;
use App\Support\HtmlHelper;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CollaneController
{
    /**
     * Check if the collane table exists (may not on partial migration).
     */
    private function hasCollaneTable(mysqli $db): bool
    {
        try {
            $check = $db->query("SHOW TABLES LIKE 'collane'");
            return $check !== false && $check->num_rows > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableCycleOrder(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $order = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        return $order === false ? null : (int) $order;
    }

    /**
     * List all distinct collane with book counts.
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $seriesRepo = new SeriesRepository($db);
        $supportsHierarchy = $seriesRepo->supportsHierarchy();
        $collane = $seriesRepo->listSeries();

        ob_start();
        require __DIR__ . '/../Views/collane/index.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Show books in a specific collana.
     */
    public function show(Request $request, Response $response, mysqli $db): Response
    {
        $collana = trim((string) ($request->getQueryParams()['nome'] ?? ''));
        if ($collana === '') {
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $seriesRepo = new SeriesRepository($db);
        $supportsHierarchy = $seriesRepo->supportsHierarchy();

        // Get collana metadata from collane table
        $collanaDesc = '';
        $seriesGroup = '';
        $seriesCycle = '';
        $cycleOrder = null;
        $seriesParent = '';
        $seriesType = 'serie';
        $seriesRepo->ensureCollana($collana, [], false);
        $metaRow = $seriesRepo->getSeriesByName($collana);
        if ($metaRow) {
            $collanaDesc = $metaRow['descrizione'] ?? '';
            $seriesGroup = $metaRow['gruppo_serie'] ?? '';
            $seriesCycle = $metaRow['ciclo'] ?? '';
            $cycleOrder = $metaRow['ordine_ciclo'] ?? null;
            $seriesParent = $metaRow['parent_nome'] ?? '';
            $seriesType = $metaRow['tipo'] ?? 'serie';
        }

        $relatedCollane = $seriesRepo->getRelatedSeries($collana);
        $books = $seriesRepo->getBooksForSeries($collana);

        // Check if a multi-volume parent exists for this collana
        $hasParentWork = false;
        $bookIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $books)));
        if ($bookIds !== []) {
            $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
            $stmtParent = $db->prepare("
                SELECT COUNT(*) AS cnt
                  FROM volumi v
                  JOIN libri l ON v.opera_id = l.id AND l.deleted_at IS NULL
                 WHERE v.volume_id IN ($placeholders)
                 LIMIT 1
            ");
            if ($stmtParent) {
                $types = str_repeat('i', count($bookIds));
                $stmtParent->bind_param($types, ...$bookIds);
                $stmtParent->execute();
                $res = $stmtParent->get_result();
                $hasParentWork = ($res->fetch_assoc()['cnt'] ?? 0) > 0;
                $stmtParent->close();
            }
        }

        ob_start();
        require __DIR__ . '/../Views/collane/dettaglio.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Create a new collana (insert into collane table).
     */
    public function create(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $nome = trim((string) ($data['nome'] ?? ''));
        $seriesGroup = $this->nullableString($data['gruppo_serie'] ?? null);
        $seriesCycle = $this->nullableString($data['ciclo'] ?? null);
        $cycleOrder = $this->nullableCycleOrder($data['ordine_ciclo'] ?? null);
        $seriesParent = $this->nullableString($data['serie_padre'] ?? null);
        $seriesType = (new SeriesRepository($db))->normalizeType((string) ($data['tipo_collana'] ?? 'serie'));

        if ($nome === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        (new SeriesRepository($db))->ensureCollana($nome, [
            'gruppo_serie' => $seriesGroup,
            'ciclo' => $seriesCycle,
            'ordine_ciclo' => $cycleOrder,
            'parent_nome' => $seriesParent,
            'tipo' => $seriesType,
        ]);

        $_SESSION['success_message'] = sprintf(__('Collana "%s" creata'), $nome);
        return $response->withHeader('Location', url('/admin/collane/dettaglio?nome=' . urlencode($nome)))->withStatus(302);
    }

    /**
     * Delete a collana: removes collana value from all books and deletes from collane table.
     */
    public function delete(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $nome = trim((string) ($data['nome'] ?? ''));

        if ($nome === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $db->begin_transaction();
        try {
            $affected = (new SeriesRepository($db))->deleteSeries($nome);

            $db->commit();
            $_SESSION['success_message'] = sprintf(__('Collana "%s" eliminata (%d libri aggiornati)'), $nome, $affected);
        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error('CollaneController::delete failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore database');
        }
        return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
    }

    /**
     * Save collana description.
     */
    public function saveDescription(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $nome = trim((string) ($data['nome'] ?? ''));
        $descrizione = trim((string) ($data['descrizione'] ?? ''));
        $seriesGroup = $this->nullableString($data['gruppo_serie'] ?? null);
        $seriesCycle = $this->nullableString($data['ciclo'] ?? null);
        $cycleOrder = $this->nullableCycleOrder($data['ordine_ciclo'] ?? null);
        $seriesParent = $this->nullableString($data['serie_padre'] ?? null);
        $seriesRepo = new SeriesRepository($db);
        $seriesType = $seriesRepo->normalizeType((string) ($data['tipo_collana'] ?? 'serie'));

        if ($nome === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        if (!$seriesRepo->hasCollaneTable()) {
            $_SESSION['error_message'] = __('Errore database');
            return $response->withHeader('Location', url('/admin/collane/dettaglio?nome=' . urlencode($nome)))->withStatus(302);
        }
        $seriesRepo->ensureCollana($nome, [
            'descrizione' => $descrizione,
            'gruppo_serie' => $seriesGroup,
            'ciclo' => $seriesCycle,
            'ordine_ciclo' => $cycleOrder,
            'parent_nome' => $seriesParent,
            'tipo' => $seriesType,
        ]);

        $_SESSION['success_message'] = __('Descrizione salvata');
        return $response->withHeader('Location', url('/admin/collane/dettaglio?nome=' . urlencode($nome)))->withStatus(302);
    }

    /**
     * Rename a collana (all books with that collana value).
     */
    public function rename(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $oldName = trim((string) ($data['old_name'] ?? ''));
        $newName = trim((string) ($data['new_name'] ?? ''));

        if ($oldName === '' || $newName === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $db->begin_transaction();
        try {
            $affected = (new SeriesRepository($db))->renameSeries($oldName, $newName);

            $db->commit();
            $_SESSION['success_message'] = sprintf(__('Collana rinominata: %d libri aggiornati'), $affected);
        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error('CollaneController::rename failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore database');
        }

        return $response->withHeader('Location', url('/admin/collane/dettaglio?nome=' . urlencode($newName)))->withStatus(302);
    }

    /**
     * Merge two collane (move all books from source to target).
     */
    public function merge(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $source = trim((string) ($data['source'] ?? ''));
        $target = trim((string) ($data['target'] ?? ''));

        if ($source === '' || $target === '' || $source === $target) {
            $_SESSION['error_message'] = __('Parametri non validi per l\'unione');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $db->begin_transaction();
        try {
            $affected = (new SeriesRepository($db))->mergeSeries($source, $target);

            $db->commit();
            $_SESSION['success_message'] = sprintf(__('Collane unite: %d libri spostati in "%s"'), $affected, $target);
        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error('CollaneController::merge failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Errore database');
        }

        return $response->withHeader('Location', url('/admin/collane?dettaglio=' . urlencode($target)))->withStatus(302);
    }

    /**
     * Update numero_serie for a book (AJAX).
     */
    public function updateOrder(Request $request, Response $response, mysqli $db): Response
    {
        $data = json_decode((string) $request->getBody(), true) ?: [];
        $bookId = (int) ($data['book_id'] ?? 0);
        $numero = trim((string) ($data['numero_serie'] ?? ''));

        if ($bookId <= 0) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => __('ID libro non valido')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $ok = (new SeriesRepository($db))->updatePrimaryOrder($bookId, $numero === '' ? null : $numero);

        if (!$ok) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => __('Errore database')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['success' => true], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Bulk assign collana to multiple books (AJAX).
     */
    public function bulkAssign(Request $request, Response $response, mysqli $db): Response
    {
        $data = json_decode((string) $request->getBody(), true) ?: [];
        $bookIds = array_filter(array_map('intval', $data['book_ids'] ?? []), fn($id) => $id > 0);
        $collana = trim((string) ($data['collana'] ?? ''));

        if (empty($bookIds) || $collana === '') {
            $response->getBody()->write(json_encode(['error' => true, 'message' => __('Parametri non validi')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $seriesRepo = new SeriesRepository($db);
        $affected = 0;
        foreach ($bookIds as $bookId) {
            $seriesRepo->assignPrimarySeries((int) $bookId, $collana);
            $affected++;
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => sprintf(__('%d libri assegnati alla collana "%s"'), $affected, $collana)
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function removeBook(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $bookId = (int) ($data['book_id'] ?? 0);
        $collana = trim((string) ($data['collana'] ?? ''));

        if ($bookId <= 0 || $collana === '') {
            $_SESSION['error_message'] = __('Parametri non validi');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        (new SeriesRepository($db))->removeBookFromSeries($bookId, $collana);
        $_SESSION['success_message'] = __('Libro rimosso dalla serie');
        return $response->withHeader('Location', url('/admin/collane/dettaglio?nome=' . urlencode($collana)))->withStatus(302);
    }

    /**
     * API: search collane names for autocomplete.
     */
    public function searchApi(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $results = [];

        if (strlen($q) >= 1) {
            $search = '%' . $q . '%';
            if ($this->hasCollaneTable($db)) {
                $stmt = $db->prepare("
                    SELECT DISTINCT nome FROM (
                        SELECT nome FROM collane WHERE nome LIKE ?
                        UNION
                        SELECT collana AS nome FROM libri WHERE collana LIKE ? AND collana IS NOT NULL AND collana != '' AND deleted_at IS NULL
                    ) AS combined ORDER BY nome LIMIT 10
                ");
                if ($stmt) {
                    $stmt->bind_param('ss', $search, $search);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $results[] = $row['nome'];
                    }
                    $stmt->close();
                }
            } else {
                $stmt = $db->prepare("
                    SELECT DISTINCT collana AS nome FROM libri
                    WHERE collana LIKE ? AND collana IS NOT NULL AND collana != '' AND deleted_at IS NULL
                    ORDER BY collana LIMIT 10
                ");
                if ($stmt) {
                    $stmt->bind_param('s', $search);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $results[] = $row['nome'];
                    }
                    $stmt->close();
                }
            }
        }

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Create a multi-volume parent work from a collana.
     */
    public function createParentWork(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody() ?? [];
        $collana = trim((string) ($data['collana'] ?? ''));
        $parentTitle = trim((string) ($data['parent_title'] ?? ''));

        if ($collana === '' || $parentTitle === '') {
            $_SESSION['error_message'] = __('Parametri non validi');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        // Create the parent book
        $stmt = $db->prepare("INSERT INTO libri (titolo, collana, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, ?, 0, 0, NOW(), NOW())");
        if (!$stmt) {
            $_SESSION['error_message'] = __('Errore database');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }
        $stmt->bind_param('ss', $parentTitle, $collana);
        $stmt->execute();
        $parentId = (int) $db->insert_id;
        $stmt->close();
        $seriesRepo = new SeriesRepository($db);
        $seriesRepo->assignPrimarySeries($parentId, $collana);

        if ($parentId <= 0) {
            $_SESSION['error_message'] = __('Errore nella creazione dell\'opera');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        // Link all books in the collana as volumes
        $linkedCount = 0;
        $rows = array_values(array_filter(
            $seriesRepo->getBooksForSeries($collana),
            static fn(array $row): bool => (int) ($row['id'] ?? 0) !== $parentId
        ));
        if ($rows !== []) {
            // Build set of used numero_serie values
            $usedNumbers = [];
            foreach ($rows as $row) {
                if (!empty($row['numero_serie'])) {
                    $usedNumbers[(int) $row['numero_serie']] = true;
                }
            }

            $stmtInsert = $db->prepare("INSERT IGNORE INTO volumi (opera_id, volume_id, numero_volume) VALUES (?, ?, ?)");
            $nextFree = 1;
            foreach ($rows as $row) {
                $bookId = (int) $row['id'];
                if (!empty($row['numero_serie'])) {
                    $num = (int) $row['numero_serie'];
                } else {
                    // Find next free number not already used
                    while (isset($usedNumbers[$nextFree])) {
                        $nextFree++;
                    }
                    $num = $nextFree;
                    $usedNumbers[$nextFree] = true;
                    $nextFree++;
                }
                if ($stmtInsert) {
                    $stmtInsert->bind_param('iii', $parentId, $bookId, $num);
                    $stmtInsert->execute();
                    if ($stmtInsert->affected_rows > 0) {
                        $linkedCount++;
                    }
                }
            }
            if ($stmtInsert) {
                $stmtInsert->close();
            }
        }

        $_SESSION['success_message'] = sprintf(__('Opera "%s" creata con %d volumi'), $parentTitle, $linkedCount);
        return $response->withHeader('Location', url('/admin/libri/' . $parentId))->withStatus(302);
    }
}
