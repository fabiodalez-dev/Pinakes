<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\HtmlHelper;
use App\Support\Log as AppLog;

class LibriApiController
{
    public function list(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $draw = (int)($q['draw'] ?? 0);
        $start = (int)($q['start'] ?? 0);
        $length = (int)($q['length'] ?? 10);
        $search_text = trim((string)($q['search_text'] ?? ''));
        $search_isbn = trim((string)($q['search_isbn'] ?? ''));
        $genere_id = (int)($q['genere_filter'] ?? 0);
        $sottogenere_id = (int)($q['sottogenere_filter'] ?? 0);
        $editore_id = (int)($q['editore_filter'] ?? 0);
        $stato = trim((string)($q['stato_filter'] ?? ''));
        $autore_id = (int)($q['autore_id'] ?? 0);
        $acq_from = trim((string)($q['acq_from'] ?? ''));
        $acq_to   = trim((string)($q['acq_to'] ?? ''));
        $pub_from = trim((string)($q['pub_from'] ?? ''));
        $pub_to   = trim((string)($q['pub_to'] ?? ''));
        $posizione_id = (int)($q['posizione_id'] ?? 0);
        $anno_from = trim((string)($q['anno_from'] ?? ''));
        $anno_to = trim((string)($q['anno_to'] ?? ''));

        // Build WHERE clause with prepared statement parameters
        $where = 'WHERE 1=1 ';
        $params = [];
        $types = '';

        if ($search_text !== '') {
            $nameExpr = $this->hasTableColumn($db, 'autori', 'cognome')
                ? "CONCAT(a.nome, ' ', a.cognome)"
                : 'a.nome';
            $where .= " AND (l.titolo LIKE ? OR l.sottotitolo LIKE ? OR EXISTS (SELECT 1 FROM libri_autori la JOIN autori a ON la.autore_id=a.id WHERE la.libro_id=l.id AND $nameExpr LIKE ?)) ";
            $searchParam = '%' . $search_text . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }
        if ($search_isbn !== '') {
            $where .= " AND (l.isbn10 LIKE ? OR l.isbn13 LIKE ?) ";
            $isbnParam = '%' . $search_isbn . '%';
            $params[] = $isbnParam;
            $params[] = $isbnParam;
            $types .= 'ss';
        }
        if ($genere_id) {
            $where .= ' AND l.genere_id = ?';
            $params[] = $genere_id;
            $types .= 'i';
        }
        if ($sottogenere_id) {
            $where .= ' AND l.sottogenere_id = ?';
            $params[] = $sottogenere_id;
            $types .= 'i';
        }
        if ($editore_id) {
            $where .= ' AND l.editore_id = ?';
            $params[] = $editore_id;
            $types .= 'i';
        }
        if ($stato !== '') {
            $where .= " AND l.stato = ?";
            $params[] = $stato;
            $types .= 's';
        }
        if ($autore_id) {
            $where .= ' AND EXISTS (SELECT 1 FROM libri_autori la WHERE la.libro_id=l.id AND la.autore_id=?)';
            $params[] = $autore_id;
            $types .= 'i';
        }
        // Date range filters
        if ($acq_from !== '') {
            $where .= " AND l.data_acquisizione >= ?";
            $params[] = $acq_from;
            $types .= 's';
        }
        if ($acq_to !== '') {
            $where .= " AND l.data_acquisizione <= ?";
            $params[] = $acq_to;
            $types .= 's';
        }
        // data_pubblicazione if column exists
        $hasPub = $this->hasColumn($db, 'data_pubblicazione');
        if ($hasPub && $pub_from !== '') {
            $where .= " AND l.data_pubblicazione >= ?";
            $params[] = $pub_from;
            $types .= 's';
        }
        if ($hasPub && $pub_to !== '') {
            $where .= " AND l.data_pubblicazione <= ?";
            $params[] = $pub_to;
            $types .= 's';
        }
        if ($posizione_id) {
            $where .= ' AND l.collocazione_id = ?';
            $params[] = $posizione_id;
            $types .= 'i';
        }
        // Year range filters for anno_pubblicazione
        if ($anno_from !== '') {
            $where .= " AND l.anno_pubblicazione >= ?";
            $params[] = (int)$anno_from;
            $types .= 'i';
        }
        if ($anno_to !== '') {
            $where .= " AND l.anno_pubblicazione <= ?";
            $params[] = (int)$anno_to;
            $types .= 'i';
        }

        // Count total records with prepared statement
        $total_sql = 'SELECT COUNT(*) AS c FROM libri l';
        $total_stmt = $db->prepare($total_sql);
        if (!$total_stmt) {
            AppLog::error('libri.total.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $total_stmt->execute();
        $total_res = $total_stmt->get_result();
        $total = (int)($total_res->fetch_assoc()['c'] ?? 0);

        // Use prepared statement for filtered count
        $count_sql = 'SELECT COUNT(*) AS c FROM libri l ' . $where;
        $count_stmt = $db->prepare($count_sql);
        if (!$count_stmt) {
            AppLog::error('libri.count.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $filRes = $count_stmt->get_result();
        $filtered = (int)($filRes->fetch_assoc()['c'] ?? 0);
        $count_stmt->close();

        $orderAuthors = $this->hasTableColumn($db, 'libri_autori', 'ordine_credito') ? 'la.ordine_credito, a.nome' : 'a.nome';
        $authorLabelExpr = $this->hasTableColumn($db, 'autori', 'cognome')
            ? "CONCAT(a.nome, ' ', a.cognome)"
            : 'a.nome';
        $sql = "SELECT l.*, e.nome AS editore_nome,
                g.nome AS genere_nome,
                COALESCE(s.nome, 'N/D') AS collocazione_nome,
                COALESCE(
                  CONCAT(
                    COALESCE(s.codice, s.nome, 'N/D'),
                    ' - Livello ', COALESCE(m.numero_livello, 'N/D'),
                    ' - Pos ', LPAD(COALESCE(l.posizione_progressiva, p.ordine), 2, '0')
                  ),
                  'N/D'
                ) AS posizione_scaffale,
                COALESCE(l.posizione_progressiva, p.ordine) AS posizione_progressiva_val,
                s.codice AS scaffale_codice,
                s.id AS scaffale_id_ref,
                m.id AS mensola_id_ref,
                m.numero_livello AS mensola_livello,
                (
                  SELECT GROUP_CONCAT($authorLabelExpr SEPARATOR ', ')
                  FROM libri_autori la
                  JOIN autori a ON la.autore_id = a.id
                  WHERE la.libro_id = l.id
                  ORDER BY $orderAuthors
                ) AS autori,
                (
                  SELECT GROUP_CONCAT(la.autore_id ORDER BY $orderAuthors SEPARATOR ',')
                  FROM libri_autori la
                  JOIN autori a ON la.autore_id = a.id
                  WHERE la.libro_id = l.id
                ) AS autori_order_key
                FROM libri l
                LEFT JOIN editori e ON l.editore_id=e.id
                LEFT JOIN generi g ON l.genere_id=g.id
                LEFT JOIN posizioni p ON l.posizione_id=p.id
                LEFT JOIN mensole m ON m.id = COALESCE(l.mensola_id, p.mensola_id)
                LEFT JOIN scaffali s ON s.id = COALESCE(l.scaffale_id, p.scaffale_id)
                $where ORDER BY l.titolo ASC LIMIT ?, ?";

        // Add LIMIT parameters
        $params[] = $start;
        $params[] = $length;
        $types .= 'ii';

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            AppLog::error('libri.list.prepare_failed', ['error' => $db->error]);
            $response->getBody()->write(json_encode(['error' => __('Errore interno del database. Riprova più tardi.')], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                // Decode HTML entities in text fields
                $row['titolo'] = HtmlHelper::decode($row['titolo'] ?? '');
                $row['sottotitolo'] = HtmlHelper::decode($row['sottotitolo'] ?? '');
                $row['editore_nome'] = HtmlHelper::decode($row['editore_nome'] ?? '');
                $row['autori'] = HtmlHelper::decode($row['autori'] ?? '');
                $row['ean'] = HtmlHelper::decode($row['ean'] ?? '');
                $row['genere_nome'] = HtmlHelper::decode($row['genere_nome'] ?? '');
                $row['sottogenere_nome'] = HtmlHelper::decode($row['sottogenere_nome'] ?? '');
                $row['collocazione_nome'] = HtmlHelper::decode($row['collocazione_nome'] ?? 'N/D');
                $row['posizione_scaffale'] = HtmlHelper::decode($row['posizione_scaffale'] ?? 'N/D');
                $row['collocazione'] = HtmlHelper::decode($row['collocazione'] ?? '');
                $row['posizione_progressiva_val'] = (int)($row['posizione_progressiva_val'] ?? 0);

                // Ensure a valid cover URL is present (fallback to legacy 'copertina')
                $cover = (string)($row['copertina_url'] ?? '');
                if ($cover === '' && !empty($row['copertina'])) { $cover = (string)$row['copertina']; }
                if ($cover !== '' && strpos($cover, '/') !== 0 && strpos($cover, 'uploads/') === 0) {
                    $cover = '/' . $cover; // normalize missing leading slash
                }
                // Convert relative URLs to absolute for consistent display (same as catalog)
                if ($cover !== '' && strpos($cover, 'http') !== 0) {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
                    $cover = $protocol . '://' . $host . $cover;
                }
                $row['copertina_url'] = $cover;

                // Format publication year if available
                if (!empty($row['anno_pubblicazione'])) {
                    $row['anno_pubblicazione_formatted'] = (string)$row['anno_pubblicazione'];
                } else if (!empty($row['data_pubblicazione'])) {
                    $timestamp = strtotime($row['data_pubblicazione']);
                    if ($timestamp !== false) {
                        $row['anno_pubblicazione_formatted'] = date('Y', $timestamp);
                    }
                }

                // Create genre display string
                $genreDisplay = [];
                if (!empty($row['genere_nome'])) {
                    $genreDisplay[] = $row['genere_nome'];
                }
                if (!empty($row['sottogenere_nome'])) {
                    $genreDisplay[] = $row['sottogenere_nome'];
                }
                $row['genere_display'] = implode(' / ', $genreDisplay);

                // Use the already formatted position
                $row['posizione_display'] = $row['collocazione'] !== ''
                    ? $row['collocazione']
                    : ($row['posizione_scaffale'] ?? 'N/D');

                $data[] = $row;
            }
        }
        $stmt->close();

        $payload = [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data
        ];
        AppLog::debug('libri.list.result', ['total'=>$total, 'filtered'=>$filtered, 'rows'=>count($data)]);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getByAuthor(Request $request, Response $response, mysqli $db, int $authorId): Response
    {
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                INNER JOIN libri_autori la ON l.id = la.libro_id
                WHERE la.autore_id = ?
                ORDER BY l.titolo ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) {
            // Decode HTML entities
            $row['titolo'] = HtmlHelper::decode($row['titolo'] ?? '');
            $row['editore_nome'] = HtmlHelper::decode($row['editore_nome'] ?? '');
            $row['autori'] = HtmlHelper::decode($row['autori'] ?? '');
            $data[] = $row;
        }

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getByPublisher(Request $request, Response $response, mysqli $db, int $publisherId): Response
    {
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       (
                         SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                       ) AS autori
                FROM libri l
                INNER JOIN libri_autori la ON l.id = la.libro_id
                INNER JOIN autori a ON la.autore_id = a.id
                WHERE l.editore_id = ?
                GROUP BY l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato
                ORDER BY l.titolo ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $publisherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) {
            // Decode HTML entities
            $row['titolo'] = HtmlHelper::decode($row['titolo'] ?? '');
            $row['autori'] = HtmlHelper::decode($row['autori'] ?? '');
            $data[] = $row;
        }

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private ?array $colCache = null;
    private function hasColumn(mysqli $db, string $name): bool
    {
        if ($this->colCache === null) {
            $this->colCache = [];
            $stmt = $db->prepare('SHOW COLUMNS FROM libri');
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) { $this->colCache[$r['Field']] = true; }
                $stmt->close();
            }
        }
        return isset($this->colCache[$name]);
    }

    /**
     * Get books by genre for carousel display
     */
    public function byGenre(Request $request, Response $response, mysqli $db): Response
    {
        $genreId = (int)($request->getQueryParams()['genre_id'] ?? 0);
        $limit = min(20, (int)($request->getQueryParams()['limit'] ?? 15));

        if ($genreId <= 0) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('ID genere non valido')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Query per ottenere libri del genere
        $sql = "
            SELECT
                l.id,
                l.titolo,
                l.sottotitolo,
                l.immagine_copertina,
                l.anno_pubblicazione,
                l.stato,
                GROUP_CONCAT(DISTINCT CONCAT(a.nome, ' ', COALESCE(a.cognome, '')) SEPARATOR ', ') AS autori
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            WHERE l.genere_id = ?
            GROUP BY l.id
            ORDER BY l.created_at DESC
            LIMIT ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $genreId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($row = $result->fetch_assoc()) {
            $books[] = [
                'id' => (int)$row['id'],
                'titolo' => $row['titolo'],
                'sottotitolo' => $row['sottotitolo'],
                'immagine_copertina' => $row['immagine_copertina'],
                'anno_pubblicazione' => $row['anno_pubblicazione'],
                'stato' => $row['stato'],
                'autori' => $row['autori']
            ];
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'books' => $books,
            'count' => count($books)
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private array $tableColCache = [];
    private function hasTableColumn(mysqli $db, string $table, string $name): bool
    {
        // Whitelist di tabelle valide per prevenire SQL injection
        $validTables = ['libri', 'autori', 'libri_autori', 'editori', 'generi',
                        'utenti', 'prestiti', 'prenotazioni', 'posizioni', 'scaffali', 'mensole'];

        if (!in_array($table, $validTables, true)) {
            AppLog::warning('hasTableColumn.invalid_table', ['table' => $table]);
            return false;
        }

        if (!isset($this->tableColCache[$table])) {
            $this->tableColCache[$table] = [];
            // Usa query safe con INFORMATION_SCHEMA
            $stmt = $db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            if ($stmt) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $this->tableColCache[$table][$r['COLUMN_NAME']] = true;
                }
                $stmt->close();
            }
        }
        return isset($this->tableColCache[$table][$name]);
    }
}
