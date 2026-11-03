<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

/**
 * The API resource of a MARC import job (ImportJobStore kind `marc`).
 *
 * The keys of the CSV ImportJob (StaffImportsController::map()) come first
 * with the same meaning, so the app drives both imports with one loop; the
 * MARC-only counters and the upload's preview follow. Pure: unit-testable.
 */
final class MarcJobMapper
{
    /** Key of the job state holding what the upload answered besides the core state. */
    public const UPLOAD_KEY = 'mobile_upload';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $job hydrated ImportJobStore row
     * @return array<string, mixed>
     */
    public static function map(array $job): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $counters = is_array($state['counters'] ?? null) ? $state['counters'] : [];
        // The last chunk's answer covers the whole run; before the first
        // chunk only the (empty) core counters exist.
        $count = static fn (string $key): int => (int) ($summary[$key] ?? $counters[$key] ?? 0);
        $upload = is_array($state[self::UPLOAD_KEY] ?? null) ? $state[self::UPLOAD_KEY] : [];
        $failed = array_key_exists('failed', $summary) ? (int) $summary['failed'] : \App\Controllers\MarcImportController::failedCount($state);
        if (is_array($summary['error_details'] ?? null)) {
            $details = array_values(array_map('strval', $summary['error_details']));
        } else {
            $details = [];
            $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
            foreach ($errors as $error) {
                if (!is_array($error) || ($error['type'] ?? '') === 'duplicate') {
                    continue;
                }
                $details[] = sprintf('#%d (%s): %s', (int) ($error['line'] ?? 0), (string) ($error['title'] ?? ''), (string) ($error['message'] ?? ''));
                if (count($details) >= 50) {
                    break;
                }
            }
        }
        $supplier = (int) ($state['fornitore_id'] ?? 0);

        return [
            'id'                 => (string) $job['id'],
            'kind'               => (string) $job['kind'],
            'file_name'          => (string) $job['file_name'],
            'status'             => (string) $job['status'],
            'total_rows'         => (int) $job['total_rows'],
            'processed'          => (int) $job['processed'],
            'chunk_size'         => (int) $job['chunk_size'],
            'enable_scraping'    => (int) $job['enable_scraping'] === 1,
            'imported'           => $count('imported'),
            'updated'            => $count('updated'),
            'scraped'            => $count('covers'),
            'authors_created'    => $count('authors_created'),
            'publishers_created' => $count('publishers_created'),
            'errors'             => $failed,
            'error_details'      => $details,
            'complete'           => $job['status'] === 'completed',
            'created_at'         => (string) $job['created_at'],
            'updated_at'         => (string) $job['updated_at'],
            'expires_at'         => (string) $job['expires_at'],
            // MARC only
            'skipped'            => $count('skipped'),
            'covers'             => $count('covers'),
            'failed'             => $failed,
            'import_id'          => is_string($summary['import_id'] ?? null) ? $summary['import_id'] : null,
            'duplicates'         => (string) ($state['mode'] ?? 'skip'),
            'download_covers'    => (bool) ($state['covers'] ?? false),
            'supplier_id'        => $supplier > 0 ? $supplier : null,
            'flavour'            => (string) ($upload['flavour'] ?? 'auto'),
            'encoding'           => (string) ($upload['encoding'] ?? 'auto'),
            'container'          => (string) ($upload['container'] ?? ''),
            'unreadable'         => (int) ($upload['unreadable'] ?? 0),
            'truncated'          => is_string($upload['truncated'] ?? null) ? $upload['truncated'] : null,
            'preview'            => array_map([self::class, 'previewRow'], is_array($upload['preview'] ?? null) ? array_values($upload['preview']) : []),
        ];
    }

    /**
     * One preview row of MarcImportController::upload() in English keys.
     *
     * @return array<string, mixed>
     */
    public static function previewRow(mixed $row): array
    {
        $row = is_array($row) ? $row : [];
        if (isset($row['error'])) {
            // An unreadable record: same keys, `error` set.
            return [
                'position' => (int) ($row['position'] ?? 0), 'flavour' => '', 'title' => '', 'subtitle' => null,
                'authors' => null, 'publisher' => null, 'year' => null, 'isbn' => null, 'has_cover' => false,
                'existing_id' => null, 'error' => (string) $row['error'],
            ];
        }
        $existing = (int) ($row['existing_id'] ?? 0);

        return [
            'position'    => (int) ($row['position'] ?? 0),
            'flavour'     => (string) ($row['flavour'] ?? ''),
            'title'       => (string) ($row['titolo'] ?? ''),
            'subtitle'    => self::nullable($row['sottotitolo'] ?? null),
            'authors'     => self::nullable($row['autori'] ?? null),
            'publisher'   => self::nullable($row['editore'] ?? null),
            'year'        => self::nullable($row['anno'] ?? null),
            'isbn'        => self::nullable($row['isbn'] ?? null),
            'has_cover'   => (bool) ($row['copertina'] ?? false),
            'existing_id' => $existing > 0 ? $existing : null,
            'error'       => null,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text !== '' ? $text : null;
    }
}
