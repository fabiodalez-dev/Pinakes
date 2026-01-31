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
         * Handle a CSV file upload, validate and import its rows into the library, and record import progress and results in session.
         *
         * Performs file and MIME validation, detects the CSV delimiter, initializes import progress, and calls importCsvData()
         * to ingest rows (optionally enabling scraping). On success or failure it stores progress, success/error messages and
         * any import errors in the session. For AJAX requests it returns a JSON response; otherwise it redirects back to the
         * import page.
         *
         * @param Request $request PSR-7 request containing the uploaded CSV file and form fields (e.g., enable_scraping).
         * @param Response $response PSR-7 response instance used to produce the final redirect or JSON reply.
         * @param \mysqli $db Database connection used for inserting/updating import records.
         * @return Response A response that is either a JSON result for AJAX callers or a redirect to the import page.
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

        // Verifica che almeno una riga contenga il separatore CSV (;, , o tab)
        $delimiter = $this->detectDelimiterFromSample($firstLines);
        if ($delimiter === null) {
            $_SESSION['error'] = __('File CSV non valido: usa ";", "," o TAB come separatore.');
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
            'parole_chiave',
            'classificazione_dewey'
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
                'medioevo, giallo, monastero',
                '853'  // Dewey: Narrativa italiana
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
                'distopia, controllo, totalitarismo',
                ''  // Dewey vuoto - verrà popolato dallo scraping se abilitato
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
                'dante, medioevo, poesia, inferno, paradiso',
                '851.1'  // Dewey: Poesia italiana - Dante
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
     * Detects the most likely CSV delimiter from sample text lines.
     *
     * Counts occurrences of semicolon (`;`), comma (`,`) and tab (`\t`) in the provided
     * sample lines and returns the delimiter with the highest count. Returns `null`
     * when no supported delimiter is found in the samples.
     *
     * @param array $lines Sample text lines to analyze for delimiter frequency.
     * @return string|null The detected delimiter character (`';'`, `','`, or `"\t"`) or `null` if none detected.
     */
    private function detectDelimiterFromSample(array $lines): ?string
    {
        $candidates = [';' => 0, ',' => 0, "\t" => 0];

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
     * Map CSV column headers to canonical internal field names.
     *
     * Matches header names (case-insensitive, trimmed) against a predefined set of multilingual/variant names
     * and returns a mapping where each array key is the original column index and each value is the canonical field name.
     * If a header has no known mapping, the original header string is preserved as the value.
     *
     * @param array $headers Original CSV header row (ordered list of column names).
     * @return array Array keyed by original column index with values set to canonical field names or the original header when unmapped.
     */
    private function mapColumnHeaders(array $headers): array
    {
        // Define mapping from various column names to canonical field names
        $columnMapping = [
            'id' => ['id', 'book id', 'book_id', 'bookid', 'codice', 'code'],
            'isbn10' => ['isbn10', 'isbn 10', 'isbn-10', 'isbn_10'],
            'isbn13' => ['isbn13', 'isbn 13', 'isbn-13', 'isbn_13', 'isbn', 'isbns'],
            'ean' => ['ean', 'ean13', 'ean-13', 'ean_13', 'barcode'],
            'titolo' => ['titolo', 'title', 'título', 'titre', 'titel'],
            'sottotitolo' => ['sottotitolo', 'subtitle', 'subtítulo', 'sous-titre', 'untertitel', 'sort character'],
            'autori' => ['autori', 'autore', 'author', 'authors', 'autor', 'auteur', 'primary author', 'secondary author'],
            'editore' => ['editore', 'publisher', 'editorial', 'éditeur', 'verlag', 'publication'],
            'anno_pubblicazione' => ['anno_pubblicazione', 'anno', 'year', 'date', 'publication year', 'año', 'année', 'jahr'],
            'lingua' => ['lingua', 'language', 'languages', 'idioma', 'langue', 'sprache'],
            'edizione' => ['edizione', 'edition', 'edición', 'édition', 'ausgabe'],
            'numero_pagine' => ['numero_pagine', 'pagine', 'pages', 'page count', 'páginas', 'seiten'],
            'genere' => ['genere', 'genre', 'género', 'category', 'categoria'],
            'descrizione' => ['descrizione', 'description', 'descripción', 'summary', 'riassunto', 'abstract'],
            'formato' => ['formato', 'format', 'media', 'binding', 'physical description'],
            'prezzo' => ['prezzo', 'price', 'precio', 'prix', 'preis', 'list price', 'purchase price'],
            'copie_totali' => ['copie_totali', 'copie', 'copies', 'quantity', 'quantità', 'cantidad'],
            'collana' => ['collana', 'series', 'collection', 'collections', 'colección', 'reihe'],
            'numero_serie' => ['numero_serie', 'series number', 'número de serie', 'numéro de série'],
            'traduttore' => ['traduttore', 'translator', 'traductor', 'traducteur', 'übersetzer'],
            'parole_chiave' => ['parole_chiave', 'parole chiave', 'keywords', 'tags', 'palabras clave', 'mots-clés', 'schlagwörter', 'subjects'],
            'classificazione_dewey' => ['classificazione_dewey', 'dewey', 'dewey decimal', 'dewey classification', 'dewey wording', 'lc classification', 'call number', 'other call number']
        ];

        $mappedHeaders = [];

        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));
            $canonicalName = $header; // Default: keep original if no mapping found

            // Try to find a mapping
            foreach ($columnMapping as $canonical => $variations) {
                foreach ($variations as $variation) {
                    if ($headerLower === strtolower($variation)) {
                        $canonicalName = $canonical;
                        break 2;
                    }
                }
            }

            $mappedHeaders[$index] = $canonicalName;
        }

        return $mappedHeaders;
    }

    /**
     * Determine whether a CSV header row appears to be in LibraryThing export format.
     *
     * @param array $headers Original CSV headers.
     * @return bool `true` if at least two LibraryThing-specific columns are present, `false` otherwise.
     */
    private function isLibraryThingFormat(array $headers): bool
    {
        $libraryThingColumns = ['Book Id', 'Primary Author', 'Secondary Author', 'ISBNs'];
        $foundCount = 0;

        foreach ($libraryThingColumns as $ltColumn) {
            foreach ($headers as $header) {
                if (strtolower(trim($header)) === strtolower($ltColumn)) {
                    $foundCount++;
                    break;
                }
            }
        }

        // If we found at least 2 LibraryThing-specific columns, consider it LibraryThing format
        return $foundCount >= 2;
    }

    /**
         * Merge LibraryThing-format columns from a CSV row into the standard book data schema.
         *
         * Converts LibraryThing-specific fields (e.g., Primary/Secondary Author, Publication, ISBNs, Date, Media, Languages)
         * into the controller's canonical keys such as `autori`, `editore`, `isbn13`, `isbn10`, `anno_pubblicazione`, `formato`, and `lingua`.
         *
         * @param array $data Row data keyed by original LibraryThing column names.
         * @param array $originalHeaders Original CSV headers before mapping.
         * @return array The row data augmented with standard-format fields where values could be derived from LibraryThing columns.
         */
    private function parseLibraryThingFormat(array $data, array $originalHeaders): array
    {
        $processed = $data;

        // Merge Primary Author and Secondary Author into autori
        $authors = [];
        if (!empty($data['Primary Author'])) {
            $authors[] = trim($data['Primary Author']);
        }
        if (!empty($data['Secondary Author'])) {
            $authors[] = trim($data['Secondary Author']);
        }
        if (!empty($authors)) {
            $processed['autori'] = implode('|', $authors);
        }

        // Parse Publication field (e.g., "Milano, Mondadori, 2013")
        if (!empty($data['Publication'])) {
            $publication = $data['Publication'];
            // Extract publisher (usually the last part before year)
            // Format: "City, Publisher, Year" or "Publisher (Year)"
            if (preg_match('/,\s*([^,]+),\s*(\d{4})/', $publication, $matches)) {
                if (empty($processed['editore'])) {
                    $processed['editore'] = trim($matches[1]);
                }
            } elseif (preg_match('/([^(]+)\s*\((\d{4})\)/', $publication, $matches)) {
                if (empty($processed['editore'])) {
                    $processed['editore'] = trim($matches[1]);
                }
            }
        }

        // Parse ISBNs field (e.g., "9788883148378, 8883148371")
        if (!empty($data['ISBNs']) || !empty($data['ISBN'])) {
            $isbnField = !empty($data['ISBNs']) ? $data['ISBNs'] : $data['ISBN'];
            // Remove brackets if present
            $isbnField = trim($isbnField, '[]');
            // Split by comma or space
            $isbns = preg_split('/[,\s]+/', $isbnField);

            foreach ($isbns as $isbn) {
                $isbn = trim($isbn);
                if (empty($isbn)) continue;

                // Assign to isbn13 or isbn10 based on length
                if (strlen($isbn) === 13) {
                    if (empty($processed['isbn13'])) {
                        $processed['isbn13'] = $isbn;
                    }
                } elseif (strlen($isbn) === 10) {
                    if (empty($processed['isbn10'])) {
                        $processed['isbn10'] = $isbn;
                    }
                }
            }
        }

        // Parse Date field (year)
        if (!empty($data['Date']) && empty($processed['anno_pubblicazione'])) {
            $year = preg_replace('/[^0-9]/', '', $data['Date']);
            if (strlen($year) === 4) {
                $processed['anno_pubblicazione'] = $year;
            }
        }

        // Map Media to formato
        if (!empty($data['Media']) && empty($processed['formato'])) {
            $mediaMapping = [
                'libro cartaceo' => 'cartaceo',
                'copertina rigida' => 'cartaceo',
                'brossura' => 'cartaceo',
                'ebook' => 'ebook',
                'audiobook' => 'audiolibro',
                'hardcover' => 'cartaceo',
                'paperback' => 'cartaceo',
            ];

            $mediaLower = strtolower($data['Media']);
            foreach ($mediaMapping as $key => $value) {
                if (str_contains($mediaLower, $key)) {
                    $processed['formato'] = $value;
                    break;
                }
            }
        }

        // Map Languages to lingua (take first language if multiple)
        if (!empty($data['Languages']) && empty($processed['lingua'])) {
            $languageMapping = [
                'italian' => 'italiano',
                'english' => 'inglese',
                'french' => 'francese',
                'german' => 'tedesco',
                'spanish' => 'spagnolo',
            ];

            $langs = explode(',', strtolower($data['Languages']));
            $firstLang = trim($langs[0]);
            $processed['lingua'] = $languageMapping[$firstLang] ?? $firstLang;
        }

        return $processed;
    }

    /**
     * Import CSV data into the library database and return a summary of the operation.
     *
     * Processes the CSV at the given path (handles optional BOM), maps headers to canonical fields,
     * upserts books and related authors/publishers, optionally enriches records via scraping, and
     * updates per-import progress in session.
     *
     * @param string   $filePath       Path to the local CSV file to import.
     * @param \mysqli  $db             Active MySQLi connection used for queries and transactions.
     * @param bool     $enableScraping If true, attempt to enrich records with external scraping (rate-limited and time-limited).
     * @param string   $delimiter      Field delimiter to use (defaults to ';' if empty).
     * @return array<string,int|array> Summary with keys:
     *   - `imported` (int): number of newly created books,
     *   - `updated` (int): number of existing books updated,
     *   - `authors_created` (int): number of authors created during import,
     *   - `publishers_created` (int): number of publishers created during import,
     *   - `scraped` (int): number of books successfully enriched by scraping,
     *   - `errors` (array): list of error messages encountered per row or global issues.
     * @throws \Exception If the CSV file cannot be opened, is empty/invalid, or a required field is missing for a row.
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
        $originalHeaders = fgetcsv($file, 0, $delimiter, '"');
        if (!$originalHeaders) {
            fclose($file);
            throw new \Exception(__('File CSV vuoto o formato non valido'));
        }

        // Map headers to canonical field names (supports multiple languages and variations)
        $mappedHeaders = $this->mapColumnHeaders($originalHeaders);

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

            if (count($row) !== count($mappedHeaders)) {
                $errors[] = sprintf(__('Riga %d: numero di colonne non corrispondente'), $lineNumber);
                continue;
            }

            // Combine with mapped headers
            $data = array_combine($mappedHeaders, $row);

            // Also create original data array for LibraryThing parsing
            $originalData = array_combine($originalHeaders, $row);

            // Parse LibraryThing-specific fields if detected
            if ($this->isLibraryThingFormat($originalHeaders)) {
                $data = array_merge($data, $this->parseLibraryThingFormat($originalData, $originalHeaders));
            }

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

                        $authorResult = $this->getOrCreateAuthor($db, $authorName);
                        $authorId = $authorResult['id'];
                        if ($authorResult['created']) {
                            $authorsCreated++;
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
     * Ottieni o crea autore (con normalizzazione per evitare duplicati)
     * Handles "Levi, Primo" vs "Primo Levi" as same author
     *
     * @return array{id: int, created: bool} Author ID and whether it was newly created
     */
    private function getOrCreateAuthor(\mysqli $db, string $name): array
    {
        $authRepo = new \App\Models\AuthorRepository($db);

        // findByName normalizes and handles different formats
        $existingId = $authRepo->findByName($name);
        if ($existingId) {
            return ['id' => $existingId, 'created' => false];
        }

        // create() normalizes the name and returns the new ID directly
        // This is safer than relying on $db->insert_id which could be affected
        // by other insert operations between create() and reading insert_id
        $newId = $authRepo->create([
            'nome' => $name,
            'pseudonimo' => '',
            'data_nascita' => null,
            'data_morte' => null,
            'nazionalita' => '',
            'biografia' => '',
            'sito_web' => ''
        ]);

        return ['id' => $newId, 'created' => true];
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
            $stmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1");
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
            $stmt = $db->prepare("SELECT id FROM libri WHERE isbn13 = ? AND deleted_at IS NULL LIMIT 1");
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
            $stmt = $db->prepare("SELECT id FROM libri WHERE ean = ? AND deleted_at IS NULL LIMIT 1");
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
                classificazione_dewey = ?,
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
        $paroleChiave = !empty($data['parole_chiave'] ?? null) ? $data['parole_chiave'] : null;
        $dewey = !empty($data['classificazione_dewey'] ?? null) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdisssssi',
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
            $dewey,
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
                classificazione_dewey, stato, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 'disponibile', NOW()
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
        $paroleChiave = !empty($data['parole_chiave'] ?? null) ? $data['parole_chiave'] : null;
        $dewey = !empty($data['classificazione_dewey'] ?? null) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdiiisssss',
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
            $paroleChiave,
            $dewey
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

        // Classificazione Dewey
        if (empty($csvData['classificazione_dewey'] ?? null) && !empty($scrapedData['classificazione_dewey'] ?? null)) {
            // Validate Dewey format: 3 digits optionally followed by decimal point and 1-4 digits
            $deweyCode = trim((string) $scrapedData['classificazione_dewey']);
            if (preg_match('/^[0-9]{3}(\.[0-9]{1,4})?$/', $deweyCode)) {
                error_log("[CSV Import] Adding Dewey classification for book $bookId: $deweyCode");
                $updates[] = 'classificazione_dewey = ?';
                $params[] = $deweyCode;
                $types .= 's';
            }
        }

        // Anno pubblicazione
        if (empty($csvData['anno_pubblicazione'] ?? null) && !empty($scrapedData['year'] ?? null)) {
            $yearClean = preg_replace('/[^0-9]/', '', (string) $scrapedData['year']);
            if (is_numeric($yearClean) && strlen($yearClean) === 4) {
                $updates[] = 'anno_pubblicazione = ?';
                $params[] = (int) $yearClean;
                $types .= 'i';
            }
        }

        // Lingua
        if (empty($csvData['lingua'] ?? null) && !empty($scrapedData['language'] ?? null)) {
            $updates[] = 'lingua = ?';
            $params[] = $scrapedData['language'];
            $types .= 's';
        }

        // Parole chiave
        if (empty($csvData['parole_chiave'] ?? null) && !empty($scrapedData['keywords'] ?? null)) {
            $updates[] = 'parole_chiave = ?';
            // Normalize keywords: handle both string and array formats
            $keywords = $scrapedData['keywords'];
            $params[] = is_array($keywords) ? implode(', ', $keywords) : $keywords;
            $types .= 's';
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

                $authorResult = $this->getOrCreateAuthor($db, $authorName);
                $authorId = $authorResult['id'];

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