<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

class CsvImportController
{
    /**
     * Mostra la pagina di import CSV
     */
    public function showImportPage(Request $request, Response $response): Response
    {
        ob_start();
        $title = "Import Libri da CSV";
        include __DIR__ . '/../Views/admin/csv_import.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Genera e scarica un CSV di esempio
     */
    public function downloadExample(Request $request, Response $response): Response
    {
        $csvData = $this->generateExampleCsv();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvData);
        rewind($stream);

        $response = $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="esempio_import_libri.csv"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');

        return $response->withBody(new Stream($stream));
    }

    /**
     * Processa l'upload del CSV
     */
    public function processImport(Request $request, Response $response, \mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();

        // CSRF validated by CsrfMiddleware

        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['csv_file'])) {
            $_SESSION['error'] = __('Nessun file caricato');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        $uploadedFile = $uploadedFiles['csv_file'];

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = __('Errore nel caricamento del file');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        // Validazione estensione file
        $filename = $uploadedFile->getClientFilename();
        if (!str_ends_with(strtolower($filename), '.csv')) {
            $_SESSION['error'] = __('Il file deve avere estensione .csv');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        // Validazione MIME type (check multipli)
        $allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $mimeType = $uploadedFile->getClientMediaType();
        if (!in_array($mimeType, $allowedMimes, true)) {
            $_SESSION['error'] = __('Tipo MIME non valido. Solo file CSV sono accettati.');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        $tmpFile = $uploadedFile->getStream()->getMetadata('uri');

        // Validazione contenuto CSV: verifica che contenga separatori validi
        $handle = fopen($tmpFile, 'r');
        if ($handle === false) {
            $_SESSION['error'] = __('Impossibile leggere il file caricato');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        // Leggi prime 3 righe per validare formato
        $firstLines = [];
        for ($i = 0; $i < 3 && !feof($handle); $i++) {
            $firstLines[] = fgets($handle);
        }
        fclose($handle);

        // Verifica che almeno una riga contenga il separatore CSV (;)
        $delimiter = $this->detectDelimiterFromSample($firstLines);
        if ($delimiter === null) {
            $_SESSION['error'] = __('File CSV non valido: usa ";" o "," come separatore.');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }
        $enableScraping = !empty($data['enable_scraping']);

        // Initialize progress tracking
        $_SESSION['import_progress'] = [
            'status' => 'processing',
            'current' => 0,
            'total' => 0,
            'current_book' => ''
        ];

        $isAjax = !empty($request->getHeaderLine('X-Requested-With')) &&
            $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

        try {
            $result = $this->importCsvData($tmpFile, $db, $enableScraping, $delimiter);

            $_SESSION['import_progress'] = [
                'status' => 'completed',
                'current' => $result['imported'],
                'total' => $result['imported']
            ];

            $message = sprintf(
                __('Import completato: %d libri nuovi, %d libri aggiornati, %d autori creati, %d editori creati'),
                $result['imported'],
                $result['updated'],
                $result['authors_created'],
                $result['publishers_created']
            );

            if ($enableScraping && isset($result['scraped'])) {
                $message .= sprintf(__(', %d libri arricchiti con scraping'), $result['scraped']);
            }

            if (!empty($result['errors'])) {
                $message .= sprintf(__(', %d errori'), count($result['errors']));
            }

            $_SESSION['success'] = $message;

            if (!empty($result['errors'])) {
                $_SESSION['import_errors'] = $result['errors'];
            }

            // Return JSON for AJAX requests
            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'redirect' => '/admin/libri/import',
                    'message' => $message
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = sprintf(__('Errore durante l\'import: %s'), $e->getMessage());
            $_SESSION['import_progress'] = ['status' => 'error'];

            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        }

        return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
    }

    /**
     * Genera CSV di esempio con intestazioni e dati di esempio
     *
     * Nota: Per autori multipli, usa il separatore | (pipe)
     * Esempio: "Umberto Eco|Federico Fellini" per due autori
     */
    private function generateExampleCsv(): string
    {
        $headers = [
            'id',
            'isbn10',
            'isbn13',
            'ean',
            'titolo',
            'sottotitolo',
            'autori',
            'editore',
            'anno_pubblicazione',
            'lingua',
            'edizione',
            'numero_pagine',
            'genere',
            'descrizione',
            'formato',
            'prezzo',
            'copie_totali',
            'collana',
            'numero_serie',
            'traduttore',
            'parole_chiave'
        ];

        $examples = [
            [
                '',  // id vuoto per nuovo libro
                '8804562625',
                '9788804562627',
                '9788804562627',
                'Il nome della rosa',
                'Un romanzo storico',
                'Umberto Eco',
                'Mondadori',
                '1980',
                'italiano',
                'Prima edizione',
                '503',
                'Narrativa',
                'Un romanzo ambientato in un monastero medievale dove avvengono misteriosi omicidi',
                'cartaceo',
                '12.50',
                '2',
                'Oscar Bestsellers',
                '1',
                '',
                'medioevo, giallo, monastero'
            ],
            [
                '',  // id vuoto per nuovo libro
                '',
                '9788806234515',
                '',
                '1984',
                '',
                'George Orwell',
                'Einaudi',
                '1949',
                'italiano',
                '',
                '328',
                'Narrativa',
                'Un classico della letteratura distopica',
                'cartaceo',
                '11.00',
                '1',
                '',
                '',
                'Gabriele Baldini',
                'distopia, controllo, totalitarismo'
            ],
            [
                '',  // id vuoto per nuovo libro
                '',
                '',
                '',
                'La Divina Commedia',
                'Inferno, Purgatorio, Paradiso',
                'Dante Alighieri',
                'Rizzoli',
                '1321',
                'italiano',
                'Edizione integrale',
                '768',
                'Classici',
                'Il capolavoro di Dante Alighieri',
                'cartaceo',
                '15.00',
                '3',
                'BUR Classici',
                '',
                '',
                'dante, medioevo, poesia, inferno, paradiso'
            ]
        ];

        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        $output .= implode(';', $headers) . "\n";

        foreach ($examples as $example) {
            $output .= implode(';', array_map(function ($field) {
                // Prevent CSV injection by prefixing dangerous characters with a single quote
                // Fix: escape the dash to avoid creating a character range that includes digits
                if (preg_match('/^[=+\-@].*/', $field)) {
                    $field = "'" . $field;
                }

                // Escape fields with semicolons, quotes, newlines, or commas
                // Commas must be quoted to prevent Excel/LibreOffice from mis-detecting the delimiter
                if (strpos($field, ';') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false || strpos($field, ',') !== false) {
                    return '"' . str_replace('"', '""', $field) . '"';
                }
                return $field;
            }, $example)) . "\n";
        }

        return $output;
    }

    /**
     * Get import progress
     */
    public function getProgress(Request $request, Response $response): Response
    {
        $progress = $_SESSION['import_progress'] ?? [
            'status' => 'idle',
            'current' => 0,
            'total' => 0,
            'current_book' => ''
        ];

        $response->getBody()->write(json_encode($progress));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Detect delimiter from sample lines
     */
    private function detectDelimiterFromSample(array $lines): ?string
    {
        $candidates = [';' => 0, ',' => 0];

        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            foreach ($candidates as $delimiter => $count) {
                $candidates[$delimiter] += substr_count($line, $delimiter);
            }
        }

        arsort($candidates);
        $bestDelimiter = array_key_first($candidates);

        if ($bestDelimiter === null || $candidates[$bestDelimiter] === 0) {
            return null;
        }

        return $bestDelimiter;
    }

    /**
     * Importa i dati dal CSV
     */
    private function importCsvData(string $filePath, \mysqli $db, bool $enableScraping = false, string $delimiter = ';'): array
    {
        $delimiter = $delimiter !== '' ? $delimiter : ';';
        $imported = 0;
        $updated = 0;
        $authorsCreated = 0;
        $publishersCreated = 0;
        $scraped = 0;
        $errors = [];

        // Scraping limits to prevent timeout
        $maxScrapingTime = 300; // 5 minutes max for scraping operations
        $maxScrapeItems = 50;   // Max 50 books with scraping enabled
        $scrapingStartTime = time();

        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new \Exception(__('Impossibile aprire il file CSV'));
        }

        // Skip BOM if present
        $bom = fread($file, 3);
        $hasBom = ($bom === "\xEF\xBB\xBF");
        if (!$hasBom) {
            rewind($file);
        }

        // Leggi intestazioni
        $headers = fgetcsv($file, 0, $delimiter, '"');
        if (!$headers) {
            fclose($file);
            throw new \Exception(__('File CSV vuoto o formato non valido'));
        }

        // Count total rows for progress tracking
        $totalRows = 0;
        while (fgetcsv($file, 0, $delimiter, '"') !== false) {
            $totalRows++;
        }

        rewind($file);
        if ($hasBom) {
            fread($file, 3);
        }
        fgetcsv($file, 0, $delimiter, '"'); // Skip headers

        // Update progress with total
        $_SESSION['import_progress']['total'] = $totalRows;

        $lineNumber = 1;
        $rowCount = 0;
        $maxRows = 10000; // Limit to prevent DoS

        while (($row = fgetcsv($file, 0, $delimiter, '"')) !== false) {
            $lineNumber++;
            $rowCount++;

            // Prevent DoS from extremely large CSV files
            if ($rowCount > $maxRows) {
                $errors[] = sprintf(__('Numero massimo di righe superato (%d). Dividi il file in parti più piccole.'), $maxRows);
                break;
            }

            if (count($row) !== count($headers)) {
                $errors[] = sprintf(__('Riga %d: numero di colonne non corrispondente'), $lineNumber);
                continue;
            }

            $data = array_combine($headers, $row);

            // Sanitize data to prevent CSV injection
            $data = $this->sanitizeCsvData($data);

            try {
                $db->begin_transaction();

                // Verifica campi obbligatori
                if (empty($data['titolo'])) {
                    throw new \Exception(__('Titolo obbligatorio mancante'));
                }

                // Gestione editore
                $editorId = null;
                if (!empty($data['editore'])) {
                    $editorId = $this->getOrCreatePublisher($db, trim($data['editore']));
                    if ($editorId === 'created') {
                        $publishersCreated++;
                        $editorId = $db->insert_id;
                    }
                }

                // Gestione genere
                $genreId = $this->getGenreId($db, $data['genere'] ?? '');

                // Upsert libro (UPDATE se esiste, INSERT altrimenti)
                $upsertResult = $this->upsertBook($db, $data, $editorId, $genreId);
                $bookId = $upsertResult['id'];
                $action = $upsertResult['action'];

                // Se è un UPDATE, rimuovi vecchi collegamenti autori
                if ($action === 'updated') {
                    $stmt = $db->prepare("DELETE FROM libri_autori WHERE libro_id = ?");
                    $stmt->bind_param('i', $bookId);
                    $stmt->execute();
                    $stmt->close();
                }

                // Gestione autori (possono essere multipli separati da ; o | per retrocompatibilità)
                if (!empty($data['autori'])) {
                    // Usa ; come separatore principale (usato dall'export), fallback su | per vecchi CSV
                    $separator = strpos($data['autori'], ';') !== false ? ';' : '|';
                    $authors = array_map('trim', explode($separator, $data['autori']));
                    $authorOrder = 1;

                    foreach ($authors as $authorName) {
                        if (empty($authorName))
                            continue;

                        $authorId = $this->getOrCreateAuthor($db, $authorName);
                        if ($authorId === 'created') {
                            $authorsCreated++;
                            $authorId = $db->insert_id;
                        }

                        // Collega autore al libro
                        $stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', ?)");
                        $stmt->bind_param('iii', $bookId, $authorId, $authorOrder);
                        $stmt->execute();
                        $authorOrder++;
                    }
                }

                $db->commit();

                // Aggiorna contatori
                if ($action === 'created') {
                    $imported++;
                } else {
                    $updated++;
                }

                // Update progress
                $_SESSION['import_progress']['current'] = $imported;
                $_SESSION['import_progress']['current_book'] = $data['titolo'];

                // Try scraping if enabled and book has ISBN
                if ($enableScraping && !empty($data['isbn13']) && $scraped < $maxScrapeItems) {
                    // Check timeout before scraping
                    if (time() - $scrapingStartTime > $maxScrapingTime) {
                        $errors[] = sprintf(__('Scraping interrotto: timeout di %d secondi raggiunto. Importati %d libri con scraping.'), $maxScrapingTime, $scraped);
                        $enableScraping = false; // Disable for remaining books
                    } else {
                        // Always try scraping when enabled - enrichBookWithScrapedData will only add missing data
                        // without overwriting CSV data (CSV has priority)
                        try {
                            error_log("[CSV Import] Attempting scraping for ISBN {$data['isbn13']}, book ID $bookId");
                            $scrapedData = $this->scrapeBookData($data['isbn13']);

                            if (!empty($scrapedData)) {
                                error_log("[CSV Import] Scraped data received: " . json_encode(array_keys($scrapedData)));
                                $this->enrichBookWithScrapedData($db, $bookId, $data, $scrapedData);
                                $scraped++;
                            } else {
                                error_log("[CSV Import] No scraped data returned for ISBN {$data['isbn13']}");
                            }

                            // Rate limiting: wait 3 seconds between scraping requests
                            sleep(3);
                        } catch (\Exception $scrapeError) {
                            // Log but don't fail the import
                            error_log("[CSV Import] Scraping failed for ISBN {$data['isbn13']}: " . $scrapeError->getMessage());
                        }
                    }
                }

            } catch (\Exception $e) {
                $db->rollback();
                $title = $data['titolo'] ?? '';
                $errors[] = sprintf(__('Riga %d (%s): %s'), $lineNumber, $title, $e->getMessage());
            }
        }

        fclose($file);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'authors_created' => $authorsCreated,
            'publishers_created' => $publishersCreated,
            'scraped' => $scraped,
            'errors' => $errors
        ];
    }

    /**
     * Ottieni o crea editore
     */
    private function getOrCreatePublisher(\mysqli $db, string $name): int|string
    {
        $stmt = $db->prepare("SELECT id FROM editori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return (int) $row['id'];
        }

        // Crea nuovo editore
        $stmt = $db->prepare("INSERT INTO editori (nome, created_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $name);
        $stmt->execute();

        return 'created';
    }

    /**
     * Ottieni o crea autore
     */
    private function getOrCreateAuthor(\mysqli $db, string $name): int|string
    {
        $stmt = $db->prepare("SELECT id FROM autori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return (int) $row['id'];
        }

        // Crea nuovo autore
        $stmt = $db->prepare("INSERT INTO autori (nome, created_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $name);
        $stmt->execute();

        return 'created';
    }

    /**
     * Ottieni ID genere
     */
    private function getGenreId(\mysqli $db, string $name): ?int
    {
        if (empty($name))
            return null;

        $stmt = $db->prepare("SELECT id FROM generi WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return (int) $row['id'];
        }

        return null;
    }

    /**
     * Trova libro esistente usando strategia a cascata:
     * 1. Per ID (se presente nel CSV)
     * 2. Per ISBN13 (se presente)
     * 3. Per EAN (se presente)
     *
     * @return int|null ID del libro esistente o null se non trovato
     */
    private function findExistingBook(\mysqli $db, array $data): ?int
    {
        // Strategia 1: Cerca per ID
        if (!empty($data['id']) && is_numeric($data['id'])) {
            $stmt = $db->prepare("SELECT id FROM libri WHERE id = ? LIMIT 1");
            $id = (int) $data['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (int) $row['id'];
            }
            $stmt->close();
        }

        // Strategia 2: Cerca per ISBN13
        if (!empty($data['isbn13'])) {
            $stmt = $db->prepare("SELECT id FROM libri WHERE isbn13 = ? LIMIT 1");
            $stmt->bind_param('s', $data['isbn13']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (int) $row['id'];
            }
            $stmt->close();
        }

        // Strategia 3: Cerca per EAN
        if (!empty($data['ean'])) {
            $stmt = $db->prepare("SELECT id FROM libri WHERE ean = ? LIMIT 1");
            $stmt->bind_param('s', $data['ean']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return (int) $row['id'];
            }
            $stmt->close();
        }

        return null;
    }

    /**
     * Aggiorna libro esistente (NON modifica copie_totali/copie_disponibili)
     */
    private function updateBook(\mysqli $db, int $bookId, array $data, ?int $editorId, ?int $genreId): void
    {
        $stmt = $db->prepare("
            UPDATE libri SET
                isbn10 = ?,
                isbn13 = ?,
                ean = ?,
                titolo = ?,
                sottotitolo = ?,
                anno_pubblicazione = ?,
                lingua = ?,
                edizione = ?,
                numero_pagine = ?,
                genere_id = ?,
                descrizione = ?,
                formato = ?,
                prezzo = ?,
                editore_id = ?,
                collana = ?,
                numero_serie = ?,
                traduttore = ?,
                parole_chiave = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $isbn10 = !empty($data['isbn10']) ? $data['isbn10'] : null;
        $isbn13 = !empty($data['isbn13']) ? $data['isbn13'] : null;
        $ean = !empty($data['ean']) ? $data['ean'] : null;
        $titolo = $data['titolo'];
        $sottotitolo = !empty($data['sottotitolo']) ? $data['sottotitolo'] : null;
        $anno = !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null;
        $lingua = !empty($data['lingua']) ? $data['lingua'] : 'italiano';
        $edizione = !empty($data['edizione']) ? $data['edizione'] : null;
        $pagine = !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null;
        $descrizione = !empty($data['descrizione']) ? $data['descrizione'] : null;
        $formato = !empty($data['formato']) ? $data['formato'] : 'cartaceo';
        $prezzo = !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null;
        $collana = !empty($data['collana']) ? $data['collana'] : null;
        $numeroSerie = !empty($data['numero_serie']) ? $data['numero_serie'] : null;
        $traduttore = !empty($data['traduttore']) ? $data['traduttore'] : null;
        $paroleChiave = !empty($data['parole_chiave']) ? $data['parole_chiave'] : null;

        $stmt->bind_param(
            'sssssississdisssssi',
            $isbn10,
            $isbn13,
            $ean,
            $titolo,
            $sottotitolo,
            $anno,
            $lingua,
            $edizione,
            $pagine,
            $genreId,
            $descrizione,
            $formato,
            $prezzo,
            $editorId,
            $collana,
            $numeroSerie,
            $traduttore,
            $paroleChiave,
            $bookId
        );

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Upsert: UPDATE se libro esiste (per ID/ISBN/EAN), altrimenti INSERT
     *
     * @return array ['id' => int, 'action' => 'created'|'updated']
     */
    private function upsertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): array
    {
        $existingBookId = $this->findExistingBook($db, $data);

        if ($existingBookId !== null) {
            // UPDATE libro esistente
            $this->updateBook($db, $existingBookId, $data, $editorId, $genreId);
            return ['id' => $existingBookId, 'action' => 'updated'];
        } else {
            // INSERT nuovo libro
            $newBookId = $this->insertBook($db, $data, $editorId, $genreId);
            return ['id' => $newBookId, 'action' => 'created'];
        }
    }

    /**
     * Inserisci libro e crea copie fisiche
     */
    private function insertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): int
    {
        $stmt = $db->prepare("
            INSERT INTO libri (
                isbn10, isbn13, ean, titolo, sottotitolo, anno_pubblicazione,
                lingua, edizione, numero_pagine, genere_id,
                descrizione, formato, prezzo, copie_totali, copie_disponibili,
                editore_id, collana, numero_serie, traduttore, parole_chiave,
                stato, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                'disponibile', NOW()
            )
        ");

        $isbn10 = !empty($data['isbn10']) ? $data['isbn10'] : null;
        $isbn13 = !empty($data['isbn13']) ? $data['isbn13'] : null;
        $ean = !empty($data['ean']) ? $data['ean'] : null;
        $titolo = $data['titolo'];
        $sottotitolo = !empty($data['sottotitolo']) ? $data['sottotitolo'] : null;
        $anno = !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null;
        $lingua = !empty($data['lingua']) ? $data['lingua'] : 'italiano';
        $edizione = !empty($data['edizione']) ? $data['edizione'] : null;
        $pagine = !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null;
        $descrizione = !empty($data['descrizione']) ? $data['descrizione'] : null;
        $formato = !empty($data['formato']) ? $data['formato'] : 'cartaceo';
        $prezzo = !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null;
        $copie = !empty($data['copie_totali']) ? (int) $data['copie_totali'] : 1;
        // Add bounds checking to prevent DoS attacks
        if ($copie < 1) {
            $copie = 1;
        } elseif ($copie > 100) {
            $copie = 100;  // Max 100 copie per libro da CSV import
        }
        $collana = !empty($data['collana']) ? $data['collana'] : null;
        $numeroSerie = !empty($data['numero_serie']) ? $data['numero_serie'] : null;
        $traduttore = !empty($data['traduttore']) ? $data['traduttore'] : null;
        $paroleChiave = !empty($data['parole_chiave']) ? $data['parole_chiave'] : null;

        $stmt->bind_param(
            'sssssississdiiisssss',
            $isbn10,
            $isbn13,
            $ean,
            $titolo,
            $sottotitolo,
            $anno,
            $lingua,
            $edizione,
            $pagine,
            $genreId,
            $descrizione,
            $formato,
            $prezzo,
            $copie,
            $copie,
            $editorId,
            $collana,
            $numeroSerie,
            $traduttore,
            $paroleChiave
        );

        $stmt->execute();
        $bookId = $db->insert_id;

        // Genera copie fisiche nella tabella copie
        $copyRepo = new \App\Models\CopyRepository($db);

        // Genera numero inventario base (usa ISBN se disponibile, altrimenti LIB-{id})
        $baseInventario = $isbn13 ?: ($isbn10 ?: "LIB-{$bookId}");

        for ($i = 1; $i <= $copie; $i++) {
            $numeroInventario = $copie > 1
                ? "{$baseInventario}-C{$i}"
                : $baseInventario;

            $note = $copie > 1 ? sprintf(__("Copia %d di %d"), $i, $copie) : null;

            $copyRepo->create($bookId, $numeroInventario, 'disponibile', $note);
        }

        // Ricalcola disponibilità dopo aver creato le copie
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($bookId);

        return $bookId;
    }

    /**
     * Sanitize CSV data to prevent formula injection
     */
    private function sanitizeCsvData(array $data): array
    {
        foreach ($data as $key => $value) {
            // Prevent CSV injection by prefixing dangerous characters with a single quote
            // Fix: escape the dash to avoid creating a character range that includes digits
            if (is_string($value) && preg_match('/^[=+\-@].*/', $value)) {
                $data[$key] = "'" . $value;
            }
        }
        return $data;
    }

    /**
     * Scrape book data from online services
     */
    private function scrapeBookData(string $isbn): array
    {
        $scrapeController = new \App\Controllers\ScrapeController();
        $maxAttempts = 5;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Build a fresh request for every attempt so streams/params are clean
                $serverParams = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/scrape/isbn'];
                $queryParams = ['isbn' => $isbn];

                $request = new \Slim\Psr7\Request(
                    'GET',
                    new \Slim\Psr7\Uri('http', 'localhost', null, '/scrape/isbn'),
                    new \Slim\Psr7\Headers(),
                    [],
                    $serverParams,
                    new \Slim\Psr7\Stream(fopen('php://temp', 'r+'))
                );

                $request = $request->withQueryParams($queryParams);
                $response = new \Slim\Psr7\Response();
                $response = $scrapeController->byIsbn($request, $response);

                if ($response->getStatusCode() === 200) {
                    $body = (string) $response->getBody();
                    $data = json_decode($body, true);
                    return $data ?: [];
                }

                error_log(sprintf(
                    'Scraping attempt %d/%d failed for ISBN %s with status %d',
                    $attempt,
                    $maxAttempts,
                    $isbn,
                    $response->getStatusCode()
                ));
            } catch (\Throwable $scrapeException) {
                error_log(sprintf(
                    'Scraping attempt %d/%d threw for ISBN %s: %s',
                    $attempt,
                    $maxAttempts,
                    $isbn,
                    $scrapeException->getMessage()
                ));
            }

            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
                $delaySeconds = min($delaySeconds * 2, 8); // exponential backoff with cap
            }
        }

        return [];
    }

    /**
     * Enrich book with scraped data
     */
    private function enrichBookWithScrapedData(\mysqli $db, int $bookId, array $csvData, array $scrapedData): void
    {
        $updates = [];
        $params = [];
        $types = '';

        // Copertina
        if (empty($csvData['copertina_url']) && !empty($scrapedData['image'])) {
            error_log("[CSV Import] Adding cover image for book $bookId: {$scrapedData['image']}");
            $updates[] = 'copertina_url = ?';
            $params[] = $scrapedData['image'];
            $types .= 's';
        }

        // Descrizione
        if (empty($csvData['descrizione']) && !empty($scrapedData['description'])) {
            $updates[] = 'descrizione = ?';
            $params[] = $scrapedData['description'];
            $types .= 's';
        }

        // Sottotitolo
        if (empty($csvData['sottotitolo']) && !empty($scrapedData['subtitle'])) {
            $updates[] = 'sottotitolo = ?';
            $params[] = $scrapedData['subtitle'];
            $types .= 's';
        }

        // Prezzo
        if (empty($csvData['prezzo']) && !empty($scrapedData['price'])) {
            $priceClean = preg_replace('/[^0-9,.]/', '', $scrapedData['price']);
            $priceClean = str_replace(',', '.', $priceClean);
            if (is_numeric($priceClean)) {
                $updates[] = 'prezzo = ?';
                $params[] = (float) $priceClean;
                $types .= 'd';
            }
        }

        // Pagine
        if (empty($csvData['numero_pagine']) && !empty($scrapedData['pages'])) {
            $pagesClean = preg_replace('/[^0-9]/', '', $scrapedData['pages']);
            if (is_numeric($pagesClean)) {
                $updates[] = 'numero_pagine = ?';
                $params[] = (int) $pagesClean;
                $types .= 'i';
            }
        }

        // Update libro if we have data
        if (!empty($updates)) {
            error_log("[CSV Import] Enriching book $bookId with " . count($updates) . " fields: " . implode(', ', $updates));
            $sql = "UPDATE libri SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $bookId;
            $types .= 'i';

            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log("[CSV Import] No fields to enrich for book $bookId (CSV has all data or scraping returned no usable data)");
        }

        // Add authors if missing
        if (empty($csvData['autori']) && !empty($scrapedData['authors'])) {
            $order = 1;
            foreach ($scrapedData['authors'] as $authorName) {
                $authorName = trim($authorName);
                if (empty($authorName))
                    continue;

                $authorId = $this->getOrCreateAuthor($db, $authorName);
                if ($authorId === 'created') {
                    $authorId = $db->insert_id;
                }

                // Check if already linked
                $checkStmt = $db->prepare("SELECT id FROM libri_autori WHERE libro_id = ? AND autore_id = ?");
                $checkStmt->bind_param('ii', $bookId, $authorId);
                $checkStmt->execute();
                if ($checkStmt->get_result()->num_rows === 0) {
                    $linkStmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', ?)");
                    $linkStmt->bind_param('iii', $bookId, $authorId, $order);
                    $linkStmt->execute();
                    $linkStmt->close();
                }
                $checkStmt->close();
                $order++;
            }
        }

        // Add publisher if missing
        if (empty($csvData['editore']) && !empty($scrapedData['publisher'])) {
            $editorId = $this->getOrCreatePublisher($db, $scrapedData['publisher']);
            if ($editorId === 'created') {
                $editorId = $db->insert_id;
            }

            $stmt = $db->prepare("UPDATE libri SET editore_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $editorId, $bookId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
