<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\SecureLogger;

class LibriController
{
    /**
     * Centralized whitelist of allowed domains for external cover image downloads
     * Used by both downloadExternalCover() and isUrlAllowed()
     */
    private const COVER_ALLOWED_DOMAINS = [
        // Google Books
        'books.google.com',
        'books.google.it',
        'images.google.com',
        // Open Library
        'covers.openlibrary.org',
        // Italian bookstores
        'www.libreriauniversitaria.it',
        'img.libreriauniversitaria.it',
        'img2.libreriauniversitaria.it',
        'img3.libreriauniversitaria.it',
        'cdn.mondadoristore.it',
        'www.lafeltrinelli.it',
        // Amazon
        'images.amazon.com',
        'images-na.ssl-images-amazon.com',
        'images-eu.ssl-images-amazon.com',
        // Ubik Libri
        'www.ubiklibri.it',
        'ubiklibri.it',
    ];

    /**
     * Maximum file size for cover downloads (5MB)
     */
    private const MAX_COVER_SIZE = 5 * 1024 * 1024;

    /**
     * Get the storage directory path
     * Centralized configuration for storage location
     */
    private function getStoragePath(): string
    {
        return __DIR__ . '/../../storage';
    }

    /**
     * Get the uploads directory path for covers
     * Centralized configuration for upload location
     */
    private function getCoversUploadPath(): string
    {
        return __DIR__ . '/../../public/uploads/copertine';
    }

    /**
     * Get the relative URL path for covers (from web root)
     */
    private function getCoversUrlPath(): string
    {
        return '/uploads/copertine';
    }

    /**
     * Download external cover image and save locally
     *
     * @param string|null $url External URL or local path
     * @return string|null Local URL path on success, original URL on failure, null/empty passed through
     */
    private function downloadExternalCover(?string $url): ?string
    {
        if ($url === null || $url === '' || !preg_match('#^https?://#i', $url)) {
            return $url; // Not an external URL, return as-is
        }

        $parsedUrl = parse_url($url);
        $host = strtolower($parsedUrl['host'] ?? '');

        // Use centralized whitelist
        if (!\in_array($host, self::COVER_ALLOWED_DOMAINS, true)) {
            \App\Support\SecureLogger::warning('Cover download from non-whitelisted domain', ['url' => $url, 'host' => $host]);
            return $url; // Return original URL if domain not allowed
        }

        try {
            // First, check file size via HEAD request to avoid downloading huge files
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BibliotecaCoverBot/1.0',
            ]);
            curl_exec($ch);
            $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            // Check size limit before downloading (if Content-Length is provided)
            if ($contentLength > self::MAX_COVER_SIZE) {
                \App\Support\SecureLogger::warning('Cover too large (HEAD check)', [
                    'url' => $url,
                    'size' => $contentLength,
                    'max' => self::MAX_COVER_SIZE
                ]);
                return $url;
            }

