<?php
declare(strict_types=1);

namespace App\Controllers\Plugins;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * LibraryThing Import/Export Plugin
 *
 * Provides import and export functionality for LibraryThing TSV format.
 * This is an optional plugin that extends the base CSV import/export functionality
 * without affecting the existing import system.
 *
 * Features:
 * - Import LibraryThing TSV exports (tab-separated values)
 * - Export to LibraryThing-compatible format
 * - Flexible column mapping (supports English and Italian column names)
 * - Integration with existing scraping system
 * - Support for multiple authors, ISBN variants, and complex metadata
 *
 * @see https://www.librarything.com
 */
class LibraryThingController
{
    /**
     * Render the LibraryThing TSV import HTML page.
     *
     * @return Response An HTML response containing the LibraryThing import page.
     */
    public function showImportPage(Request $request, Response $response): Response
    {
        ob_start();
        $title = "Import LibraryThing";
        include __DIR__ . '/../../Views/plugins/librarything_import.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Render the LibraryThing plugin administration page.
     *
     * Retrieves installation status from the database, renders the administration view,
     * writes the resulting HTML into the response body, and returns the response with
     * a text/html Content-Type header.
     *
     * @param Request  $request  PSR-7 request object.
     * @param Response $response PSR-7 response object to write the HTML into.
     * @param \mysqli  $db       Database connection used to determine plugin status.
     * @return Response The HTTP response containing the rendered administration page.
     */
    public function showAdminPage(Request $request, Response $response, \mysqli $db): Response
    {
        $installer = new LibraryThingInstaller($db);
        $status = $installer->getStatus();

        ob_start();
        $data = ['status' => $status];
        include __DIR__ . '/../../Views/plugins/librarything_admin.php';
        $content = ob_get_clean();

        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Install the LibraryThing plugin and redirect to the plugin administration page.
     *
     * Stores the installer's message in $_SESSION['success'] when installation succeeds
     * or in $_SESSION['error'] when it fails.
     *
     * @return Response A redirect response to /admin/plugins/librarything.
     */
    public function install(Request $request, Response $response, \mysqli $db): Response
    {
        $installer = new LibraryThingInstaller($db);
        $result = $installer->install();

        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }

        return $response->withHeader('Location', '/admin/plugins/librarything')->withStatus(302);
    }

    /**
     * Uninstalls the LibraryThing plugin and redirects to the plugin administration page.
     *
     * Stores a success or error message in the session under 'success' or 'error' depending on the outcome.
     *
     * @return Response A Response configured as a 302 redirect to /admin/plugins/librarything.
     */
    public function uninstall(Request $request, Response $response, \mysqli $db): Response
    {
        $installer = new LibraryThingInstaller($db);
        $result = $installer->uninstall();

        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }

        return $response->withHeader('Location', '/admin/plugins/librarything')->withStatus(302);
    }

    /**
     * Handle a LibraryThing TSV/CSV upload and import its records into the system.
     *
     * Validates the uploaded file (extensions .tsv, .csv, .txt), initializes import progress in
     * session state, delegates processing to importLibraryThingData, and records results or errors
     * in the session. For AJAX requests returns a JSON response with success or error details;
     * for standard requests returns a redirect to the LibraryThing import admin page.
     *
     * Side effects:
     * - Sets $_SESSION['import_progress'] to track status and progress.
     * - Stores summary messages in $_SESSION['success'] or $_SESSION['error'].
     * - Stores per-row import errors (if any) in $_SESSION['import_errors'].
     *
     * @param Request $request HTTP request containing the uploaded file under key 'tsv_file' and an optional 'enable_scraping' flag.
     * @param Response $response PSR-7 response instance used to produce either a JSON response for AJAX or a redirect.
     * @return Response A redirect response to the import admin page for normal requests, or a JSON response for AJAX requests.
    public function processImport(Request $request, Response $response, \mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['tsv_file'])) {
            $_SESSION['error'] = __('Nessun file caricato');
            return $response->withHeader('Location', '/admin/libri/import/librarything')->withStatus(302);
        }

        $uploadedFile = $uploadedFiles['tsv_file'];

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = __('Errore nel caricamento del file');
            return $response->withHeader('Location', '/admin/libri/import/librarything')->withStatus(302);
        }

        // Validazione estensione file (TSV or CSV)
        $filename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['tsv', 'csv', 'txt'], true)) {
            $_SESSION['error'] = __('Il file deve avere estensione .tsv, .csv o .txt');
            return $response->withHeader('Location', '/admin/libri/import/librarything')->withStatus(302);
        }

        $tmpFile = $uploadedFile->getStream()->getMetadata('uri');
        $enableScraping = !empty($data['enable_scraping']);

        // Initialize progress tracking
        $_SESSION['import_progress'] = [
            'status' => 'processing',
            'current' => 0,
            'total' => 0,
            'current_book' => ''
        ];

        /** @var bool $isAjax */
        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

        try {
            $result = $this->importLibraryThingData($tmpFile, $db, $enableScraping);

            $_SESSION['import_progress'] = [
                'status' => 'completed',
                'current' => $result['imported'],
                'total' => $result['imported']
            ];

            $message = sprintf(
                __('Import LibraryThing completato: %d libri nuovi, %d libri aggiornati, %d autori creati, %d editori creati'),
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

            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'redirect' => '/admin/libri/import/librarything',
                    'message' => $message
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = sprintf(__('Errore durante l\'import: %s'), $e->getMessage());
            $_SESSION['import_progress'] = ['status' => 'error'];

            // phpstan incorrectly infers this condition is always true in catch block
            /** @phpstan-ignore if.alwaysTrue */
            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        }

        return $response->withHeader('Location', '/admin/libri/import/librarything')->withStatus(302);
    }

    /**
         * Import records from a LibraryThing TSV file into the local database.
         *
         * Parses the provided TSV, upserts books (creating publishers/authors as needed), associates authors,
         * updates import progress in session, and optionally enriches records via web scraping.
         *
         * @param string $filePath Path to the LibraryThing TSV file.
         * @param \mysqli $db Active MySQLi database connection used for queries and transactions.
         * @param bool $enableScraping If true, attempts to fetch missing metadata via web scraping (subject to rate limits and timeouts).
         * @return array Associative array with import statistics:
         *               - 'imported' (int): number of newly created books.
         *               - 'updated' (int): number of existing books updated.
         *               - 'authors_created' (int): number of author records created.
         *               - 'publishers_created' (int): number of publisher records created.
         *               - 'scraped' (int): number of records successfully enriched by scraping.
         *               - 'errors' (string[]): array of error messages encountered during import.
         */
    private function importLibraryThingData(string $filePath, \mysqli $db, bool $enableScraping = false): array
    {
        $imported = 0;
        $updated = 0;
        $authorsCreated = 0;
        $publishersCreated = 0;
        $scraped = 0;
        $errors = [];

        // Scraping limits
        $maxScrapingTime = 300; // 5 minutes
        $maxScrapeItems = 50;
        $scrapingStartTime = time();

        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new \Exception(__('Impossibile aprire il file'));
        }

