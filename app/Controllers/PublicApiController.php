<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\BookRepository;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PublicApiController
{
    /**
     * Search books by EAN, ISBN-13, ISBN-10, or Author
     *
     * Query parameters:
     * - ean: Search by EAN code
     * - isbn13: Search by ISBN-13
     * - isbn10: Search by ISBN-10
     * - author: Search by author name (partial match)
     *
     * Returns complete book data including author, publisher, loan status, reviews, etc.
     */
    public function searchBooks(Request $request, Response $response, mysqli $db): Response
    {
        $queryParams = $request->getQueryParams();

        $ean = isset($queryParams['ean']) ? trim((string)$queryParams['ean']) : null;
        $isbn13 = isset($queryParams['isbn13']) ? trim((string)$queryParams['isbn13']) : null;
        $isbn10 = isset($queryParams['isbn10']) ? trim((string)$queryParams['isbn10']) : null;
        $author = isset($queryParams['author']) ? trim((string)$queryParams['author']) : null;

        // Validate that at least one parameter is provided
        if ($ean === null && $isbn13 === null && $isbn10 === null && $author === null) {
            return $this->jsonError(__('At least one search parameter is required (ean, isbn13, isbn10, or author)'), 400, $response);
        }

        try {
            $books = $this->findBooks($db, $ean, $isbn13, $isbn10, $author);

            // Get API key data from request attributes (set by middleware)
            $apiKeyData = $request->getAttribute('api_key_data');

            return $this->jsonSuccess([
                'results' => $books,
                'count' => count($books),
                'api_key_name' => $apiKeyData['name'] ?? 'Unknown'
            ], $response);
        } catch (\Throwable $e) {
            error_log('Public API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->jsonError(__('Internal server error'), 500, $response);
        }
    }

    /**
     * Find books based on search criteria
     */
    private function findBooks(mysqli $db, ?string $ean, ?string $isbn13, ?string $isbn10, ?string $author): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        // Build WHERE conditions
        if ($ean !== null && $ean !== '') {
            $conditions[] = 'l.ean = ?';
            $params[] = $ean;
            $types .= 's';
        }

        if ($isbn13 !== null && $isbn13 !== '') {
            $conditions[] = 'l.isbn13 = ?';
            $params[] = $isbn13;
            $types .= 's';
        }

        if ($isbn10 !== null && $isbn10 !== '') {
            $conditions[] = 'l.isbn10 = ?';
            $params[] = $isbn10;
            $types .= 's';
        }

        if ($author !== null && $author !== '') {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM libri_autori la
                JOIN autori a ON la.autore_id = a.id
                WHERE la.libro_id = l.id
                AND a.nome LIKE ?
            )';
            $params[] = '%' . $author . '%';
            $types .= 's';
        }

        if (empty($conditions)) {
            return [];
        }

        $whereClause = '(' . implode(' OR ', $conditions) . ') AND l.deleted_at IS NULL';

        // Main query to get books with all related data
        $sql = "
            SELECT
                l.id,
                l.titolo,
                l.sottotitolo,
                l.isbn10,
                l.isbn13,
                l.ean,
                l.data_acquisizione,
                l.data_pubblicazione,
                l.tipo_acquisizione,
                l.copertina_url,
                l.descrizione,
                l.parole_chiave,
                l.formato,
                l.peso,
                l.dimensioni,
                l.prezzo,
                l.numero_pagine,
                l.traduttore,
                l.collana,
                l.numero_serie,
                l.copie_totali,
                l.copie_disponibili,
                l.numero_inventario,
                l.classificazione_dewey,
                l.note_varie,
                l.file_url,
                l.audio_url,
                l.collocazione,
                l.stato,
                l.created_at,
                l.updated_at,
                e.id AS editore_id,
                e.nome AS editore_nome,
                e.indirizzo AS editore_indirizzo,
                e.sito_web AS editore_sito_web,
                g.id AS genere_id,
                g.nome AS genere_nome,
                g.parent_id AS genere_parent_id,
                gp.nome AS genere_parent_nome
            FROM libri l
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN generi gp ON g.parent_id = gp.id
            WHERE {$whereClause}
            LIMIT 50
        ";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare statement: ' . $db->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($row = $result->fetch_assoc()) {
            $bookId = (int)$row['id'];

            // Get authors
            $authors = $this->getBookAuthors($db, $bookId);

            // Get loan status
            $loanStatus = $this->getBookLoanStatus($db, $bookId);

            // Get reviews
            $reviews = $this->getBookReviews($db, $bookId);

            // Get reservations count
            $reservationsCount = $this->getBookReservationsCount($db, $bookId);

            $books[] = [
                'id' => $bookId,
                'titolo' => $row['titolo'],
                'sottotitolo' => $row['sottotitolo'],
                'isbn10' => $row['isbn10'],
                'isbn13' => $row['isbn13'],
                'ean' => $row['ean'],
                'data_acquisizione' => $row['data_acquisizione'],
                'data_pubblicazione' => $row['data_pubblicazione'],
                'tipo_acquisizione' => $row['tipo_acquisizione'],
                'copertina_url' => $row['copertina_url'],
                'descrizione' => $row['descrizione'],
                'parole_chiave' => $row['parole_chiave'],
                'formato' => $row['formato'],
                'peso' => $row['peso'] !== null ? (float)$row['peso'] : null,
                'dimensioni' => $row['dimensioni'],
                'prezzo' => $row['prezzo'] !== null ? (float)$row['prezzo'] : null,
                'numero_pagine' => $row['numero_pagine'] !== null ? (int)$row['numero_pagine'] : null,
                'traduttore' => $row['traduttore'],
                'collana' => $row['collana'],
                'numero_serie' => $row['numero_serie'],
                'copie_totali' => (int)$row['copie_totali'],
                'copie_disponibili' => (int)$row['copie_disponibili'],
                'numero_inventario' => $row['numero_inventario'],
                'classificazione_dewey' => $row['classificazione_dewey'],
                'note_varie' => $row['note_varie'],
                'file_url' => $row['file_url'],
                'audio_url' => $row['audio_url'],
                'collocazione' => $row['collocazione'],
                'stato' => $row['stato'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'editore' => $row['editore_id'] !== null ? [
                    'id' => (int)$row['editore_id'],
                    'nome' => $row['editore_nome'],
                    'indirizzo' => $row['editore_indirizzo'],
                    'sito_web' => $row['editore_sito_web']
                ] : null,
                'genere' => $row['genere_id'] !== null ? [
                    'id' => (int)$row['genere_id'],
                    'nome' => $row['genere_nome'],
                    'parent_id' => $row['genere_parent_id'] !== null ? (int)$row['genere_parent_id'] : null,
                    'parent_nome' => $row['genere_parent_nome']
                ] : null,
                'autori' => $authors,
                'prestito' => $loanStatus,
                'recensioni' => $reviews,
                'prenotazioni_attive' => $reservationsCount
            ];
        }

        $stmt->close();

        return $books;
    }

    /**
     * Get authors for a book
     */
    private function getBookAuthors(mysqli $db, int $bookId): array
    {
        $sql = "
            SELECT a.id, a.nome, a.biografia, a.data_nascita, a.data_morte, a.nazionalita
            FROM libri_autori la
            JOIN autori a ON la.autore_id = a.id
            WHERE la.libro_id = ?
            ORDER BY la.ordine_credito, a.nome
        ";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $authors = [];
        while ($row = $result->fetch_assoc()) {
            $authors[] = [
                'id' => (int)$row['id'],
                'nome' => $row['nome'],
                'biografia' => $row['biografia'],
                'data_nascita' => $row['data_nascita'],
                'data_morte' => $row['data_morte'],
                'nazionalita' => $row['nazionalita']
            ];
        }

        $stmt->close();

        return $authors;
    }

    /**
     * Get current loan status for a book
     */
    private function getBookLoanStatus(mysqli $db, int $bookId): ?array
    {
        $sql = "
            SELECT
                p.id,
                p.data_prestito,
                p.data_scadenza,
                p.data_restituzione,
                p.stato,
                p.attivo,
                u.id AS utente_id,
                u.nome AS utente_nome,
                u.cognome AS utente_cognome,
                u.email AS utente_email
            FROM prestiti p
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.libro_id = ? AND p.attivo = 1
            ORDER BY p.data_prestito DESC
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'data_prestito' => $row['data_prestito'],
            'data_scadenza' => $row['data_scadenza'],
            'data_restituzione' => $row['data_restituzione'],
            'stato' => $row['stato'],
            'attivo' => (bool)$row['attivo'],
            'utente' => [
                'id' => (int)$row['utente_id'],
                'nome' => $row['utente_nome'],
                'cognome' => $row['utente_cognome'],
                'email' => $row['utente_email']
            ]
        ];
    }

    /**
     * Get reviews for a book
     */
    private function getBookReviews(mysqli $db, int $bookId): array
    {
        // Check if recensioni table exists
        $tableExists = $db->query("SHOW TABLES LIKE 'recensioni'");
        if ($tableExists === false || $tableExists->num_rows === 0) {
            return [];
        }

        $sql = "
            SELECT
                r.id,
                r.voto,
                r.testo,
                r.created_at,
                u.id AS utente_id,
                u.nome AS utente_nome,
                u.cognome AS utente_cognome
            FROM recensioni r
            JOIN utenti u ON r.utente_id = u.id
            WHERE r.libro_id = ?
            ORDER BY r.created_at DESC
        ";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = [
                'id' => (int)$row['id'],
                'voto' => $row['voto'] !== null ? (int)$row['voto'] : null,
                'testo' => $row['testo'],
                'created_at' => $row['created_at'],
                'utente' => [
                    'id' => (int)$row['utente_id'],
                    'nome' => $row['utente_nome'],
                    'cognome' => $row['utente_cognome']
                ]
            ];
        }

        $stmt->close();

        return $reviews;
    }

    /**
     * Get active reservations count for a book
     */
    private function getBookReservationsCount(mysqli $db, int $bookId): int
    {
        $sql = "SELECT COUNT(*) as count FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Return JSON success response
     */
    private function jsonSuccess(array $data, Response $response): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200);
    }

    /**
     * Return JSON error response
     */
    private function jsonError(string $message, int $statusCode, Response $response): Response
    {
        $response->getBody()->write(json_encode([
            'error' => $message,
            'status' => $statusCode
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($statusCode);
    }
}