            // Download the image
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BibliotecaCoverBot/1.0',
            ]);

            $imageData = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($imageData === false || $httpCode !== 200 || \strlen($imageData) < 100) {
                \App\Support\SecureLogger::warning('Cover download failed', ['url' => $url, 'http_code' => $httpCode]);
                return $url; // Return original URL on download failure
            }

            // Verify size after download (in case Content-Length was not provided or wrong)
            if (\strlen($imageData) > self::MAX_COVER_SIZE) {
                \App\Support\SecureLogger::warning('Cover too large (POST check)', [
                    'url' => $url,
                    'size' => \strlen($imageData),
                    'max' => self::MAX_COVER_SIZE
                ]);
                return $url;
            }

            // Validate image
            $imageInfo = @getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                \App\Support\SecureLogger::warning('Invalid image data', ['url' => $url]);
                return $url;
            }

            $mimeType = $imageInfo['mime'] ?? '';
            $extension = match ($mimeType) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                default => null,
            };

            if ($extension === null) {
                return $url; // Unsupported format
            }

            // Ensure upload directory exists
            $uploadDir = $this->getCoversUploadPath();
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename and save
            $filename = 'copertina_' . uniqid('', true) . '.' . $extension;
            $filepath = $uploadDir . '/' . $filename;

            // Create image resource and save
            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                return $url;
            }

            $saveResult = match ($extension) {
                'png' => imagepng($image, $filepath, 9),
                default => imagejpeg($image, $filepath, 85),
            };
            imagedestroy($image);

            if (!$saveResult) {
                return $url;
            }

            chmod($filepath, 0644);
            \App\Support\SecureLogger::info('Cover downloaded successfully', ['url' => $url, 'local' => $filename]);

            return $this->getCoversUrlPath() . '/' . $filename;

        } catch (\Exception $e) {
            \App\Support\SecureLogger::error('Cover download exception', ['url' => $url, 'error' => $e->getMessage()]);
            return $url; // Return original URL on any error
        }
    }

    /**
     * Normalize text fields by removing MARC-8 control characters and collapsing whitespace
     * MARC-8 uses characters like NSB (0x88, 0x98) and NSE (0x89, 0x9C) for non-sorting blocks
     * These appear as invisible characters or ? when stored in MySQL with UTF-8
     */
    private function normalizeText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        // Remove MARC-8 control characters (NSB/NSE non-sorting markers)
        $text = preg_replace('/[\x88\x89\x98\x9C]/', '', $text);
        // Collapse multiple whitespace into single space and trim
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return $text;
    }

    /**
     * Rotate log files to prevent unlimited growth
     * Keeps only last 7 days of logs, max 10MB per file
     */
    private function rotateLogFile(string $logFile): void
    {
        if (!file_exists($logFile)) {
            return;
        }

        $maxSize = 10 * 1024 * 1024; // 10MB
        $maxAge = 7 * 24 * 60 * 60;  // 7 days

        // Check size
        if (filesize($logFile) > $maxSize) {
            // Rotate by renaming
            $rotated = $logFile . '.' . date('Y-m-d_His');
            rename($logFile, $rotated);
        }

        // Clean old rotated logs
        $dir = dirname($logFile);
        $basename = basename($logFile);
        $pattern = $dir . '/' . $basename . '.*';
        foreach (glob($pattern) as $oldLog) {
            if (filemtime($oldLog) < time() - $maxAge) {
                unlink($oldLog);
            }
        }
    }

    private function logCoverDebug(string $label, array $data): void
    {
        if (getenv('APP_ENV') === 'development') {
            $logDir = $this->getStoragePath();

            // Create directory if not exists
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $file = $logDir . '/cover_debug.log';

            // Rotate log before writing
            $this->rotateLogFile($file);

            // Enhanced sanitization - remove any sensitive data
            $sanitized = $data;
            $sensitiveKeys = ['password', 'token', 'csrf_token', 'api_key', 'secret', 'auth'];
            foreach ($sensitiveKeys as $key) {
                unset($sanitized[$key]);
            }

            $line = date('Y-m-d H:i:s') . " [$label] " . json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

            // Use LOCK_EX to prevent race conditions
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libri = $repo->listWithAuthors(100);

        ob_start();
        $data = ['libri' => $libri];
        // extract($data);
        require __DIR__ . '/../Views/libri/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function show(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);
        if (!$libro) {
            return $response->withStatus(404);
        }
        $loanRepo = new \App\Models\LoanRepository($db);
        $activeLoan = $loanRepo->getActiveLoanByBook($id);
        $bookPath = '/admin/libri/' . $id;

        // Recupera tutte le copie del libro con informazioni sui prestiti
        $copyRepo = new \App\Models\CopyRepository($db);
        $copie = $copyRepo->getByBookId($id);

        // Get loan history for this book
        $loanHistoryQuery = "
            SELECT
                p.id,
                p.data_prestito,
                p.data_scadenza,
                p.data_restituzione,
                p.stato,
                p.renewals,
                p.note,
                u.nome as utente_nome,
                u.cognome as utente_cognome,
                u.email as utente_email,
                u.id as utente_id,
                staff.nome as staff_nome,
                staff.cognome as staff_cognome
            FROM prestiti p
            LEFT JOIN utenti u ON p.utente_id = u.id
            LEFT JOIN utenti staff ON p.processed_by = staff.id
            WHERE p.libro_id = ?
            ORDER BY p.data_prestito DESC
        ";
        $stmt = $db->prepare($loanHistoryQuery);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $loanHistory = [];
        while ($row = $result->fetch_assoc()) {
            $loanHistory[] = $row;
        }
        $stmt->close();

        // Active reservations for this book (admin/staff only - contains PII)
        $activeReservations = [];
        $currentUserRole = $_SESSION['user']['tipo_utente'] ?? '';
        $isAdminOrStaff = \in_array($currentUserRole, ['admin', 'staff'], true);

        if ($isAdminOrStaff) {
            $resStmt = $db->prepare("
                SELECT r.id, r.data_inizio_richiesta, r.data_fine_richiesta, r.data_scadenza_prenotazione,
                       r.stato, r.queue_position,
                       u.nome, u.cognome, u.email
                FROM prenotazioni r
                JOIN utenti u ON u.id = r.utente_id
                WHERE r.libro_id = ? AND r.stato = 'attiva'
                ORDER BY r.data_inizio_richiesta IS NULL, r.data_inizio_richiesta ASC, r.id ASC
            ");
            $resStmt->bind_param('i', $id);
            $resStmt->execute();
            $resResult = $resStmt->get_result();
            while ($row = $resResult->fetch_assoc()) {
                $activeReservations[] = $row;
            }
            $resStmt->close();
        }

        ob_start();
        // extract([
        //     'libro' => $libro,
        //     'activeLoan' => $activeLoan,
        //     'bookPath' => $bookPath,
        //     'loanHistory' => $loanHistory,
        // ]);
        require __DIR__ . '/../Views/libri/scheda_libro.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response, mysqli $db): Response
    {
        // For select boxes, load minimal lists
        $editRepo = new \App\Models\PublisherRepository($db);
        $autRepo = new \App\Models\AuthorRepository($db);
        $editori = $editRepo->listBasic();
        $autori = $autRepo->listBasic(500);
        $colRepo = new \App\Models\CollocationRepository($db);
        $taxRepo = new \App\Models\TaxonomyRepository($db);
        $scaffali = $colRepo->getScaffali();
        $mensole = $colRepo->getMensole();
        $generi = $taxRepo->genres();
        $sottogeneri = $taxRepo->subgenres();
        ob_start();
        $data = ['editori' => $editori, 'autori' => $autori, 'scaffali' => $scaffali, 'mensole' => $mensole, 'generi' => $generi, 'sottogeneri' => $sottogeneri];
        // extract(['editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri]); 
        require __DIR__ . '/../Views/libri/crea_libro.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        // SECURITY: Debug logging rimosso per prevenire information disclosure
        // Il logging dettagliato è disponibile solo in ambiente development tramite AppLog

        // Merge all supported fields with defaults
        $fields = [
            'titolo' => '',
            'sottotitolo' => '',
            'isbn10' => '',
            'isbn13' => '',
            'ean' => '',
            'genere_id' => 0,
            'sottogenere_id' => 0,
            'editore_id' => 0,
            'data_acquisizione' => null,
            'tipo_acquisizione' => '',
            'descrizione' => '',
            'parole_chiave' => '',
            'formato' => '',
            'peso' => null,
            'dimensioni' => '',
            'prezzo' => null,
            'copie_totali' => 1,
            'copie_disponibili' => 1,
            'numero_inventario' => '',
            'classificazione_dewey' => '',
            'collana' => '',
            'numero_serie' => '',
            'note_varie' => '',
            'file_url' => '',
            'audio_url' => '',
            'copertina_url' => '',
            'scaffale_id' => 0,
            'mensola_id' => 0,
            'posizione_progressiva' => 0,
            'posizione_id' => 0,
            'collocazione' => '',
            'stato' => '',
            'lingua' => '',
            'anno_pubblicazione' => null,
            'edizione' => '',
            'data_pubblicazione' => '',
            'traduttore' => '',
            // LibraryThing fields (optional, only saved if plugin installed)
            'review' => null,
            'rating' => null,
            'comment' => null,
            'private_comment' => null,
            'physical_description' => null,
            'weight' => null,
            'height' => null,
            'thickness' => null,
            'length' => null,
            'lccn' => null,
            'lc_classification' => null,
            'other_call_number' => null,
            'date_acquired' => null,
            'date_started' => null,
            'date_read' => null,
            'bcid' => null,
            'oclc' => null,
            'work_id' => null,
            'issn' => null,
            'original_languages' => null,
            'source' => null,
            'from_where' => null,
            'lending_patron' => null,
            'lending_status' => null,
            'lending_start' => null,
            'lending_end' => null,
            'purchase_price' => null,
            'value' => null,
            'condition_lt' => null
        ];
        foreach ($fields as $k => $v) {
            if (array_key_exists($k, $data))
                $fields[$k] = $data[$k];
        }

        // Normalize text fields to remove MARC-8 control characters and collapse whitespace
        $fields['titolo'] = $this->normalizeText($fields['titolo']);
        $fields['sottotitolo'] = $this->normalizeText($fields['sottotitolo']);

        // Merge scraped subtitle and notes if present
        $subtitleFromScrape = trim((string) ($data['subtitle'] ?? ''));
        if ($subtitleFromScrape !== '') {
            $fields['sottotitolo'] = $this->normalizeText($subtitleFromScrape);
        }

        $notesParts = [];
        $currentNotes = trim((string) ($fields['note_varie'] ?? ''));
        if ($currentNotes !== '') {
            $notesParts[] = $currentNotes;
        }
        $notesFromScrape = trim((string) ($data['notes'] ?? ''));
        if ($notesFromScrape !== '') {
            $notesParts[] = $notesFromScrape;
        }
        $tipologiaScrape = trim((string) ($data['scraped_tipologia'] ?? ''));
        if ($tipologiaScrape !== '') {
            $notesParts[] = 'Tipologia: ' . $tipologiaScrape;
        }
        if ($notesParts) {
            $uniqueNotes = [];
            foreach ($notesParts as $part) {
                $clean = trim($part);
                if ($clean === '')
                    continue;
                $exists = false;
                foreach ($uniqueNotes as $existing) {
                    if (strcasecmp($existing, $clean) === 0) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $uniqueNotes[] = $clean;
                }
            }
            $fields['note_varie'] = implode("\n", $uniqueNotes);
        }

        // Sanitize ISBN/EAN: strip spaces and dashes (server-side safety)
        foreach (['isbn10', 'isbn13', 'ean'] as $codeKey) {
            if (isset($fields[$codeKey])) {
                $rawValue = (string) $fields[$codeKey];

                // Input validation: prevent ReDoS by checking length before regex
                // ISBN-10: max 13 chars (10 digits + 3 separators), ISBN-13: max 17 chars (13 digits + 4 separators), EAN: max 13
                $maxLength = ($codeKey === 'isbn10') ? 13 : (($codeKey === 'isbn13') ? 17 : 13);
                if (strlen($rawValue) > $maxLength) {
                    $rawValue = substr($rawValue, 0, $maxLength);
                }

                $fields[$codeKey] = preg_replace('/[\s-]+/', '', $rawValue);
            }
        }

        // Convert 0 to NULL for optional foreign keys to avoid constraint failures
        $fields['editore_id'] = empty($fields['editore_id']) || $fields['editore_id'] == 0 ? null : (int) $fields['editore_id'];
        $fields['genere_id'] = empty($fields['genere_id']) || $fields['genere_id'] == 0 ? null : (int) $fields['genere_id'];
        $fields['sottogenere_id'] = empty($fields['sottogenere_id']) || $fields['sottogenere_id'] == 0 ? null : (int) $fields['sottogenere_id'];
        $fields['copie_totali'] = (int) $fields['copie_totali'];
        // Add bounds checking to prevent integer overflow
        if ($fields['copie_totali'] < 1) {
            $fields['copie_totali'] = 1;
        } elseif ($fields['copie_totali'] > 9999) {
            $fields['copie_totali'] = 9999;
        }
        // In creazione, copie_disponibili = copie_totali (le copie sono tutte nuove e disponibili)
        $fields['copie_disponibili'] = $fields['copie_totali'];
        $fields['scaffale_id'] = empty($fields['scaffale_id']) ? null : (int) $fields['scaffale_id'];
        $fields['mensola_id'] = empty($fields['mensola_id']) ? null : (int) $fields['mensola_id'];
        $fields['posizione_progressiva'] = isset($fields['posizione_progressiva']) && $fields['posizione_progressiva'] !== '' ? (int) $fields['posizione_progressiva'] : null;
        $fields['posizione_id'] = null;
        $fields['peso'] = $fields['peso'] !== null && $fields['peso'] !== '' ? (float) $fields['peso'] : null;
        $fields['prezzo'] = $fields['prezzo'] !== null && $fields['prezzo'] !== '' ? (float) $fields['prezzo'] : null;
        if ($fields['copertina_url'] === '' || $fields['copertina_url'] === null) {
            $fields['copertina_url'] = null;
        } else {
            // Auto-download external cover URLs
            $fields['copertina_url'] = $this->downloadExternalCover($fields['copertina_url']);
        }

        // Ensure hierarchical consistency between genere_id (parent) and sottogenere_id (child)
        try {
            $genRepoTmp = new \App\Models\GenereRepository($db);
            if (!empty($fields['sottogenere_id'])) {
                $sub = $genRepoTmp->getById((int) $fields['sottogenere_id']);
                if ($sub && !empty($sub['parent_id'])) {
                    $fields['genere_id'] = (int) $sub['parent_id'];
                }
            } elseif (!empty($fields['genere_id'])) {
                // If a leaf has been posted as genere, promote its parent
                $g = $genRepoTmp->getById((int) $fields['genere_id']);
                if ($g && !empty($g['parent_id'])) {
                    // Posted value is actually a child; move it to sottogenere and set its parent as genere
                    $fields['sottogenere_id'] = (int) $fields['genere_id'];
                    $fields['genere_id'] = (int) $g['parent_id'];
                }
            }
        } catch (\Throwable $e) {
            // fail-safe: ignore and continue
        }


        // DEBUG: Log field processing for store method
        // SECURITY: Logging disabilitato in produzione per prevenire information disclosure
        if (getenv('APP_ENV') === 'development') {
            $debugFile = $this->getStoragePath() . '/field_debug.log';
            $debugEntry = "FIELD PROCESSING (STORE):\n";
            foreach ($fields as $key => $value) {
                $type = gettype($value);
                $displayValue = $value === null ? 'NULL' : (string) $value;
                if (strlen($displayValue) > 100)
                    $displayValue = substr($displayValue, 0, 100) . '...';
                $debugEntry .= "  {$key} ({$type}): '{$displayValue}'\n";
            }
            @file_put_contents($debugFile, $debugEntry, FILE_APPEND);
        }

        // Duplicate check on identifiers (EAN/ISBN) with advisory lock to prevent race conditions
        $codes = [];
        foreach (['isbn10', 'isbn13', 'ean'] as $k) {
            $v = trim((string) ($fields[$k] ?? ''));
            if ($v !== '') {
                $codes[$k] = $v;
            }
        }

        // Acquire advisory lock to make duplicate check + insert atomic
        $lockKey = null;
        if (!empty($codes)) {
            // Create unique lock key from identifiers
            $lockKey = 'book_create_' . md5(implode('|', array_values($codes)));
            $lockResult = $db->query("SELECT GET_LOCK('{$db->real_escape_string($lockKey)}', 10)");
            $locked = $lockResult ? (int) $lockResult->fetch_row()[0] : 0;

            if (!$locked) {
                // Failed to acquire lock (timeout or error)
                $response->getBody()->write(json_encode([
                    'error' => 'lock_timeout',
                    'message' => __('Impossibile acquisire il lock. Riprova tra qualche secondo.')
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
            }
        }

        try {
            // Perform duplicate check within the lock
            // IMPORTANT: Check ALL values against ALL identifier fields (isbn10, isbn13, ean)
            // because EAN-13 and ISBN-13 are identical for books
            if (!empty($codes)) {
                // Get unique values from all identifier fields
                $allValues = array_unique(array_values($codes));
                $placeholders = implode(',', array_fill(0, count($allValues), '?'));
                $types = str_repeat('s', count($allValues) * 3); // 3 fields to check
                $params = array_merge($allValues, $allValues, $allValues); // Same values for each field

                $sql = 'SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.collocazione, l.scaffale_id, l.mensola_id, l.posizione_progressiva,
                        s.codice as scaffale_codice, m.numero_livello as mensola_livello
                        FROM libri l
                        LEFT JOIN scaffali s ON l.scaffale_id = s.id
                        LEFT JOIN mensole m ON l.mensola_id = m.id
                        WHERE l.isbn10 IN (' . $placeholders . ')
                           OR l.isbn13 IN (' . $placeholders . ')
                           OR l.ean IN (' . $placeholders . ')
                        LIMIT 1';
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                if ($dup) {
                    // Release lock before returning
                    if ($lockKey) {
                        $db->query("SELECT RELEASE_LOCK('{$db->real_escape_string($lockKey)}')");
                    }

                    // Build location string
                    $location = '';
                    if (!empty($dup['scaffale_codice']) && !empty($dup['mensola_livello']) && !empty($dup['posizione_progressiva'])) {
                        $location = $dup['scaffale_codice'] . '.' . $dup['mensola_livello'] . '.' . $dup['posizione_progressiva'];
                    } elseif (!empty($dup['collocazione'])) {
                        $location = $dup['collocazione'];
                    }

                    // Return JSON with duplicate book info for frontend to handle
                    $response->getBody()->write(json_encode([
                        'error' => 'duplicate',
                        'message' => __('Esiste già un libro con lo stesso identificatore (ISBN/EAN).'),
                        'existing_book' => [
                            'id' => (int) $dup['id'],
                            'title' => (string) ($dup['titolo'] ?? ''),
                            'isbn10' => (string) ($dup['isbn10'] ?? ''),
                            'isbn13' => (string) ($dup['isbn13'] ?? ''),
                            'ean' => (string) ($dup['ean'] ?? ''),
                            'location' => $location
                        ]
                    ], JSON_UNESCAPED_UNICODE));
                    return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
                }
            }

            $repo = new \App\Models\BookRepository($db);
            $fields['autori_ids'] = array_map('intval', $data['autori_ids'] ?? []);

            // Get scraped author bio if available
            $scrapedAuthorBio = trim((string) ($data['scraped_author_bio'] ?? ''));

            // Gestione autori nuovi da creare (con controllo duplicati normalizzati)
            if (!empty($data['autori_new'])) {
                $authRepo = new \App\Models\AuthorRepository($db);
                foreach ((array) $data['autori_new'] as $nomeCompleto) {
                    $nomeCompleto = trim((string) $nomeCompleto);
                    if ($nomeCompleto !== '') {
                        // Check if author already exists (handles "Levi, Primo" vs "Primo Levi")
                        $existingId = $authRepo->findByName($nomeCompleto);
                        if ($existingId) {
                            $fields['autori_ids'][] = $existingId;
                            // Update bio if existing author has no bio and we have scraped one
                            if ($scrapedAuthorBio !== '') {
                                $this->updateAuthorBioIfEmpty($db, $existingId, $scrapedAuthorBio);
                            }
                        } else {
                            $authorId = $authRepo->create([
                                'nome' => $nomeCompleto,
                                'pseudonimo' => '',
                                'data_nascita' => null,
                                'data_morte' => null,
                                'nazionalita' => '',
                                'biografia' => $scrapedAuthorBio,
                                'sito_web' => ''
                            ]);
                            $fields['autori_ids'][] = $authorId;
                        }
                    }
                }
            }

            // Auto-create author from scraped data if no authors selected
            if (empty($fields['autori_ids']) && !empty($data['scraped_author'])) {
                $authRepo = new \App\Models\AuthorRepository($db);
                $scrapedAuthor = trim((string) $data['scraped_author']);
                if ($scrapedAuthor !== '') {
                    $found = $authRepo->findByName($scrapedAuthor);
                    if ($found) {
                        $fields['autori_ids'][] = $found;
                        // Update bio if existing author has no bio and we have scraped one
                        if ($scrapedAuthorBio !== '') {
                            $this->updateAuthorBioIfEmpty($db, $found, $scrapedAuthorBio);
                        }
                    } else {
                        $authorId = $authRepo->create([
                            'nome' => $scrapedAuthor,
                            'pseudonimo' => '',
                            'data_nascita' => null,
                            'data_morte' => null,
                            'nazionalita' => '',
                            'biografia' => $scrapedAuthorBio,
                            'sito_web' => ''
                        ]);
                        $fields['autori_ids'][] = $authorId;
                    }
                }
            }

            // Update bio for the FIRST selected author if they have no bio
            // Scraped bio is from the book's primary author, don't apply to all authors
            if ($scrapedAuthorBio !== '' && !empty($fields['autori_ids'])) {
                $firstAuthorId = (int) reset($fields['autori_ids']);
                if ($firstAuthorId > 0) {
                    $this->updateAuthorBioIfEmpty($db, $firstAuthorId, $scrapedAuthorBio);
                }
            }

            // Handle publisher auto-creation from manual entry or scraped data
            if ((int) $fields['editore_id'] === 0) {
                $pubRepo = new \App\Models\PublisherRepository($db);
                $publisherName = '';

                // First try manual entry (editore_search field)
                if (!empty($data['editore_search'])) {
                    $publisherName = trim((string) $data['editore_search']);
                }
                // Fall back to scraped data
                elseif (!empty($data['scraped_publisher'])) {
                    $publisherName = trim((string) $data['scraped_publisher']);
                }

                if ($publisherName !== '') {
                    $found = $pubRepo->findByName($publisherName);
                    if ($found) {
                        $fields['editore_id'] = $found;
                    } else {
                        $fields['editore_id'] = $pubRepo->create(['nome' => $publisherName, 'sito_web' => '']);
                    }
                }
            }

            // Handle genere auto-creation from manual entry
            if ((int) $fields['genere_id'] === 0 && !empty($data['genere_search'])) {
                $genereRepo = new \App\Models\GenereRepository($db);
                $genereName = trim((string) $data['genere_search']);

                if ($genereName !== '') {
                    $found = $genereRepo->findByName($genereName);
                    if ($found) {
                        $fields['genere_id'] = $found;
                    } else {
                        $fields['genere_id'] = $genereRepo->create(['nome' => $genereName]);
                    }
                }
            }

            // Handle sottogenere auto-creation from manual entry
            if ((int) $fields['sottogenere_id'] === 0 && !empty($data['sottogenere_search'])) {
                $genereRepo = new \App\Models\GenereRepository($db);
                $sottogenereName = trim((string) $data['sottogenere_search']);

                if ($sottogenereName !== '') {
                    // If we have a parent genere, use it
                    $parent_id = !empty($fields['genere_id']) ? (int) $fields['genere_id'] : null;

                    $found = $genereRepo->findByName($sottogenereName, $parent_id);
                    if ($found) {
                        $fields['sottogenere_id'] = $found;
                    } else {
                        $fields['sottogenere_id'] = $genereRepo->create([
                            'nome' => $sottogenereName,
                            'parent_id' => $parent_id
                        ]);
                    }
                }
            }
            $collRepo = new \App\Models\CollocationRepository($db);
            if ($fields['scaffale_id'] && $fields['mensola_id']) {
                $pos = $fields['posizione_progressiva'] ?? null;
                if ($pos === null || $pos <= 0 || $collRepo->isProgressivaOccupied($fields['scaffale_id'], $fields['mensola_id'], $pos)) {
                    $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id']);
                }
                $fields['posizione_progressiva'] = $pos;
                $fields['collocazione'] = $collRepo->buildCollocazioneString($fields['scaffale_id'], $fields['mensola_id'], $pos);
            } else {
                $fields['posizione_progressiva'] = null;
                $fields['collocazione'] = $fields['collocazione'] ?? '';
            }

            // Plugin hook: Before book save
            \App\Support\Hooks::do('book.save.before', [$fields, null]);

            $id = $repo->createBasic($fields);

            // Plugin hook: After book save
            \App\Support\Hooks::do('book.save.after', [$id, $fields]);

            // Genera copie fisiche del libro
            $copyRepo = new \App\Models\CopyRepository($db);
            $copieTotali = (int) ($fields['copie_totali'] ?? 1);
            $baseInventario = !empty($fields['numero_inventario'])
                ? $fields['numero_inventario']
                : "LIB-{$id}";

            for ($i = 1; $i <= $copieTotali; $i++) {
                $numeroInventario = $copieTotali > 1
                    ? "{$baseInventario}-C{$i}"
                    : $baseInventario;

                $note = $copieTotali > 1 ? "Copia {$i} di {$copieTotali}" : null;
                $copyRepo->create($id, $numeroInventario, 'disponibile', $note);
            }

            // Handle simple cover upload
            if (!empty($_FILES['copertina']) && ($_FILES['copertina']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->handleCoverUpload($db, $id, $_FILES['copertina']);
            }
            if (!empty($data['scraped_cover_url'])) {
                $this->handleCoverUrl($db, $id, (string) $data['scraped_cover_url']);
            }
            // Optionals (numero_pagine, ean, data_pubblicazione, traduttore)
            (new \App\Models\BookRepository($db))->updateOptionals($id, $data);

            // Set a success message in the session
            $_SESSION['success_message'] = __('Libro aggiunto con successo!');

            return $response->withHeader('Location', '/admin/libri/' . $id)->withStatus(302);

        } finally {
            // Release advisory lock
            if ($lockKey) {
                $db->query("SELECT RELEASE_LOCK('{$db->real_escape_string($lockKey)}')");
            }
        }
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);
        if (!$libro) {
            return $response->withStatus(404);
        }
        $editRepo = new \App\Models\PublisherRepository($db);
        $autRepo = new \App\Models\AuthorRepository($db);
        $editori = $editRepo->listBasic();
        $autori = $autRepo->listBasic(500);
        $colRepo = new \App\Models\CollocationRepository($db);
        $taxRepo = new \App\Models\TaxonomyRepository($db);
        $scaffali = $colRepo->getScaffali();
        $mensole = $colRepo->getMensole();
        $generi = $taxRepo->genres();
        $sottogeneri = $taxRepo->subgenres();
        ob_start();
        $data = ['libro' => $libro, 'editori' => $editori, 'autori' => $autori, 'scaffali' => $scaffali, 'mensole' => $mensole, 'generi' => $generi, 'sottogeneri' => $sottogeneri];
        // extract(['libro'=>$libro,'editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri]); 
        require __DIR__ . '/../Views/libri/modifica_libro.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\BookRepository($db);
        $currentBook = $repo->getById($id);
        if (!$currentBook) {
            return $response->withStatus(404);
        }
        $fields = [
            'titolo' => '',
            'sottotitolo' => '',
            'isbn10' => '',
            'isbn13' => '',
            'ean' => '',
            'genere_id' => 0,
            'sottogenere_id' => 0,
            'editore_id' => 0,
            'data_acquisizione' => null,
            'tipo_acquisizione' => '',
            'descrizione' => '',
            'parole_chiave' => '',
            'formato' => '',
            'peso' => null,
            'dimensioni' => '',
            'prezzo' => null,
            'copie_totali' => 1,
            'copie_disponibili' => 1,
            'numero_inventario' => '',
            'classificazione_dewey' => '',
            'collana' => '',
            'numero_serie' => '',
            'note_varie' => '',
            'file_url' => '',
            'audio_url' => '',
            'copertina_url' => '',
            'scaffale_id' => 0,
            'mensola_id' => 0,
            'posizione_progressiva' => 0,
            'posizione_id' => 0,
            'collocazione' => '',
            'stato' => '',
            'lingua' => '',
            'anno_pubblicazione' => null,
            'edizione' => '',
            'data_pubblicazione' => '',
            'traduttore' => '',
            // LibraryThing fields (optional, only saved if plugin installed)
            'review' => null,
            'rating' => null,
            'comment' => null,
            'private_comment' => null,
            'physical_description' => null,
            'weight' => null,
            'height' => null,
            'thickness' => null,
            'length' => null,
            'lccn' => null,
            'lc_classification' => null,
            'other_call_number' => null,
            'date_acquired' => null,
            'date_started' => null,
            'date_read' => null,
            'bcid' => null,
            'oclc' => null,
            'work_id' => null,
            'issn' => null,
            'original_languages' => null,
            'source' => null,
            'from_where' => null,
            'lending_patron' => null,
            'lending_status' => null,
            'lending_start' => null,
            'lending_end' => null,
            'purchase_price' => null,
            'value' => null,
            'condition_lt' => null
        ];
        foreach ($fields as $k => $v) {
            if (array_key_exists($k, $data))
                $fields[$k] = $data[$k];
        }

        // Normalize text fields to remove MARC-8 control characters and collapse whitespace
        $fields['titolo'] = $this->normalizeText($fields['titolo']);
        $fields['sottotitolo'] = $this->normalizeText($fields['sottotitolo']);

        // Sanitize ISBN/EAN on update as well
        foreach (['isbn10', 'isbn13', 'ean'] as $codeKey) {
            if (isset($fields[$codeKey])) {
                $rawValue = (string) $fields[$codeKey];

                // Input validation: prevent ReDoS by checking length before regex
                // ISBN-10: max 13 chars (10 digits + 3 separators), ISBN-13: max 17 chars (13 digits + 4 separators), EAN: max 13
                $maxLength = ($codeKey === 'isbn10') ? 13 : (($codeKey === 'isbn13') ? 17 : 13);
                if (strlen($rawValue) > $maxLength) {
                    $rawValue = substr($rawValue, 0, $maxLength);
                }

                $fields[$codeKey] = preg_replace('/[\s-]+/', '', $rawValue);
            }
        }

        // Merge scraped subtitle and notes if present
        $subtitleFromScrape = trim((string) ($data['subtitle'] ?? ''));
        if ($subtitleFromScrape !== '' && trim((string) ($fields['sottotitolo'] ?? '')) === '') {
            $fields['sottotitolo'] = $this->normalizeText($subtitleFromScrape);
        }

        $notesParts = [];
        $currentNotes = trim((string) ($fields['note_varie'] ?? ''));
        if ($currentNotes !== '') {
            $notesParts[] = $currentNotes;
        }
        $notesFromScrape = trim((string) ($data['notes'] ?? ''));
        if ($notesFromScrape !== '') {
            $notesParts[] = $notesFromScrape;
        }
        $tipologiaScrape = trim((string) ($data['scraped_tipologia'] ?? ''));
        if ($tipologiaScrape !== '') {
            $notesParts[] = 'Tipologia: ' . $tipologiaScrape;
        }
        if ($notesParts) {
            $uniqueNotes = [];
            foreach ($notesParts as $part) {
                $clean = trim($part);
                if ($clean === '')
                    continue;
                $exists = false;
                foreach ($uniqueNotes as $existing) {
                    if (strcasecmp($existing, $clean) === 0) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $uniqueNotes[] = $clean;
                }
            }
            $fields['note_varie'] = implode("\n", $uniqueNotes);
        }

        // Convert 0 to NULL for optional foreign keys to avoid constraint failures
        $fields['editore_id'] = empty($fields['editore_id']) || $fields['editore_id'] == 0 ? null : (int) $fields['editore_id'];
        $fields['genere_id'] = empty($fields['genere_id']) || $fields['genere_id'] == 0 ? null : (int) $fields['genere_id'];
        $fields['sottogenere_id'] = empty($fields['sottogenere_id']) || $fields['sottogenere_id'] == 0 ? null : (int) $fields['sottogenere_id'];
        $fields['copie_totali'] = (int) $fields['copie_totali'];

        // Validazione copie: verifica che sia possibile ridurre il numero di copie
        $copyRepo = new \App\Models\CopyRepository($db);
        $currentCopieCount = $copyRepo->countByBookId($id);
        $newCopieCount = $fields['copie_totali'];

        if ($newCopieCount < $currentCopieCount) {
            // Conta quante copie sono disponibili per la rimozione
            $copie = $copyRepo->getByBookId($id);
            $removableCopies = 0;
            $nonRemovableCopies = 0;

            foreach ($copie as $copia) {
                if ($copia['stato'] === 'disponibile' && empty($copia['prestito_id'])) {
                    $removableCopies++;
                } else {
                    $nonRemovableCopies++;
                }
            }

            $requiredReduction = $currentCopieCount - $newCopieCount;

            if ($requiredReduction > $removableCopies) {
                $_SESSION['error_message'] = sprintf(
                    __('Impossibile ridurre le copie a %d. Ci sono %d copie non disponibili (in prestito, perse o danneggiate). Il numero minimo di copie totali è %d.'),
                    $newCopieCount,
                    $nonRemovableCopies,
                    $nonRemovableCopies
                );
                return $response->withHeader('Location', '/admin/libri/modifica/' . $id)->withStatus(302);
            }
        }

        // Non aggiorniamo copie_disponibili dall'utente, sarà ricalcolato automaticamente
        unset($fields['copie_disponibili']);

        $fields['scaffale_id'] = empty($fields['scaffale_id']) || $fields['scaffale_id'] == 0 ? null : (int) $fields['scaffale_id'];
        $fields['mensola_id'] = empty($fields['mensola_id']) || $fields['mensola_id'] == 0 ? null : (int) $fields['mensola_id'];
        $fields['posizione_progressiva'] = isset($fields['posizione_progressiva']) && $fields['posizione_progressiva'] !== '' ? (int) $fields['posizione_progressiva'] : null;
        $fields['posizione_id'] = null;
        $fields['peso'] = $fields['peso'] !== null && $fields['peso'] !== '' ? (float) $fields['peso'] : null;
        $fields['prezzo'] = $fields['prezzo'] !== null && $fields['prezzo'] !== '' ? (float) $fields['prezzo'] : null;

        // Gestione rimozione copertina
        if (isset($data['remove_cover']) && $data['remove_cover'] === '1') {
            // Cancella il file della copertina esistente se presente
            if (!empty($currentBook['copertina_url'])) {
                $oldCoverPath = $currentBook['copertina_url'];
                // Solo se è un file locale (non URL esterno)
                if (strpos($oldCoverPath, '/uploads/copertine/') === 0) {
                    // SECURITY FIX #2 (CRITICAL): Prevent path traversal attacks
                    $requestedPath = __DIR__ . '/../../public' . $oldCoverPath;
                    $safePath = realpath($requestedPath);
                    $baseDir = realpath($this->getCoversUploadPath());

                    // Ensure resolved path is within allowed directory
                    if ($safePath && $baseDir && strpos($safePath, $baseDir) === 0 && file_exists($safePath)) {
                        unlink($safePath);
                        $this->logCoverDebug('cover.deleted', ['path' => $oldCoverPath]);
                    } else {
                        $this->logCoverDebug('cover.delete.security_block', [
                            'requested' => $oldCoverPath,
                            'resolved' => $safePath,
                            'baseDir' => $baseDir
                        ]);
                    }
                }
            }
            // Imposta a NULL per rimuovere dal database
            $fields['copertina_url'] = null;
        } elseif ($fields['copertina_url'] === '' || $fields['copertina_url'] === null) {
            $fields['copertina_url'] = null;
        } else {
            // Auto-download external cover URLs
            $fields['copertina_url'] = $this->downloadExternalCover($fields['copertina_url']);
        }

        // Ensure hierarchical consistency between genere_id and sottogenere_id also on update
        try {
            $genRepoTmp = new \App\Models\GenereRepository($db);
            if (!empty($fields['sottogenere_id'])) {
                $sub = $genRepoTmp->getById((int) $fields['sottogenere_id']);
                if ($sub && !empty($sub['parent_id'])) {
                    $fields['genere_id'] = (int) $sub['parent_id'];
                }
            } elseif (!empty($fields['genere_id'])) {
                $g = $genRepoTmp->getById((int) $fields['genere_id']);
                if ($g && !empty($g['parent_id'])) {
                    $fields['sottogenere_id'] = (int) $fields['genere_id'];
                    $fields['genere_id'] = (int) $g['parent_id'];
                }
            }
        } catch (\Throwable $e) { /* ignore */
        }

        // Duplicate check on update (exclude current record) with advisory lock to prevent race conditions
        $codes = [];
        foreach (['isbn10', 'isbn13', 'ean'] as $k) {
            $v = trim((string) ($fields[$k] ?? ''));
            if ($v !== '') {
                $codes[$k] = $v;
            }
        }

        // Acquire advisory lock to make duplicate check + update atomic
        $lockKey = null;
        if (!empty($codes)) {
            // Create unique lock key from identifiers
            $lockKey = 'book_update_' . md5(implode('|', array_values($codes)));
            $lockResult = $db->query("SELECT GET_LOCK('{$db->real_escape_string($lockKey)}', 10)");
            $locked = $lockResult ? (int) $lockResult->fetch_row()[0] : 0;

            if (!$locked) {
                // Failed to acquire lock (timeout or error)
                $_SESSION['error_message'] = __('Impossibile acquisire il lock. Riprova tra qualche secondo.');
                return $response->withHeader('Location', '/admin/libri/modifica/' . $id)->withStatus(302);
            }
        }

        try {
            // Perform duplicate check within the lock
            // IMPORTANT: Check ALL values against ALL identifier fields (isbn10, isbn13, ean)
            // because EAN-13 and ISBN-13 are identical for books
            if (!empty($codes)) {
                // Get unique values from all identifier fields
                $allValues = array_unique(array_values($codes));
                $placeholders = implode(',', array_fill(0, count($allValues), '?'));
                $types = str_repeat('s', count($allValues) * 3) . 'i'; // 3 fields + book id
                $params = array_merge($allValues, $allValues, $allValues, [$id]);

                $sql = 'SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.ean, l.collocazione,
                               s.codice AS scaffale_codice, m.numero_livello AS mensola_livello, l.posizione_progressiva
                        FROM libri l
                        LEFT JOIN scaffali s ON l.scaffale_id = s.id
                        LEFT JOIN mensole m ON l.mensola_id = m.id
                        WHERE (l.isbn10 IN (' . $placeholders . ')
                           OR l.isbn13 IN (' . $placeholders . ')
                           OR l.ean IN (' . $placeholders . '))
                          AND l.id <> ?
                        LIMIT 1';
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                if ($dup) {
                    // Release lock before returning
                    if ($lockKey) {
                        $db->query("SELECT RELEASE_LOCK('{$db->real_escape_string($lockKey)}')");
                    }

                    // Build location string
                    $location = '';
                    if (!empty($dup['scaffale_codice']) && !empty($dup['mensola_livello']) && !empty($dup['posizione_progressiva'])) {
                        $location = $dup['scaffale_codice'] . '.' . $dup['mensola_livello'] . '.' . $dup['posizione_progressiva'];
                    } elseif (!empty($dup['collocazione'])) {
                        $location = $dup['collocazione'];
                    }

                    // Return JSON with duplicate book info for frontend to handle
                    $response->getBody()->write(json_encode([
                        'error' => 'duplicate',
                        'message' => __('Esiste già un altro libro con lo stesso identificatore (ISBN/EAN).'),
                        'existing_book' => [
                            'id' => (int) $dup['id'],
                            'title' => (string) ($dup['titolo'] ?? ''),
                            'isbn10' => (string) ($dup['isbn10'] ?? ''),
                            'isbn13' => (string) ($dup['isbn13'] ?? ''),
                            'ean' => (string) ($dup['ean'] ?? ''),
                            'location' => $location
                        ]
                    ], JSON_UNESCAPED_UNICODE));
                    return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
                }
            }
            $fields['autori_ids'] = array_map('intval', $data['autori_ids'] ?? []);

            $collRepo = new \App\Models\CollocationRepository($db);
            if ($fields['scaffale_id'] && $fields['mensola_id']) {
                $pos = $fields['posizione_progressiva'] ?? null;
                if ($pos === null || $pos <= 0) {
                    $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id'], $id);
                } elseif ($collRepo->isProgressivaOccupied($fields['scaffale_id'], $fields['mensola_id'], $pos, $id)) {
                    $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id'], $id);
                }
                $fields['posizione_progressiva'] = $pos;
                $fields['collocazione'] = $collRepo->buildCollocazioneString($fields['scaffale_id'], $fields['mensola_id'], $pos);
            } else {
                $fields['posizione_progressiva'] = null;
                $fields['collocazione'] = '';
            }

            // Get scraped author bio if available (for update method)
            $scrapedAuthorBioUpdate = trim((string) ($data['scraped_author_bio'] ?? ''));

            // Gestione autori nuovi da creare (con controllo duplicati normalizzati)
            if (!empty($data['autori_new'])) {
                $authRepo = new \App\Models\AuthorRepository($db);
                foreach ((array) $data['autori_new'] as $nomeCompleto) {
                    $nomeCompleto = trim((string) $nomeCompleto);
                    if ($nomeCompleto !== '') {
                        // Check if author already exists (handles "Levi, Primo" vs "Primo Levi")
                        $existingId = $authRepo->findByName($nomeCompleto);
                        if ($existingId) {
                            $fields['autori_ids'][] = $existingId;
                            // Update bio if existing author has no bio and we have scraped one
                            if ($scrapedAuthorBioUpdate !== '') {
                                $this->updateAuthorBioIfEmpty($db, $existingId, $scrapedAuthorBioUpdate);
                            }
                        } else {
                            $authorId = $authRepo->create([
                                'nome' => $nomeCompleto,
                                'pseudonimo' => '',
                                'data_nascita' => null,
                                'data_morte' => null,
                                'nazionalita' => '',
                                'biografia' => $scrapedAuthorBioUpdate,
                                'sito_web' => ''
                            ]);
                            $fields['autori_ids'][] = $authorId;
                        }
                    }
                }
            }

            // Auto-create author from scraped data if no authors selected
            if (empty($fields['autori_ids']) && !empty($data['scraped_author'])) {
                $authRepo = new \App\Models\AuthorRepository($db);
                $scrapedAuthor = trim((string) $data['scraped_author']);
                if ($scrapedAuthor !== '') {
                    $found = $authRepo->findByName($scrapedAuthor);
                    if ($found) {
                        $fields['autori_ids'][] = $found;
                        // Update bio if existing author has no bio and we have scraped one
                        if ($scrapedAuthorBioUpdate !== '') {
                            $this->updateAuthorBioIfEmpty($db, $found, $scrapedAuthorBioUpdate);
                        }
                    } else {
                        $authorId = $authRepo->create([
                            'nome' => $scrapedAuthor,
                            'pseudonimo' => '',
                            'data_nascita' => null,
                            'data_morte' => null,
                            'nazionalita' => '',
                            'biografia' => $scrapedAuthorBioUpdate,
                            'sito_web' => ''
                        ]);
                        $fields['autori_ids'][] = $authorId;
                    }
                }
            }

            // Update bio for the FIRST selected author if they have no bio
            // Scraped bio is from the book's primary author, don't apply to all authors
            if ($scrapedAuthorBioUpdate !== '' && !empty($fields['autori_ids'])) {
                $firstAuthorIdUpdate = (int) reset($fields['autori_ids']);
                if ($firstAuthorIdUpdate > 0) {
                    $this->updateAuthorBioIfEmpty($db, $firstAuthorIdUpdate, $scrapedAuthorBioUpdate);
                }
            }

            // Handle publisher auto-creation from manual entry or scraped data
            if ((int) $fields['editore_id'] === 0) {
                $pubRepo = new \App\Models\PublisherRepository($db);
                $publisherName = '';

                // First try manual entry (editore_search field)
                if (!empty($data['editore_search'])) {
                    $publisherName = trim((string) $data['editore_search']);
                }
                // Fall back to scraped data
                elseif (!empty($data['scraped_publisher'])) {
                    $publisherName = trim((string) $data['scraped_publisher']);
                }

                if ($publisherName !== '') {
                    $found = $pubRepo->findByName($publisherName);
                    if ($found) {
                        $fields['editore_id'] = $found;
                    } else {
                        $fields['editore_id'] = $pubRepo->create(['nome' => $publisherName, 'sito_web' => '']);
                    }
                }
            }

            // Handle genere auto-creation from manual entry
            if ((int) $fields['genere_id'] === 0 && !empty($data['genere_search'])) {
                $genereRepo = new \App\Models\GenereRepository($db);
                $genereName = trim((string) $data['genere_search']);

                if ($genereName !== '') {
                    $found = $genereRepo->findByName($genereName);
                    if ($found) {
                        $fields['genere_id'] = $found;
                    } else {
                        $fields['genere_id'] = $genereRepo->create(['nome' => $genereName]);
                    }
                }
            }

            // Handle sottogenere auto-creation from manual entry
            if ((int) $fields['sottogenere_id'] === 0 && !empty($data['sottogenere_search'])) {
                $genereRepo = new \App\Models\GenereRepository($db);
                $sottogenereName = trim((string) $data['sottogenere_search']);

                if ($sottogenereName !== '') {
                    // If we have a parent genere, use it
                    $parent_id = !empty($fields['genere_id']) ? (int) $fields['genere_id'] : null;

                    $found = $genereRepo->findByName($sottogenereName, $parent_id);
                    if ($found) {
                        $fields['sottogenere_id'] = $found;
                    } else {
                        $fields['sottogenere_id'] = $genereRepo->create([
                            'nome' => $sottogenereName,
                            'parent_id' => $parent_id
                        ]);
                    }
                }
            }

            // Plugin hook: Before book save (update)
            \App\Support\Hooks::do('book.save.before', [$fields, $id]);

            $repo->updateBasic($id, $fields);

            // Plugin hook: After book save (update)
            \App\Support\Hooks::do('book.save.after', [$id, $fields]);

            // Gestione copie: aggiorna il numero di copie se cambiato
            $copyRepo = new \App\Models\CopyRepository($db);
            $currentCopieCount = $copyRepo->countByBookId($id);
            $newCopieCount = (int) ($fields['copie_totali'] ?? 1);

            if ($newCopieCount > $currentCopieCount) {
                // Aggiungi nuove copie
                $baseInventario = !empty($fields['numero_inventario'])
                    ? $fields['numero_inventario']
                    : "LIB-{$id}";

                for ($i = $currentCopieCount + 1; $i <= $newCopieCount; $i++) {
                    $numeroInventario = $newCopieCount > 1
                        ? "{$baseInventario}-C{$i}"
                        : $baseInventario;

                    $note = "Copia {$i} di {$newCopieCount}";
                    $newCopyId = $copyRepo->create($id, $numeroInventario, 'disponibile', $note);

                    // Case 1: Reassign pending reservations to this new copy
                    try {
                        $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                        $reassignmentService->reassignOnNewCopy($id, $newCopyId);
                    } catch (\Exception $e) {
                        SecureLogger::error(__('Riassegnazione prenotazione nuova copia fallita'), [
                            'copia_id' => $newCopyId,
                            'error' => $e->getMessage()
                        ]);
                    }

                    // Also process waitlist (prenotazioni -> prestiti) as we have more capacity now
                    try {
                        $reservationManager = new \App\Controllers\ReservationManager($db);
                        $reservationManager->processBookAvailability($id);
                    } catch (\Exception $e) {
                        SecureLogger::error(__('Elaborazione lista attesa fallita'), [
                            'libro_id' => $id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } elseif ($newCopieCount < $currentCopieCount) {
                // Rimuovi copie in eccesso (solo quelle disponibili, non in prestito)
                $copie = $copyRepo->getByBookId($id);
                $toRemove = $currentCopieCount - $newCopieCount;
                $removed = 0;

                foreach ($copie as $copia) {
                    if ($removed >= $toRemove)
                        break;

                    // Rimuovi solo copie disponibili senza prestiti attivi
                    if ($copia['stato'] === 'disponibile' && empty($copia['prestito_id'])) {
                        $copyRepo->delete($copia['id']);
                        $removed++;
                    }
                }

                // Se non riusciamo a rimuovere abbastanza copie, avvisa l'utente
                if ($removed < $toRemove) {
                    $_SESSION['warning_message'] = __("Attenzione: Non è stato possibile rimuovere tutte le copie richieste. Alcune copie sono attualmente in prestito.");
                }
            }

            // Ricalcola disponibilità dopo aver modificato le copie
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($id);

            if (!empty($_FILES['copertina']) && ($_FILES['copertina']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->handleCoverUpload($db, $id, $_FILES['copertina']);
            }
            if (!empty($data['scraped_cover_url'])) {
                $this->handleCoverUrl($db, $id, (string) $data['scraped_cover_url']);
            }
            (new \App\Models\BookRepository($db))->updateOptionals($id, $data);

            // Set a success message in the session
            $_SESSION['success_message'] = __('Libro aggiornato con successo!');

            return $response->withHeader('Location', '/admin/libri/' . $id)->withStatus(302);

        } finally {
            // Release advisory lock
            if ($lockKey) {
                $db->query("SELECT RELEASE_LOCK('{$db->real_escape_string($lockKey)}')");
            }
        }
    }

    private function handleCoverUrl(mysqli $db, int $bookId, string $url): void
    {
        if (!$url)
            return;
        $this->logCoverDebug('handleCoverUrl.start', ['bookId' => $bookId, 'url' => $url]);

        // Security: Validate URL against whitelist for external downloads
        if (!$this->isUrlAllowed($url)) {
            $this->logCoverDebug('handleCoverUrl.security.blocked', ['bookId' => $bookId, 'url' => $url]);
            return;
        }

        // Case 1: local path already in /uploads/copertine => just persist it (normalize leading slash)
        if (strpos($url, '/uploads/copertine/') === 0 || strpos($url, 'uploads/copertine/') === 0) {
            if (strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }
            $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('si', $url, $bookId);
            $stmt->execute();
            $this->logCoverDebug('handleCoverUrl.local.persist', ['bookId' => $bookId, 'stored' => $url]);
            return;
        }

        // Case 2: absolute URL
        if (strpos($url, 'http') === 0) {
            // If it points to our own uploads path, just persist the relative path
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if ($path && (strpos($path, '/uploads/copertine/') === 0 || strpos($path, 'uploads/copertine/') === 0)) {
                if (strpos($path, '/') !== 0) {
                    $path = '/' . $path;
                }
                $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
                $stmt->bind_param('si', $path, $bookId);
                $stmt->execute();
                $this->logCoverDebug('handleCoverUrl.absolute.local', ['bookId' => $bookId, 'stored' => $path]);
                return;
            }

            // Otherwise, download and save locally

            // SECURITY FIX #1 (CRITICAL): Prevent SSRF via DNS rebinding
            // Resolve hostname to IP and validate it's not a private/reserved IP
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $ip = gethostbyname($host);

                // Block private IP ranges (RFC 1918), link-local, loopback, and reserved ranges
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $this->logCoverDebug('handleCoverUrl.security.private_ip_blocked', [
                        'bookId' => $bookId,
                        'url' => $url,
                        'host' => $host,
                        'resolved_ip' => $ip
                    ]);
                    return;
                }
            }

            // SECURITY FIX: DoS Prevention - Check Content-Length BEFORE downloading
            // Prevent memory exhaustion attacks by verifying file size before streaming

            // Step 1: HEAD request to check Content-Length
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,  // Only headers, no body
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BibliotecaBot/1.0'
            ]);
            curl_exec($ch);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Reject if size is known and exceeds limit (2MB)
            if ($contentLength > 0 && $contentLength > 2 * 1024 * 1024) {
                $this->logCoverDebug('handleCoverUrl.security.size_exceeded_precheck', [
                    'bookId' => $bookId,
                    'url' => $url,
                    'size' => $contentLength
                ]);
                return;
            }

            // Reject if HEAD request failed
            if ($httpCode >= 400) {
                $this->logCoverDebug('handleCoverUrl.download.head_fail', [
                    'bookId' => $bookId,
                    'url' => $url,
                    'httpCode' => $httpCode
                ]);
                return;
            }

            // Step 2: Download with streaming and size limit enforcement
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,  // Prevent redirect bypass
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BibliotecaBot/1.0',
                CURLOPT_BUFFERSIZE => 128 * 1024,  // 128KB chunks
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                    // Abort download if it exceeds 2MB during streaming
                    return ($downloaded > 2 * 1024 * 1024) ? 1 : 0;
                }
            ]);
            $img = curl_exec($ch);
            $curlError = curl_errno($ch);
            $curlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Handle abort from progress function
            if ($curlError === CURLE_ABORTED_BY_CALLBACK) {
                $this->logCoverDebug('handleCoverUrl.security.size_exceeded_during_download', [
                    'bookId' => $bookId,
                    'url' => $url
                ]);
                return;
            }

            // Handle download failure
            if ($img === false || $curlError !== 0 || $curlHttpCode >= 400) {
                $this->logCoverDebug('handleCoverUrl.download.fail', [
                    'bookId' => $bookId,
                    'url' => $url,
                    'curlError' => $curlError,
                    'httpCode' => $curlHttpCode
                ]);
                return;
            }

            // Final size check (defense in depth)
            if (strlen($img) > 2 * 1024 * 1024) {
                $this->logCoverDebug('handleCoverUrl.security.size_exceeded_final', [
                    'bookId' => $bookId,
                    'url' => $url,
                    'size' => strlen($img)
                ]);
                return;
            }

            // Security: Validate MIME type of downloaded content
            if (!$this->isValidImageMimeType($img)) {
                $this->logCoverDebug('handleCoverUrl.security.invalid_mime', ['bookId' => $bookId, 'url' => $url]);
                return;
            }
            $dir = $this->getCoversUploadPath() . '/';
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->logCoverDebug('handleCoverUrl.fail.mkdir', ['bookId' => $bookId, 'dir' => $dir]);
                    return;
                }
            }
            $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg';
            $name = 'libro_' . $bookId . '_' . time() . '.' . $ext;
            $dst = $dir . $name;
            if (file_put_contents($dst, $img) !== false) {
                $cover = $this->getCoversUrlPath() . '/' . $name;
                $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
                $stmt->bind_param('si', $cover, $bookId);
                $stmt->execute();
                $this->logCoverDebug('handleCoverUrl.download.ok', ['bookId' => $bookId, 'stored' => $cover]);
            }
        }
    }

    private function handleCoverUpload(mysqli $db, int $bookId, array $file): void
    {
        // Security: Do NOT trust $file['type'] from client - always validate actual content

        // 1. Verify this is an uploaded file
        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpPath)) {
            $this->logCoverDebug('handleCoverUpload.skip.not_uploaded', ['bookId' => $bookId]);
            return;
        }

        // 2. Check file size
        if (($file['size'] ?? 0) > 2 * 1024 * 1024 || ($file['size'] ?? 0) === 0) {
            $this->logCoverDebug('handleCoverUpload.skip.size', ['bookId' => $bookId, 'size' => $file['size'] ?? 0]);
            return;
        }

        // 3. Read actual file content
        $content = @file_get_contents($tmpPath);
        if ($content === false || $content === '') {
            $this->logCoverDebug('handleCoverUpload.skip.read_fail', ['bookId' => $bookId]);
            return;
        }

        // 4. Validate MIME type using magic bytes (NOT $file['type'])
        if (!$this->isValidImageMimeType($content)) {
            $this->logCoverDebug('handleCoverUpload.skip.invalid_magic', ['bookId' => $bookId]);
            return;
        }

        // 5. Get safe extension based on magic bytes (NOT from user-supplied filename)
        $ext = $this->getExtensionFromMagicBytes($content);
        if ($ext === null) {
            $this->logCoverDebug('handleCoverUpload.skip.unknown_format', ['bookId' => $bookId]);
            return;
        }

        // 6. Save file with safe name
        $dir = $this->getCoversUploadPath() . '/';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                $this->logCoverDebug('handleCoverUpload.fail.mkdir', ['bookId' => $bookId, 'dir' => $dir]);
                return;
            }
        }
        $name = 'libro_' . $bookId . '_' . time() . '.' . $ext;
        $dst = $dir . $name;

        if (file_put_contents($dst, $content) !== false) {
            $url = $this->getCoversUrlPath() . '/' . $name;
            $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('si', $url, $bookId);
            $stmt->execute();
            $this->logCoverDebug('handleCoverUpload.ok', ['bookId' => $bookId, 'stored' => $url]);
        } else {
            $this->logCoverDebug('handleCoverUpload.fail', ['bookId' => $bookId]);
        }
    }

    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        // CSRF validated by CsrfMiddleware

        // Case 10: Prevent deletion if there are active loans/reservations
        // Check prestiti table (approved loans only)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM prestiti
            WHERE libro_id = ?
            AND attivo = 1
            AND stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $prestitiCount = (int) $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        // Also check prenotazioni table (waitlist entries with stato='attiva')
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM prenotazioni
            WHERE libro_id = ?
            AND stato = 'attiva'
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $prenotazioniCount = (int) $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        if ($prestitiCount > 0 || $prenotazioniCount > 0) {
            $_SESSION['error_message'] = __('Impossibile eliminare il libro: ci sono prestiti o prenotazioni attive. Termina prima i prestiti/prenotazioni.');
            return $response->withHeader('Location', '/admin/libri/' . $id)->withStatus(302);
        }

        $repo = new \App\Models\BookRepository($db);
        $repo->delete($id);
        return $response->withHeader('Location', '/admin/libri')->withStatus(302);
    }

    /**
     * Security: Validate URL against whitelist for external image downloads
     * Uses centralized COVER_ALLOWED_DOMAINS constant
     */
    private function isUrlAllowed(string $url): bool
    {
        // Allow local paths
        if (strpos($url, '/uploads/') === 0 || strpos($url, 'uploads/') === 0) {
            return true;
        }

        // Only allow external downloads if URL starts with http/https
        if (strpos($url, 'http') !== 0) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        // Block localhost, private IPs, and internal networks
        if (
            \in_array($host, ['localhost', '127.0.0.1', '::1']) ||
            preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)
        ) {
            return false;
        }

        return \in_array($host, self::COVER_ALLOWED_DOMAINS, true);
    }

    /**
     * Security: Validate MIME type of downloaded image content
     */
    private function isValidImageMimeType(string $content): bool
    {
        if (strlen($content) < 12) {
            return false;
        }

        // SECURITY FIX #3 (CRITICAL): Block SVG files (can contain JavaScript)
        // SVG files can start with <?xml, <svg, or <!DOCTYPE
        $contentStart = substr($content, 0, 100);
        if (
            substr($contentStart, 0, 5) === '<?xml' ||
            stripos($contentStart, '<svg') !== false ||
            stripos($contentStart, '<!DOCTYPE svg') !== false
        ) {
            return false;
        }

        // Check magic bytes for common image formats
        $magicBytes = substr($content, 0, 12);

        // JPEG: FF D8 FF
        if (substr($magicBytes, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($magicBytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return true;
        }

        // GIF: 47 49 46 38 (GIF8)
        if (substr($magicBytes, 0, 4) === "GIF8") {
            return true;
        }

        // WebP: 52 49 46 46 + 57 45 42 50 (RIFF + WEBP)
        if (substr($magicBytes, 0, 4) === "RIFF" && substr($magicBytes, 8, 4) === "WEBP") {
            return true;
        }

        // AVIF: ftypavif or ftypavis (offset 4-12)
        if (strpos(substr($content, 4, 8), 'ftyp') === 0) {
            $brand = substr($content, 8, 4);
            if ($brand === 'avif' || $brand === 'avis') {
                return true;
            }
        }

        // BMP: 42 4D (BM in ASCII)
        if (substr($magicBytes, 0, 2) === "\x42\x4D") {
            return true;
        }

        return false;
    }

    /**
     * Get file extension from magic bytes (binary signature)
     * Security: Determines actual file type, not based on user-supplied name
     */
    private function getExtensionFromMagicBytes(string $content): ?string
    {
        if (strlen($content) < 4) {
            return null;
        }

        $magic = substr($content, 0, 12);

        // JPEG: FF D8 FF
        if (substr($magic, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (strlen($magic) >= 8 && substr($magic, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'png';
        }

        // GIF: GIF8
        if (substr($magic, 0, 4) === "GIF8") {
            return 'gif';
        }

        // WebP: RIFF + WEBP
        if (strlen($magic) >= 12 && substr($magic, 0, 4) === "RIFF" && substr($magic, 8, 4) === "WEBP") {
            return 'webp';
        }

        // AVIF: ftypavif or ftypavis (offset 4-12)
        if (strlen($content) >= 12 && strpos(substr($content, 4, 8), 'ftyp') === 0) {
            $brand = substr($content, 8, 4);
            if ($brand === 'avif' || $brand === 'avis') {
                return 'avif';
            }
        }

        // BMP: 42 4D (BM in ASCII)
        if (substr($magic, 0, 2) === "\x42\x4D") {
            return 'bmp';
        }

        return null;
    }

    /**
     * Fetch cover for a book via scraping (if missing)
     */
    public function fetchCover(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);

        if (!$libro) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Libro non trovato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Check if already has cover
        if (!empty($libro['copertina_url'])) {
            $response->getBody()->write(json_encode(['success' => true, 'fetched' => false, 'reason' => 'already_has_cover']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Get ISBN for scraping
        $isbn = $libro['isbn13'] ?? $libro['isbn10'] ?? $libro['ean'] ?? '';
        if (empty($isbn)) {
            $response->getBody()->write(json_encode(['success' => true, 'fetched' => false, 'reason' => 'no_isbn']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Call scraping API to get book data including cover (use /api/scrape/isbn route)
        $scrapeUrl = '/api/scrape/isbn?isbn=' . urlencode($isbn);

        // Internal request to scraping endpoint
        $ch = curl_init();
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . $scrapeUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Internal request
        ]);
        $scrapeResult = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$scrapeResult) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Scraping fallito')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
        }

        $scrapeData = json_decode($scrapeResult, true);
        if (!$scrapeData || empty($scrapeData['image'])) {
            $response->getBody()->write(json_encode(['success' => true, 'fetched' => false, 'reason' => 'no_cover_found']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Download and save the cover image
        $imageUrl = $scrapeData['image'];

        $uploadDir = $this->getCoversUploadPath() . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Download image
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BibliotecaCoverBot/1.0',
        ]);
        $imageData = curl_exec($ch);
        $imgHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($imgHttpCode !== 200 || !$imageData) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Download copertina fallito')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
        }

        // Check size limit (5MB max)
        $maxSize = 5 * 1024 * 1024;
        if (\strlen($imageData) > $maxSize) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('File troppo grande. Dimensione massima 5MB.')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate image
        $imageInfo = @getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Immagine non valida')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $mimeType = $imageInfo['mime'] ?? '';
        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };

        if ($extension === null) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Formato immagine non supportato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Save image
        $filename = 'copertina_' . $id . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        $image = imagecreatefromstring($imageData);
        if ($image === false) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Errore processamento immagine')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $saveResult = match ($extension) {
            'png' => imagepng($image, $filepath, 9),
            default => imagejpeg($image, $filepath, 85),
        };
        imagedestroy($image);

        if (!$saveResult) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => __('Errore salvataggio immagine')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        chmod($filepath, 0644);

        // Update book record with cover URL
        $coverUrl = $this->getCoversUrlPath() . '/' . $filename;
        $stmt = $db->prepare("UPDATE libri SET copertina_url = ? WHERE id = ?");
        $stmt->bind_param('si', $coverUrl, $id);
        $stmt->execute();
        $stmt->close();

        $response->getBody()->write(json_encode(['success' => true, 'fetched' => true, 'cover_url' => $coverUrl]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function generateLabelPDF(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);

        if (!$libro) {
            $response->getBody()->write('Libro non trovato');
            return $response->withStatus(404);
        }

        // Get application name and label settings from settings
        $settingsRepo = new \App\Models\SettingsRepository($db);
        $appName = $settingsRepo->get('app', 'name', 'Biblioteca');

        // Get label dimensions from settings (default to 25x38mm)
        $labelWidth = (int) ($settingsRepo->get('label', 'width', (string) \App\Support\ConfigStore::get('label.width', 25)));
        $labelHeight = (int) ($settingsRepo->get('label', 'height', (string) \App\Support\ConfigStore::get('label.height', 38)));

        // Ensure dimensions are within reasonable bounds
        if ($labelWidth < 10 || $labelWidth > 100)
            $labelWidth = 25;
        if ($labelHeight < 10 || $labelHeight > 100)
            $labelHeight = 38;

        // Determine orientation based on dimensions
        $orientation = $labelWidth > $labelHeight ? 'L' : 'P';

        // Get collocazione data
        $collocazione = '';
        if (!empty($libro['scaffale_id']) && !empty($libro['mensola_id']) && !empty($libro['posizione_progressiva'])) {
            $stmt = $db->prepare("SELECT s.codice as scaffale_codice, m.numero_livello
                                  FROM scaffali s, mensole m
                                  WHERE s.id = ? AND m.id = ?");
            $scaffaleId = (int) $libro['scaffale_id'];
            $mensolaId = (int) $libro['mensola_id'];
            $stmt->bind_param('ii', $scaffaleId, $mensolaId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $collocazione = $row['scaffale_codice'] . '.' . $row['numero_livello'] . '.' . $libro['posizione_progressiva'];
            }
        }

        // Create PDF with TCPDF using configured dimensions
        $pdf = new \TCPDF($orientation, 'mm', [$labelWidth, $labelHeight], true, 'UTF-8', false);

        // Document settings
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle('Etichetta - ' . $libro['titolo']);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Calculate scaled dimensions based on label size
        $isPortrait = $labelHeight > $labelWidth;

        // Set dynamic margins based on label size (proportional to width)
        $margin = max(1, min(3, $labelWidth * 0.05));
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetAutoPageBreak(false, 0);

        // Add page
        $pdf->AddPage();

        // Calculate available space
        $availableWidth = $labelWidth - ($margin * 2);
        $availableHeight = $labelHeight - ($margin * 2);

        // Handle autori
        $autoriStr = '';
        if (!empty($libro['autori'])) {
            if (is_array($libro['autori'])) {
                $autoriStr = implode(', ', array_map(function ($a) {
                    return $a['nome'] ?? '';
                }, $libro['autori']));
            } else {
                $autoriStr = (string) $libro['autori'];
            }
        }

        // Barcode data
        $barcodePayload = $this->prepareBarcodePayload($libro, $libro['isbn13'] ?? $libro['ean'] ?? $libro['isbn10'] ?? '');

        // Position text
        $positionText = '';
        if (!empty($libro['scaffale_codice']) && !empty($libro['mensola_livello']) && !empty($libro['posizione_progressiva'])) {
            $positionText = $libro['scaffale_codice'] . '.' . $libro['mensola_livello'] . '.' . $libro['posizione_progressiva'];
        } elseif (isset($libro['posizione_progressiva']) && $libro['posizione_progressiva'] > 0) {
            $positionText = 'Pos. ' . $libro['posizione_progressiva'];
        }

        if ($isPortrait) {
            // PORTRAIT LAYOUT (vertical label - most common for book spines)
            $this->renderPortraitLabel($pdf, $appName, $libro, $autoriStr, $collocazione, $barcodePayload, $positionText, $availableWidth, $availableHeight, $margin);
        } else {
            // LANDSCAPE LAYOUT (horizontal label - for larger internal labels)
            $this->renderLandscapeLabel($pdf, $appName, $libro, $autoriStr, $collocazione, $barcodePayload, $positionText, $availableWidth, $availableHeight, $margin);
        }

        // Output PDF
        $pdfContent = $pdf->Output('', 'S');

        $response->getBody()->write($pdfContent);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="etichetta_' . $id . '.pdf"');
    }

    /**
     * Render portrait (vertical) label layout
     * Optimized for narrow spine labels like 25x38mm, 25x40mm, 34x48mm
     * Includes: App name, Title, Author, EAN barcode, EAN text, Dewey, Collocazione
     */
    private function renderPortraitLabel($pdf, string $appName, array $libro, string $autoriStr, string $collocazione, array $barcode, string $positionText, float $availableWidth, float $availableHeight, float $margin): void
    {
        // Calculate font sizes proportional to label width
        $fontSizeApp = max(4, min(7, $availableWidth * 0.25));
        $fontSizeTitle = max(4, min(6, $availableWidth * 0.22));
        $fontSizeAuthor = max(3, min(5, $availableWidth * 0.18));
        $fontSizeSmall = max(3, min(4, $availableWidth * 0.15));
        $fontSizePosition = max(4, min(6, $availableWidth * 0.20));

        // Prepare text content
        $appNameShort = mb_substr($appName, 0, 12);
        $maxTitleChars = (int) ($availableWidth * 1.8);
        $titolo = mb_substr($libro['titolo'], 0, $maxTitleChars);
        if (mb_strlen($libro['titolo']) > $maxTitleChars)
            $titolo .= '...';

        $maxAuthorChars = (int) ($availableWidth * 1.5);
        $autoreShort = !empty($autoriStr) ? mb_substr($autoriStr, 0, $maxAuthorChars) : '';

        // EAN/ISBN text (for display under barcode)
        $eanText = $libro['ean'] ?? $libro['isbn13'] ?? $libro['isbn10'] ?? '';

        // Dewey classification
        $dewey = $libro['classificazione_dewey'] ?? '';

        // Calculate total content height
        $totalHeight = 0;
        $totalHeight += 3.5; // App name

        // Calculate title height (using getStringHeight)
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $titleHeight = $pdf->getStringHeight($availableWidth, $titolo);
        $totalHeight += $titleHeight + 0.5;

        // Author
        $includeAuthor = !empty($autoreShort);
        if ($includeAuthor) {
            $totalHeight += 2.5;
        }

        // Barcode + EAN text
        $includeBarcode = !empty($barcode['value']);
        $barcodeHeight = 0;
        if ($includeBarcode) {
            $barcodeHeight = min(8, $availableHeight * 0.18);
            $totalHeight += $barcodeHeight + 1;
            if (!empty($eanText)) {
                $totalHeight += 2; // EAN text under barcode
            }
        }

        // Dewey
        $includeDewey = !empty($dewey);
        if ($includeDewey) {
            $totalHeight += 2.5;
        }

        // Position/Collocazione
        $posColText = $collocazione ?: $positionText;
        if ($posColText) {
            $totalHeight += 3;
        }

        // Calculate vertical centering offset
        $verticalOffset = ($availableHeight - $totalHeight) / 2;
        if ($verticalOffset < 0)
            $verticalOffset = 0;

        // Start rendering with vertical centering
        $currentY = $margin + $verticalOffset;

        // App name
        $pdf->SetFont('helvetica', 'B', $fontSizeApp);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 3, $appNameShort, 0, 0, 'C');
        $currentY += 3.5;

        // Titolo libro (wrapped)
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $pdf->SetXY($margin, $currentY);
        $pdf->MultiCell($availableWidth, 2.5, $titolo, 0, 'C', false, 1);
        $currentY = $pdf->GetY() + 0.5;

        // Autore
        if ($includeAuthor) {
            $pdf->SetFont('helvetica', '', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 2, $autoreShort, 0, 0, 'C');
            $currentY += 2.5;
        }

        // Barcode
        if ($includeBarcode) {
            $barcodeWidth = $availableWidth * 0.85;
            $barcodeX = $margin + (($availableWidth - $barcodeWidth) / 2);
            $currentY += 0.5;
            $pdf->write1DBarcode($barcode['value'], $barcode['type'], $barcodeX, $currentY, $barcodeWidth, $barcodeHeight, 0.3, ['stretch' => true, 'fitwidth' => true]);
            $currentY += $barcodeHeight;

            // EAN text under barcode
            if (!empty($eanText)) {
                $pdf->SetFont('helvetica', '', $fontSizeSmall);
                $pdf->SetXY($margin, $currentY);
                $pdf->Cell($availableWidth, 2, $eanText, 0, 0, 'C');
                $currentY += 2;
            }
        }

        // Dewey classification
        if ($includeDewey) {
            $pdf->SetFont('helvetica', 'I', $fontSizeSmall);
            $pdf->SetXY($margin, $currentY);
            $deweyShort = mb_substr($dewey, 0, 15);
            $pdf->Cell($availableWidth, 2, "Dewey: {$deweyShort}", 0, 0, 'C');
            $currentY += 2.5;
        }

        // Position/Collocazione
        if ($posColText) {
            $pdf->SetFont('helvetica', 'B', $fontSizePosition);
            $pdf->SetXY($margin, $currentY);
            $posShort = mb_substr($posColText, 0, 12);
            $pdf->Cell($availableWidth, 3, $posShort, 0, 0, 'C');
        }
    }

    /**
     * Render landscape (horizontal) label layout
     * Optimized for larger labels like 70x36mm, 50x25mm, 52x30mm
     * Includes: App name, Title, Author/Publisher, EAN barcode, EAN text, Dewey, Collocazione
     */
    private function renderLandscapeLabel($pdf, string $appName, array $libro, string $autoriStr, string $collocazione, array $barcode, string $positionText, float $availableWidth, float $availableHeight, float $margin): void
    {
        // Calculate font sizes proportional to label height
        $fontSizeApp = max(6, min(10, $availableHeight * 0.25));
        $fontSizeTitle = max(5, min(8, $availableHeight * 0.20));
        $fontSizeAuthor = max(4, min(6, $availableHeight * 0.15));
        $fontSizeSmall = max(3, min(5, $availableHeight * 0.12));
        $fontSizePosition = max(5, min(8, $availableHeight * 0.22));

        // Prepare text content
        $maxTitleChars = (int) ($availableWidth * 0.8);
        $titolo = mb_substr($libro['titolo'], 0, $maxTitleChars);
        if (mb_strlen($libro['titolo']) > $maxTitleChars)
            $titolo .= '...';

        // Autore ed editore
        $autorEditore = [];
        if (!empty($autoriStr)) {
            $autorEditore[] = mb_substr($autoriStr, 0, 30);
        }
        if (!empty($libro['editore_nome'])) {
            $autorEditore[] = mb_substr((string) $libro['editore_nome'], 0, 20);
        }
        $infoText = '';
        if (!empty($autorEditore)) {
            $infoText = implode(' - ', $autorEditore);
            $maxInfoChars = (int) ($availableWidth * 0.9);
            $infoText = mb_substr($infoText, 0, $maxInfoChars);
        }

        // EAN/ISBN text (for display under barcode)
        $eanText = $libro['ean'] ?? $libro['isbn13'] ?? $libro['isbn10'] ?? '';

        // Dewey classification
        $dewey = $libro['classificazione_dewey'] ?? '';

        // Calculate total content height
        $totalHeight = 0;
        $totalHeight += 4.5; // App name

        $totalHeight += 4; // Title

        // Author/Publisher
        $includeInfo = !empty($infoText);
        if ($includeInfo) {
            $totalHeight += 3;
        }

        // Barcode + EAN text
        $includeBarcode = !empty($barcode['value']);
        $barcodeHeight = 0;
        if ($includeBarcode) {
            $barcodeHeight = min(10, $availableHeight * 0.25);
            $totalHeight += $barcodeHeight + 0.5;
            if (!empty($eanText)) {
                $totalHeight += 2.5; // EAN text under barcode
            }
        }

        // Dewey
        $includeDewey = !empty($dewey);
        if ($includeDewey) {
            $totalHeight += 2.5;
        }

        // Position text
        $includePosition = !empty($positionText) && !$collocazione;
        if ($includePosition) {
            $totalHeight += 3.5;
        }

        // Collocazione
        $includeCollocazione = !empty($collocazione);
        if ($includeCollocazione) {
            $totalHeight += 4;
        }

        // Calculate vertical centering offset
        $verticalOffset = ($availableHeight - $totalHeight) / 2;
        if ($verticalOffset < 0)
            $verticalOffset = 0;

        // Start rendering with vertical centering
        $currentY = $margin + $verticalOffset;

        // App name
        $pdf->SetFont('helvetica', 'B', $fontSizeApp);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 4, $appName, 0, 0, 'C');
        $currentY += 4.5;

        // Titolo libro
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 3.5, $titolo, 0, 0, 'C');
        $currentY += 4;

        // Autore ed editore
        if ($includeInfo) {
            $pdf->SetFont('helvetica', '', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 2.5, $infoText, 0, 0, 'C');
            $currentY += 3;
        }

        // Barcode (centered horizontally)
        if ($includeBarcode) {
            $barcodeWidth = min($availableWidth * 0.65, 44);
            $barcodeX = $margin + (($availableWidth - $barcodeWidth) / 2);
            $currentY += 0.5;
            $pdf->write1DBarcode($barcode['value'], $barcode['type'], $barcodeX, $currentY, $barcodeWidth, $barcodeHeight, 0.4, ['stretch' => true]);
            $currentY += $barcodeHeight;

            // EAN text under barcode
            if (!empty($eanText)) {
                $pdf->SetFont('helvetica', '', $fontSizeSmall);
                $pdf->SetXY($margin, $currentY);
                $pdf->Cell($availableWidth, 2.5, $eanText, 0, 0, 'C');
                $currentY += 2.5;
            }
        }

        // Dewey classification
        if ($includeDewey) {
            $pdf->SetFont('helvetica', 'I', $fontSizeSmall);
            $pdf->SetXY($margin, $currentY);
            $deweyShort = mb_substr($dewey, 0, 20);
            $pdf->Cell($availableWidth, 2.5, "Dewey: {$deweyShort}", 0, 0, 'C');
            $currentY += 2.5;
        }

        // Position text
        if ($includePosition) {
            $pdf->SetFont('helvetica', 'B', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 3, $positionText, 0, 0, 'C');
            $currentY += 3.5;
        }

        // Collocazione
        if ($includeCollocazione) {
            $pdf->SetFont('helvetica', 'B', $fontSizePosition);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 4, $collocazione, 0, 0, 'C');
        }
    }

    private function prepareBarcodePayload(array $libro, string $rawValue): array
    {
        $normalized = strtoupper(trim($rawValue));
        $digits = preg_replace('/[^0-9]/', '', $normalized);

        if ($digits && preg_match('/^\d{13}$/', $digits)) {
            return ['value' => $digits, 'type' => 'EAN13'];
        }

        if ($digits && preg_match('/^\d{12}$/', $digits)) {
            return ['value' => $digits . $this->calculateEan13CheckDigit($digits), 'type' => 'EAN13'];
        }

        // Convert ISBN-10 (with possible X) to EAN-13 by prefixing 978 and recomputing the check digit
        if ($normalized && preg_match('/^\d{9}[\dX]$/i', $normalized)) {
            $isbnDigits = substr($normalized, 0, 9); // drop ISBN-10 check digit
            $base = '978' . $isbnDigits;
            return ['value' => $base . $this->calculateEan13CheckDigit($base), 'type' => 'EAN13'];
        }

        if ($digits && preg_match('/^\d{8}$/', $digits)) {
            return ['value' => $digits, 'type' => 'EAN8'];
        }

        $fallback = 'LIB-' . str_pad((string) ($libro['id'] ?? 0), 6, '0', STR_PAD_LEFT);
        return ['value' => $fallback, 'type' => 'C128'];
    }

    private function calculateEan13CheckDigit(string $digits): int
    {
        $sum = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $num = (int) $digits[$i];
            $sum += ($i % 2 === 0) ? $num : $num * 3;
        }
        $mod = $sum % 10;
        return $mod === 0 ? 0 : 10 - $mod;
    }

    /**
     * Export libri to CSV in import-compatible format
     * Supports multiple formats and delimiters
     */
    public function exportCsv(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\BookRepository($db);

        // Get filters and options from query parameters
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $stato = $params['stato'] ?? '';
        $editoreId = isset($params['editore_id']) && is_numeric($params['editore_id']) ? (int) $params['editore_id'] : 0;
        $genereId = isset($params['genere_id']) && is_numeric($params['genere_id']) ? (int) $params['genere_id'] : 0;
        $autoreId = isset($params['autore_id']) && is_numeric($params['autore_id']) ? (int) $params['autore_id'] : 0;

        // Export format options
        $format = $params['format'] ?? 'standard'; // standard, librarything
        $delimiter = $params['delimiter'] ?? ';'; // ;, comma, tab

        // Normalize delimiter parameter
        if ($delimiter === 'comma') {
            $delimiter = ',';
        } elseif ($delimiter === 'tab') {
            $delimiter = "\t";
        }

        // Build WHERE clause based on filters
        $whereClauses = [];
        $bindTypes = '';
        $bindValues = [];

        // Global search filter
        if (!empty($search)) {
            $whereClauses[] = "(l.titolo LIKE ? OR l.sottotitolo LIKE ? OR l.isbn13 LIKE ? OR l.isbn10 LIKE ? OR a.nome LIKE ? OR e.nome LIKE ?)";
            $searchParam = "%{$search}%";
            for ($i = 0; $i < 6; $i++) {
                $bindTypes .= 's';
                $bindValues[] = $searchParam;
            }
        }

        // Status filter
        if (!empty($stato)) {
            $whereClauses[] = "l.stato = ?";
            $bindTypes .= 's';
            $bindValues[] = $stato;
        }

        // Editore filter
        if ($editoreId > 0) {
            $whereClauses[] = "l.editore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $editoreId;
        }

        // Genere filter
        if ($genereId > 0) {
            $whereClauses[] = "l.genere_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $genereId;
        }

        // Autore filter
        if ($autoreId > 0) {
            $whereClauses[] = "la.autore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $autoreId;
        }

        // Build the query
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

        // Execute query with prepared statement if filters are applied
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

        // OPTIMIZATION: Use php://temp with memory limit to handle large datasets
        // Stream data instead of building giant string in memory
        $stream = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'r+'); // 5MB limit

        // UTF-8 BOM
        fwrite($stream, "\xEF\xBB\xBF");

        // CSV headers based on format
        if ($format === 'librarything') {
            $headers = $this->getLibraryThingHeaders();
        } else {
            // Standard format (default)
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
        }

        fwrite($stream, implode($delimiter, $headers) . "\n");

        $rowCount = 0;
        foreach ($libri as $libro) {
            // Use anno_pubblicazione directly (SMALLINT UNSIGNED type in DB, range 0-65535)
            $anno = $libro['anno_pubblicazione'] ?? '';

            if ($format === 'librarything') {
                $row = $this->formatLibraryThingRow($libro, $anno);
            } else {
                // Standard format (default)
                $row = [
                    $libro['id'] ?? '',
                    $libro['isbn10'] ?? '',
                    $libro['isbn13'] ?? '',
                    $libro['ean'] ?? '',
                    $libro['titolo'] ?? '',
                    $libro['sottotitolo'] ?? '',
                    $libro['autori_nomi'] ?? '',
                    $libro['editore_nome'] ?? '',
                    $anno,
                    $libro['lingua'] ?? '',
                    $libro['edizione'] ?? '',
                    $libro['numero_pagine'] ?? '',
                    $libro['genere_nome'] ?? '',
                    $libro['descrizione'] ?? '',
                    $libro['formato'] ?? '',
                    $libro['prezzo'] ?? '',
                    $libro['copie_totali'] ?? '1',
                    $libro['collana'] ?? '',
                    $libro['numero_serie'] ?? '',
                    $libro['traduttore'] ?? '',
                    $libro['parole_chiave'] ?? ''
                ];
            }

            // Escape fields for CSV
            $escapedRow = array_map(function ($field) use ($delimiter) {
                $field = str_replace('"', '""', (string) $field);
                // Quote if contains delimiter, newline, or quotes
                if (strpos($field, $delimiter) !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $row);

            fwrite($stream, implode($delimiter, $escapedRow) . "\n");

            // OPTIMIZATION: Garbage collection every 1000 rows to prevent memory buildup
            if (++$rowCount % 1000 === 0) {
                gc_collect_cycles();
            }
        }

        rewind($stream);

        $filename = 'libri_export_' . date('Y-m-d_His') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
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
     * Get LibraryThing CSV headers
     *
     * @return array Headers in LibraryThing format
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
     * Format book row in LibraryThing format
     *
     * @param array $libro Book data from database
     * @param string $anno Publication year
     * @return array Row data in LibraryThing format
     */
    private function formatLibraryThingRow(array $libro, string $anno): array
    {
        // Parse authors (may be semicolon-separated)
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

        // Build publication string (City, Publisher, Year)
        $publication = '';
        if (!empty($libro['editore_nome'])) {
            $publication = $libro['editore_nome'];
            if (!empty($anno)) {
                $publication .= ', ' . $anno;
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

        // Build ISBNs (combine isbn10 and isbn13)
        $isbns = [];
        if (!empty($libro['isbn13'])) {
            $isbns[] = $libro['isbn13'];
        }
        if (!empty($libro['isbn10'])) {
            $isbns[] = $libro['isbn10'];
        }
        $isbnString = !empty($isbns) ? '[' . implode(', ', $isbns) . ']' : '';

        return [
            $libro['id'] ?? '',                                    // Book Id
            $libro['titolo'] ?? '',                                // Title
            $libro['sottotitolo'] ?? '',                           // Sort Character
            $primaryAuthor,                                        // Primary Author
            '',                                                    // Primary Author Role
            $secondaryAuthor,                                      // Secondary Author
            '',                                                    // Secondary Author Roles
            $publication,                                          // Publication
            $anno,                                                 // Date
            '',                                                    // Review
            '',                                                    // Rating
            '',                                                    // Comment
            '',                                                    // Private Comment
            $libro['descrizione'] ?? '',                           // Summary
            $media,                                                // Media
            '',                                                    // Physical Description
            '',                                                    // Weight
            '',                                                    // Height
            '',                                                    // Thickness
            '',                                                    // Length
            '',                                                    // Dimensions
            $libro['numero_pagine'] ?? '',                         // Page Count
            '',                                                    // LCCN
            '',                                                    // Acquired
            '',                                                    // Date Started
            '',                                                    // Date Read
            $libro['ean'] ?? '',                                   // Barcode
            '',                                                    // BCID
            $libro['parole_chiave'] ?? '',                         // Tags
            $libro['collana'] ?? '',                               // Collections
            $language,                                             // Languages
            '',                                                    // Original Languages
            '',                                                    // LC Classification
            $libro['isbn13'] ?? $libro['isbn10'] ?? '',            // ISBN
            $isbnString,                                           // ISBNs
            $libro['genere_nome'] ?? '',                           // Subjects
            $libro['classificazione_dewey'] ?? '',                 // Dewey Decimal
            '',                                                    // Dewey Wording
            '',                                                    // Other Call Number
            $libro['copie_totali'] ?? '1',                         // Copies
            '',                                                    // Source
            '',                                                    // Entry Date
            '',                                                    // From Where
            '',                                                    // OCLC
            '',                                                    // Work id
            '',                                                    // Lending Patron
            '',                                                    // Lending Status
            '',                                                    // Lending Start
            '',                                                    // Lending End
            $libro['prezzo'] ?? '',                                // List Price
            '',                                                    // Purchase Price
            '',                                                    // Value
            '',                                                    // Condition
            ''                                                     // ISSN
        ];
    }

    /**
     * Sync book covers using scraping plugins (Open Library, Scraper Pro, Scraper API)
     */
    public function syncCovers(Request $request, Response $response, mysqli $db): Response
    {
        set_time_limit(600); // 10 minutes max

        $synced = 0;
        $skipped = 0;
        $errors = 0;

        // Find all books with ISBN but without cover
        $query = "SELECT id, isbn13, isbn10, titolo
                  FROM libri
                  WHERE (isbn13 IS NOT NULL AND isbn13 != '' OR isbn10 IS NOT NULL AND isbn10 != '')
                    AND (copertina_url IS NULL OR copertina_url = '')
                  ORDER BY id DESC";

        $result = $db->query($query);
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $books[] = $row;
        }

        error_log("[Cover Sync] Found " . count($books) . " books without covers");

        foreach ($books as $book) {
            $isbn = $book['isbn13'] ?: $book['isbn10'];

            if (!$isbn) {
                $skipped++;
                continue;
            }

            try {
                error_log("[Cover Sync] Attempting to scrape cover for book ID {$book['id']}, ISBN: $isbn");

                // Use scraping controller to get book data
                $scrapedData = $this->scrapeBookCover($isbn);

                if (!empty($scrapedData['image'])) {
                    // Update only cover URL
                    $stmt = $db->prepare("UPDATE libri SET copertina_url = ? WHERE id = ?");
                    $stmt->bind_param('si', $scrapedData['image'], $book['id']);
                    $stmt->execute();
                    $stmt->close();

                    $synced++;
                    error_log("[Cover Sync] Cover updated for book ID {$book['id']}: {$scrapedData['image']}");
                } else {
                    $errors++;
                    error_log("[Cover Sync] No cover found for book ID {$book['id']}, ISBN: $isbn");
                }

                // Rate limiting: wait 2 seconds between requests
                sleep(2);

            } catch (\Exception $e) {
                $errors++;
                error_log("[Cover Sync] Error scraping book ID {$book['id']}: " . $e->getMessage());
            }
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => count($books)
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Scrape book cover from online services
     */
    private function scrapeBookCover(string $isbn): array
    {
        $scrapeController = new \App\Controllers\ScrapeController();
        $maxAttempts = 3;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Build a fresh request for every attempt
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

                    // Return only image data
                    return [
                        'image' => $data['image'] ?? null
                    ];
                }

                error_log(sprintf(
                    '[Cover Sync] Scraping attempt %d/%d failed for ISBN %s with status %d',
                    $attempt,
                    $maxAttempts,
                    $isbn,
                    $response->getStatusCode()
                ));
            } catch (\Throwable $scrapeException) {
                error_log(sprintf(
                    '[Cover Sync] Scraping attempt %d/%d threw for ISBN %s: %s',
                    $attempt,
                    $maxAttempts,
                    $isbn,
                    $scrapeException->getMessage()
                ));
            }

            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return [];
    }

    /**
     * Update author biography if currently empty
     * Used to populate bio from scraped data without overwriting existing content
     */
    private function updateAuthorBioIfEmpty(\mysqli $db, int $authorId, string $bio): void
    {
        if ($bio === '' || $authorId <= 0) {
            return;
        }

        // Decode HTML entities for consistency with AuthorRepository::create()
        $bio = \App\Support\HtmlHelper::decode($bio);

        // Atomic update: only set bio if currently empty (prevents TOCTOU race condition)
        // Use TRIM to also catch biographies containing only whitespace
        $stmt = $db->prepare("UPDATE autori SET biografia = ? WHERE id = ? AND (biografia IS NULL OR TRIM(biografia) = '')");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('si', $bio, $authorId);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows > 0) {
            error_log("[LibriController] Updated author bio for ID $authorId from scraping");
        }
    }
}
