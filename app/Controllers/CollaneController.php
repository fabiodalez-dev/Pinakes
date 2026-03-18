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
