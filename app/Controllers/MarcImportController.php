<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\MarcBookImporter;
use App\Support\Marc\MarcFormat;
use App\Support\Marc\MarcReadError;
use App\Support\Marc\MarcRecord;
use App\Support\Marc\MarcXmlReader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bulk import of MARC records (ISO 2709 ".mrc" or MARCXML; MARC 21, UNIMARC
 * or danMARC2) on the CSV import's infrastructure: upload, a preview of the
 * first mapped records, chunked processing with a progress bar, the import
 * history (import_logs, type 'marc') and its error download.
 *
 * The upload reads the file once, streaming, and stages every record as one
 * JSON line (its mapped book data, or the reason it could not be read), so
 * each chunk resumes at a byte offset instead of re-reading the file.
 */
class MarcImportController
{
    private const CHUNK_SIZE = 10;
    private const PREVIEW_SIZE = 10;
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_RECORDS = 20000;
    private const SESSION_KEY = 'marc_import_data';
    private const EXTENSIONS = ['mrc', 'marc', 'iso', 'dat', 'xml', 'marcxml'];

    public function showImportPage(Request $request, Response $response): Response
    {
        ob_start();
        $title = __('Import MARC');
        include __DIR__ . '/../Views/admin/marc_import.php';
        $content = (string) ob_get_clean();
        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    }

