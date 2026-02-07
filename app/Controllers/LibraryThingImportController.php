<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\LibraryThingInstaller;

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
class LibraryThingImportController
{
    /**
     * Number of LibraryThing rows to process per chunk
     * LibraryThing imports are typically larger and more complex than CSV
     * Recommended: 5-10 rows per chunk for LibraryThing TSV files
     */
    private const CHUNK_SIZE = 5;

    /**
     * Write log message to import log file
     */
    private function log(string $message): void
    {
        $logFile = __DIR__ . '/../../writable/logs/import.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [LT] $message\n", FILE_APPEND);
    }

    /**
     * Show LibraryThing import page
     */
    public function showImportPage(Request $request, Response $response): Response
    {

        ob_start();
        $title = "Import LibraryThing";
        require __DIR__ . '/../Views/libri/import_librarything.php';
        $content = ob_get_clean();

        // Wrap content in admin layout
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
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
        include __DIR__ . '/../Views/plugins/librarything_admin.php';
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
     * Prepare LibraryThing import (chunked processing)
     * Step 1: Validate and save file, return metadata
     */
    public function prepareImport(Request $request, Response $response): Response
    {
        // Set timeout to 5 minutes for file upload and preparation
        set_time_limit(300);

        $data = (array) $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['tsv_file'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Nessun file caricato')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $uploadedFile = $uploadedFiles['tsv_file'];

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Errore nel caricamento del file')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validazione estensione file
        $filename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['tsv', 'csv', 'txt'], true)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Il file deve avere estensione .tsv, .csv o .txt')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Save to temporary location
        $tmpDir = sys_get_temp_dir() . '/librarything_imports';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $importId = uniqid('lt_', true);
        $savedPath = $tmpDir . '/' . $importId . '.tsv';
        $uploadedFile->moveTo($savedPath);

        // Validate format and count rows
        try {
            $file = fopen($savedPath, 'r');
            if (!$file) {
                throw new \Exception(__('Impossibile aprire il file'));
            }

            // Skip BOM
            $bom = fread($file, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($file);
            }

            // Read and validate headers
            $headers = fgetcsv($file, 0, "\t", '"', "");
            if (!$headers || !$this->isLibraryThingFormat($headers)) {
                fclose($file);
                unlink($savedPath);
                throw new \Exception(__('Il file non sembra essere in formato LibraryThing'));
            }

            // Count rows
            $totalRows = 0;
            while (fgetcsv($file, 0, "\t", '"', "") !== false) {
                $totalRows++;
            }
            fclose($file);

            // Initialize session data
            $_SESSION['librarything_import'] = [
                'import_id' => $importId,
                'file_path' => $savedPath,
                'original_filename' => $filename,
                'total_rows' => $totalRows,
                'enable_scraping' => !empty($data['enable_scraping']),
                'imported' => 0,
                'updated' => 0,
                'authors_created' => 0,
                'publishers_created' => 0,
                'scraped' => 0,
                'errors' => [],
                'current_row' => 0
            ];

            $response->getBody()->write(json_encode([
                'success' => true,
                'import_id' => $importId,
                'total_rows' => $totalRows,
                'chunk_size' => self::CHUNK_SIZE
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            if (file_exists($savedPath)) {
                unlink($savedPath);
            }
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Process a chunk of LibraryThing import
     * Step 2: Process rows in chunks to avoid timeout
     */
    public function processChunk(Request $request, Response $response, \mysqli $db): Response
    {
        // Set timeout to 10 minutes for import processing (reset for EACH chunk request)
        // This ensures each chunk gets the full timeout, not cumulative
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $data = json_decode((string) $request->getBody(), true);

        // Validate JSON decode result before accessing array keys
        if (!is_array($data)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Payload JSON non valido')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $importId = $data['import_id'] ?? '';
        $chunkStart = (int) ($data['start'] ?? 0);
        $chunkSize = (int) ($data['size'] ?? 10);

        // Validate and cap chunk parameters to prevent DoS
        $chunkStart = max(0, $chunkStart); // Must be >= 0
        $chunkSize = max(1, min($chunkSize, 50)); // Capped at 50 books per chunk

        // Keep session alive during long imports by updating last_regeneration timestamp
        $_SESSION['last_regeneration'] = time();

        if (!isset($_SESSION['librarything_import']) || $_SESSION['librarything_import']['import_id'] !== $importId) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Sessione import non valida')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $importData = &$_SESSION['librarything_import'];
        $filePath = $importData['file_path'];
        $enableScraping = $importData['enable_scraping'];


        try {
            $file = fopen($filePath, 'r');
            if (!$file) {
                throw new \Exception(__('Impossibile aprire il file'));
            }

            // Skip BOM
            $bom = fread($file, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($file);
            }

            // Read headers
            $headers = fgetcsv($file, 0, "\t", '"', "");
            if (!$headers) {
                fclose($file);
                throw new \Exception(__('File vuoto o formato non valido'));
            }

            // Skip to chunk start
            for ($i = 0; $i < $chunkStart; $i++) {
                if (fgetcsv($file, 0, "\t", '"', "") === false) {
                    break;
                }
            }

            // Process chunk
            $processed = 0;
            $lineNumber = $chunkStart + 2; // +2 because of header and 1-indexed

            while ($processed < $chunkSize && ($rawData = fgetcsv($file, 0, "\t", '"', "")) !== false) {
                $parsedData = [];
                try {
                    // Validate column count
                    if (count($rawData) !== count($headers)) {
                        throw new \RuntimeException(__('Numero colonne non valido'));
                    }

                    // Map headers to data (PHPStan verified: count check guarantees success)
                    $row = array_combine($headers, $rawData);

                    $parsedData = $this->parseLibraryThingRow($row);

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
                        $authors = array_map('trim', explode('|', $parsedData['autori']));
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

                    // NOTE: Cover download disabled during import to avoid timeout on shared hosting
                    // Users can download covers later via the standard enrichment system
                    // if (!empty($parsedData['isbn13']) || !empty($parsedData['isbn10'])) {
                    //     try {
                    //         $isbn = $parsedData['isbn13'] ?? $parsedData['isbn10'];
                    //         $this->downloadCoverIfMissing($db, $bookId, $isbn);
                    //     } catch (\Exception $coverError) {
                    //         $this->writeLog("[LibraryThing Import] Cover download failed: " . $coverError->getMessage());
                    //     }
                    // }

                    // Scraping integration for additional metadata
                    if ($enableScraping && !empty($parsedData['isbn13'])) {
                        try {
                            $scrapedData = $this->scrapeBookData($parsedData['isbn13']);
                            if (!empty($scrapedData)) {
                                $this->enrichBookWithScrapedData($db, $bookId, $parsedData, $scrapedData);
                                $importData['scraped']++;
                            }
                        } catch (\Exception $scrapeError) {
                            $this->log("[processChunk] Scraping failed for ISBN {$parsedData['isbn13']}: " . $scrapeError->getMessage());
                            $importData['errors'][] = [
                                'line' => $lineNumber,
                                'title' => $parsedData['titolo'],
                                'message' => 'Scraping fallito - ' . $scrapeError->getMessage(),
                                'type' => 'scraping',
                            ];
                        }
                    }

                } catch (\Throwable $e) {
                    $db->rollback();
                    $title = $parsedData['titolo'] ?? ($rawData[1] ?? '');
                    $importData['errors'][] = [
                        'line' => $lineNumber,
                        'title' => $title,
                        'message' => $e->getMessage(),
                        'type' => 'validation',
                    ];
                    $this->log("[processChunk] ERROR Riga $lineNumber ($title): " . $e->getMessage());
                    $this->log("[processChunk] ERROR Class: " . get_class($e));
                    $this->log("[processChunk] ERROR Trace: " . $e->getTraceAsString());
                }

                $processed++;
                $lineNumber++;
            }

            fclose($file);

            $importData['current_row'] = $chunkStart + $processed;
            $isComplete = $importData['current_row'] >= $importData['total_rows'];

            // Persist import history to database when complete
            if ($isComplete) {
                $persisted = false;
                try {
                    $userId = isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])
                        ? (int)$_SESSION['user']['id']
                        : null;
                    $fileName = $importData['original_filename'] ?? basename($filePath);

                    $importLogger = new \App\Support\ImportLogger($db, 'librarything', $fileName, $userId);

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
                                $err['title'] ?? 'LibraryThing',
                                $err['message'] ?? '',
                                $err['type'] ?? 'validation',
                                false
                            );
                        } else {
                            // Legacy string format fallback
                            $importLogger->addError(0, 'LibraryThing', (string)$err, 'validation', false);
                        }
                    }

                    // Complete and persist
                    $persisted = $importLogger->complete($importData['total_rows']);
                    if (!$persisted) {
                        error_log("[LibraryThingImportController] Failed to persist import history to database");
                        // Mark as failed so the record doesn't stay stuck in 'processing'
                        $importLogger->fail('Failed to persist import history', $importData['total_rows']);
                    }
                } catch (\Throwable $e) {
                    // Log error but don't fail the import (already completed)
                    // Catches \Error/TypeError too (strict_types=1 can throw TypeError)
                    error_log("[LibraryThingImportController] Failed to persist import history (" . get_class($e) . "): " . $e->getMessage());
                    // Mark as failed so the record doesn't stay stuck in 'processing'
                    try {
                        $importLogger->fail($e->getMessage(), $importData['total_rows']);
                    } catch (\Throwable $inner) {
                        error_log("[LibraryThingImportController] Also failed to mark import as failed: " . $inner->getMessage());
                    }
                }

                // Cleanup file only after successful persistence
                if ($persisted && file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'processed' => $processed,
                'current' => $importData['current_row'],
                'total' => $importData['total_rows'],
                'imported' => $importData['imported'],
                'updated' => $importData['updated'],
                'authors_created' => $importData['authors_created'],
                'publishers_created' => $importData['publishers_created'],
                'scraped' => $importData['scraped'],
                'errors' => count($importData['errors']),
                'complete' => $isComplete
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $importData['errors'][] = [
                'line' => 0,
                'title' => 'LibraryThing',
                'message' => $e->getMessage(),
                'type' => 'validation',
            ];
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Get final import results
     */
    public function getImportResults(Request $request, Response $response): Response
    {
        if (!isset($_SESSION['librarything_import'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => __('Nessun import in corso')
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $importData = $_SESSION['librarything_import'];

        $message = sprintf(
            __('Import LibraryThing completato: %d libri nuovi, %d libri aggiornati, %d autori creati, %d editori creati'),
            $importData['imported'],
            $importData['updated'],
            $importData['authors_created'],
            $importData['publishers_created']
        );

        if ($importData['enable_scraping'] && $importData['scraped'] > 0) {
            $message .= sprintf(__(', %d libri arricchiti con scraping'), $importData['scraped']);
        }

        if (!empty($importData['errors'])) {
            $message .= sprintf(__(', %d errori'), count($importData['errors']));
        }

        $_SESSION['success'] = $message;
        if (!empty($importData['errors'])) {
            // Convert structured errors to display strings for the view
            $_SESSION['import_errors'] = array_map(function ($err) {
                if (is_array($err)) {
                    return sprintf(
                        'Riga %d (%s): %s',
                        $err['line'] ?? 0,
                        htmlspecialchars($err['title'] ?? 'LibraryThing', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($err['message'] ?? '', ENT_QUOTES, 'UTF-8')
                    );
                }
                return htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8');
            }, $importData['errors']);
        }

        // Clear import session
        unset($_SESSION['librarything_import']);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $message,
            'redirect' => '/admin/libri/import/librarything'
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Legacy process import (kept for backwards compatibility)
     * @deprecated Use prepareImport + processChunk instead
     */
    public function processImport(Request $request, Response $response, \mysqli $db): Response
    {
        // Redirect to new chunked processing
        return $this->prepareImport($request, $response);
    }

    /**
     * Detect if file is in LibraryThing format
     * Validates that all required columns used by the importer are present
     */
    private function isLibraryThingFormat(array $headers): bool
    {
        // Required columns that the importer expects
        $required = ['Book Id', 'Title', 'ISBNs', 'Primary Author'];

        // Normalize headers for case-insensitive comparison
        $normalizedHeaders = array_map(function($h) {
            return strtolower(trim($h));
        }, $headers);

        // Check that all required columns exist
        foreach ($required as $col) {
            $found = false;
            $normalizedCol = strtolower($col);

            foreach ($normalizedHeaders as $header) {
                if ($header === $normalizedCol) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fix common UTF-8 encoding issues from LibraryThing export
     * Converts corrupted characters back to their correct form
     */
    private function fixUtf8Encoding(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // Common replacements for double-encoded or corrupted UTF-8
        $replacements = [
            // ©♭ → é (e with acute accent)
            "\xC2\xA9\xE2\x99\xAD" => "\xC3\xA9",  // Pappé
            // ©¨ → è (e with grave accent)
            "\xC2\xA9\xC2\xA8" => "\xC3\xA8",
            // ©† → à (a with grave)
            "\xC2\xA9\xE2\x80\xA0" => "\xC3\xA0",
            // More common double-encoded patterns
            "Ã©" => "é",
            "Ã¨" => "è",
            "Ã " => "à",
            "Ã¹" => "ù",
            "Ã²" => "ò",
            "Ã¬" => "ì",
        ];

        $fixed = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Ensure valid UTF-8
        if (!mb_check_encoding($fixed, 'UTF-8')) {
            $fixed = mb_convert_encoding($fixed, 'UTF-8', 'UTF-8');
        }

        return $fixed;
    }

    /**
     * Parse LibraryThing row to standard format
     */
    private function parseLibraryThingRow(array $data): array
    {
        // Fix UTF-8 encoding issues in all string values
        $data = array_map(function ($value) {
            return is_string($value) ? $this->fixUtf8Encoding($value) : $value;
        }, $data);

        $result = [];

        // Book ID
        $result['id'] = !empty($data['Book Id']) ? trim($data['Book Id']) : '';

        // Title
        $result['titolo'] = !empty($data['Title']) ? trim($data['Title']) : '';

        // Subtitle (LibraryThing doesn't export subtitles, leave empty)
        $result['sottotitolo'] = '';

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

        // Year - extract first 4-digit year (handles ISO dates like "2020-05-01")
        if (!empty($data['Date'])) {
            if (preg_match('/\b(\d{4})\b/', $data['Date'], $matches)) {
                $result['anno_pubblicazione'] = $matches[1];
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

        // Collections (LibraryThing categories, not actual book series - ignore or append to keywords)
        // Note: Collections like "Your library" are not useful, so we skip this field
        // If you want to import Collections, uncomment and map to parole_chiave:
        // if (!empty($data['Collections'])) {
        //     $result['parole_chiave'] .= (!empty($result['parole_chiave']) ? ', ' : '') . trim($data['Collections']);
        // }

        // Dewey Decimal and Description
        $result['classificazione_dewey'] = !empty($data['Dewey Decimal']) ? trim($data['Dewey Decimal']) : '';
        $result['dewey_wording'] = !empty($data['Dewey Wording']) ? trim($data['Dewey Wording']) : '';

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
        $rating = !empty($data['Rating']) && is_numeric($data['Rating']) ? (int) $data['Rating'] : null;
        $result['rating'] = ($rating !== null && $rating >= 1 && $rating <= 5) ? $rating : null;
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

        // LibraryThing Entry Date
        $result['entry_date'] = !empty($data['Entry Date']) ? $this->parseDate($data['Entry Date']) : '';

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
     * Parse date from LibraryThing format to MySQL DATE format
     *
     * @param string $dateString Date string in various formats
     * @return string|null MySQL DATE format (YYYY-MM-DD) or null
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

    // Reuse methods from CsvImportController
    private function getOrCreatePublisher(\mysqli $db, string $name): array
    {
        $stmt = $db->prepare("SELECT id FROM editori WHERE nome = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return ['id' => (int) $row['id'], 'created' => false];
        }
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO editori (nome, created_at) VALUES (?, NOW())");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $insertId = $db->insert_id;
        $stmt->close();

        return ['id' => $insertId, 'created' => true];
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
            $stmt->close();
            return (int) $row['id'];
        }

        $stmt->close();
        return null;
    }

    private function findExistingBook(\mysqli $db, array $data): ?int
    {
        $this->log("[findExistingBook] Searching for book: " . json_encode([
            'id' => $data['id'] ?? null,
            'isbn13' => $data['isbn13'] ?? null,
            'isbn10' => $data['isbn10'] ?? null,
            'titolo' => $data['titolo'] ?? null
        ]));

        if (!empty($data['id']) && is_numeric($data['id'])) {
            $stmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $id = (int) $data['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                $this->log("[findExistingBook] Found by ID: {$row['id']}");
                return (int) $row['id'];
            }
            $stmt->close();
        }

        if (!empty($data['isbn13'])) {
            $this->log("[findExistingBook] Searching by ISBN13: '{$data['isbn13']}'");
            $stmt = $db->prepare("SELECT id FROM libri WHERE isbn13 = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->bind_param('s', $data['isbn13']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                $this->log("[findExistingBook] Found by ISBN13: {$row['id']}");
                return (int) $row['id'];
            } else {
                $this->log("[findExistingBook] NOT found by ISBN13");
            }
            $stmt->close();
        }

        // Fallback to ISBN-10 to avoid duplicates
        if (!empty($data['isbn10'])) {
            $stmt = $db->prepare("SELECT id FROM libri WHERE isbn10 = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->bind_param('s', $data['isbn10']);
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
            $this->log("[upsertBook] UPDATING existing book ID: $existingBookId");

            // Clear ISBNs from other books if they conflict (TSV data is authoritative)
            if (!empty($data['isbn13'])) {
                $stmt = $db->prepare("UPDATE libri SET isbn13 = NULL WHERE isbn13 = ? AND id != ? AND deleted_at IS NULL");
                $stmt->bind_param('si', $data['isbn13'], $existingBookId);
                $stmt->execute();
                $conflictsCleared = $stmt->affected_rows;
                $stmt->close();
                if ($conflictsCleared > 0) {
                    $this->log("[upsertBook] Cleared ISBN13 '{$data['isbn13']}' from $conflictsCleared conflicting book(s)");
                }
            }
            if (!empty($data['isbn10'])) {
                $stmt = $db->prepare("UPDATE libri SET isbn10 = NULL WHERE isbn10 = ? AND id != ? AND deleted_at IS NULL");
                $stmt->bind_param('si', $data['isbn10'], $existingBookId);
                $stmt->execute();
                $conflictsCleared = $stmt->affected_rows;
                $stmt->close();
                if ($conflictsCleared > 0) {
                    $this->log("[upsertBook] Cleared ISBN10 '{$data['isbn10']}' from $conflictsCleared conflicting book(s)");
                }
            }

            $this->updateBook($db, $existingBookId, $data, $editorId, $genreId);
            return ['id' => $existingBookId, 'action' => 'updated'];
        } else {
            $this->log("[upsertBook] INSERTING new book: {$data['titolo']}");
            $newBookId = $this->insertBook($db, $data, $editorId, $genreId);
            return ['id' => $newBookId, 'action' => 'created'];
        }
    }

    private function updateBook(\mysqli $db, int $bookId, array $data, ?int $editorId, ?int $genreId): void
    {
        // Check if LibraryThing plugin is installed
        $hasLTFields = \App\Support\LibraryThingInstaller::isInstalled($db);

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
                    value = ?, condition_lt = ?, entry_date = ?,
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
                !empty($data['entry_date']) ? $data['entry_date'] : null,
                $bookId
            ];

            // Type string: 47 characters matching params (s=string, i=int, d=double)
            // isbn10,isbn13,ean,titolo,sottotitolo,anno_pubblicazione,lingua,edizione,numero_pagine,genreId,
            // descrizione,formato,prezzo,editoreId,collana,numero_serie,traduttore,parole_chiave,classificazione_dewey,
            // peso,dimensioni,data_acquisizione,review,rating,comment...lending_end(19),value,condition_lt,entry_date,bookId
            $types = 'sssssissiissdisssssdsssisssssssssssssssssssdssi';
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

    private function insertBook(\mysqli $db, array $data, ?int $editorId, ?int $genreId): int
    {
        // Check if LibraryThing plugin is installed
        $hasLTFields = \App\Support\LibraryThingInstaller::isInstalled($db);

        $copie = !empty($data['copie_totali']) ? (int) $data['copie_totali'] : 1;
        if ($copie < 1) {
            $copie = 1;
        } elseif ($copie > 100) {
            $copie = 100;
        }

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
                    value, condition_lt, entry_date,
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
                    ?, ?, ?,
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
                !empty($data['condition_lt']) ? $data['condition_lt'] : null,
                !empty($data['entry_date']) ? $data['entry_date'] : null
            ];

            // Type string: 48 characters matching params (s=string, i=int, d=double)
            // isbn10,isbn13,ean,titolo,sottotitolo,anno_pubblicazione,lingua,edizione,numero_pagine,genreId,
            // descrizione,formato,prezzo,copie,copie_disponibili,editoreId,collana,numero_serie,traduttore,
            // parole_chiave,classificazione_dewey,peso,dimensioni,data_acquisizione,review,rating,
            // comment...lending_end(19),value,condition_lt,entry_date
            $types = 'sssssissiissdiiisssssdsssisssssssssssssssssssdss';
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

    private function scrapeBookData(string $isbn): array
    {
        // Use centralized scraping service
        return \App\Support\ScrapingService::scrapeBookData($isbn, 3, 'LibraryThing Import');
    }

    /**
     * Download cover image if book doesn't have one
     * Uses the existing scrapeBookData method
     */
    private function enrichBookWithScrapedData(\mysqli $db, int $bookId, array $csvData, array $scrapedData): void
    {
        $updates = [];
        $params = [];
        $types = '';

        // Download and save cover image if available
        if (empty($csvData['copertina_url']) && !empty($scrapedData['image'])) {
            try {
                $coverController = new \App\Controllers\CoverController();
                $coverData = $coverController->downloadFromUrl($scrapedData['image']);

                if (!empty($coverData['file_url'])) {
                    $updates[] = 'copertina_url = ?';
                    $params[] = $coverData['file_url'];
                    $types .= 's';
                }
            } catch (\Exception $e) {
                // Fallback: save URL only
                $updates[] = 'copertina_url = ?';
                $params[] = $scrapedData['image'];
                $types .= 's';
            }
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
        $progress = $_SESSION['librarything_import'] ?? [
            'status' => 'idle',
            'current_row' => 0,
            'total_rows' => 0,
            'imported' => 0,
            'updated' => 0
        ];

        $response->getBody()->write(json_encode($progress, JSON_THROW_ON_ERROR));
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

        $whereClauses[] = "l.deleted_at IS NULL";

        $query .= " WHERE " . implode(' AND ', $whereClauses);

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
            $libro['review'] ?? '',
            $libro['rating'] ?? '',
            $libro['comment'] ?? '',
            $libro['private_comment'] ?? '',
            $libro['descrizione'] ?? '',
            $media,
            $libro['physical_description'] ?? '',
            $libro['peso'] ?? '',
            '',  // Height
            '',  // Thickness
            '',  // Length
            $libro['dimensioni'] ?? '',
            $libro['numero_pagine'] ?? '',
            $libro['lccn'] ?? '',
            $libro['data_acquisizione'] ?? '',
            $libro['date_started'] ?? '',
            $libro['date_read'] ?? '',
            $libro['ean'] ?? '',  // Barcode value is stored in 'ean' field
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
            $libro['dewey_wording'] ?? '',
            $libro['other_call_number'] ?? '',
            $libro['copie_totali'] ?? '1',
            $libro['source'] ?? '',
            $libro['entry_date'] ?? '',
            $libro['from_where'] ?? '',
            $libro['oclc'] ?? '',
            $libro['work_id'] ?? '',
            $libro['lending_patron'] ?? '',
            $libro['lending_status'] ?? '',
            $libro['lending_start'] ?? '',
            $libro['lending_end'] ?? '',
            $libro['prezzo'] ?? '',
            '',  // Purchase Price (not stored separately)
            $libro['value'] ?? '',
            $libro['condition_lt'] ?? '',
            $libro['issn'] ?? ''
        ];
    }

}
