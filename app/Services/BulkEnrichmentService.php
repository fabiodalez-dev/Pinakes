<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;
use App\Support\SecureLogger;
use App\Support\ScrapingService;

/**
 * Service for bulk ISBN enrichment of books.
 *
 * Finds books that have an ISBN/EAN but are missing cover images or descriptions,
 * then uses the scraping infrastructure to fill in the gaps.
 */
class BulkEnrichmentService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get statistics about books eligible for enrichment.
     *
     * @return array{total_with_isbn: int, missing_cover: int, missing_description: int, pending: int}
     */
    public function getStats(): array
    {
        $totalWithIsbn = 0;
        $missingCover = 0;
        $missingDescription = 0;
        $pending = 0;

        // Note: identifier columns are checked via NULLIF(TRIM(col), '')
        // because legacy imports sometimes wrote empty strings rather than
        // NULL. Without NULLIF, an isbn13='' would count as "has ISBN" here
        // and then $book['isbn13'] ?? would pick '' over a populated isbn10.
        $hasIdentifier = '(NULLIF(TRIM(isbn13), \'\') IS NOT NULL'
                       . ' OR NULLIF(TRIM(isbn10), \'\') IS NOT NULL'
                       . ' OR NULLIF(TRIM(ean), \'\') IS NOT NULL)';

        // Total books with at least one ISBN identifier
        $result = $this->db->query("
            SELECT COUNT(*) AS cnt FROM libri
            WHERE {$hasIdentifier}
              AND deleted_at IS NULL
        ");
        if ($result) {
            $totalWithIsbn = (int) ($result->fetch_assoc()['cnt'] ?? 0);
            $result->free();
        }

        // Books missing cover
        $result = $this->db->query("
            SELECT COUNT(*) AS cnt FROM libri
            WHERE {$hasIdentifier}
              AND deleted_at IS NULL
              AND (copertina_url IS NULL OR copertina_url = '')
        ");
        if ($result) {
            $missingCover = (int) ($result->fetch_assoc()['cnt'] ?? 0);
            $result->free();
        }

        // Books missing description
        $result = $this->db->query("
            SELECT COUNT(*) AS cnt FROM libri
            WHERE {$hasIdentifier}
              AND deleted_at IS NULL
              AND (descrizione IS NULL OR descrizione = '')
        ");
        if ($result) {
            $missingDescription = (int) ($result->fetch_assoc()['cnt'] ?? 0);
            $result->free();
        }

        // Books pending enrichment (missing cover OR description)
        $result = $this->db->query("
            SELECT COUNT(*) AS cnt FROM libri
            WHERE {$hasIdentifier}
              AND deleted_at IS NULL
              AND (copertina_url IS NULL OR copertina_url = ''
                   OR descrizione IS NULL OR descrizione = '')
        ");
        if ($result) {
            $pending = (int) ($result->fetch_assoc()['cnt'] ?? 0);
            $result->free();
        }

        return [
            'total_with_isbn' => $totalWithIsbn,
            'missing_cover' => $missingCover,
            'missing_description' => $missingDescription,
            'pending' => $pending,
        ];
    }

    /**
     * Find books that need enrichment.
     *
     * Priority for ISBN selection: isbn13 > isbn10 > ean.
     *
     * @param int|null $limit Maximum number of books to return (default 20)
     * @return array<int, array{id: int, isbn: string}>
     */
    public function findPending(?int $limit = 20): array
    {
        $limitVal = $limit ?? 20;

        $stmt = $this->db->prepare("
            SELECT id, isbn13, isbn10, ean
            FROM libri
            WHERE (NULLIF(TRIM(isbn13), '') IS NOT NULL
                   OR NULLIF(TRIM(isbn10), '') IS NOT NULL
                   OR NULLIF(TRIM(ean), '') IS NOT NULL)
              AND deleted_at IS NULL
              AND (copertina_url IS NULL OR copertina_url = ''
                   OR descrizione IS NULL OR descrizione = '')
            ORDER BY id ASC
            LIMIT ?
        ");
        if ($stmt === false) {
            SecureLogger::error('[BulkEnrichment] Failed to prepare findPending query', [
                'error' => $this->db->error,
            ]);
            return [];
        }

        $stmt->bind_param('i', $limitVal);
        $stmt->execute();
        $result = $stmt->get_result();

        $books = [];
        while ($row = $result->fetch_assoc()) {
            // Priority: isbn13 > isbn10 > ean, treating empty/whitespace as
            // missing (so a blank isbn13 doesn't win over a populated isbn10).
            $isbn = $this->firstNonEmpty([$row['isbn13'] ?? null, $row['isbn10'] ?? null, $row['ean'] ?? null]);
            if ($isbn !== '') {
                $books[] = [
                    'id' => (int) $row['id'],
                    'isbn' => $isbn,
                ];
            }
        }

        $stmt->close();

        return $books;
    }

    /**
     * Return the first value in the list that is not null and not
     * whitespace-only. Empty string when nothing qualifies.
     *
     * @param array<int, string|null> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $trimmed = trim($v);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return '';
    }

    /**
     * Enrich a single book by scraping missing data.
     *
     * Only fills in fields that are currently NULL or empty — never overwrites existing data.
     *
     * @param int $bookId The book ID to enrich
     * @return array{status: string, fields_updated: list<string>, book_id: int}
     */
    public function enrichBook(int $bookId): array
    {
        $result = [
            'status' => 'error',
            'fields_updated' => [],
            'book_id' => $bookId,
        ];

        try {
            // Load book from DB
            $stmt = $this->db->prepare("
                SELECT isbn13, isbn10, ean, copertina_url, descrizione,
                       editore_id, anno_pubblicazione, lingua, numero_pagine,
                       parole_chiave, collana
                FROM libri
                WHERE id = ? AND deleted_at IS NULL
            ");
            if ($stmt === false) {
                SecureLogger::error('[BulkEnrichment] Failed to prepare book query', [
                    'book_id' => $bookId,
                    'error' => $this->db->error,
                ]);
                return $result;
            }

            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $bookResult = $stmt->get_result();
            $book = $bookResult->fetch_assoc();
            $stmt->close();

            if ($book === null) {
                $result['status'] = 'not_found';
                return $result;
            }

            // Determine ISBN to scrape (priority: isbn13 > isbn10 > ean).
            // Empty/whitespace values are treated as missing so a blank
            // isbn13 doesn't shadow a populated isbn10.
            $isbn = $this->firstNonEmpty([
                $book['isbn13'] ?? null,
                $book['isbn10'] ?? null,
                $book['ean'] ?? null,
            ]);
            if ($isbn === '') {
                $result['status'] = 'skipped';
                return $result;
            }

            // Check if book actually needs enrichment
            $needsCover = empty($book['copertina_url']);
            $needsDescription = empty($book['descrizione']);
            if (!$needsCover && !$needsDescription) {
                $result['status'] = 'skipped';
                return $result;
            }

            // Scrape using the existing ScrapingService
            $scraped = ScrapingService::scrapeBookData($isbn, 3, 'BulkEnrichment');

            if (empty($scraped) || empty($scraped['title'])) {
                $result['status'] = 'not_found';
                return $result;
            }

            // Build UPDATE sets for missing fields only
            $sets = [];
            $types = '';
            $values = [];
            $fieldsUpdated = [];

            // Cover image
            if ($needsCover && !empty($scraped['image'])) {
                $sets[] = 'copertina_url = ?';
                $types .= 's';
                $values[] = $scraped['image'];
                $fieldsUpdated[] = 'copertina_url';
            }

            // Description
            if ($needsDescription && !empty($scraped['description'])) {
                $sets[] = 'descrizione = ?';
                $types .= 's';
                $values[] = $scraped['description'];
                $fieldsUpdated[] = 'descrizione';

                // Also generate plain-text description
                $plain = strip_tags($scraped['description']);
                $sets[] = 'descrizione_plain = ?';
                $types .= 's';
                $values[] = $plain;
            }

            // Publisher (editore_id) — only if currently NULL/0
            if (empty($book['editore_id']) && !empty($scraped['publisher'])) {
                $publisherId = $this->findOrCreatePublisher($scraped['publisher']);
                if ($publisherId !== null) {
                    $sets[] = 'editore_id = ?';
                    $types .= 'i';
                    $values[] = $publisherId;
                    $fieldsUpdated[] = 'editore_id';
                }
            }

            // Year
            if (empty($book['anno_pubblicazione']) && !empty($scraped['year'])) {
                $year = (int) $scraped['year'];
                if ($year > 0 && $year <= (int) date('Y') + 2) {
                    $sets[] = 'anno_pubblicazione = ?';
                    $types .= 'i';
                    $values[] = $year;
                    $fieldsUpdated[] = 'anno_pubblicazione';
                }
            }

            // Language
            if (empty($book['lingua']) && !empty($scraped['language'])) {
                $sets[] = 'lingua = ?';
                $types .= 's';
                $values[] = $scraped['language'];
                $fieldsUpdated[] = 'lingua';
            }

            // Pages
            if (empty($book['numero_pagine']) && !empty($scraped['pages'])) {
                $pages = (int) preg_replace('/[^0-9]/', '', (string) $scraped['pages']);
                if ($pages > 0) {
                    $sets[] = 'numero_pagine = ?';
                    $types .= 'i';
                    $values[] = $pages;
                    $fieldsUpdated[] = 'numero_pagine';
                }
            }

            // Keywords
            if (empty($book['parole_chiave']) && !empty($scraped['keywords'])) {
                $sets[] = 'parole_chiave = ?';
                $types .= 's';
                $values[] = $scraped['keywords'];
                $fieldsUpdated[] = 'parole_chiave';
            }

            // Series / collection
            $series = '';
            if (empty($book['collana']) && !empty($scraped['series'] ?? $scraped['collana'] ?? '')) {
                $series = (string) ($scraped['series'] ?? $scraped['collana'] ?? '');
                $sets[] = 'collana = ?';
                $types .= 's';
                $values[] = $series;
                $fieldsUpdated[] = 'collana';
            }

            if (empty($sets)) {
                $result['status'] = 'skipped';
                return $result;
            }

            // Execute UPDATE
            $sql = 'UPDATE libri SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
            $types .= 'i';
            $values[] = $bookId;

            // CR R8 #1: wrap UPDATE + series sync in a single transaction so a
            // sync failure rolls back the libri.collana write — otherwise we
            // commit the legacy varchar but leave libri_collane orphaned.
            $this->db->begin_transaction();
            try {
                $updateStmt = $this->db->prepare($sql);
                if ($updateStmt === false) {
                    $this->db->rollback();
                    SecureLogger::error('[BulkEnrichment] Failed to prepare update query', [
                        'book_id' => $bookId,
                        'error' => $this->db->error,
                    ]);
                    return $result;
                }

                $updateStmt->bind_param($types, ...$values);
                if (!$updateStmt->execute()) {
                    SecureLogger::error('[BulkEnrichment] UPDATE execute failed', [
                        'book_id' => $bookId,
                        'error'   => $updateStmt->error,
                        'fields'  => $fieldsUpdated,
                    ]);
                    $updateStmt->close();
                    $this->db->rollback();
                    return $result; // status stays 'error'
                }
                $updateStmt->close();

                // REG-1 (review): if we just populated a series name, sync the
                // collane / libri_collane rows so admin views and detail-page
                // hierarchy queries find the book. Pre-fix, bulk-enrich landed
                // orphan series invisible to /admin/collane.
                if (in_array('collana', $fieldsUpdated, true) && !empty($series)) {
                    (new \App\Models\SeriesRepository($this->db))->syncBookMemberships(
                        $bookId,
                        $series,
                        null,
                        [],
                        []
                    );
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error('[BulkEnrichment] enrichment transaction failed', [
                    'book_id' => $bookId,
                    'series' => $series,
                    'error' => $e->getMessage(),
                ]);
                return $result;
            }

            // Handle authors (via libri_autori junction table) — only if book has no authors
            if (!empty($scraped['authors']) && is_array($scraped['authors'])) {
                $this->enrichAuthorsIfEmpty($bookId, $scraped['authors']);
            }

            $result['status'] = 'enriched';
            $result['fields_updated'] = $fieldsUpdated;

            SecureLogger::info('[BulkEnrichment] Book enriched', [
                'book_id' => $bookId,
                'isbn' => $isbn,
                'fields' => $fieldsUpdated,
            ]);

        } catch (\Throwable $e) {
            SecureLogger::error('[BulkEnrichment] Exception enriching book', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
            ]);
            $result['status'] = 'error';
        }

        return $result;
    }

    /**
     * Enrich a batch of books.
     *
     * @param int $limit Maximum number of books to process
     * @param callable|null $onProgress Callback: function(int $current, int $total, array $result): void
     * @return array{processed: int, enriched: int, not_found: int, errors: int, details: list<array>}
     */
    public function enrichBatch(int $limit = 20, ?callable $onProgress = null): array
    {
        $summary = [
            'processed' => 0,
            'enriched' => 0,
            'not_found' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        $pending = $this->findPending($limit);
        $total = count($pending);

        if ($total === 0) {
            return $summary;
        }

        foreach ($pending as $index => $book) {
            $bookResult = $this->enrichBook($book['id']);
            $summary['processed']++;

            switch ($bookResult['status']) {
                case 'enriched':
                    $summary['enriched']++;
                    break;
                case 'not_found':
                    $summary['not_found']++;
                    break;
                case 'error':
                    $summary['errors']++;
                    break;
                case 'skipped':
                    $summary['skipped']++;
                    break;
            }

            $summary['details'][] = $bookResult;

            if ($onProgress !== null) {
                $onProgress($index + 1, $total, $bookResult);
            }

            // Rate-limit: 1 second between requests to avoid hammering scraping APIs
            if ($index < $total - 1) {
                sleep(1);
            }
        }

        SecureLogger::info('[BulkEnrichment] Batch completed', [
            'processed' => $summary['processed'],
            'enriched' => $summary['enriched'],
            'not_found' => $summary['not_found'],
            'errors' => $summary['errors'],
        ]);

        return $summary;
    }

    /**
     * Check if bulk enrichment is enabled in system settings.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        try {
            // Check if system_settings table exists
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'system_settings'");
            if ($tableCheck === false || $tableCheck->num_rows === 0) {
                return false;
            }
            if ($tableCheck instanceof \mysqli_result) {
                $tableCheck->free();
            }

            $stmt = $this->db->prepare(
                "SELECT setting_value FROM system_settings WHERE category = 'bulk_enrich' AND setting_key = 'enabled' LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return $row !== null && $row['setting_value'] === '1';
        } catch (\Throwable $e) {
            SecureLogger::error('[BulkEnrichment] Failed to check enabled status', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enable or disable bulk enrichment.
     * @return bool true on success, false on prepare/execute failure
     */
    public function setEnabled(bool $enabled): bool
    {
        $value = $enabled ? '1' : '0';
        $category = 'bulk_enrich';
        $key = 'enabled';

        $stmt = $this->db->prepare("
            INSERT INTO system_settings (category, setting_key, setting_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        if ($stmt === false) {
            SecureLogger::error('[BulkEnrichment] Failed to prepare setEnabled query', [
                'error' => $this->db->error,
            ]);
            return false;
        }

        $stmt->bind_param('sss', $category, $key, $value);
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BulkEnrichment] setEnabled execute failed', [
                'error' => $stmt->error,
            ]);
        }
        $stmt->close();
        return $ok;
    }

    /**
     * Find or create a publisher by name.
     *
     * @param string $name Publisher name
     * @return int|null Publisher ID or null if creation failed
     */
    private function findOrCreatePublisher(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // Try to find existing publisher
        $stmt = $this->db->prepare("SELECT id FROM editori WHERE nome = ? LIMIT 1");
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['id'];
        }

        // Create new publisher
        $stmt = $this->db->prepare("INSERT INTO editori (nome) VALUES (?)");
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return $id > 0 ? (int) $id : null;
    }

    /**
     * Add authors to a book only if it currently has none.
     *
     * @param int $bookId Book ID
     * @param list<string> $authors Author names from scraping
     */
    private function enrichAuthorsIfEmpty(int $bookId, array $authors): void
    {
        // Check if book already has authors
        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM libri_autori WHERE libro_id = ?");
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();

        if ($count > 0) {
            return; // Already has authors, do not overwrite
        }

        $order = 1;
        foreach ($authors as $authorName) {
            $authorName = trim($authorName);
            if ($authorName === '') {
                continue;
            }

            $authorId = $this->findOrCreateAuthor($authorName);
            if ($authorId === null) {
                continue;
            }

            $stmt = $this->db->prepare("
                INSERT IGNORE INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito)
                VALUES (?, ?, 'principale', ?)
            ");
            if ($stmt === false) {
                continue;
            }

            $stmt->bind_param('iii', $bookId, $authorId, $order);
            $stmt->execute();
            $stmt->close();
            $order++;
        }
    }

    /**
     * Find or create an author by name.
     *
     * @param string $name Author name
     * @return int|null Author ID or null if creation failed
     */
    private function findOrCreateAuthor(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // Try to find existing author
        $stmt = $this->db->prepare("SELECT id FROM autori WHERE nome = ? LIMIT 1");
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            return (int) $row['id'];
        }

        // Create new author
        $stmt = $this->db->prepare("INSERT INTO autori (nome) VALUES (?)");
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return $id > 0 ? (int) $id : null;
    }
}