    /**
     * Stage the uploaded file and answer with the record count and a preview.
     */
    public function upload(Request $request, Response $response, \mysqli $db): Response
    {
        @ini_set('display_errors', '0');
        @set_time_limit(300);
        $data = (array) $request->getParsedBody();
        $file = $request->getUploadedFiles()['marc_file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['success' => false, 'error' => __('Nessun file caricato')], 400);
        }
        $name = (string) $file->getClientFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return $this->json($response, ['success' => false, 'error' => __('Il file deve essere un file MARC (.mrc, .marc, .iso) o MARCXML (.xml)')], 400);
        }
        if ((int) $file->getSize() > self::MAX_FILE_BYTES) {
            return $this->json($response, ['success' => false, 'error' => __('File troppo grande. Dimensione massima %d MB.', (int) (self::MAX_FILE_BYTES / 1048576))], 400);
        }
        $tmp = (string) $file->getStream()->getMetadata('uri');
        if ($tmp === '' || !is_file($tmp)) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }
        $container = MarcFormat::containerOfFile($tmp);
        if ($container === null) {
            return $this->json($response, ['success' => false, 'error' => __('Il file non contiene record MARC riconoscibili (ISO 2709 o MARCXML)')], 400);
        }

        $flavour = (string) ($data['flavour'] ?? MarcFormat::AUTO);
        if ($flavour !== MarcFormat::AUTO && !in_array($flavour, MarcFormat::FLAVOURS, true)) {
            $flavour = MarcFormat::AUTO;
        }
        $encoding = (string) ($data['encoding'] ?? Iso2709Reader::ENCODING_AUTO);
        if (!in_array($encoding, [Iso2709Reader::ENCODING_AUTO, Iso2709Reader::ENCODING_UTF8, Iso2709Reader::ENCODING_MARC8], true)) {
            $encoding = Iso2709Reader::ENCODING_AUTO;
        }
        $mode = (string) ($data['duplicates'] ?? MarcBookImporter::MODE_SKIP);
        if (!in_array($mode, MarcBookImporter::MODES, true)) {
            $mode = MarcBookImporter::MODE_SKIP;
        }

        $dir = __DIR__ . '/../../writable/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->discardStaging();
        $staging = $dir . '/marc_import_' . preg_replace('/[^A-Za-z0-9]/', '', session_id()) . '_' . bin2hex(random_bytes(6)) . '.jsonl';
        $out = @fopen($staging, 'wb');
        if ($out === false) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile salvare il file caricato')], 500);
        }

        $total = 0;
        $unreadable = 0;
        $truncated = false;
        $preview = [];
        $importer = new MarcBookImporter($db, $mode, false);
        $csv = new CsvImportController();
        try {
            $reader = $container === MarcFormat::CONTAINER_XML
                ? MarcXmlReader::fromPath($tmp)
                : Iso2709Reader::fromPath($tmp, $encoding);
            foreach ($reader as $position => $item) {
                if ($total >= self::MAX_RECORDS) {
                    $truncated = true;
                    break;
                }
                $total++;
                $line = $this->stageLine((int) $position, $item, $flavour);
                fwrite($out, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                if (isset($line['e'])) {
                    $unreadable++;
                }
                if (count($preview) < self::PREVIEW_SIZE) {
                    $preview[] = $this->previewRow($line, $importer, $csv);
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($staging);
            \App\Support\SecureLogger::error('[MARC import] cannot read upload', ['error' => $e->getMessage()]);
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }
        fclose($out);
        if ($total === 0) {
            @unlink($staging);
            return $this->json($response, ['success' => false, 'error' => __('Il file non contiene record MARC riconoscibili (ISO 2709 o MARCXML)')], 400);
        }

        $_SESSION[self::SESSION_KEY] = [
            'file_path' => $staging,
            'original_filename' => $name,
            'total_rows' => $total,
            'mode' => $mode,
            'covers' => !empty($data['download_covers']),
            'offset' => 0,
            'processed' => 0,
            'counters' => [],
            'errors' => [],
        ];

        return $this->json($response, [
            'success' => true,
            'total_rows' => $total,
            'unreadable' => $unreadable,
            'truncated' => $truncated ? sprintf(__('Il file contiene più di %d record: vengono importati solo i primi %d'), self::MAX_RECORDS, self::MAX_RECORDS) : null,
            'container' => $container,
            'chunk_size' => !empty($data['download_covers']) ? 2 : self::CHUNK_SIZE,
            'preview' => $preview,
        ]);
    }

    /**
     * Import the next records of the staged file. A repeated request for a
     * chunk already done (a retry after a lost response) does nothing.
     */
    public function chunk(Request $request, Response $response, \mysqli $db): Response
    {
        @set_time_limit(600);
        @ini_set('display_errors', '0');
        $payload = json_decode((string) $request->getBody(), true);
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($payload) || !is_array($state)) {
            return $this->json($response, ['success' => false, 'error' => __('Sessione import scaduta o dati non validi')], 400);
        }
        $start = max(0, (int) ($payload['start'] ?? 0));
        $size = max(1, min((int) ($payload['size'] ?? self::CHUNK_SIZE), self::CHUNK_SIZE));
        $processed = (int) $state['processed'];

        if ($start === $processed && $processed < (int) $state['total_rows']) {
            $handle = @fopen((string) $state['file_path'], 'rb');
            if ($handle === false) {
                return $this->json($response, ['success' => false, 'error' => __('Impossibile aprire il file caricato')], 500);
            }
            fseek($handle, (int) $state['offset']);
            $importer = new MarcBookImporter($db, (string) $state['mode'], (bool) $state['covers']);
            $counters = (array) $state['counters'];
            for ($i = 0; $i < $size; $i++) {
                $raw = fgets($handle);
                if ($raw === false) {
                    break;
                }
                $processed++;
                $line = json_decode($raw, true);
                if (!is_array($line)) {
                    $state['errors'][] = ['line' => $processed, 'title' => '', 'message' => __('Record non leggibile'), 'type' => 'parse'];
                    continue;
                }
                $position = (int) ($line['p'] ?? $processed);
                if (isset($line['e'])) {
                    $state['errors'][] = [
                        'line' => $position,
                        'title' => (string) ($line['x'] ?? ''),
                        'message' => (string) $line['e'],
                        'type' => 'parse',
                    ];
                    continue;
                }
                $bookData = is_array($line['d'] ?? null) ? $line['d'] : [];
                $title = (string) ($bookData['titolo'] ?? '');
                try {
                    $warnings = [];
                    $result = $importer->import($bookData, $counters, $warnings);
                    if ($result['action'] === 'skipped') {
                        $state['errors'][] = [
                            'line' => $position,
                            'title' => $title,
                            'message' => sprintf(__('Già in catalogo (libro #%d): saltato'), (int) $result['id']),
                            'type' => 'duplicate',
                        ];
                    }
                    foreach ($warnings as $warning) {
                        $state['errors'][] = ['line' => $position, 'title' => $title, 'message' => $warning, 'type' => 'cover'];
                    }
                } catch (\Throwable $e) {
                    $state['errors'][] = ['line' => $position, 'title' => $title, 'message' => $e->getMessage(), 'type' => 'validation'];
                    \App\Support\SecureLogger::warning('[MARC import] record failed', ['position' => $position, 'error' => $e->getMessage()]);
                }
            }
            $offset = ftell($handle);
            fclose($handle);
            $state['offset'] = $offset === false ? (int) $state['offset'] : $offset;
            $state['processed'] = $processed;
            $state['counters'] = $counters;
            $_SESSION[self::SESSION_KEY] = $state;
        }

        $complete = $processed >= (int) $state['total_rows'];
        $counters = (array) $state['counters'];
        $errors = (array) $state['errors'];
        $failed = count(array_filter($errors, static fn (array $e): bool => in_array($e['type'] ?? '', ['validation', 'parse'], true)));
        $importId = null;
        if ($complete) {
            $importId = $this->persistHistory($db, $state, $failed);
            $this->discardStaging();
        }

        return $this->json($response, [
            'success' => true,
            'processed' => $processed,
            'total_rows' => (int) $state['total_rows'],
            'imported' => (int) ($counters['imported'] ?? 0),
            'updated' => (int) ($counters['updated'] ?? 0),
            'skipped' => (int) ($counters['skipped'] ?? 0),
            'covers' => (int) ($counters['covers'] ?? 0),
            'authors_created' => (int) ($counters['authors_created'] ?? 0),
            'publishers_created' => (int) ($counters['publishers_created'] ?? 0),
            'failed' => $failed,
            'error_details' => array_map(
                static fn (array $e): string => sprintf('#%d (%s): %s', (int) ($e['line'] ?? 0), (string) ($e['title'] ?? ''), (string) ($e['message'] ?? '')),
                array_slice(array_values(array_filter($errors, static fn (array $e): bool => ($e['type'] ?? '') !== 'duplicate')), 0, 50)
            ),
            'complete' => $complete,
            'import_id' => $importId,
        ]);
    }

    /**
     * @param MarcRecord|MarcReadError $item
     * @return array<string, mixed>
     */
    private function stageLine(int $position, MarcRecord|MarcReadError $item, string $flavour): array
    {
        if ($item instanceof MarcReadError) {
            return ['p' => $position, 'e' => $item->message, 'x' => $item->excerpt];
        }
        $actual = $flavour === MarcFormat::AUTO ? MarcFormat::detectFlavour($item) : $flavour;
        try {
            $data = MarcFormat::mapperFor($actual)->map($item);
        } catch (\Throwable $e) {
            return ['p' => $position, 'e' => $e->getMessage(), 'x' => ''];
        }
        return ['p' => $position, 'f' => $actual, 'd' => $data];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function previewRow(array $line, MarcBookImporter $importer, CsvImportController $csv): array
    {
        if (isset($line['e'])) {
            return ['position' => $line['p'], 'error' => (string) $line['e']];
        }
        $data = (array) $line['d'];
        $existing = null;
        try {
            $existing = $importer->findExisting($csv->normalizeImportRow(\App\Support\Marc\MarcBookData::toImportRow($data)));
        } catch (\Throwable) {
            $existing = null;
        }
        return [
            'position' => $line['p'],
            'flavour' => $line['f'] ?? '',
            'titolo' => (string) ($data['titolo'] ?? ''),
            'sottotitolo' => (string) ($data['sottotitolo'] ?? ''),
            'autori' => implode('; ', (array) ($data['autori'] ?? [])),
            'editore' => implode('; ', (array) ($data['editori'] ?? [])),
            'anno' => (string) ($data['anno_pubblicazione'] ?? ''),
            'isbn' => (string) (($data['isbn13'] ?? '') !== '' ? $data['isbn13'] : ($data['isbn10'] ?? '')),
            'copertina' => (string) ($data['copertina_url'] ?? '') !== '',
            'existing_id' => $existing,
        ];
    }

    /** @param array<string, mixed> $state */
    private function persistHistory(\mysqli $db, array $state, int $failed): ?string
    {
        try {
            $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
            $logger = new \App\Support\ImportLogger($db, 'marc', (string) $state['original_filename'], $userId);
            $counters = (array) $state['counters'];
            $logger->setStats([
                'imported' => (int) ($counters['imported'] ?? 0),
                'updated' => (int) ($counters['updated'] ?? 0),
                'failed' => $failed,
                'authors_created' => (int) ($counters['authors_created'] ?? 0),
                'publishers_created' => (int) ($counters['publishers_created'] ?? 0),
                'scraped' => (int) ($counters['covers'] ?? 0),
            ]);
            foreach ((array) $state['errors'] as $error) {
                $logger->addError((int) ($error['line'] ?? 0), (string) ($error['title'] ?? ''), (string) ($error['message'] ?? ''), (string) ($error['type'] ?? 'validation'));
            }
            if (!$logger->complete((int) $state['total_rows'])) {
                $logger->fail('Failed to persist import history', (int) $state['total_rows']);
                return null;
            }
            return $logger->getImportId();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[MARC import] history not saved', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function discardStaging(): void
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && !empty($state['file_path'])) {
            $path = (string) $state['file_path'];
            $dir = realpath(__DIR__ . '/../../writable/uploads');
            $real = realpath($path);
            if ($dir !== false && $real !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR) && str_starts_with(basename($real), 'marc_import_')) {
                @unlink($real);
            }
        }
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
