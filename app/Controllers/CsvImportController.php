<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

class CsvImportController
{
    /**
     * Number of CSV rows to process per chunk
     * Lower values = more frequent progress updates but more HTTP overhead
     * Higher values = faster overall import but less granular progress
     * Recommended: 10-50 rows per chunk
     */
    private const CHUNK_SIZE = 10;

    /**
     * Write log message to import log file
     */
    private function log(string $message): void
    {
        $logFile = __DIR__ . '/../../writable/logs/import.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [CSV] $message\n", FILE_APPEND);
    }

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
        if (!$tmpFile || !is_file($tmpFile)) {
            $_SESSION['error'] = __('Impossibile leggere il file caricato');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

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
        // Save CSV file for chunked processing (like LibraryThing)
        $uploadDir = __DIR__ . '/../../writable/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $savedFilePath = $uploadDir . '/csv_import_' . session_id() . '_' . uniqid('', true) . '.csv';
        if (!@copy($tmpFile, $savedFilePath)) {
            $_SESSION['error'] = __('Impossibile salvare il file CSV caricato');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        // Count total rows for chunked processing
        $file = fopen($savedFilePath, 'r');
        if ($file === false) {
            @unlink($savedFilePath);
            $_SESSION['error'] = __('Impossibile aprire il file CSV salvato');
            return $response->withHeader('Location', '/admin/libri/import')->withStatus(302);
        }

        // Skip BOM if present
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }

        // Skip header
        fgetcsv($file, 0, $delimiter, '"');

        // Count rows
        $totalRows = 0;
        while (fgetcsv($file, 0, $delimiter, '"') !== false) {
            $totalRows++;
        }
        fclose($file);

        // Store import metadata in session
        $_SESSION['csv_import_data'] = [
            'file_path' => $savedFilePath,
            'original_filename' => $filename,
            'delimiter' => $delimiter,
            'total_rows' => $totalRows,
            'enable_scraping' => !empty($data['enable_scraping']),
            'imported' => 0,
            'updated' => 0,
            'scraped' => 0,
            'authors_created' => 0,
            'publishers_created' => 0,
            'errors' => []
        ];

        // Return JSON response for chunked processing
        $response->getBody()->write(json_encode([
            'success' => true,
            'total_rows' => $totalRows,
            'chunk_size' => self::CHUNK_SIZE,
            'enable_scraping' => !empty($data['enable_scraping'])
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Process a chunk of CSV rows (10 at a time, like LibraryThing)
     */
    public function processChunk(Request $request, Response $response, \mysqli $db): Response
    {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $data = json_decode((string) $request->getBody(), true);

        if (!is_array($data) || !isset($_SESSION['csv_import_data'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Sessione import scaduta o dati non validi')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $importData = $_SESSION['csv_import_data'];
        $chunkStart = (int) ($data['start'] ?? 0);
        $chunkSize = (int) ($data['size'] ?? self::CHUNK_SIZE);

        // Validate and cap chunk parameters to prevent DoS
        $chunkStart = max(0, $chunkStart); // Must be >= 0
        $chunkSize = max(1, min($chunkSize, self::CHUNK_SIZE)); // Capped at CHUNK_SIZE

        $enableScraping = (bool) ($importData['enable_scraping'] ?? false);

        $file = fopen($importData['file_path'], 'r');
        if ($file === false) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Impossibile aprire il file CSV')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        // Skip BOM
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }

        // Read headers
        $headers = fgetcsv($file, 0, $importData['delimiter'], '"');
        if (!$headers) {
            fclose($file);
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('File CSV vuoto o formato non valido')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Map headers
        $mappedHeaders = $this->mapColumnHeaders($headers);

        // Skip to chunk start
        for ($i = 0; $i < $chunkStart; $i++) {
            if (fgetcsv($file, 0, $importData['delimiter'], '"') === false) {
                break;
            }
        }

        // Process chunk
        $processed = 0;
        $lineNumber = $chunkStart + 2; // +2 for header and 1-indexed

        while ($processed < $chunkSize && ($rawData = fgetcsv($file, 0, $importData['delimiter'], '"')) !== false) {
            $parsedData = []; // Initialize to avoid undefined variable in catch block
            try {
                // Validate column count
                if (count($rawData) !== count($headers)) {
                    $this->log("[processChunk] Column count mismatch at row $lineNumber: expected " . count($headers) . ", got " . count($rawData));
                    $this->log("[processChunk] Headers: " . json_encode($headers));
                    $this->log("[processChunk] Row data: " . json_encode($rawData));
                    throw new \RuntimeException(sprintf(
                        __('Numero colonne non valido') . ' (attese: %d, trovate: %d)',
                        count($headers),
                        count($rawData)
                    ));
                }

                // Map data
                $row = array_combine($mappedHeaders, $rawData);
                $parsedData = $this->parseCsvRow($row);

                if (empty($parsedData['titolo'])) {
                    throw new \Exception(__('Titolo mancante'));
                }

                $db->begin_transaction();

                // Get or create publisher
                $editorId = null;
                if (!empty($parsedData['editore'])) {
                    $publisherResult = $this->getOrCreatePublisher($db, trim($parsedData['editore']));
                    $editorId = $publisherResult['id'];
                    if ($publisherResult['created']) {
                        $importData['publishers_created']++;
                    }
                }

                // Get genre ID
                $genreId = $this->getGenreId($db, $parsedData['genere'] ?? '');

                // Upsert book
                $upsertResult = $this->upsertBook($db, $parsedData, $editorId, $genreId);
                $bookId = $upsertResult['id'];
                $action = $upsertResult['action'];

                // Remove old author links if updating
                if ($action === 'updated') {
                    $stmt = $db->prepare("DELETE FROM libri_autori WHERE libro_id = ?");
                    $stmt->bind_param('i', $bookId);
                    $stmt->execute();
                    $stmt->close();
                }

                // Handle authors
                if (!empty($parsedData['autori'])) {
                    $separator = strpos($parsedData['autori'], ';') !== false ? ';' : '|';
                    $authors = array_map('trim', explode($separator, $parsedData['autori']));
                    $authorOrder = 1;

                    foreach ($authors as $authorName) {
                        if (empty($authorName)) continue;

                        $authorResult = $this->getOrCreateAuthor($db, $authorName);
                        $authorId = $authorResult['id'];
                        if ($authorResult['created']) {
                            $importData['authors_created']++;
                        }

                        $stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', ?)");
                        $stmt->bind_param('iii', $bookId, $authorId, $authorOrder);
                        $stmt->execute();
                        $stmt->close();
                        $authorOrder++;
                    }
                }

                $db->commit();

                if ($action === 'created') {
                    $importData['imported']++;
                } else {
                    $importData['updated']++;
                }

                // Scraping (if enabled and ISBN exists)
                if ($enableScraping && !empty($parsedData['isbn13'])) {
                    try {
                        $scrapedData = $this->scrapeBookData($parsedData['isbn13']);

                        if (!empty($scrapedData)) {
                            $this->enrichBookWithScrapedData($db, $bookId, $parsedData, $scrapedData);
                            $importData['scraped']++;
                        }

                        sleep(1); // Rate limiting
                    } catch (\Throwable $scrapeError) {
                        // Log scraping error but continue with import
                        $title = $parsedData['titolo'];
                        $importData['errors'][] = [
                            'line' => $lineNumber,
                            'title' => $title,
                            'message' => 'Scraping fallito - ' . $scrapeError->getMessage(),
                            'type' => 'scraping',
                        ];

                        // Also log to file for debugging
                        $logFile = __DIR__ . '/../../writable/logs/csv_errors.log';
                        $logMsg = sprintf(
                            "[%s] SCRAPING ERROR Riga %d (%s): %s\n",
                            date('Y-m-d H:i:s'),
                            $lineNumber,
                            $title,
                            $scrapeError->getMessage()
                        );
                        file_put_contents($logFile, $logMsg, FILE_APPEND);
                    }
                }

            } catch (\Throwable $e) {
                $db->rollback();
                $title = $parsedData['titolo'] ?? ($rawData[0] ?? '');
                $importData['errors'][] = [
                    'line' => $lineNumber,
                    'title' => $title,
                    'message' => $e->getMessage(),
                    'type' => 'validation',
                ];

                // Log to import.log (same format as LT)
                $this->log("[processChunk] ERROR Riga $lineNumber ($title): " . $e->getMessage());
                $this->log("[processChunk] ERROR Class: " . get_class($e));
                $this->log("[processChunk] ERROR Trace: " . $e->getTraceAsString());

                // Log to csv_errors.log (legacy)
                $logFile = __DIR__ . '/../../writable/logs/csv_errors.log';
                $logMsg = sprintf(
                    "[%s] ERROR Riga %d (%s): %s\n\n",
                    date('Y-m-d H:i:s'),
                    $lineNumber,
                    $title,
                    $e->getMessage()
                );
                file_put_contents($logFile, $logMsg, FILE_APPEND);
            }

            $processed++;
            $lineNumber++;
        }

        fclose($file);

        // Update session data
        $_SESSION['csv_import_data'] = $importData;

        // Check if complete
        $isComplete = ($chunkStart + $processed) >= $importData['total_rows'];

        if ($isComplete) {
            // Persist import history to database
            $persisted = false;
            try {
                $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
                $fileName = $importData['original_filename'] ?? basename($importData['file_path'] ?? 'unknown.csv');

                $importLogger = new \App\Support\ImportLogger($db, 'csv', $fileName, $userId);

                // Calculate failed count: only non-scraping errors (using type field)
                $failedCount = 0;
                foreach ($importData['errors'] as $err) {
                    $type = is_array($err) ? ($err['type'] ?? 'validation') : 'validation';
                    if ($type !== 'scraping') {
                        $failedCount++;
                    }
                }

                // Transfer stats from session to logger (efficient batch update)
                $importLogger->setStats([
                    'imported' => $importData['imported'],
                    'updated' => $importData['updated'],
                    'failed' => $failedCount,
                    'authors_created' => $importData['authors_created'],
                    'publishers_created' => $importData['publishers_created'],
                    'scraped' => $importData['scraped'],
                ]);

                // Transfer errors — consume structured arrays directly
                foreach ($importData['errors'] as $err) {
                    if (is_array($err)) {
                        $importLogger->addError(
                            $err['line'] ?? 0,
                            $err['title'] ?? 'Unknown',
                            $err['message'] ?? '',
                            $err['type'] ?? 'validation',
                            false
                        );
                    } else {
                        // Legacy string format fallback
                        $importLogger->addError(0, 'Unknown', (string)$err, 'validation', false);
                    }
                }

                // Complete and persist
                $persisted = $importLogger->complete($importData['total_rows']);
                if (!$persisted) {
                    error_log("[CsvImportController] Failed to persist import history to database");
                    // Mark as failed so the record doesn't stay stuck in 'processing'
                    $importLogger->fail('Failed to persist import history', $importData['total_rows']);
                }
            } catch (\Throwable $e) {
                // Log error but don't fail the import (already completed)
                // Catches \Error/TypeError too (strict_types=1 can throw TypeError)
                error_log("[CsvImportController] Failed to persist import history (" . get_class($e) . "): " . $e->getMessage());
                // Mark as failed so the record doesn't stay stuck in 'processing'
                if (isset($importLogger)) {
                    try {
                        $importLogger->fail($e->getMessage(), $importData['total_rows']);
                    } catch (\Throwable $inner) {
                        error_log("[CsvImportController] Also failed to mark import as failed: " . $inner->getMessage());
                    }
                }
            }

            // Cleanup file only after successful persistence
            if ($persisted) {
                @unlink($importData['file_path']);
            } else {
                error_log("[CsvImportController] Orphaned import file (persistence failed): " . ($importData['file_path'] ?? 'unknown'));
            }

            // Save summary + errors to session for display after redirect (same as LT import)
            $message = sprintf(__('%d libri importati, %d aggiornati'), $importData['imported'], $importData['updated']);
            if ($importData['authors_created'] > 0) {
                $message .= sprintf(__(', %d autori creati'), $importData['authors_created']);
            }
            if ($importData['publishers_created'] > 0) {
                $message .= sprintf(__(', %d editori creati'), $importData['publishers_created']);
            }
            if ($importData['scraped'] > 0) {
                $message .= sprintf(__(', %d libri arricchiti con scraping'), $importData['scraped']);
            }
            if (!empty($importData['errors'])) {
                $message .= sprintf(__(', %d errori'), count($importData['errors']));
            }
            $_SESSION['success'] = $message;

            if (!empty($importData['errors'])) {
                $_SESSION['import_errors'] = array_map(function ($err) {
                    if (is_array($err)) {
                        return sprintf(
                            'Riga %d (%s): %s',
                            $err['line'] ?? 0,
                            htmlspecialchars($err['title'] ?? 'CSV', ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($err['message'] ?? '', ENT_QUOTES, 'UTF-8')
                        );
                    }
                    return htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8');
                }, $importData['errors']);
            }

            unset($_SESSION['csv_import_data']);
        }

        // Build error_details for frontend display (limit to 50 to keep payload small)
        $maxErrorDetails = 50;
        $errorsForUi = array_slice($importData['errors'], 0, $maxErrorDetails);
        $errorDetails = array_map(function ($err) {
            if (is_array($err)) {
                return sprintf(
                    'Riga %d (%s): %s',
                    $err['line'] ?? 0,
                    $err['title'] ?? 'CSV',
                    $err['message'] ?? ''
                );
            }
            return (string)$err;
        }, $errorsForUi);

        $response->getBody()->write(json_encode([
            'success' => true,
            'processed' => $processed,
            'imported' => $importData['imported'],
            'updated' => $importData['updated'],
            'scraped' => $importData['scraped'],
            'authors_created' => $importData['authors_created'],
            'publishers_created' => $importData['publishers_created'],
            'errors' => count($importData['errors']),
            'error_details' => $errorDetails,
            'complete' => $isComplete
        ]));

        return $response->withHeader('Content-Type', 'application/json');
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
            'illustratore',
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
                'Italiano',
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
                'Italiano',
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
                '',
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
                'Italiano',
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
     * Detect delimiter from sample lines
     * Supports: semicolon (;), comma (,), and tab (\t)
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

        // $candidates always has 3 elements, so $bestDelimiter is never null
        if ($candidates[$bestDelimiter] === 0) {
            return null;
        }

        return $bestDelimiter;
    }

    /**
     * Parse a single CSV row into normalized book data
     */
    private function parseCsvRow(array $row): array
    {
        // Combine primary_author, secondary_author, and autori fields to prevent data loss
        $authors = [];
        if (!empty($row['primary_author'])) {
            $authors[] = trim($row['primary_author']);
        }
        if (!empty($row['secondary_author'])) {
            $authors[] = trim($row['secondary_author']);
        }
        if (!empty($row['autori'])) {
            // autori might contain multiple authors separated by ; or |
            $existingAuthors = array_filter(array_map('trim', preg_split('/[;|]/', $row['autori'])));
            $authors = array_merge($authors, $existingAuthors);
        }
        // Remove duplicates and empty values
        $authors = array_filter(array_unique($authors));
        $autoriCombined = !empty($authors) ? implode(';', $authors) : null;

        return [
            'id' => !empty($row['id']) ? trim($row['id']) : null,
            'isbn10' => !empty($row['isbn10']) ? $this->normalizeIsbn($row['isbn10']) : null,
            'isbn13' => !empty($row['isbn13']) ? $this->normalizeIsbn($row['isbn13']) : null,
            'ean' => !empty($row['ean']) ? $this->normalizeEan($row['ean']) : null,
            'titolo' => !empty($row['titolo']) ? trim($row['titolo']) : '',
            'sottotitolo' => !empty($row['sottotitolo']) ? trim($row['sottotitolo']) : null,
            'autori' => $autoriCombined,
            'editore' => !empty($row['editore']) ? trim($row['editore']) : null,
            'anno_pubblicazione' => !empty($row['anno_pubblicazione']) ? (int)$row['anno_pubblicazione'] : null,
            'lingua' => $this->validateLanguage($row['lingua'] ?? ''),
            'edizione' => !empty($row['edizione']) ? trim($row['edizione']) : null,
            'numero_pagine' => !empty($row['numero_pagine']) ? (int)$row['numero_pagine'] : null,
            'genere' => !empty($row['genere']) ? trim($row['genere']) : null,
            'descrizione' => !empty($row['descrizione']) ? trim($row['descrizione']) : null,
            'formato' => !empty($row['formato']) ? trim($row['formato']) : 'cartaceo',
            'prezzo' => $this->validatePrice($row['prezzo'] ?? ''),
            'copie_totali' => !empty($row['copie_totali']) ? (int)$row['copie_totali'] : 1,
            'collana' => !empty($row['collana']) ? trim($row['collana']) : null,
            'numero_serie' => !empty($row['numero_serie']) ? trim($row['numero_serie']) : null,
            'traduttore' => !empty($row['traduttore']) ? trim($row['traduttore']) : null,
            'illustratore' => !empty($row['illustratore']) ? trim($row['illustratore']) : null,
            'parole_chiave' => !empty($row['parole_chiave']) ? trim($row['parole_chiave']) : null,
            'classificazione_dewey' => !empty($row['classificazione_dewey']) ? trim($row['classificazione_dewey']) : null,
            'copertina_url' => !empty($row['copertina_url']) ? trim($row['copertina_url']) : null
        ];
    }

    /**
     * Validate and normalize price value
     *
     * @param string $price Raw price value from CSV
     * @return float|null Validated price or null
     * @throws \Exception If price is invalid
     */
    private function validatePrice(string $price): ?float
    {
        if (trim($price) === '') {
            return null;
        }

        // Normalize: strip currency symbols and whitespace, keeping only digits, dot, comma, minus
        $normalized = trim($price);
        $normalized = preg_replace('/[^0-9,.\-]/', '', $normalized);

        // Handle thousands/decimal separator ambiguity
        // Use last-occurring separator as decimal: handles both US (1,234.56) and EU (1.234,56)
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // EU format: dot is thousands, comma is decimal (1.234,56)
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // US format: comma is thousands, dot is decimal (1,234.56)
                $normalized = str_replace(',', '', $normalized);
            }
        } else {
            // Only one separator type: replace comma with dot
            $normalized = str_replace(',', '.', $normalized);
        }

        // Validate: must be numeric after normalization
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new \Exception(__('Prezzo non valido: deve essere un numero') . " ('{$price}')");
        }

        $result = (float)$normalized;
        if ($result < 0) {
            throw new \Exception(__('Prezzo non valido: non può essere negativo') . " ('{$price}')");
        }

        return $result;
    }

    /**
     * Validate and normalize language value
     *
     * @param string $language Raw language value from CSV
     * @return string Validated language
     * @throws \Exception If language is not supported
     */
    private function validateLanguage(string $language): string
    {
        // Canonical native language names
        $validLanguages = [
            'Italiano', 'English', 'Français', 'Deutsch', 'Español',
            'Português', 'Русский', '中文', '日本語', 'العربية',
            'Nederlands', 'Svenska', 'Norsk', 'Dansk', 'Suomi',
            'Polski', 'Čeština', 'Magyar', 'Română', 'Ελληνικά',
            'Türkçe', 'עברית', 'हिन्दी', '한국어', 'ไทย', 'Latina'
        ];

        // Lowercase lookup for case-insensitive matching
        $validLower = array_map('mb_strtolower', $validLanguages);
        $nativeByLower = array_combine($validLower, $validLanguages);

        // Aliases: ISO codes, Italian names, and English names → native
        $aliases = [
            // ISO 639-1 codes
            'it' => 'Italiano', 'en' => 'English', 'fr' => 'Français',
            'de' => 'Deutsch', 'es' => 'Español', 'pt' => 'Português',
            'ru' => 'Русский', 'zh' => '中文', 'ja' => '日本語',
            'ar' => 'العربية', 'nl' => 'Nederlands', 'sv' => 'Svenska',
            'no' => 'Norsk', 'da' => 'Dansk', 'fi' => 'Suomi',
            'pl' => 'Polski', 'cs' => 'Čeština', 'hu' => 'Magyar',
            'ro' => 'Română', 'el' => 'Ελληνικά', 'tr' => 'Türkçe',
            'he' => 'עברית', 'hi' => 'हिन्दी', 'ko' => '한국어',
            'th' => 'ไทย', 'la' => 'Latina',
            // Italian names
            'italiano' => 'Italiano', 'inglese' => 'English', 'francese' => 'Français',
            'tedesco' => 'Deutsch', 'spagnolo' => 'Español', 'portoghese' => 'Português',
            'russo' => 'Русский', 'cinese' => '中文', 'giapponese' => '日本語',
            'arabo' => 'العربية', 'olandese' => 'Nederlands', 'svedese' => 'Svenska',
            'norvegese' => 'Norsk', 'danese' => 'Dansk', 'finlandese' => 'Suomi',
            'polacco' => 'Polski', 'ceco' => 'Čeština', 'ungherese' => 'Magyar',
            'rumeno' => 'Română', 'greco' => 'Ελληνικά', 'turco' => 'Türkçe',
            'ebraico' => 'עברית', 'hindi' => 'हिन्दी', 'coreano' => '한국어',
            'thai' => 'ไทย', 'latino' => 'Latina',
            // English names
            'italian' => 'Italiano', 'english' => 'English', 'french' => 'Français',
            'german' => 'Deutsch', 'spanish' => 'Español', 'portuguese' => 'Português',
            'russian' => 'Русский', 'chinese' => '中文', 'japanese' => '日本語',
            'arabic' => 'العربية', 'dutch' => 'Nederlands', 'swedish' => 'Svenska',
            'norwegian' => 'Norsk', 'danish' => 'Dansk', 'finnish' => 'Suomi',
            'polish' => 'Polski', 'czech' => 'Čeština', 'hungarian' => 'Magyar',
            'romanian' => 'Română', 'greek' => 'Ελληνικά', 'turkish' => 'Türkçe',
            'hebrew' => 'עברית', 'latin' => 'Latina', 'korean' => '한국어',
        ];

        // Default to Italiano if empty
        if (empty($language)) {
            return 'Italiano';
        }

        // Split on commas to support multi-language values like "English, German"
        $parts = array_map('trim', explode(',', $language));
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $lower = mb_strtolower($part);

            // Map aliases to canonical native names (passthrough if unknown)
            if (isset($aliases[$lower])) {
                $normalized[] = $aliases[$lower];
            } elseif (isset($nativeByLower[$lower])) {
                $normalized[] = $nativeByLower[$lower];
            } else {
                // Accept unknown languages as-is (ucfirst for consistency)
                $normalized[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
            }
        }

        // Remove duplicates and rejoin
        return implode(', ', array_unique($normalized)) ?: 'Italiano';
    }

    /**
     * Normalize ISBN by removing dashes, spaces, and non-alphanumeric characters
     * Preserves only digits and 'X' (valid in ISBN-10 check digit)
     *
     * @param string $isbn Raw ISBN value from CSV
     * @return string|null Normalized ISBN or null if empty
     */
    private function normalizeIsbn(string $isbn): ?string
    {
        if (empty($isbn)) {
            return null;
        }

        // Remove all characters except digits and X
        $normalized = preg_replace('/[^0-9X]/i', '', trim($isbn));

        // Return null if nothing left after normalization
        if (empty($normalized)) {
            return null;
        }

        $normalized = strtoupper($normalized);
        $len = strlen($normalized);

        // Validate length: ISBN-10 (10 chars) or ISBN-13/EAN (13 chars)
        if ($len !== 10 && $len !== 13) {
            return null; // Invalid length, skip silently (not a valid ISBN)
        }

        // Validate check digit using existing IsbnFormatter
        if (!\App\Support\IsbnFormatter::isValid($normalized)) {
            return null; // Invalid checksum, skip silently
        }

        return $normalized;
    }

    /**
     * Validate and normalize EAN-13 barcode value
     *
     * Unlike normalizeIsbn(), this only validates format and length (13 digits)
     * without ISBN checksum checks, since valid EAN-13 barcodes may not be ISBNs.
     *
     * @param string $ean Raw EAN value from CSV
     * @return string|null Normalized EAN or null if invalid
     */
    private function normalizeEan(string $ean): ?string
    {
        if (empty($ean)) {
            return null;
        }

        // Remove all non-digit characters
        $normalized = preg_replace('/[^0-9]/', '', trim($ean));

        if (empty($normalized)) {
            return null;
        }

        // EAN-13 must be exactly 13 digits
        if (strlen($normalized) !== 13) {
            return null;
        }

        // Validate EAN-13 checksum (mod 10, weights 1-3)
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $normalized[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        if ($checkDigit !== (int) $normalized[12]) {
            return null;
        }

        return $normalized;
    }

    /**
     * Map column headers to canonical field names
     * Supports multiple languages and variations (case-insensitive)
     *
     * @param array $headers Original CSV headers
     * @return array Mapped headers with canonical field names
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
            'sottotitolo' => ['sottotitolo', 'subtitle', 'subtítulo', 'sous-titre', 'untertitel'],
            'sort_character' => ['sort character', 'sort_char', 'sortchar', 'sort key'],
            // Separate LibraryThing-specific author fields to avoid data loss
            'primary_author' => ['primary author', 'primary_author'],
            'secondary_author' => ['secondary author', 'secondary_author', 'other authors'],
            // Generic author field (removed primary/secondary to prevent overwrite)
            'autori' => ['autori', 'autore', 'author', 'authors', 'autor', 'auteur'],
            'editore' => ['editore', 'publisher', 'editorial', 'éditeur', 'verlag'],
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
            'illustratore' => ['illustratore', 'illustrator', 'ilustrador', 'illustrateur', 'zeichner'],
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
     * Ottieni o crea editore
     */
    private function getOrCreatePublisher(\mysqli $db, string $name): array
    {
        $stmt = $db->prepare("SELECT id FROM editori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $publisherId = (int) $row['id'];
            $stmt->close();
            return ['id' => $publisherId, 'created' => false];
        }
        $stmt->close();

        // Crea nuovo editore
        $stmt = $db->prepare("INSERT INTO editori (nome, created_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $newId = $db->insert_id;
        $stmt->close();

        return ['id' => $newId, 'created' => true];
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

        // Strategia 4: Fallback per titolo + primo autore (per libri senza ISBN)
        if (!empty($data['titolo']) && !empty($data['autori'])) {
            // Estrai il primo autore dalla stringa separata da ";"
            $authorsArray = array_map('trim', explode(';', $data['autori']));
            $firstAuthor = $authorsArray[0];

            if ($firstAuthor !== '') {
                // Normalize author name to match DB format (e.g. "Levi, Primo" → "Primo Levi")
                $normalizedAuthor = \App\Support\AuthorNormalizer::normalize($firstAuthor);
                $stmt = $db->prepare("
                    SELECT DISTINCT l.id
                    FROM libri l
                    JOIN libri_autori al ON l.id = al.libro_id
                    JOIN autori a ON al.autore_id = a.id
                    WHERE l.titolo = ?
                    AND a.nome = ?
                    AND l.deleted_at IS NULL
                    LIMIT 1
                ");
                $stmt->bind_param('ss', $data['titolo'], $normalizedAuthor);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $stmt->close();
                    return (int) $row['id'];
                }
                $stmt->close();
            }
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
                illustratore = ?,
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
        $prezzo = $data['prezzo'] ?? null;
        $collana = !empty($data['collana']) ? $data['collana'] : null;
        $numeroSerie = !empty($data['numero_serie']) ? $data['numero_serie'] : null;
        $traduttore = !empty($data['traduttore']) ? $data['traduttore'] : null;
        $illustratore = !empty($data['illustratore']) ? $data['illustratore'] : null;
        $paroleChiave = !empty($data['parole_chiave'] ?? null) ? $data['parole_chiave'] : null;
        $dewey = !empty($data['classificazione_dewey'] ?? null) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdissssssi',
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
            $illustratore,
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
                editore_id, collana, numero_serie, traduttore, illustratore, parole_chiave,
                classificazione_dewey, stato, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
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
        $prezzo = $data['prezzo'] ?? null;
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
        $illustratore = !empty($data['illustratore']) ? $data['illustratore'] : null;
        $paroleChiave = !empty($data['parole_chiave'] ?? null) ? $data['parole_chiave'] : null;
        $dewey = !empty($data['classificazione_dewey'] ?? null) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdiiissssss',
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
            $illustratore,
            $paroleChiave,
            $dewey
        );

        $stmt->execute();
        $bookId = $db->insert_id;
        $stmt->close();

        // Genera copie fisiche nella tabella copie
        $copyRepo = new \App\Models\CopyRepository($db);

        // Genera numero inventario base (usa ISBN se disponibile, altrimenti LIB-{id})
        $baseInventario = $isbn13 ?: ($isbn10 ?: "LIB-{$bookId}");

        for ($i = 1; $i <= $copie; $i++) {
            $candidato = $copie > 1
                ? "{$baseInventario}-C{$i}"
                : $baseInventario;

            // Ensure unique numero_inventario (may collide with previous imports)
            $numeroInventario = $candidato;
            $suffix = 2;
            $maxAttempts = 1000;
            while ($this->inventoryNumberExists($db, $numeroInventario) && $suffix <= $maxAttempts) {
                $numeroInventario = "{$candidato}-{$suffix}";
                $suffix++;
            }
            if ($suffix > $maxAttempts) {
                throw new \RuntimeException("Impossibile generare numero inventario unico per: {$candidato}");
            }

            $note = $copie > 1 ? sprintf(__("Copia %d di %d"), $i, $copie) : null;

            $copyRepo->create($bookId, $numeroInventario, 'disponibile', $note);
        }

        // Ricalcola disponibilità dopo aver creato le copie
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($bookId);

        return $bookId;
    }


    /**
     * Check if a numero_inventario already exists in the copie table.
     */
    private function inventoryNumberExists(\mysqli $db, string $numero): bool
    {
        $stmt = $db->prepare("SELECT 1 FROM copie WHERE numero_inventario = ? LIMIT 1");
        $stmt->bind_param('s', $numero);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Scrape book data from online services
     * Uses hooks system for scraping to avoid hardcoded localhost dependencies
     */
    private function scrapeBookData(string $isbn): array
    {
        // Use centralized scraping service (same as LibraryThing: 3 attempts)
        return \App\Support\ScrapingService::scrapeBookData($isbn, 3, 'CSV Import');
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

        // Prezzo — don't use empty() as it discards valid 0.00 prices
        if (($csvData['prezzo'] === null || $csvData['prezzo'] === '') && !empty($scrapedData['price'])) {
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
            $sql = "UPDATE libri SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $bookId;
            $types .= 'i';

            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
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
            $publisherResult = $this->getOrCreatePublisher($db, $scrapedData['publisher']);
            $editorId = $publisherResult['id'];

            $stmt = $db->prepare("UPDATE libri SET editore_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $editorId, $bookId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