        // Skip BOM if present
        $bom = fread($file, 3);
        $hasBom = ($bom === "\xEF\xBB\xBF");
        if (!$hasBom) {
            rewind($file);
        }

        // Read headers (tab-separated)
        $headers = fgetcsv($file, 0, "\t", '"');
        if (!$headers) {
            fclose($file);
            throw new \Exception(__('File vuoto o formato non valido'));
        }

        // Verify it's a LibraryThing format
        if (!$this->isLibraryThingFormat($headers)) {
            fclose($file);
            throw new \Exception(__('Il file non sembra essere in formato LibraryThing. Colonne richieste: Book Id, Title, Primary Author, ISBNs'));
        }

        // Count total rows
        $totalRows = 0;
        while (fgetcsv($file, 0, "\t", '"') !== false) {
            $totalRows++;
        }

        rewind($file);
        if ($hasBom) {
            fread($file, 3);
        }
        fgetcsv($file, 0, "\t", '"'); // Skip headers

        $_SESSION['import_progress']['total'] = $totalRows;

        $lineNumber = 1;
        $rowCount = 0;
        $maxRows = 10000;

        while (($row = fgetcsv($file, 0, "\t", '"')) !== false) {
            $lineNumber++;
            $rowCount++;

            if ($rowCount > $maxRows) {
                $errors[] = sprintf(__('Numero massimo di righe superato (%d)'), $maxRows);
                break;
            }

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) !== count($headers)) {
                $errors[] = sprintf(__('Riga %d: numero di colonne non corrispondente'), $lineNumber);
                continue;
            }

            $rawData = array_combine($headers, $row);

            try {
                $db->begin_transaction();

                // Parse LibraryThing format to standard format
                $data = $this->parseLibraryThingRow($rawData);

                // Verify required fields
                if (empty($data['titolo'])) {
                    throw new \Exception(__('Titolo obbligatorio mancante'));
                }

                // Get or create publisher
                $editorId = null;
                if (!empty($data['editore'])) {
                    $editorId = $this->getOrCreatePublisher($db, trim($data['editore']));
                    if ($editorId === 'created') {
                        $publishersCreated++;
                        $editorId = $db->insert_id;
                    }
                }

                // Get genre ID
                $genreId = $this->getGenreId($db, $data['genere'] ?? '');

                // Upsert book
                $upsertResult = $this->upsertBook($db, $data, $editorId, $genreId);
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
                if (!empty($data['autori'])) {
                    $authors = array_map('trim', explode('|', $data['autori']));
                    $authorOrder = 1;

                    foreach ($authors as $authorName) {
                        if (empty($authorName)) continue;

                        $authorResult = $this->getOrCreateAuthor($db, $authorName);
                        $authorId = $authorResult['id'];
                        if ($authorResult['created']) {
                            $authorsCreated++;
                        }

                        $stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', ?)");
                        $stmt->bind_param('iii', $bookId, $authorId, $authorOrder);
                        $stmt->execute();
                        $authorOrder++;
                    }
                }

                $db->commit();

                if ($action === 'created') {
                    $imported++;
                } else {
                    $updated++;
                }

                $_SESSION['import_progress']['current'] = $imported;
                $_SESSION['import_progress']['current_book'] = $data['titolo'];

                // Scraping integration
                if ($enableScraping && !empty($data['isbn13']) && $scraped < $maxScrapeItems) {
                    if (time() - $scrapingStartTime > $maxScrapingTime) {
                        $errors[] = sprintf(__('Scraping interrotto: timeout di %d secondi raggiunto'), $maxScrapingTime);
                        $enableScraping = false;
                    } else {
                        try {
                            $scrapedData = $this->scrapeBookData($data['isbn13']);
                            if (!empty($scrapedData)) {
                                $this->enrichBookWithScrapedData($db, $bookId, $data, $scrapedData);
                                $scraped++;
                            }
                            sleep(3); // Rate limiting
                        } catch (\Exception $scrapeError) {
                            error_log("[LibraryThing Import] Scraping failed: " . $scrapeError->getMessage());
                        }
                    }
                }

            } catch (\Exception $e) {
                $db->rollback();
                $title = $data['titolo'] ?? $rawData['Title'] ?? '';
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
     * Check whether a header row matches the LibraryThing TSV/CSV format.
     *
     * Requires the presence of at least two of the LibraryThing-specific columns:
     * "Book Id", "Title", and "ISBNs".
     *
     * @param string[] $headers Array of header names from the input file.
     * @return bool `true` if at least two of the required LibraryThing columns are present, `false` otherwise.
     */
    private function isLibraryThingFormat(array $headers): bool
    {
        $required = ['Book Id', 'Title', 'ISBNs'];
        $foundCount = 0;

        foreach ($required as $col) {
            foreach ($headers as $header) {
                if (trim($header) === $col) {
                    $foundCount++;
                    break;
                }
            }
        }

        return $foundCount >= 2;
    }

    /**
         * Convert a single LibraryThing TSV/CSV row into the controller's standardized book data array.
         *
         * The input is an associative array using LibraryThing column headers (e.g., "Title", "Primary Author",
         * "ISBNs", "Publication", "Date", etc.). The returned array contains normalized keys used by the import
         * pipeline (for example: `id`, `titolo`, `sottotitolo`, `autori`, `editore`, `anno_pubblicazione`,
         * `isbn13`, `isbn10`, `ean`, `lingua`, `numero_pagine`, `descrizione`, `formato`, `genere`, `parole_chiave`,
         * `collana`, `classificazione_dewey`, `prezzo`, `copie_totali` and extended LibraryThing fields like
         * `review`, `rating`, `peso`, `dimensioni`, `data_acquisizione`, `date_started`, `date_read`, `bcid`,
         * `oclc`, `work_id`, `issn`, `source`, `lending_start`, `lending_end`, `value`, `condition_lt`, etc.).
         *
         * @param array $data Associative row from a LibraryThing export keyed by header names.
         * @return array Associative array with normalized book fields ready for upsert/processing by the import pipeline.
         */
    private function parseLibraryThingRow(array $data): array
    {
        $result = [];

        // Book ID
        $result['id'] = !empty($data['Book Id']) ? trim($data['Book Id']) : '';

        // Title
        $result['titolo'] = !empty($data['Title']) ? trim($data['Title']) : '';

        // Subtitle
        $result['sottotitolo'] = !empty($data['Sort Character']) ? trim($data['Sort Character']) : '';

        // Authors (combine primary and secondary)
        $authors = [];
        if (!empty($data['Primary Author'])) {
            $authors[] = trim($data['Primary Author']);
        }
        if (!empty($data['Secondary Author'])) {
            $authors[] = trim($data['Secondary Author']);
        }
        $result['autori'] = !empty($authors) ? implode('|', $authors) : '';

        // Publisher (parse from Publication field)
        if (!empty($data['Publication'])) {
            $publication = $data['Publication'];
            // Try to extract publisher from "City, Publisher, Year" or "Publisher (Year)" format
            if (preg_match('/,\s*([^,]+),\s*\d{4}/', $publication, $matches)) {
                $result['editore'] = trim($matches[1]);
            } elseif (preg_match('/([^(]+)\s*\(\d{4}\)/', $publication, $matches)) {
                $result['editore'] = trim($matches[1]);
            } else {
                // Just use the whole publication field
                $result['editore'] = trim($publication);
            }
        }

        // Year
        if (!empty($data['Date'])) {
            $year = preg_replace('/[^0-9]/', '', $data['Date']);
            if (strlen($year) === 4) {
                $result['anno_pubblicazione'] = $year;
            }
        }

        // ISBNs
        $isbnField = !empty($data['ISBNs']) ? $data['ISBNs'] : ($data['ISBN'] ?? '');
        if (!empty($isbnField)) {
            $isbnField = trim($isbnField, '[]');
            $isbns = preg_split('/[,\s]+/', $isbnField);

            foreach ($isbns as $isbn) {
                $isbn = preg_replace('/[^0-9X]/', '', trim($isbn));
                if (empty($isbn)) continue;

                if (strlen($isbn) === 13) {
                    if (empty($result['isbn13'])) {
                        $result['isbn13'] = $isbn;
                    }
                } elseif (strlen($isbn) === 10) {
                    if (empty($result['isbn10'])) {
                        $result['isbn10'] = $isbn;
                    }
                }
            }
        }

        // EAN/Barcode
        $result['ean'] = !empty($data['Barcode']) ? trim($data['Barcode']) : '';

        // Language
        if (!empty($data['Languages'])) {
            $langMap = [
                'italian' => 'italiano',
                'english' => 'inglese',
                'french' => 'francese',
                'german' => 'tedesco',
                'spanish' => 'spagnolo',
            ];
            $lang = strtolower(trim(explode(',', $data['Languages'])[0]));
            $result['lingua'] = $langMap[$lang] ?? $lang;
        }

        // Pages
        $result['numero_pagine'] = !empty($data['Page Count']) ? preg_replace('/[^0-9]/', '', $data['Page Count']) : '';

        // Description/Summary
        $result['descrizione'] = !empty($data['Summary']) ? trim($data['Summary']) : '';

        // Format/Media
        if (!empty($data['Media'])) {
            $mediaMap = [
                'libro cartaceo' => 'cartaceo',
                'copertina rigida' => 'cartaceo',
                'brossura' => 'cartaceo',
                'hardcover' => 'cartaceo',
                'paperback' => 'cartaceo',
                'ebook' => 'ebook',
                'audiobook' => 'audiolibro',
            ];
            $media = strtolower($data['Media']);
            foreach ($mediaMap as $key => $value) {
                if (str_contains($media, $key)) {
                    $result['formato'] = $value;
                    break;
                }
            }
            if (empty($result['formato'])) {
                $result['formato'] = 'cartaceo';
            }
        }

        // Genre/Subjects
        $result['genere'] = !empty($data['Subjects']) ? trim(explode(',', $data['Subjects'])[0]) : '';

        // Tags/Keywords
        $result['parole_chiave'] = !empty($data['Tags']) ? trim($data['Tags']) : '';

        // Collections (can be used as collana)
        $result['collana'] = !empty($data['Collections']) ? trim($data['Collections']) : '';

        // Dewey Decimal
        $result['classificazione_dewey'] = !empty($data['Dewey Decimal']) ? trim($data['Dewey Decimal']) : '';

        // Price
        if (!empty($data['List Price']) || !empty($data['Purchase Price'])) {
            $price = !empty($data['List Price']) ? $data['List Price'] : $data['Purchase Price'];
            $price = preg_replace('/[^0-9,.]/', '', $price);
            $price = str_replace(',', '.', $price);
            if (is_numeric($price)) {
                $result['prezzo'] = $price;
            }
        }

        // Copies
        $result['copie_totali'] = !empty($data['Copies']) && is_numeric($data['Copies']) ? (int)$data['Copies'] : '1';

        // === LibraryThing Extended Fields (29 additional fields) ===

        // Review and Rating
        $result['review'] = !empty($data['Review']) ? trim($data['Review']) : '';
        $result['rating'] = !empty($data['Rating']) && is_numeric($data['Rating']) ? (int)$data['Rating'] : null;
        $result['comment'] = !empty($data['Comment']) ? trim($data['Comment']) : '';
        $result['private_comment'] = !empty($data['Private Comment']) ? trim($data['Private Comment']) : '';

        // Physical Description
        $result['physical_description'] = !empty($data['Physical Description']) ? trim($data['Physical Description']) : '';

        // Weight → peso (native field)
        if (!empty($data['Weight'])) {
            $weight = trim($data['Weight']);
            // Try to extract numeric value (e.g., "1.2 kg" → 1.2)
            if (preg_match('/([0-9.]+)/', $weight, $matches)) {
                $result['peso'] = (float)$matches[1];
            }
        }

        // Dimensions → dimensioni (native field, combine height/thickness/length)
        $dimensions = [];
        if (!empty($data['Height'])) $dimensions[] = 'H: ' . trim($data['Height']);
        if (!empty($data['Thickness'])) $dimensions[] = 'T: ' . trim($data['Thickness']);
        if (!empty($data['Length'])) $dimensions[] = 'L: ' . trim($data['Length']);
        if (!empty($dimensions)) {
            $result['dimensioni'] = implode(' × ', $dimensions);
        }

        // Library Classifications
        $result['lccn'] = !empty($data['LCCN']) ? trim($data['LCCN']) : '';
        $result['lc_classification'] = !empty($data['LC Classification']) ? trim($data['LC Classification']) : '';
        $result['other_call_number'] = !empty($data['Other Call Number']) ? trim($data['Other Call Number']) : '';

        // Date Acquired → data_acquisizione (native field)
        if (!empty($data['Acquired'])) {
            $result['data_acquisizione'] = $this->parseDate($data['Acquired']);
        }

        // Reading Date Tracking (LibraryThing only)
        $result['date_started'] = !empty($data['Date Started']) ? $this->parseDate($data['Date Started']) : '';
        $result['date_read'] = !empty($data['Date Read']) ? $this->parseDate($data['Date Read']) : '';

        // Catalog Identifiers
        $result['bcid'] = !empty($data['BCID']) ? trim($data['BCID']) : '';
        $result['oclc'] = !empty($data['OCLC']) ? trim($data['OCLC']) : '';
        $result['work_id'] = !empty($data['Work id']) ? trim($data['Work id']) : '';
        $result['issn'] = !empty($data['ISSN']) ? trim($data['ISSN']) : '';

        // Languages
        $result['original_languages'] = !empty($data['Original Languages']) ? trim($data['Original Languages']) : '';

        // Acquisition Info
        $result['source'] = !empty($data['Source']) ? trim($data['Source']) : '';
        $result['from_where'] = !empty($data['From Where']) ? trim($data['From Where']) : '';

        // Lending Tracking
        $result['lending_patron'] = !empty($data['Lending Patron']) ? trim($data['Lending Patron']) : '';
        $result['lending_status'] = !empty($data['Lending Status']) ? trim($data['Lending Status']) : '';
        $result['lending_start'] = !empty($data['Lending Start']) ? $this->parseDate($data['Lending Start']) : '';
        $result['lending_end'] = !empty($data['Lending End']) ? $this->parseDate($data['Lending End']) : '';

        // Financial and Condition Fields
        // Note: Purchase Price already handled above in 'prezzo' field (native)

        // Current Value (LibraryThing only - different from purchase price)
        if (!empty($data['Value'])) {
            $value = preg_replace('/[^0-9,.]/', '', $data['Value']);
            $value = str_replace(',', '.', $value);
            if (is_numeric($value)) {
                $result['value'] = $value;
            }
        }

        // Physical Condition
        $result['condition_lt'] = !empty($data['Condition']) ? trim($data['Condition']) : '';

        return $result;
    }

    /**
     * Normalize a LibraryThing date string to MySQL DATE (YYYY-MM-DD).
     *
     * Attempts to parse common date formats and returns a normalized date string,
     * or `null` when the input cannot be resolved to a valid date.
     *
     * @param string $dateString Date string in various possible formats.
     * @return string|null MySQL date (YYYY-MM-DD) or `null` if unparseable.
     */
    private function parseDate(string $dateString): ?string
    {
        $dateString = trim($dateString);
        if (empty($dateString)) {
            return null;
        }

        // Try to parse with strtotime
        $timestamp = strtotime($dateString);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        // Try to extract year-month-day pattern
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $dateString, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[1], $matches[2], $matches[3]);
        }

        return null;
    }

    /**
     * Retrieve a publisher ID by name, creating a new publisher record if none exists.
     *
     * Creates a new publisher row when the given name is not found.
     *
     * @param string $name The publisher name to look up or create.
     * @return int|string The existing publisher ID as an `int`, or the string `'created'` if a new publisher was inserted.
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

        $stmt = $db->prepare("INSERT INTO editori (nome, created_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $name);
        $stmt->execute();

        return 'created';
    }

    /**
     * Fetches an author by name or creates a new author when none exists.
     *
     * @return array An associative array with keys:
     *               - 'id': int The author's ID.
     *               - 'created': bool `true` if a new author was inserted, `false` if an existing author was found.
     */
    private function getOrCreateAuthor(\mysqli $db, string $name): array
    {
        $authRepo = new \App\Models\AuthorRepository($db);
        $existingId = $authRepo->findByName($name);
        if ($existingId) {
            return ['id' => $existingId, 'created' => false];
        }

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
     * Retrieve the genre ID for the given genre name.
     *
     * Returns the genre's numeric id if a genre with the exact name exists in the `generi` table, or `null` if the name is empty or no match is found.
     *
     * @param string $name The genre name to look up (exact match).
     * @return int|null The genre id if found, `null` otherwise.
     */
    private function getGenreId(\mysqli $db, string $name): ?int
    {
        if (empty($name)) return null;

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
     * Locate an existing book record using an internal book ID or an ISBN-13.
     *
     * Checks for a numeric 'id' in $data and, if present, looks up that book (ignoring deleted records).
     * If no id is provided or not found, attempts lookup by 'isbn13'.
     *
     * @param \mysqli $db Database connection.
     * @param array $data Associative array with optional keys:
     *                    - 'id' (int|string): internal book id to search for.
     *                    - 'isbn13' (string): ISBN-13 to search for.
     * @return int|null The found book's id, or `null` if no matching book exists.
     */
    private function findExistingBook(\mysqli $db, array $data): ?int
    {
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

        return null;
    }

    /**
     * Inserts a new book or updates an existing one based on the provided data, returning the book id and performed action.
     *
     * @param \mysqli $db Database connection used for lookups and modifications.
     * @param array $data Associative array of normalized book fields (may include internal id or ISBNs to locate existing records).
     * @param int|null $editorId Optional editor/publisher id to associate with the book if creating or updating.
     * @param int|null $genreId Optional genre id to associate with the book if creating or updating.
     * @return array An associative array with keys:
     *               - `id` (int): the id of the created or updated book,
     *               - `action` (string): either `'created'` if a new book was inserted or `'updated'` if an existing book was modified.
     */
    private function upsertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): array
    {
        $existingBookId = $this->findExistingBook($db, $data);

        if ($existingBookId !== null) {
            $this->updateBook($db, $existingBookId, $data, $editorId, $genreId);
            return ['id' => $existingBookId, 'action' => 'updated'];
        } else {
            $newBookId = $this->insertBook($db, $data, $editorId, $genreId);
            return ['id' => $newBookId, 'action' => 'created'];
        }
    }

    /**
     * Update an existing book record with provided native and LibraryThing fields.
     *
     * When the LibraryThing plugin is installed, this updates an extended set of LibraryThing-specific
     * columns in addition to the core book fields; otherwise it updates only the core fields.
     *
     * @param int $bookId The ID of the book to update.
     * @param array $data Associative array of book values mapped from LibraryThing input. Expected keys include
     *                    titolo, sottotitolo, isbn10, isbn13, ean, anno_pubblicazione, lingua, edizione,
     *                    numero_pagine, descrizione, formato, prezzo, collana, numero_serie, traduttore,
     *                    parole_chiave, classificazione_dewey and, when available, LibraryThing-specific keys
     *                    such as peso, dimensioni, data_acquisizione, review, rating, comment, private_comment,
     *                    physical_description, lccn, lc_classification, other_call_number, date_started,
     *                    date_read, bcid, oclc, work_id, issn, original_languages, source, from_where,
     *                    lending_patron, lending_status, lending_start, lending_end, value, condition_lt.
     * @param int|null $editorId ID of the publisher (editore) to set, or null to leave unset.
     * @param int|null $genreId ID of the genre (genere) to set, or null to leave unset.
     */
    private function updateBook(\mysqli $db, int $bookId, array $data, ?int $editorId, ?int $genreId): void
    {
        // Check if LibraryThing plugin is installed
        $hasLTFields = LibraryThingInstaller::isInstalled($db);

        if ($hasLTFields) {
            // Full update with all LibraryThing fields
            $stmt = $db->prepare("
                UPDATE libri SET
                    isbn10 = ?, isbn13 = ?, ean = ?, titolo = ?, sottotitolo = ?,
                    anno_pubblicazione = ?, lingua = ?, edizione = ?, numero_pagine = ?,
                    genere_id = ?, descrizione = ?, formato = ?, prezzo = ?, editore_id = ?,
                    collana = ?, numero_serie = ?, traduttore = ?, parole_chiave = ?,
                    classificazione_dewey = ?, peso = ?, dimensioni = ?, data_acquisizione = ?,
                    review = ?, rating = ?, comment = ?, private_comment = ?,
                    physical_description = ?,
                    lccn = ?, lc_classification = ?, other_call_number = ?,
                    date_started = ?, date_read = ?,
                    bcid = ?, oclc = ?, work_id = ?, issn = ?,
                    original_languages = ?, source = ?, from_where = ?,
                    lending_patron = ?, lending_status = ?, lending_start = ?, lending_end = ?,
                    value = ?, condition_lt = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $params = [
                !empty($data['isbn10']) ? $data['isbn10'] : null,
                !empty($data['isbn13']) ? $data['isbn13'] : null,
                !empty($data['ean']) ? $data['ean'] : null,
                $data['titolo'],
                !empty($data['sottotitolo']) ? $data['sottotitolo'] : null,
                !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null,
                !empty($data['lingua']) ? $data['lingua'] : 'italiano',
                !empty($data['edizione']) ? $data['edizione'] : null,
                !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null,
                $genreId,
                !empty($data['descrizione']) ? $data['descrizione'] : null,
                !empty($data['formato']) ? $data['formato'] : 'cartaceo',
                !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null,
                $editorId,
                !empty($data['collana']) ? $data['collana'] : null,
                !empty($data['numero_serie']) ? $data['numero_serie'] : null,
                !empty($data['traduttore']) ? $data['traduttore'] : null,
                !empty($data['parole_chiave']) ? $data['parole_chiave'] : null,
                !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null,
                // Native fields from LibraryThing mapping
                !empty($data['peso']) ? (float) $data['peso'] : null,
                !empty($data['dimensioni']) ? $data['dimensioni'] : null,
                !empty($data['data_acquisizione']) ? $data['data_acquisizione'] : null,
                // LibraryThing-specific fields (25 unique fields)
                !empty($data['review']) ? $data['review'] : null,
                !empty($data['rating']) ? (int) $data['rating'] : null,
                !empty($data['comment']) ? $data['comment'] : null,
                !empty($data['private_comment']) ? $data['private_comment'] : null,
                !empty($data['physical_description']) ? $data['physical_description'] : null,
                !empty($data['lccn']) ? $data['lccn'] : null,
                !empty($data['lc_classification']) ? $data['lc_classification'] : null,
                !empty($data['other_call_number']) ? $data['other_call_number'] : null,
                !empty($data['date_started']) ? $data['date_started'] : null,
                !empty($data['date_read']) ? $data['date_read'] : null,
                !empty($data['bcid']) ? $data['bcid'] : null,
                !empty($data['oclc']) ? $data['oclc'] : null,
                !empty($data['work_id']) ? $data['work_id'] : null,
                !empty($data['issn']) ? $data['issn'] : null,
                !empty($data['original_languages']) ? $data['original_languages'] : null,
                !empty($data['source']) ? $data['source'] : null,
                !empty($data['from_where']) ? $data['from_where'] : null,
                !empty($data['lending_patron']) ? $data['lending_patron'] : null,
                !empty($data['lending_status']) ? $data['lending_status'] : null,
                !empty($data['lending_start']) ? $data['lending_start'] : null,
                !empty($data['lending_end']) ? $data['lending_end'] : null,
                !empty($data['value']) ? (float) str_replace(',', '.', $data['value']) : null,
                !empty($data['condition_lt']) ? $data['condition_lt'] : null,
                $bookId
            ];

            $types = 'sssssissiissdisssssdssisssisssssssssssssssssdssi';
            $stmt->bind_param($types, ...$params);
        } else {
            // Basic update without LibraryThing fields (plugin not installed)
            $stmt = $db->prepare("
                UPDATE libri SET
                    isbn10 = ?, isbn13 = ?, ean = ?, titolo = ?, sottotitolo = ?,
                    anno_pubblicazione = ?, lingua = ?, edizione = ?, numero_pagine = ?,
                    genere_id = ?, descrizione = ?, formato = ?, prezzo = ?, editore_id = ?,
                    collana = ?, numero_serie = ?, traduttore = ?, parole_chiave = ?,
                    classificazione_dewey = ?, updated_at = NOW()
                WHERE id = ?
            ");

            $params = [
                !empty($data['isbn10']) ? $data['isbn10'] : null,
                !empty($data['isbn13']) ? $data['isbn13'] : null,
                !empty($data['ean']) ? $data['ean'] : null,
                $data['titolo'],
                !empty($data['sottotitolo']) ? $data['sottotitolo'] : null,
                !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null,
                !empty($data['lingua']) ? $data['lingua'] : 'italiano',
                !empty($data['edizione']) ? $data['edizione'] : null,
                !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null,
                $genreId,
                !empty($data['descrizione']) ? $data['descrizione'] : null,
                !empty($data['formato']) ? $data['formato'] : 'cartaceo',
                !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null,
                $editorId,
                !empty($data['collana']) ? $data['collana'] : null,
                !empty($data['numero_serie']) ? $data['numero_serie'] : null,
                !empty($data['traduttore']) ? $data['traduttore'] : null,
                !empty($data['parole_chiave']) ? $data['parole_chiave'] : null,
                !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null,
                $bookId
            ];

            $types = 'sssssissiissdisssssi';
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Insert a new book record into the database, create its physical copy records, and update availability.
     *
     * When the LibraryThing plugin is installed, the record includes the extended LibraryThing fields; otherwise a reduced
     * set of core fields is stored. This method also creates one or more physical copies (inventory items) for the new
     * book and recalculates its availability.
     *
     * @param \mysqli $db Database connection.
     * @param array $data Associative array with book fields (e.g., titolo, isbn13, isbn10, ean, descrizione, numero_pagine,
     *                    formato, prezzo, copie_totali, data_acquisizione and optional LibraryThing-specific keys).
     * @param int|null $editorId ID of the publisher (editore) or null to leave unset.
     * @param int|null $genreId ID of the genre (genere) or null to leave unset.
     * @return int The newly inserted book ID.
     */
    private function insertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): int
    {
        // Check if LibraryThing plugin is installed
        $hasLTFields = LibraryThingInstaller::isInstalled($db);

        $copie = !empty($data['copie_totali']) ? (int) $data['copie_totali'] : 1;
        if ($copie < 1) $copie = 1;
        elseif ($copie > 100) $copie = 100;

        if ($hasLTFields) {
            // Full insert with all LibraryThing fields (25 unique LT + native fields)
            $stmt = $db->prepare("
                INSERT INTO libri (
                    isbn10, isbn13, ean, titolo, sottotitolo, anno_pubblicazione,
                    lingua, edizione, numero_pagine, genere_id, descrizione, formato,
                    prezzo, copie_totali, copie_disponibili, editore_id, collana,
                    numero_serie, traduttore, parole_chiave, classificazione_dewey,
                    peso, dimensioni, data_acquisizione,
                    review, rating, comment, private_comment,
                    physical_description,
                    lccn, lc_classification, other_call_number,
                    date_started, date_read,
                    bcid, oclc, work_id, issn,
                    original_languages, source, from_where,
                    lending_patron, lending_status, lending_start, lending_end,
                    value, condition_lt,
                    stato, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    'disponibile', NOW()
                )
            ");

            $params = [
                !empty($data['isbn10']) ? $data['isbn10'] : null,
                !empty($data['isbn13']) ? $data['isbn13'] : null,
                !empty($data['ean']) ? $data['ean'] : null,
                $data['titolo'],
                !empty($data['sottotitolo']) ? $data['sottotitolo'] : null,
                !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null,
                !empty($data['lingua']) ? $data['lingua'] : 'italiano',
                !empty($data['edizione']) ? $data['edizione'] : null,
                !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null,
                $genreId,
                !empty($data['descrizione']) ? $data['descrizione'] : null,
                !empty($data['formato']) ? $data['formato'] : 'cartaceo',
                !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null,
                $copie,
                $copie,
                $editorId,
                !empty($data['collana']) ? $data['collana'] : null,
                !empty($data['numero_serie']) ? $data['numero_serie'] : null,
                !empty($data['traduttore']) ? $data['traduttore'] : null,
                !empty($data['parole_chiave']) ? $data['parole_chiave'] : null,
                !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null,
                // Native fields from LibraryThing mapping
                !empty($data['peso']) ? (float) $data['peso'] : null,
                !empty($data['dimensioni']) ? $data['dimensioni'] : null,
                !empty($data['data_acquisizione']) ? $data['data_acquisizione'] : null,
                // LibraryThing unique fields (25)
                !empty($data['review']) ? $data['review'] : null,
                !empty($data['rating']) ? (int) $data['rating'] : null,
                !empty($data['comment']) ? $data['comment'] : null,
                !empty($data['private_comment']) ? $data['private_comment'] : null,
                !empty($data['physical_description']) ? $data['physical_description'] : null,
                !empty($data['lccn']) ? $data['lccn'] : null,
                !empty($data['lc_classification']) ? $data['lc_classification'] : null,
                !empty($data['other_call_number']) ? $data['other_call_number'] : null,
                !empty($data['date_started']) ? $data['date_started'] : null,
                !empty($data['date_read']) ? $data['date_read'] : null,
                !empty($data['bcid']) ? $data['bcid'] : null,
                !empty($data['oclc']) ? $data['oclc'] : null,
                !empty($data['work_id']) ? $data['work_id'] : null,
                !empty($data['issn']) ? $data['issn'] : null,
                !empty($data['original_languages']) ? $data['original_languages'] : null,
                !empty($data['source']) ? $data['source'] : null,
                !empty($data['from_where']) ? $data['from_where'] : null,
                !empty($data['lending_patron']) ? $data['lending_patron'] : null,
                !empty($data['lending_status']) ? $data['lending_status'] : null,
                !empty($data['lending_start']) ? $data['lending_start'] : null,
                !empty($data['lending_end']) ? $data['lending_end'] : null,
                !empty($data['value']) ? (float) str_replace(',', '.', $data['value']) : null,
                !empty($data['condition_lt']) ? $data['condition_lt'] : null
            ];

            $types = 'sssssissiissdiiisssssdssisssisssssssssssssssds';
            $stmt->bind_param($types, ...$params);
        } else {
            // Basic insert without LibraryThing fields (plugin not installed)
            $stmt = $db->prepare("
                INSERT INTO libri (
                    isbn10, isbn13, ean, titolo, sottotitolo, anno_pubblicazione,
                    lingua, edizione, numero_pagine, genere_id, descrizione, formato,
                    prezzo, copie_totali, copie_disponibili, editore_id, collana,
                    numero_serie, traduttore, parole_chiave, classificazione_dewey,
                    stato, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'disponibile', NOW()
                )
            ");

            $params = [
                !empty($data['isbn10']) ? $data['isbn10'] : null,
                !empty($data['isbn13']) ? $data['isbn13'] : null,
                !empty($data['ean']) ? $data['ean'] : null,
                $data['titolo'],
                !empty($data['sottotitolo']) ? $data['sottotitolo'] : null,
                !empty($data['anno_pubblicazione']) ? (int) $data['anno_pubblicazione'] : null,
                !empty($data['lingua']) ? $data['lingua'] : 'italiano',
                !empty($data['edizione']) ? $data['edizione'] : null,
                !empty($data['numero_pagine']) ? (int) $data['numero_pagine'] : null,
                $genreId,
                !empty($data['descrizione']) ? $data['descrizione'] : null,
                !empty($data['formato']) ? $data['formato'] : 'cartaceo',
                !empty($data['prezzo']) ? (float) str_replace(',', '.', $data['prezzo']) : null,
                $copie,
                $copie,
                $editorId,
                !empty($data['collana']) ? $data['collana'] : null,
                !empty($data['numero_serie']) ? $data['numero_serie'] : null,
                !empty($data['traduttore']) ? $data['traduttore'] : null,
                !empty($data['parole_chiave']) ? $data['parole_chiave'] : null,
                !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null
            ];

            $types = 'sssssissiissdiiisssss';
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $bookId = $db->insert_id;

        // Create physical copies
        $copyRepo = new \App\Models\CopyRepository($db);
        $isbn13 = !empty($data['isbn13']) ? $data['isbn13'] : null;
        $isbn10 = !empty($data['isbn10']) ? $data['isbn10'] : null;
        $baseInventario = $isbn13 ?: ($isbn10 ?: "LIB-{$bookId}");

        for ($i = 1; $i <= $copie; $i++) {
            $numeroInventario = $copie > 1 ? "{$baseInventario}-C{$i}" : $baseInventario;
            $note = $copie > 1 ? sprintf(__("Copia %d di %d"), $i, $copie) : null;
            $copyRepo->create($bookId, $numeroInventario, 'disponibile', $note);
        }

        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($bookId);

        return $bookId;
    }

    /**
     * Fetches supplementary book metadata for the given ISBN by invoking the internal scrape endpoint with retries.
     *
     * Attempts up to five times with exponential backoff to retrieve JSON data from the scraper and returns the decoded
     * associative array when successful; returns an empty array if no data is available or all attempts fail.
     *
     * @param string $isbn The ISBN to query.
     * @return array Associative array of scraped book data, or an empty array if scraping fails or returns no data.
     */
    private function scrapeBookData(string $isbn): array
    {
        $scrapeController = new \App\Controllers\ScrapeController();
        $maxAttempts = 5;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
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
            } catch (\Throwable $e) {
                error_log("Scraping attempt $attempt failed: " . $e->getMessage());
            }

            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
                $delaySeconds = min($delaySeconds * 2, 8);
            }
        }

        return [];
    }

    /**
     * Updates a book record with missing cover and description fields using scraped data.
     *
     * Only fills fields that are absent in the provided CSV data: it sets `copertina_url`
     * from `$scrapedData['image']` and `descrizione` from `$scrapedData['description']` when available.
     *
     * @param int   $bookId     ID of the book to update.
     * @param array $csvData    Original CSV/TSV row mapped to internal keys; checks `copertina_url` and `descrizione`.
     * @param array $scrapedData Scraped data keyed by `image` (cover URL) and `description` (text).
     */
    private function enrichBookWithScrapedData(\mysqli $db, int $bookId, array $csvData, array $scrapedData): void
    {
        $updates = [];
        $params = [];
        $types = '';

        if (empty($csvData['copertina_url']) && !empty($scrapedData['image'])) {
            $updates[] = 'copertina_url = ?';
            $params[] = $scrapedData['image'];
            $types .= 's';
        }

        if (empty($csvData['descrizione']) && !empty($scrapedData['description'])) {
            $updates[] = 'descrizione = ?';
            $params[] = $scrapedData['description'];
            $types .= 's';
        }

        if (!empty($updates)) {
            $sql = "UPDATE libri SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $bookId;
            $types .= 'i';

            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Provide the current LibraryThing import progress as a JSON response.
     *
     * Returns a PSR-7 Response whose body is a JSON object with the current import progress.
     * If no progress is active, defaults to `status: "idle"`, `current: 0`, `total: 0`, and `current_book: ""`.
     *
     * @return Response JSON body with keys:
     *   - `status` (string): import state, e.g. "idle", "running", or "done".
     *   - `current` (int): number of rows/books processed so far.
     *   - `total` (int): total number of rows/books to process.
     *   - `current_book` (string): title of the book currently being processed or empty string.
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
     * Export selected books as a LibraryThing-compatible TSV download.
     *
     * Builds a filtered query from request query parameters (search, stato, editore_id, genere_id, autore_id),
     * converts matching book rows to LibraryThing TSV rows, and returns an HTTP response with the TSV file
     * (UTF-8 BOM) as an attachment.
     *
     * @param Request $request HTTP request providing filter query parameters.
     * @param Response $response PSR-7 response used to return the TSV attachment.
     * @param \mysqli $db Database connection used to select books and related metadata.
     * @return Response HTTP response containing the generated TSV file as a downloadable attachment.
     */
    public function exportToLibraryThing(Request $request, Response $response, \mysqli $db): Response
    {
        // Get filters from query parameters
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $stato = $params['stato'] ?? '';
        $editoreId = isset($params['editore_id']) && is_numeric($params['editore_id']) ? (int) $params['editore_id'] : 0;
        $genereId = isset($params['genere_id']) && is_numeric($params['genere_id']) ? (int) $params['genere_id'] : 0;
        $autoreId = isset($params['autore_id']) && is_numeric($params['autore_id']) ? (int) $params['autore_id'] : 0;

        // Build WHERE clause
        $whereClauses = [];
        $bindTypes = '';
        $bindValues = [];

        if (!empty($search)) {
            $whereClauses[] = "(l.titolo LIKE ? OR l.sottotitolo LIKE ? OR l.isbn13 LIKE ? OR l.isbn10 LIKE ? OR a.nome LIKE ? OR e.nome LIKE ?)";
            $searchParam = "%{$search}%";
            for ($i = 0; $i < 6; $i++) {
                $bindTypes .= 's';
                $bindValues[] = $searchParam;
            }
        }

        if (!empty($stato)) {
            $whereClauses[] = "l.stato = ?";
            $bindTypes .= 's';
            $bindValues[] = $stato;
        }

        if ($editoreId > 0) {
            $whereClauses[] = "l.editore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $editoreId;
        }

        if ($genereId > 0) {
            $whereClauses[] = "l.genere_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $genereId;
        }

        if ($autoreId > 0) {
            $whereClauses[] = "la.autore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $autoreId;
        }

        // Build query
        $query = "
            SELECT
                l.*,
                GROUP_CONCAT(DISTINCT a.nome ORDER BY la.ordine_credito SEPARATOR ';') as autori_nomi,
                e.nome as editore_nome,
                g.nome as genere_nome
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
        ";

        if (!empty($whereClauses)) {
            $query .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $query .= " GROUP BY l.id ORDER BY l.id DESC";

        // Execute query
        if (!empty($bindValues)) {
            $stmt = $db->prepare($query);
            $refs = [];
            foreach ($bindValues as $key => $value) {
                $refs[$key] = &$bindValues[$key];
            }
            array_unshift($refs, $bindTypes);
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($query);
        }

        $libri = [];
        while ($row = $result->fetch_assoc()) {
            $libri[] = $row;
        }

        if (isset($stmt)) {
            $stmt->close();
        }

        // Create TSV file in memory
        $stream = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'r+');

        // UTF-8 BOM
        fwrite($stream, "\xEF\xBB\xBF");

        // LibraryThing headers (TSV format)
        $headers = $this->getLibraryThingHeaders();
        fwrite($stream, implode("\t", $headers) . "\n");

        $rowCount = 0;
        foreach ($libri as $libro) {
            $row = $this->formatLibraryThingRow($libro);

            // Escape fields for TSV
            $escapedRow = array_map(function ($field) {
                $field = str_replace('"', '""', (string) $field);
                // Quote if contains tab, newline, or quotes
                if (strpos($field, "\t") !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $row);

            fwrite($stream, implode("\t", $escapedRow) . "\n");

            // Garbage collection every 1000 rows
            if (++$rowCount % 1000 === 0) {
                gc_collect_cycles();
            }
        }

        rewind($stream);

        $filename = 'librarything_export_' . date('Y-m-d_His') . '.tsv';

        return $response
            ->withHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'")
            ->withBody(new \Slim\Psr7\Stream($stream));
    }

    /**
         * Provide the ordered set of header names used for LibraryThing TSV export/import.
         *
         * The array defines the exact column sequence expected by LibraryThing-compatible TSV files.
         *
         * @return string[] Array of header labels in export/import column order.
         */
    private function getLibraryThingHeaders(): array
    {
        return [
            'Book Id',
            'Title',
            'Sort Character',
            'Primary Author',
            'Primary Author Role',
            'Secondary Author',
            'Secondary Author Roles',
            'Publication',
            'Date',
            'Review',
            'Rating',
            'Comment',
            'Private Comment',
            'Summary',
            'Media',
            'Physical Description',
            'Weight',
            'Height',
            'Thickness',
            'Length',
            'Dimensions',
            'Page Count',
            'LCCN',
            'Acquired',
            'Date Started',
            'Date Read',
            'Barcode',
            'BCID',
            'Tags',
            'Collections',
            'Languages',
            'Original Languages',
            'LC Classification',
            'ISBN',
            'ISBNs',
            'Subjects',
            'Dewey Decimal',
            'Dewey Wording',
            'Other Call Number',
            'Copies',
            'Source',
            'Entry Date',
            'From Where',
            'OCLC',
            'Work id',
            'Lending Patron',
            'Lending Status',
            'Lending Start',
            'Lending End',
            'List Price',
            'Purchase Price',
            'Value',
            'Condition',
            'ISSN'
        ];
    }

    /**
         * Convert an internal book record into an ordered array of fields suitable for LibraryThing TSV export.
         *
         * The returned array contains values placed in the exact column order expected by the LibraryThing export format,
         * including primary and secondary author fields, publication string (publisher with optional year), media and language
         * labels, consolidated ISBN fields (single ISBN value and a combined bracketed ISBN list), and other LibraryThing-specific
         * metadata columns. Missing values are returned as empty strings.
         *
         * @param array $libro Associative book record (database row) with keys like 'id', 'titolo', 'autori_nomi', 'editore_nome',
         *                     'anno_pubblicazione', 'isbn13', 'isbn10', 'formato', 'lingua', and other metadata fields.
         * @return array Ordered list of field values corresponding to LibraryThing TSV columns.
         */
    private function formatLibraryThingRow(array $libro): array
    {
        // Parse authors
        $autori = $libro['autori_nomi'] ?? '';
        $autoriArray = !empty($autori) ? explode(';', $autori) : [];
        $primaryAuthor = $autoriArray[0] ?? '';
        $secondaryAuthor = $autoriArray[1] ?? '';

        // Map formato to Media
        $formatoMap = [
            'cartaceo' => 'Libro cartaceo',
            'ebook' => 'eBook',
            'audiolibro' => 'Audiobook',
            'rivista' => 'Magazine',
        ];
        $media = $formatoMap[$libro['formato'] ?? 'cartaceo'] ?? 'Libro cartaceo';

        // Build publication string
        $publication = '';
        if (!empty($libro['editore_nome'])) {
            $publication = $libro['editore_nome'];
            if (!empty($libro['anno_pubblicazione'])) {
                $publication .= ' (' . $libro['anno_pubblicazione'] . ')';
            }
        }

        // Map lingua to Languages
        $linguaMap = [
            'italiano' => 'Italian',
            'inglese' => 'English',
            'francese' => 'French',
            'tedesco' => 'German',
            'spagnolo' => 'Spanish',
        ];
        $language = $linguaMap[$libro['lingua'] ?? 'italiano'] ?? ucfirst($libro['lingua'] ?? 'Italian');

        // Build ISBNs
        $isbns = [];
        if (!empty($libro['isbn13'])) {
            $isbns[] = $libro['isbn13'];
        }
        if (!empty($libro['isbn10'])) {
            $isbns[] = $libro['isbn10'];
        }
        $isbnString = !empty($isbns) ? '[' . implode(', ', $isbns) . ']' : '';

        return [
            $libro['id'] ?? '',
            $libro['titolo'] ?? '',
            $libro['sottotitolo'] ?? '',
            $primaryAuthor,
            '',  // Primary Author Role
            $secondaryAuthor,
            '',  // Secondary Author Roles
            $publication,
            $libro['anno_pubblicazione'] ?? '',
            $libro['review'] ?? '',
            $libro['rating'] ?? '',
            $libro['comment'] ?? '',
            $libro['private_comment'] ?? '',
            $libro['descrizione'] ?? '',
            $media,
            $libro['physical_description'] ?? '',
            $libro['weight'] ?? '',
            $libro['height'] ?? '',
            $libro['thickness'] ?? '',
            $libro['length'] ?? '',
            '',  // Dimensions
            $libro['numero_pagine'] ?? '',
            $libro['lccn'] ?? '',
            $libro['date_acquired'] ?? '',
            $libro['date_started'] ?? '',
            $libro['date_read'] ?? '',
            $libro['ean'] ?? '',
            $libro['bcid'] ?? '',
            $libro['parole_chiave'] ?? '',
            $libro['collana'] ?? '',
            $language,
            $libro['original_languages'] ?? '',
            $libro['lc_classification'] ?? '',
            $libro['isbn13'] ?? $libro['isbn10'] ?? '',
            $isbnString,
            $libro['genere_nome'] ?? '',
            $libro['classificazione_dewey'] ?? '',
            '',  // Dewey Wording
            $libro['other_call_number'] ?? '',
            $libro['copie_totali'] ?? '1',
            $libro['source'] ?? '',
            '',  // Entry Date
            $libro['from_where'] ?? '',
            $libro['oclc'] ?? '',
            $libro['work_id'] ?? '',
            $libro['lending_patron'] ?? '',
            $libro['lending_status'] ?? '',
            $libro['lending_start'] ?? '',
            $libro['lending_end'] ?? '',
            $libro['prezzo'] ?? '',
            $libro['purchase_price'] ?? '',
            $libro['value'] ?? '',
            $libro['condition_lt'] ?? '',
            $libro['issn'] ?? ''
        ];
    }
}