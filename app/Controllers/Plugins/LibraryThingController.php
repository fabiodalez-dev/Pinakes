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
     * Show LibraryThing import page
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
     * Show plugin administration page
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
     * Install plugin
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
     * Uninstall plugin
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
     * Process LibraryThing import
     */
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

        $isAjax = !empty($request->getHeaderLine('X-Requested-With')) &&
            $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

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
     * Import LibraryThing TSV data
     *
     * @param string $filePath Path to TSV file
     * @param \mysqli $db Database connection
     * @param bool $enableScraping Enable web scraping for missing metadata
     * @return array Import statistics
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
     * Detect if file is in LibraryThing format
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
     * Parse LibraryThing row to standard format
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

        return $result;
    }

    // Reuse methods from CsvImportController
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

    private function updateBook(\mysqli $db, int $bookId, array $data, ?int $editorId, ?int $genreId): void
    {
        $stmt = $db->prepare("
            UPDATE libri SET
                isbn10 = ?, isbn13 = ?, ean = ?, titolo = ?, sottotitolo = ?,
                anno_pubblicazione = ?, lingua = ?, edizione = ?, numero_pagine = ?,
                genere_id = ?, descrizione = ?, formato = ?, prezzo = ?, editore_id = ?,
                collana = ?, numero_serie = ?, traduttore = ?, parole_chiave = ?,
                classificazione_dewey = ?, updated_at = NOW()
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
        $dewey = !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdisssssi',
            $isbn10, $isbn13, $ean, $titolo, $sottotitolo,
            $anno, $lingua, $edizione, $pagine, $genreId,
            $descrizione, $formato, $prezzo, $editorId,
            $collana, $numeroSerie, $traduttore, $paroleChiave,
            $dewey, $bookId
        );

        $stmt->execute();
        $stmt->close();
    }

    private function insertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): int
    {
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
        if ($copie < 1) $copie = 1;
        elseif ($copie > 100) $copie = 100;
        $collana = !empty($data['collana']) ? $data['collana'] : null;
        $numeroSerie = !empty($data['numero_serie']) ? $data['numero_serie'] : null;
        $traduttore = !empty($data['traduttore']) ? $data['traduttore'] : null;
        $paroleChiave = !empty($data['parole_chiave']) ? $data['parole_chiave'] : null;
        $dewey = !empty($data['classificazione_dewey']) ? $data['classificazione_dewey'] : null;

        $stmt->bind_param(
            'sssssissiissdiiisssss',
            $isbn10, $isbn13, $ean, $titolo, $sottotitolo,
            $anno, $lingua, $edizione, $pagine, $genreId,
            $descrizione, $formato, $prezzo, $copie, $copie,
            $editorId, $collana, $numeroSerie, $traduttore,
            $paroleChiave, $dewey
        );

        $stmt->execute();
        $bookId = $db->insert_id;

        // Create physical copies
        $copyRepo = new \App\Models\CopyRepository($db);
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
     * Get import progress (AJAX endpoint)
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
     * Export books to LibraryThing-compatible TSV format
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
     * Get LibraryThing TSV headers
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
     * Format book row for LibraryThing export
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
            '',  // Review
            '',  // Rating
            '',  // Comment
            '',  // Private Comment
            $libro['descrizione'] ?? '',
            $media,
            '',  // Physical Description
            '',  // Weight
            '',  // Height
            '',  // Thickness
            '',  // Length
            '',  // Dimensions
            $libro['numero_pagine'] ?? '',
            '',  // LCCN
            '',  // Acquired
            '',  // Date Started
            '',  // Date Read
            $libro['ean'] ?? '',
            '',  // BCID
            $libro['parole_chiave'] ?? '',
            $libro['collana'] ?? '',
            $language,
            '',  // Original Languages
            '',  // LC Classification
            $libro['isbn13'] ?? $libro['isbn10'] ?? '',
            $isbnString,
            $libro['genere_nome'] ?? '',
            $libro['classificazione_dewey'] ?? '',
            '',  // Dewey Wording
            '',  // Other Call Number
            $libro['copie_totali'] ?? '1',
            '',  // Source
            '',  // Entry Date
            '',  // From Where
            '',  // OCLC
            '',  // Work id
            '',  // Lending Patron
            '',  // Lending Status
            '',  // Lending Start
            '',  // Lending End
            $libro['prezzo'] ?? '',
            '',  // Purchase Price
            '',  // Value
            '',  // Condition
            ''   // ISSN
        ];
    }
}
