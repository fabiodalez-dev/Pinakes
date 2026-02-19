<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CollocazioneController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\CollocationRepository($db);
        $scaffali = $repo->getScaffali();
        $mensole = $repo->getMensole();

        // Count books that have a collocazione assigned (not posizioni table rows)
        $posizioniUsate = 0;
        $cntResult = $db->query("SELECT COUNT(*) as cnt FROM libri WHERE scaffale_id IS NOT NULL AND mensola_id IS NOT NULL AND posizione_progressiva IS NOT NULL AND deleted_at IS NULL");
        if ($cntResult) {
            $row = $cntResult->fetch_assoc();
            $posizioniUsate = (int) ($row['cnt'] ?? 0);
        }

        ob_start();
        require __DIR__ . '/../Views/collocazione/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createScaffale(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $codice = strtoupper(trim((string) ($data['codice'] ?? '')));
        $nome = trim((string) ($data['nome'] ?? ''));
        $ordine = (int) ($data['ordine'] ?? 0);
        if ($codice === '') {
            $_SESSION['error_message'] = __('Codice scaffale obbligatorio');
            return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
        }
        try {
            (new \App\Models\CollocationRepository($db))->createScaffale(['codice' => $codice, 'nome' => $nome, 'ordine' => $ordine]);
            $_SESSION['success_message'] = __('Scaffale creato');
        } catch (\RuntimeException $e) {
            // Duplicate check from repository — message is already user-friendly and translated
            $_SESSION['error_message'] = $e->getMessage();
            error_log("Scaffale creation failed: " . $e->getMessage());
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062 || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                $_SESSION['error_message'] = sprintf(__('Il codice scaffale "%s" esiste già. Usa un codice diverso.'), $codice);
            } else {
                $_SESSION['error_message'] = __('Impossibile creare lo scaffale. Riprova più tardi.');
            }
            error_log("Scaffale creation failed: " . $e->getMessage());
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = __('Impossibile creare lo scaffale. Riprova più tardi.');
            error_log("Scaffale creation failed: " . $e->getMessage());
        }
        return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
    }

    public function createMensola(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $scaffale_id = (int) ($data['scaffale_id'] ?? 0);
        $numero_livello = (int) ($data['numero_livello'] ?? 1);
        $ordine = (int) ($data['ordine'] ?? 0);
        $genera_n = max(0, (int) ($data['genera_posizioni'] ?? 0));
        if ($scaffale_id <= 0) {
            $_SESSION['error_message'] = __('Scaffale obbligatorio');
            return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
        }
        try {
            $repo = new \App\Models\CollocationRepository($db);
            $mensola_id = $repo->createMensola(['scaffale_id' => $scaffale_id, 'numero_livello' => $numero_livello, 'ordine' => $ordine]);
            if ($genera_n > 0) {
                $repo->createPosizioni($scaffale_id, $mensola_id, $genera_n);
                $_SESSION['success_message'] = sprintf(__('Mensola creata e %d posizioni generate.'), $genera_n);
            } else {
                $_SESSION['success_message'] = __('Mensola creata.');
            }
        } catch (\mysqli_sql_exception $e) {
            // Check if it's a duplicate entry error (errno 1062)
            if ($e->getCode() === 1062 || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                $_SESSION['error_message'] = sprintf(__('Una mensola con livello %d esiste già in questo scaffale. Usa un livello diverso.'), $numero_livello);
            } else {
                $_SESSION['error_message'] = __('Impossibile creare la mensola. Riprova più tardi.');
            }
            error_log("Mensola creation failed: " . $e->getMessage());
        } catch (\Throwable $e) {
            $_SESSION['error_message'] = __('Impossibile creare la mensola. Riprova più tardi.');
            error_log("Mensola creation failed: " . $e->getMessage());
        }
        return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
    }

    public function deleteScaffale(Request $request, Response $response, mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        // Check if scaffale has mensole
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM mensole WHERE scaffale_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ((int) $row['cnt'] > 0) {
            $_SESSION['error_message'] = __('Impossibile eliminare: lo scaffale contiene mensole');
            return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
        }

        // Check if scaffale has books (excluding soft-deleted)
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM libri WHERE scaffale_id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ((int) $row['cnt'] > 0) {
            $_SESSION['error_message'] = __('Impossibile eliminare: lo scaffale contiene libri');
            return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
        }

        // Delete scaffale
        $stmt = $db->prepare("DELETE FROM scaffali WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $_SESSION['success_message'] = __('Scaffale eliminato');
        return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
    }

    public function deleteMensola(Request $request, Response $response, mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware
        // Check if mensola has books (excluding soft-deleted)
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM libri WHERE mensola_id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ((int) $row['cnt'] > 0) {
            $_SESSION['error_message'] = __('Impossibile eliminare: la mensola contiene libri');
            return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
        }

        // Delete mensola
        $stmt = $db->prepare("DELETE FROM mensole WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $_SESSION['success_message'] = __('Mensola eliminata');
        return $response->withHeader('Location', url('/admin/collocazione'))->withStatus(302);
    }

    public function sort(Request $request, Response $response, mysqli $db): Response
    {
        $data = json_decode((string) $request->getBody(), true) ?: [];
        // CSRF validated by CsrfMiddleware
        $type = (string) ($data['type'] ?? '');
        $ids = (array) ($data['ids'] ?? []);
        $repo = new \App\Models\CollocationRepository($db);
        switch ($type) {
            case 'scaffali':
                $repo->updateOrder('scaffali', $ids);
                break;
            case 'mensole':
                $repo->updateOrder('mensole', $ids);
                break;
            case 'posizioni':
                $repo->updateOrder('posizioni', $ids);
                break;
            default:
                $response->getBody()->write(json_encode(['error' => __('Tipo non valido')], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['ok' => true], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
    public function suggest(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $genere_id = (int) ($q['genere_id'] ?? 0);
        $sottogenere_id = (int) ($q['sottogenere_id'] ?? 0);
        $repo = new \App\Models\CollocationRepository($db);
        $sug = $repo->suggestByGenre($genere_id, $sottogenere_id);
        $response->getBody()->write(json_encode($sug, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function nextPosition(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $scaffaleId = (int) ($q['scaffale_id'] ?? 0);
        $mensolaId = (int) ($q['mensola_id'] ?? 0);
        $bookId = isset($q['book_id']) ? (int) $q['book_id'] : null;

        if ($scaffaleId <= 0 || $mensolaId <= 0) {
            $response->getBody()->write(json_encode([
                'error' => __('Parametri non validi')
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $repo = new \App\Models\CollocationRepository($db);

        $existingPosition = null;
        if ($bookId) {
            $stmt = $db->prepare('SELECT scaffale_id, mensola_id, posizione_progressiva FROM libri WHERE id=? AND deleted_at IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $res = $stmt->get_result();
                $existingPosition = $res ? $res->fetch_assoc() : null;
            }
        }

        $position = null;
        // Check if existing position matches current scaffale/mensola
        $matchesScaffale = $existingPosition && (int) ($existingPosition['scaffale_id'] ?? 0) === $scaffaleId;
        $matchesMensola = $existingPosition && (int) ($existingPosition['mensola_id'] ?? 0) === $mensolaId;
        if ($matchesScaffale && $matchesMensola) {
            $position = (int) ($existingPosition['posizione_progressiva'] ?? 0);
        }

        if (!$position || $position <= 0) {
            $position = $repo->computeNextProgressiva($scaffaleId, $mensolaId, $bookId);
        }

        if ($repo->isProgressivaOccupied($scaffaleId, $mensolaId, $position, $bookId)) {
            $position = $repo->computeNextProgressiva($scaffaleId, $mensolaId, $bookId);
        }

        $collocazione = $repo->buildCollocazioneString($scaffaleId, $mensolaId, $position);
        $level = $repo->getMensolaLevel($mensolaId);
        $scaffaleCode = $repo->getScaffaleLetter($scaffaleId);

        $response->getBody()->write(json_encode([
            'next_position' => $position,
            'collocazione' => $collocazione,
            'mensola_level' => $level,
            'scaffale_code' => $scaffaleCode,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getLibri(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $scaffaleId = (int) ($q['scaffale_id'] ?? 0);
        $mensolaId = (int) ($q['mensola_id'] ?? 0);

        $sql = "SELECT l.id, l.titolo, l.scaffale_id, l.mensola_id, l.posizione_progressiva,
                       s.codice as scaffale_codice, m.numero_livello,
                       GROUP_CONCAT(DISTINCT a.nome SEPARATOR ', ') as autori,
                       e.nome as editore
                FROM libri l
                LEFT JOIN scaffali s ON l.scaffale_id = s.id
                LEFT JOIN mensole m ON l.mensola_id = m.id
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.scaffale_id IS NOT NULL AND l.mensola_id IS NOT NULL AND l.deleted_at IS NULL";

        $params = [];
        $types = '';

        if ($scaffaleId > 0) {
            $sql .= " AND l.scaffale_id = ?";
            $params[] = $scaffaleId;
            $types .= 'i';
        }

        if ($mensolaId > 0) {
            $sql .= " AND l.mensola_id = ?";
            $params[] = $mensolaId;
            $types .= 'i';
        }

        $sql .= " GROUP BY l.id ORDER BY s.ordine, m.ordine, l.posizione_progressiva";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $response->getBody()->write(json_encode(['error' => __('Errore nella query.'), 'detail' => $db->error], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $libri = [];
        while ($row = $result->fetch_assoc()) {
            $collocazione = '';
            if ($row['scaffale_codice'] && $row['numero_livello'] && $row['posizione_progressiva']) {
                $collocazione = $row['scaffale_codice'] . '.' . $row['numero_livello'] . '.' . $row['posizione_progressiva'];
            }

            $libri[] = [
                'id' => (int) $row['id'],
                'titolo' => $row['titolo'] ?? '',
                'autori' => $row['autori'] ?? '',
                'editore' => $row['editore'] ?? '',
                'collocazione' => $collocazione,
                'scaffale_id' => (int) ($row['scaffale_id'] ?? 0),
                'mensola_id' => (int) ($row['mensola_id'] ?? 0)
            ];
        }

        $response->getBody()->write(json_encode(['libri' => $libri], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function exportCSV(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $scaffaleId = (int) ($q['scaffale_id'] ?? 0);
        $mensolaId = (int) ($q['mensola_id'] ?? 0);

        $sql = "SELECT l.id, l.titolo, COALESCE(l.isbn13, l.isbn10) as isbn, l.anno_pubblicazione,
                       s.codice as scaffale_codice, m.numero_livello, l.posizione_progressiva,
                       GROUP_CONCAT(DISTINCT a.nome SEPARATOR ', ') as autori,
                       e.nome as editore
                FROM libri l
                LEFT JOIN scaffali s ON l.scaffale_id = s.id
                LEFT JOIN mensole m ON l.mensola_id = m.id
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.scaffale_id IS NOT NULL AND l.mensola_id IS NOT NULL AND l.deleted_at IS NULL";

        $params = [];
        $types = '';

        if ($scaffaleId > 0) {
            $sql .= " AND l.scaffale_id = ?";
            $params[] = $scaffaleId;
            $types .= 'i';
        }

        if ($mensolaId > 0) {
            $sql .= " AND l.mensola_id = ?";
            $params[] = $mensolaId;
            $types .= 'i';
        }

        $sql .= " GROUP BY l.id ORDER BY s.ordine, m.ordine, l.posizione_progressiva";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $response->getBody()->write(__('Errore nella query.'));
            return $response->withStatus(500);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        // Prepare CSV
        $csv = [];
        $csv[] = [
            __('Collocazione'),
            __('Titolo'),
            __('Autori'),
            __('Editore'),
            'ISBN',
            __('Anno')
        ];

        while ($row = $result->fetch_assoc()) {
            $collocazione = '';
            if ($row['scaffale_codice'] && $row['numero_livello'] && $row['posizione_progressiva']) {
                $collocazione = $row['scaffale_codice'] . '.' . $row['numero_livello'] . '.' . $row['posizione_progressiva'];
            }

            $csv[] = [
                $collocazione,
                $row['titolo'] ?? '',
                $row['autori'] ?? '',
                $row['editore'] ?? '',
                $row['isbn'] ?? '',
                $row['anno_pubblicazione'] ?? ''
            ];
        }

        // Generate CSV output
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $line) {
            fputcsv($output, $line, ';');
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        // Add UTF-8 BOM
        $csvContent = "\xEF\xBB\xBF" . $csvContent;

        $filename = 'collocazione_' . date('Y-m-d_H-i-s') . '.csv';

        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
