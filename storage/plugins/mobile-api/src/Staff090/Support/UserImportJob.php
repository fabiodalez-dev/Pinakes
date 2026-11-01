<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

/**
 * The API shape of a patron CSV import job (ImportJobStore kind `users`):
 * state = {core: the web's $_SESSION['user_import'], headers, preview,
 * fields, suggested, notice, and from the start on: started, mapping,
 * send_invites}; summary = the web's last chunk answer.
 */
final class UserImportJob
{
    /** Fields the web refuses to start without (UserImport::validateMapping). */
    public const REQUIRED_FIELDS = ['nome', 'email'];
    /** Error entries returned with a job (the web page shows as many). */
    public const MAX_ERRORS = 50;

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
        $core = is_array($state['core'] ?? null) ? $state['core'] : [];
        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $headers = array_values(array_map('strval', (array) ($state['headers'] ?? [])));
        $counter = static fn (string $key): int => (int) ($summary[$key] ?? $core[$key] ?? 0);

        $fields = [];
        foreach ((array) ($state['fields'] ?? []) as $key => $label) {
            $fields[] = ['key' => (string) $key, 'label' => (string) $label, 'required' => in_array((string) $key, self::REQUIRED_FIELDS, true)];
        }
        $started = !empty($state['started']);
        $source = $started && is_array($state['mapping'] ?? null) ? $state['mapping'] : (array) ($state['suggested'] ?? []);
        $mapping = [];
        foreach (array_keys($headers) as $i) {
            $mapping[] = is_string($source[$i] ?? null) ? $source[$i] : '';
        }
        $rawErrors = is_array($summary['errors'] ?? null) ? $summary['errors'] : (is_array($core['errors'] ?? null) ? $core['errors'] : []);
        $errors = [];
        foreach (array_slice($rawErrors, 0, self::MAX_ERRORS) as $err) {
            if (!is_array($err)) {
                continue;
            }
            $errors[] = [
                'line'    => (int) ($err['line'] ?? 0),
                'title'   => (string) ($err['title'] ?? ''),
                'message' => (string) ($err['message'] ?? ''),
                'type'    => (string) ($err['type'] ?? ''),
            ];
        }
        $importId = self::importId($job);
        $status = (string) $job['status'];

        return [
            'id'               => (string) $job['id'],
            'kind'             => (string) $job['kind'],
            'file_name'        => (string) $job['file_name'],
            'status'           => $status,
            'started'          => $started,
            'complete'         => $status === 'completed',
            'total_rows'       => (int) $job['total_rows'],
            'processed'        => (int) $job['processed'],
            'chunk_size'       => (int) $job['chunk_size'],
            'headers'          => $headers,
            'preview'          => array_values(array_map(static fn ($row): array => array_values(array_map('strval', (array) $row)), (array) ($state['preview'] ?? []))),
            'fields'           => $fields,
            'mapping'          => $mapping,
            'send_invites'     => $started ? (bool) ($state['send_invites'] ?? false) : null,
            'created'          => $counter('created'),
            'duplicates'       => $counter('duplicates'),
            'failed'           => $counter('failed'),
            'invited'          => $counter('invited'),
            'invite_failed'    => $counter('invite_failed'),
            'errors'           => $errors,
            'import_id'        => $importId !== '' ? $importId : null,
            'report_available' => $importId !== null && $importId !== '' && in_array($status, ['completed', 'cancelled', 'failed'], true),
            'notice'           => is_string($state['notice'] ?? null) ? $state['notice'] : null,
            'log_error'        => is_string($summary['log_error'] ?? null) ? $summary['log_error'] : null,
            'created_at'       => (string) $job['created_at'],
            'updated_at'       => (string) $job['updated_at'],
            'expires_at'       => (string) $job['expires_at'],
        ];
    }

    /**
     * The import history id of a finished / closed run: null = none yet,
     * '' = the run ended but its history entry could not be written.
     *
     * @param array<string, mixed> $job
     */
    public static function importId(array $job): ?string
    {
        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $id = $summary['import_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Remove the staged upload of a run that never started (the web page
     * does it when the preview is abandoned), only inside writable/uploads.
     *
     * @param array<string, mixed> $core
     */
    public static function unlinkUpload(array $core): void
    {
        $file = $core['file'] ?? null;
        if (!is_string($file) || !str_contains($file, '/writable/uploads/user_import_') || !is_file($file)) {
            return;
        }
        $real = realpath($file);
        $dir = realpath(dirname(__DIR__, 6) . '/writable/uploads');
        if ($real !== false && $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }
}
