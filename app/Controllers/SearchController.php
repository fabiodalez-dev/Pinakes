<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\HtmlHelper;

class SearchController
{
    public function authors(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows = [];
        if ($q !== '') {
            // Split query into words — each word must match (AND logic)
            $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            $conditions = [];
            $params = [];
            $types = '';
            foreach ($words as $word) {
                $conditions[] = 'nome LIKE ?';
                $params[] = '%' . $word . '%';
                $types .= 's';
            }
            $sql = "SELECT id, nome AS label FROM autori WHERE " . implode(' AND ', $conditions) . " ORDER BY nome";
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        } else {
            // Return all authors (for Choices.js initial load)
            $res = $db->query("SELECT id, nome AS label FROM autori ORDER BY nome");
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function publishers(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows = [];
        if ($q !== '') {
            // Split query into words — each word must match (AND logic)
            $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            $conditions = [];
            $params = [];
            $types = '';
            foreach ($words as $word) {
                $conditions[] = 'nome LIKE ?';
                $params[] = '%' . $word . '%';
                $types .= 's';
            }
            $sql = "SELECT id, nome AS label FROM editori WHERE " . implode(' AND ', $conditions) . " ORDER BY nome LIMIT 20";
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function users(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows=[];
        if ($q !== '') {
            $s = '%'.$q.'%';
            $stmt = $db->prepare("
                SELECT id,
                       CONCAT(
                           nome, ' ', cognome,
                           CASE
                               WHEN codice_tessera IS NOT NULL AND codice_tessera <> '' THEN CONCAT(' (Tessera: ', codice_tessera, ')')
                               ELSE ''
                           END
                       ) AS label
                FROM utenti
                WHERE nome LIKE ?
                   OR cognome LIKE ?
                   OR telefono LIKE ?
                   OR email LIKE ?
                   OR codice_tessera LIKE ?
                ORDER BY cognome, nome
                LIMIT 20
            ");
            $stmt->bind_param('sssss', $s, $s, $s, $s, $s);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        }
        
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function books(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows=[];
        if ($q !== '') {
            $s = '%'.$q.'%';
            // Search by title, subtitle, ISBN10, ISBN13, or EAN
            $stmt = $db->prepare("
                SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.stato,
                       (SELECT GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ')
                        FROM libri_autori la
                        JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id) AS autori,
                       l.copie_disponibili,
                       l.copie_totali
                FROM libri l
                WHERE l.deleted_at IS NULL AND (l.titolo LIKE ? OR l.sottotitolo LIKE ? OR l.isbn10 LIKE ? OR l.isbn13 LIKE ? OR l.ean LIKE ?)
                ORDER BY l.titolo LIMIT 20
            ");
            $stmt->bind_param('sssss', $s, $s, $s, $s, $s);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $titolo = HtmlHelper::decode($r['titolo']);
                $autori = !empty($r['autori']) ? HtmlHelper::decode($r['autori']) : '';

                // Build label with author
                $label = $titolo;
                if ($autori) {
                    $label .= ' - ' . $autori;
                }

                // Add ISBN/EAN info
                $isbn = '';
                if (!empty($r['isbn13'])) {
                    $isbn = $r['isbn13'];
                } elseif (!empty($r['isbn10'])) {
                    $isbn = $r['isbn10'];
                } elseif (!empty($r['ean'])) {
                    $isbn = $r['ean'];
                }

                $rows[] = [
                    'id' => $r['id'],
                    'label' => $label,
                    'isbn' => $isbn,
                    'copie_disponibili' => (int)$r['copie_disponibili'],
                    'copie_totali' => (int)$r['copie_totali'],
                    'stato' => $r['stato']
                ];
            }
        }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function unifiedSearch(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $results = [];

        if ($q !== '') {
            // Search books by ISBN, EAN, title, subtitle
            $bookResults = $this->searchBooks($db, $q);
            $results = array_merge($results, $bookResults);

            // Search authors
            $authorResults = $this->searchAuthors($db, $q);
            $results = array_merge($results, $authorResults);

            // Search publishers
            $publisherResults = $this->searchPublishers($db, $q);
            $results = array_merge($results, $publisherResults);

            // Note: User search is excluded from frontend unified search to keep admin data separate.
        }

        // Limit to 20 results total
        $results = array_slice($results, 0, 20);

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function searchPreview(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $results = [];

        if ($q !== '') {
            // Search books with full details for preview
            $bookResults = $this->searchBooksWithDetails($db, $q);
            $results = array_merge($results, $bookResults);

            // Search authors with details
            $authorResults = $this->searchAuthorsWithDetails($db, $q);
            $results = array_merge($results, $authorResults);

            // Search publishers with details
            $publisherResults = $this->searchPublishersWithDetails($db, $q);
            $results = array_merge($results, $publisherResults);
        }

        // Limit to 15 results total
        $results = array_slice($results, 0, 15);

        $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    private function searchBooks(mysqli $db, string $query): array
    {
        $results = [];
        $s = '%'.$query.'%';

        // Search by ISBN, EAN, title, subtitle - include author via subquery
        $stmt = $db->prepare("
            SELECT l.id, l.titolo AS label, l.isbn10, l.isbn13, l.ean,
                   (SELECT GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ')
                    FROM libri_autori la
                    JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id) AS autori
            FROM libri l
            WHERE l.deleted_at IS NULL AND (l.isbn10 LIKE ? OR l.isbn13 LIKE ? OR l.ean LIKE ? OR l.titolo LIKE ? OR l.sottotitolo LIKE ?)
            ORDER BY l.titolo LIMIT 10
        ");
        $stmt->bind_param('sssss', $s, $s, $s, $s, $s);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $label = HtmlHelper::decode($row['label']);
            $identifier = '';

            // Show author if available
            if (!empty($row['autori'])) {
                $identifier = HtmlHelper::decode($row['autori']);
            }

            // Add ISBN/EAN as secondary info
            $isbn = '';
            if (!empty($row['isbn13'])) {
                $isbn = 'ISBN: ' . $row['isbn13'];
            } elseif (!empty($row['isbn10'])) {
                $isbn = 'ISBN: ' . $row['isbn10'];
            } elseif (!empty($row['ean'])) {
                $isbn = 'EAN: ' . $row['ean'];
            }

            $results[] = [
                'id' => $row['id'],
                'label' => $label,
                'identifier' => $identifier,
                'isbn' => $isbn,
                'type' => 'book',
                'url' => url('/admin/libri/' . (int)$row['id'])
            ];
        }

        return $results;
    }
    
    private function searchAuthors(mysqli $db, string $query): array
    {
        $results = [];
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $conditions = [];
        $params = [];
        $types = '';
        foreach ($words as $word) {
            $conditions[] = 'nome LIKE ?';
            $params[] = '%' . $word . '%';
            $types .= 's';
        }
        $sql = "SELECT id, nome AS label FROM autori WHERE " . implode(' AND ', $conditions) . " ORDER BY nome LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $results[] = [
                'id' => $row['id'],
                'label' => HtmlHelper::decode($row['label']),
                'type' => 'author',
                'url' => url('/admin/autori/' . (int)$row['id'])
            ];
        }
        
        return $results;
    }
    
    private function searchPublishers(mysqli $db, string $query): array
    {
        $results = [];
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $conditions = [];
        $params = [];
        $types = '';
        foreach ($words as $word) {
            $conditions[] = 'nome LIKE ?';
            $params[] = '%' . $word . '%';
            $types .= 's';
        }
        $sql = "SELECT id, nome AS label FROM editori WHERE " . implode(' AND ', $conditions) . " ORDER BY nome LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $results[] = [
                'id' => $row['id'],
                'label' => HtmlHelper::decode($row['label']),
                'type' => 'publisher',
                'url' => url('/admin/editori/' . (int)$row['id'])
            ];
        }
        
        return $results;
    }

    private function searchBooksWithDetails(mysqli $db, string $query): array
    {
        $results = [];
        $s = '%'.$query.'%';

        // Search books with author and cover details for preview
        // Include books where the title, subtitle, ISBN, EAN, OR author name matches
        $stmt = $db->prepare("
            SELECT DISTINCT l.id, l.titolo, l.copertina_url, l.anno_pubblicazione,
                   (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                    WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore_principale
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            WHERE l.deleted_at IS NULL AND (l.titolo LIKE ? OR l.sottotitolo LIKE ? OR l.isbn10 LIKE ? OR l.isbn13 LIKE ? OR l.ean LIKE ? OR a.nome LIKE ?)
            ORDER BY l.titolo LIMIT 8
        ");
        $stmt->bind_param('ssssss', $s, $s, $s, $s, $s, $s);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $coverUrl = trim((string)($row['copertina_url'] ?? ''));
            if ($coverUrl === '') {
                $coverUrl = '/uploads/copertine/placeholder.jpg';
            }
            $absoluteCoverUrl = absoluteUrl($coverUrl);

            $results[] = [
                'id' => $row['id'],
                'title' => HtmlHelper::decode($row['titolo']),
                'author' => HtmlHelper::decode($row['autore_principale'] ?? ''),
                'year' => $row['anno_pubblicazione'],
                'cover' => $absoluteCoverUrl,
                'type' => 'book',
                'url' => book_url([
                    'id' => $row['id'],
                    'titolo' => $row['titolo'],
                    'autore_principale' => $row['autore_principale'] ?? ''
                ])
            ];
        }

        return $results;
    }

    private function searchAuthorsWithDetails(mysqli $db, string $query): array
    {
        $results = [];
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $conditions = [];
        $params = [];
        $types = '';
        foreach ($words as $word) {
            $conditions[] = 'a.nome LIKE ?';
            $params[] = '%' . $word . '%';
            $types .= 's';
        }

        $sql = "
            SELECT a.id, a.nome, a.biografia,
                   (SELECT COUNT(*) FROM libri_autori la2 JOIN libri l2 ON la2.libro_id = l2.id WHERE la2.autore_id = a.id AND l2.deleted_at IS NULL) as libro_count
            FROM autori a
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY a.nome LIMIT 4
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $biografia = $row['biografia'] ? substr(strip_tags(HtmlHelper::decode($row['biografia'])), 0, 100) . '...' : '';

            $results[] = [
                'id' => $row['id'],
                'name' => HtmlHelper::decode($row['nome']),
                'biography' => $biografia,
                'book_count' => (int)$row['libro_count'],
                'type' => 'author',
                'url' => route_path('author') . '/' . (int)$row['id']
            ];
        }

        return $results;
    }

    private function searchPublishersWithDetails(mysqli $db, string $query): array
    {
        $results = [];
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $conditions = [];
        $params = [];
        $types = '';
        foreach ($words as $word) {
            $conditions[] = 'e.nome LIKE ?';
            $params[] = '%' . $word . '%';
            $types .= 's';
        }

        $sql = "
            SELECT e.id, e.nome, e.indirizzo,
                   (SELECT COUNT(*) FROM libri l2 WHERE l2.editore_id = e.id AND l2.deleted_at IS NULL) as libro_count
            FROM editori e
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY e.nome LIMIT 3
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $indirizzo = $row['indirizzo'] ? substr(strip_tags(HtmlHelper::decode($row['indirizzo'])), 0, 100) . '...' : '';

            $results[] = [
                'id' => $row['id'],
                'name' => HtmlHelper::decode($row['nome']),
                'description' => $indirizzo,
                'book_count' => (int)$row['libro_count'],
                'type' => 'publisher',
                'url' => route_path('publisher') . '/' . (int)$row['id']
            ];
        }

        return $results;
    }

    public function genres(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows=[];
        if ($q !== '') {
            $s = '%'.$q.'%';
            $stmt = $db->prepare("SELECT id, nome AS label FROM generi WHERE nome LIKE ? ORDER BY nome LIMIT 20");
            $stmt->bind_param('s', $s);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function locations(Request $request, Response $response, mysqli $db): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $rows = [];
        if ($q !== '') {
            $s = '%' . $q . '%';
            $stmt = $db->prepare("
                SELECT p.id,
                       CONCAT(
                           COALESCE(NULLIF(s.codice, ''), s.nome, ''),
                           ' - Liv. ',
                           m.numero_livello
                       ) AS label
                FROM posizioni p
                JOIN scaffali s ON p.scaffale_id = s.id
                JOIN mensole m ON p.mensola_id = m.id
                WHERE s.nome LIKE ? OR s.codice LIKE ? OR CAST(m.numero_livello AS CHAR) LIKE ?
                ORDER BY s.ordine, m.numero_livello
                LIMIT 20
            ");
            $stmt->bind_param('sss', $s, $s, $s);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $r['label'] = HtmlHelper::decode($r['label']);
                $rows[] = $r;
            }
        }
        $response->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
