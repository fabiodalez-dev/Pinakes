<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\HtmlHelper;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CollaneController
{
    /**
     * List all distinct collane with book counts.
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $collane = [];
        $result = $db->query("
            SELECT collana, COUNT(*) AS book_count,
                   MIN(CAST(numero_serie AS UNSIGNED)) AS min_num,
                   MAX(CAST(numero_serie AS UNSIGNED)) AS max_num
            FROM libri
            WHERE collana IS NOT NULL AND collana != '' AND deleted_at IS NULL
            GROUP BY collana
            ORDER BY collana ASC
        ");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $collane[] = $row;
            }
            $result->free();
        }

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

        // Get collana metadata (description) from collane table
        $collanaDesc = '';
        $stmtMeta = $db->prepare("SELECT descrizione FROM collane WHERE nome = ?");
        if ($stmtMeta) {
            $stmtMeta->bind_param('s', $collana);
            $stmtMeta->execute();
            $metaRes = $stmtMeta->get_result();
            $metaRow = $metaRes->fetch_assoc();
            $collanaDesc = $metaRow['descrizione'] ?? '';
            $stmtMeta->close();
        }

        $books = [];
        $stmt = $db->prepare("
            SELECT l.id, l.titolo, l.numero_serie, l.isbn13, l.isbn10, l.copertina_url,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
            FROM libri l
            WHERE l.collana = ? AND l.deleted_at IS NULL
            ORDER BY CAST(l.numero_serie AS UNSIGNED), l.titolo
        ");
        if ($stmt) {
            $stmt->bind_param('s', $collana);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $books[] = $row;
            }
            $stmt->close();
        }

        // Check if a multi-volume parent exists for this collana
        $hasParentWork = false;
        $stmtParent = $db->prepare("
            SELECT COUNT(*) AS cnt FROM volumi v
            JOIN libri l ON v.opera_id = l.id AND l.deleted_at IS NULL
            JOIN libri l2 ON v.volume_id = l2.id AND l2.collana = ? AND l2.deleted_at IS NULL
            LIMIT 1
        ");
        if ($stmtParent) {
            $stmtParent->bind_param('s', $collana);
            $stmtParent->execute();
            $res = $stmtParent->get_result();
            $hasParentWork = ($res->fetch_assoc()['cnt'] ?? 0) > 0;
            $stmtParent->close();
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

        if ($nome === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $stmt = $db->prepare("INSERT IGNORE INTO collane (nome) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('s', $nome);
            $stmt->execute();
            $stmt->close();
        }

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

        // Remove collana from all books
        $stmt = $db->prepare("UPDATE libri SET collana = NULL, numero_serie = NULL, updated_at = NOW() WHERE collana = ? AND deleted_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('s', $nome);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
        }

        // Delete from collane table
        $stmt2 = $db->prepare("DELETE FROM collane WHERE nome = ?");
        if ($stmt2) {
            $stmt2->bind_param('s', $nome);
            $stmt2->execute();
            $stmt2->close();
        }

        $_SESSION['success_message'] = sprintf(__('Collana "%s" eliminata (%d libri aggiornati)'), $nome, $affected ?? 0);
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

        if ($nome === '') {
            $_SESSION['error_message'] = __('Nome collana non valido');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        $stmt = $db->prepare("INSERT INTO collane (nome, descrizione) VALUES (?, ?) ON DUPLICATE KEY UPDATE descrizione = VALUES(descrizione)");
        if ($stmt) {
            $stmt->bind_param('ss', $nome, $descrizione);
            $stmt->execute();
            $stmt->close();
        }

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

        $stmt = $db->prepare("UPDATE libri SET collana = ?, updated_at = NOW() WHERE collana = ? AND deleted_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('ss', $newName, $oldName);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            $_SESSION['success_message'] = sprintf(__('Collana rinominata: %d libri aggiornati'), $affected);
        }

        return $response->withHeader('Location', url('/admin/collane?dettaglio=' . urlencode($newName)))->withStatus(302);
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

        $stmt = $db->prepare("UPDATE libri SET collana = ?, updated_at = NOW() WHERE collana = ? AND deleted_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('ss', $target, $source);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            $_SESSION['success_message'] = sprintf(__('Collane unite: %d libri spostati in "%s"'), $affected, $target);
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

        $stmt = $db->prepare("UPDATE libri SET numero_serie = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('si', $numero, $bookId);
            $stmt->execute();
            $stmt->close();
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

        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $stmt = $db->prepare("UPDATE libri SET collana = ?, updated_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL");
        if ($stmt) {
            $types = 's' . str_repeat('i', count($bookIds));
            $params = array_merge([$collana], $bookIds);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => sprintf(__('%d libri assegnati alla collana "%s"'), $affected, $collana)
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => true, 'message' => __('Errore database')], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: search collane names for autocomplete.
     */
    public function searchApi(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $results = [];

        if (strlen($q) >= 1) {
            // Search in collane table first, then in distinct libri.collana
            $search = '%' . $q . '%';
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
        $stmt = $db->prepare("INSERT INTO libri (titolo, collana, copie_totali, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())");
        if (!$stmt) {
            $_SESSION['error_message'] = __('Errore database');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }
        $stmt->bind_param('ss', $parentTitle, $collana);
        $stmt->execute();
        $parentId = (int) $db->insert_id;
        $stmt->close();

        if ($parentId <= 0) {
            $_SESSION['error_message'] = __('Errore nella creazione dell\'opera');
            return $response->withHeader('Location', url('/admin/collane'))->withStatus(302);
        }

        // Link all books in the collana as volumes
        $volNum = 1;
        $stmtBooks = $db->prepare("SELECT id, numero_serie FROM libri WHERE collana = ? AND id != ? AND deleted_at IS NULL ORDER BY CAST(numero_serie AS UNSIGNED), titolo");
        if ($stmtBooks) {
            $stmtBooks->bind_param('si', $collana, $parentId);
            $stmtBooks->execute();
            $result = $stmtBooks->get_result();
            $stmtInsert = $db->prepare("INSERT IGNORE INTO volumi (opera_id, volume_id, numero_volume) VALUES (?, ?, ?)");
            while ($row = $result->fetch_assoc()) {
                $bookId = (int) $row['id'];
                $num = (int) ($row['numero_serie'] ?: $volNum);
                if ($stmtInsert) {
                    $stmtInsert->bind_param('iii', $parentId, $bookId, $num);
                    $stmtInsert->execute();
                }
                $volNum++;
            }
            if ($stmtInsert) {
                $stmtInsert->close();
            }
            $stmtBooks->close();
        }

        $_SESSION['success_message'] = sprintf(__('Opera "%s" creata con %d volumi'), $parentTitle, $volNum - 1);
        return $response->withHeader('Location', url('/admin/libri/' . $parentId))->withStatus(302);
    }
}
