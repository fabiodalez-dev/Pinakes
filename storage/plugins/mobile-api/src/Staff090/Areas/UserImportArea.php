<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffUserImportController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * Patron CSV import (Pinakes 0.9.0 /admin/users/import) for the app, behind
 * the `user_import` module. Permissions maps /api/v1/staff/user-imports to
 * users.manage, as /admin/users. Rate limits mirror the web routes (upload,
 * example and error report 30/min; start, chunk and cancel unthrottled).
 */
final class UserImportArea implements StaffArea
{
    public static function area(): string
    {
        return 'user_import';
    }

    public static function feature(): string
    {
        return 'user_import';
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $b = '/staff/user-imports';
        $id = '/{id:[0-9a-f-]{36}}';

        return [
            ['GET', $b . '/example.csv', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->example($q, $r), 'limit' => [30, 60, 'staff_user_import_example']],
            ['GET', $b, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->list($q, $r)],
            ['POST', $b, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->create($q, $r), 'limit' => [30, 60, 'staff_user_import_upload']],
            ['GET', $b . $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->detail($q, $r, (string) $a['id'])],
            ['POST', $b . $id . '/start', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->start($q, $r, (string) $a['id'])],
            ['POST', $b . $id . '/chunk', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->chunk($q, $r, (string) $a['id'])],
            ['DELETE', $b . $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->cancel($q, $r, (string) $a['id'])],
            ['GET', $b . $id . '/errors.csv', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->errorsCsv($q, $r, (string) $a['id']), 'limit' => [30, 60, 'staff_user_import_errors']],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-user-import';
        $doc['tags'][] = ['name' => $t, 'description' => 'Patron CSV import (Mobile API 1.10.0, Pinakes 0.9.0 /admin/users/import): upload → preview + suggested mapping → start → chunk loop → error report. A row whose email or card code already exists (or appeared earlier in the file) is skipped as a duplicate, never merged. Passwords are never imported: each reader sets one through the optional invitation. Every route answers 404 feature_disabled while the user_import module is off. Jobs are visible to their owner (an administrator sees every job) and expire 24 h after their last activity; a staged file unused for an hour is removed, after which the job fails with file_missing.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_user_import'] = [
            'type' => 'boolean', 'description' => 'The patron CSV import of Mobile API 1.10.0 is available (module user_import on).',
        ];

        $i = ['type' => 'integer'];
        $s = ['type' => 'string'];
        $doc['components']['schemas']['UserImportJob'] = O::object([
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'kind' => ['type' => 'string', 'enum' => ['users']],
            'file_name' => $s,
            'status' => ['type' => 'string', 'enum' => ['ready', 'running', 'completed', 'cancelled', 'failed'], 'description' => 'ready = waiting for start or the next chunk; failed = the staged file vanished (file_missing).'],
            'started' => ['type' => 'boolean', 'description' => 'The mapping was confirmed (POST …/start).'],
            'complete' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer', 'description' => 'Data rows (header excluded).'],
            'processed' => ['type' => 'integer', 'description' => 'Rows done: the `start` of the next chunk.'],
            'chunk_size' => ['type' => 'integer', 'description' => '10 rows per chunk.'],
            'headers' => ['type' => 'array', 'items' => $s, 'description' => 'The file\'s header cells.'],
            'preview' => ['type' => 'array', 'description' => 'First 5 data rows, cells cut to 120 characters.', 'items' => ['type' => 'array', 'items' => $s]],
            'fields' => ['type' => 'array', 'description' => 'Target fields in display order: nome, cognome, email, telefono, indirizzo, data_nascita, sesso, cod_fiscale, codice_tessera, data_scadenza_tessera, note_utente, locale, tipologia (when patron types exist), then custom:<id> registration fields.', 'items' => O::object([
                'key' => $s, 'label' => $s, 'required' => ['type' => 'boolean', 'description' => 'nome and email must be mapped.'],
            ])],
            'mapping' => ['type' => 'array', 'items' => $s, 'description' => 'One entry per header: the field key, or "" = ignored. Before start: the suggestion; after: the confirmed mapping.'],
            'send_invites' => ['type' => ['boolean', 'null'], 'description' => 'Null before start.'],
            'created' => $i, 'duplicates' => $i, 'failed' => $i, 'invited' => $i, 'invite_failed' => $i,
            'errors' => ['type' => 'array', 'description' => 'First 50 report entries (the CSV has them all, up to 500).', 'items' => O::object([
                'line' => ['type' => 'integer', 'description' => 'File line (header = 1).'], 'title' => $s, 'message' => $s,
                'type' => ['type' => 'string', 'enum' => ['validation', 'duplicate', 'database', 'warning', 'email', 'truncated']],
            ])],
            'import_id' => ['type' => ['string', 'null'], 'description' => 'Import history id once the run is over (completed, cancelled after start, or failed).'],
            'report_available' => ['type' => 'boolean', 'description' => 'GET …/errors.csv will answer.'],
            'notice' => ['type' => ['string', 'null'], 'description' => 'E.g. the file was converted from Windows-1252: check accented letters in the preview.'],
            'log_error' => ['type' => ['string', 'null'], 'description' => 'Completed, but the import history could not be written.'],
            'created_at' => $s, 'updated_at' => $s, 'expires_at' => $s,
        ]);
        $jobId = ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Job id (UUID).', 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $job = static fn (string $what): array => O::ok($what, O::ref('UserImportJob'));
        $gone = ['410' => O::err('`gone`: the job is completed, cancelled, failed or expired (meta.status).')];
        $notFound = ['404' => O::err('`not_found`: no such job, or another operator\'s job (only administrators see every job). `feature_disabled`.')];

        $doc['paths']['/staff/user-imports'] = ['get' => O::op($t, 'staffUserImports', 'The caller\'s unexpired patron import jobs, newest first (paged: limit, cursor): a job started elsewhere can be resumed', [
            '200' => O::ok('Jobs.', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserImportJob']]),
        ]), 'post' => O::op($t, 'staffUserImportUpload', 'Upload a patron CSV (user_import, 30/min)', [
            '201' => $job('Job created (status ready, not started): headers, preview, fields and the suggested mapping.'),
            '422' => O::err('`validation` (meta.fields.file: missing, not .csv/.tsv/.txt, over 5 MB, empty or unreadable, no ";", "," or TAB separator; meta.fields.delimiter).'),
        ], ['requestBody' => O::multipart([
            'file' => ['type' => 'string', 'format' => 'binary', 'description' => '.csv, .tsv or .txt, at most 5 MB; UTF-8 or Windows-1252 (converted).'],
            'delimiter' => ['type' => 'string', 'enum' => ['', 'auto', ';', ',', 'tab'], 'description' => 'Default: detected.'],
        ], ['file'])])];
        $doc['paths']['/staff/user-imports/example.csv'] = ['get' => O::op($t, 'staffUserImportExample', 'Example patron CSV (user_import, 30/min)', [
            '200' => O::file('text/csv', 'Every recognised column, two sample rows. Content-Disposition: attachment; filename="esempio_import_utenti.csv".'),
        ])];
        $doc['paths']['/staff/user-imports/{id}'] = [
            'get' => O::op($t, 'staffUserImport', 'A patron import job', ['200' => $job('The job.')] + $notFound, ['parameters' => [$jobId]]),
            'delete' => O::op($t, 'staffUserImportCancel', 'Cancel a patron import job (idempotent)', [
                '200' => O::ok('Cancelled. The accounts already created stay; a started run is written to the import history (import_id). A completed job stays completed (status).', O::object([
                    'id' => $s, 'cancelled' => ['type' => 'boolean'], 'status' => ['type' => 'string', 'enum' => ['cancelled', 'completed']],
                    'import_id' => O::nullable('string'), 'history_warning' => O::nullable('string'),
                ])),
                '409' => O::err('`job_busy`: a chunk is running (retry after it).'),
            ] + $notFound, ['parameters' => [$jobId]]),
        ];
        $doc['paths']['/staff/user-imports/{id}/start'] = ['post' => O::op($t, 'staffUserImportStart', 'Confirm the mapping and start (user_import)', [
            '200' => $job('Started (started=true, processed=0): drive it with …/chunk.'),
            '409' => O::err('`already_started` (meta.processed: resume from there); `job_busy`; `file_missing` (the staged file vanished; status failed).'),
            '422' => O::err('`validation` (meta.fields.mapping: a field on two columns, nome or email not mapped, unknown field; meta.fields.send_invites).'),
        ] + $gone + $notFound, ['parameters' => [$jobId], 'requestBody' => O::inlineBody([
            'mapping' => ['description' => 'Column index → field key ("" = ignore): an array aligned with headers, or an object {"0": "nome", …}. Omitted = the suggested mapping.', 'oneOf' => [['type' => 'array', 'items' => $s], ['type' => 'object', 'additionalProperties' => $s]]],
            'send_invites' => ['type' => 'boolean', 'default' => false, 'description' => 'E-mail each created reader the password-setup invitation.'],
        ])])];
        $doc['paths']['/staff/user-imports/{id}/chunk'] = ['post' => O::op($t, 'staffUserImportChunk', 'Import the next 10 rows (user_import)', [
            '200' => $job('Chunk done; complete=true (status completed, import_id) after the last one.'),
            '409' => O::err('`out_of_order` (meta.processed: send that as start); `job_busy`; `not_started`; `job_cancelled` (cancelled while the chunk ran); `file_missing` (status failed, meta.import_id: the rows done are in the history).'),
            '422' => O::err('`validation` (meta.fields.start: not a non-negative integer).'),
        ] + $gone + $notFound, ['parameters' => [$jobId], 'requestBody' => O::inlineBody([
            'start' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Optional guard: must equal processed. A retried chunk whose answer was lost gets 409 out_of_order and resumes from meta.processed — rows are never imported twice.'],
        ])])];
        $doc['paths']['/staff/user-imports/{id}/errors.csv'] = ['get' => O::op($t, 'staffUserImportErrors', 'Error report of a finished patron import (user_import, 30/min)', [
            '200' => O::file('text/csv', 'The import history report (line, name, type, message). Content-Disposition: attachment; filename from the server.'),
            '404' => O::err('`not_found`: no such job, or no report (the run created no history entry).'),
            '409' => O::err('`import_not_finished` (meta.processed).'),
        ], ['parameters' => [$jobId]])];

        return $doc;
    }
}
