<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffCopyCatalogingController;
use App\Plugins\MobileApi\Staff090\Controllers\StaffMarcController;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * MARC import / export and copy cataloguing (Pinakes 0.9.0, module `marc`).
 * Copy cataloguing also needs the z39-server plugin: without it its routes
 * answer 404 `copy_cataloging_unavailable`, and GET /staff/marc/options says
 * so in `copy_cataloging.available`.
 */
final class MarcArea implements StaffArea
{
    private const JOB_ID = '{id:[0-9a-f-]{36}}';

    public static function area(): string
    {
        return 'marc';
    }

    public static function feature(): string
    {
        return 'marc';
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $m = static fn (\mysqli $db): StaffMarcController => new StaffMarcController($db);
        $c = static fn (\mysqli $db): StaffCopyCatalogingController => new StaffCopyCatalogingController($db);

        return [
            ['GET', '/staff/marc/options', static fn (\mysqli $db, $q, $r, array $a) => $m($db)->options($q, $r)],
            ['GET', '/staff/marc/imports', static fn (\mysqli $db, $q, $r, array $a) => $m($db)->list($q, $r)],
            ['POST', '/staff/marc/imports', static fn (\mysqli $db, $q, $r, array $a) => $m($db)->create($q, $r)],
            ['GET', '/staff/marc/imports/' . self::JOB_ID, static fn (\mysqli $db, $q, $r, array $a) => $m($db)->detail($q, $r, (string) $a['id'])],
            ['POST', '/staff/marc/imports/' . self::JOB_ID . '/chunk', static fn (\mysqli $db, $q, $r, array $a) => $m($db)->chunk($q, $r, (string) $a['id'])],
            ['DELETE', '/staff/marc/imports/' . self::JOB_ID, static fn (\mysqli $db, $q, $r, array $a) => $m($db)->cancel($q, $r, (string) $a['id'])],
            ['GET', '/staff/marc/export', static fn (\mysqli $db, $q, $r, array $a) => $m($db)->export($q, $r), 'limit' => [15, 60, 'staff_marc_export']],
            ['GET', '/staff/copy-cataloging/servers', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->servers($q, $r)],
            ['POST', '/staff/copy-cataloging/search', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->search($q, $r)],
            ['POST', '/staff/copy-cataloging/import', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->import($q, $r)],
        ];
    }

    public static function openApi(array $doc): array
    {
        $doc['tags'][] = ['name' => 'staff-marc', 'description' => 'MARC bulk import (ISO 2709 / MARCXML; MARC 21, UNIMARC, danMARC2) and export (Pinakes 0.9.0, module `marc`; 404 feature_disabled while it is off). The import is a job driven chunk by chunk, with the contract of /staff/imports.'];
        $doc['tags'][] = ['name' => 'staff-copy-cataloging', 'description' => 'Copy cataloguing: search other catalogues (SRU servers, SBN, national catalogues, this installation) and import the chosen records. Module `marc` and the z39-server plugin (404 `copy_cataloging_unavailable` without it).'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_marc'] = ['type' => 'boolean', 'description' => 'The /staff/marc and /staff/copy-cataloging routes are mounted (module `marc` on). Copy cataloguing also needs the z39-server plugin: see GET /staff/marc/options → copy_cataloging.available. Mobile API 1.10.0.'];

        foreach (self::schemas() as $name => $schema) {
            $doc['components']['schemas'][$name] = $schema;
        }
        foreach (self::paths() as $path => $operations) {
            $doc['paths'][$path] = $operations;
        }

        return $doc;
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = O::nullable('string');
        $i = O::nullable('integer');
        $option = O::object(['value' => ['type' => 'string'], 'label' => ['type' => 'string']]);
        $mode = ['type' => 'string', 'enum' => ['skip', 'fill', 'overwrite']];

        return [
            'MarcOptions' => O::object([
                'flavours' => ['type' => 'array', 'items' => $option, 'description' => 'auto, marc21, unimarc, danmarc2'],
                'encodings' => ['type' => 'array', 'items' => $option, 'description' => 'auto, utf8, marc8 (ISO 2709 only)'],
                'duplicate_modes' => ['type' => 'array', 'items' => O::object(['value' => $mode, 'label' => ['type' => 'string'], 'description' => ['type' => 'string']])],
                'export_formats' => ['type' => 'array', 'items' => $option, 'description' => 'marc21, marcxml, unimarc'],
                'extensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'max_file_bytes' => ['type' => 'integer'], 'max_records' => ['type' => 'integer'], 'max_export_ids' => ['type' => 'integer'],
                'suppliers' => ['type' => ['array', 'null'], 'items' => O::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]), 'description' => 'null while the suppliers module is off (no supplier choice).'],
                'copy_cataloging' => O::object(['available' => ['type' => 'boolean'], 'reason' => ['type' => ['string', 'null'], 'enum' => ['plugin_inactive', null]]]),
            ]),
            'MarcPreviewRow' => O::object([
                'position' => ['type' => 'integer'], 'flavour' => ['type' => 'string'], 'title' => ['type' => 'string'], 'subtitle' => $s,
                'authors' => $s, 'publisher' => $s, 'year' => $s, 'isbn' => $s, 'has_cover' => ['type' => 'boolean'],
                'existing_id' => ['type' => ['integer', 'null'], 'description' => 'The book already in the catalogue (duplicate rule).'],
                'error' => ['type' => ['string', 'null'], 'description' => 'Set for a record that could not be read.'],
            ]),
            'MarcImportJob' => ['type' => 'object', 'description' => 'The first keys are those of ImportJob (CSV), same meaning; `scraped` counts downloaded covers and `errors` failed records.', 'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'], 'kind' => ['type' => 'string', 'enum' => ['marc']], 'file_name' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['ready', 'running', 'completed', 'failed', 'cancelled']],
                'total_rows' => ['type' => 'integer'], 'processed' => ['type' => 'integer'], 'chunk_size' => ['type' => 'integer', 'description' => '10, or 2 with download_covers.'],
                'enable_scraping' => ['type' => 'boolean', 'description' => 'Same as download_covers.'],
                'imported' => ['type' => 'integer'], 'updated' => ['type' => 'integer'], 'scraped' => ['type' => 'integer'],
                'authors_created' => ['type' => 'integer'], 'publishers_created' => ['type' => 'integer'],
                'errors' => ['type' => 'integer'], 'error_details' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'At most 50 lines "#position (title): message".'],
                'complete' => ['type' => 'boolean'], 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'], 'expires_at' => ['type' => 'string'],
                'skipped' => ['type' => 'integer'], 'covers' => ['type' => 'integer'], 'failed' => ['type' => 'integer'],
                'import_id' => ['type' => ['string', 'null'], 'description' => 'Import history id once complete (errors CSV: GET /staff/imports/history/{import_id}/errors.csv).'],
                'duplicates' => $mode, 'download_covers' => ['type' => 'boolean'], 'supplier_id' => $i,
                'flavour' => ['type' => 'string'], 'encoding' => ['type' => 'string'], 'container' => ['type' => 'string', 'enum' => ['iso2709', 'xml']],
                'unreadable' => ['type' => 'integer'], 'truncated' => ['type' => ['string', 'null'], 'description' => 'Message when the file has more than 20000 records (only the first are imported).'],
                'preview' => ['type' => 'array', 'items' => O::ref('MarcPreviewRow'), 'description' => 'The first 10 records as the upload read them.'],
            ]],
            'CopyCatalogingServer' => O::object([
                'key' => ['type' => 'string', 'description' => 'cfg:N (configured), sbn, preset:loc|bnf|dnb|k10plus, local'],
                'name' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['configured', 'preset', 'local']],
                'url' => ['type' => 'string'], 'syntax' => ['type' => 'string'],
            ]),
            'CopyCatalogingResult' => O::object([
                'id' => ['type' => 'string', 'description' => '16 hex characters; pass it to the import.'], 'server' => ['type' => 'string'], 'flavour' => ['type' => 'string'],
                'title' => ['type' => 'string'], 'subtitle' => $s, 'authors' => $s, 'publisher' => $s, 'year' => $s, 'isbn' => $s, 'cover_url' => $s,
                'existing_id' => $i,
                'detail' => ['type' => 'array', 'items' => O::object(['tag' => ['type' => 'string'], 'ind' => ['type' => 'string'], 'value' => ['type' => 'string']]), 'description' => 'The record as the server sent it (at most 300 fields).'],
            ]),
            'CopyCatalogingSearch' => O::object([
                'search_id' => ['type' => 'string', 'format' => 'uuid'], 'expires_at' => ['type' => 'string'],
                'results' => O::listOf('CopyCatalogingResult'),
                'servers' => ['type' => 'array', 'items' => O::object(['key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'total' => ['type' => 'integer'], 'count' => ['type' => 'integer'], 'error' => $s])],
            ]),
            'CopyCatalogingImport' => O::object([
                'imported' => ['type' => 'integer'], 'updated' => ['type' => 'integer'], 'skipped' => ['type' => 'integer'],
                'failed' => ['type' => 'integer'], 'covers' => ['type' => 'integer'], 'import_id' => $s,
                'items' => ['type' => 'array', 'items' => O::object([
                    'id' => ['type' => 'string'], 'action' => ['type' => 'string', 'enum' => ['created', 'updated', 'skipped', 'failed']],
                    'book_id' => $i, 'title' => ['type' => 'string'], 'warnings' => ['type' => 'array', 'items' => ['type' => 'string']], 'message' => $s,
                ])],
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function paths(): array
    {
        $t = 'staff-marc';
        $cc = 'staff-copy-cataloging';
        $jobId = ['parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Job id (uuid)', 'schema' => ['type' => 'string', 'format' => 'uuid']]]];
        $job = O::ok('The job as it is now.', O::ref('MarcImportJob'));
        $invalid = ['422' => O::err('`validation`: meta.fields = {field: message}.')];
        $unavailable = ['404' => O::err('`copy_cataloging_unavailable`: the z39-server plugin is not active.')];

        return [
            '/staff/marc/options' => ['get' => O::op($t, 'staffMarcOptions', 'Choices of the MARC import / export forms and copy cataloguing availability', [
                '200' => O::ok('Options.', O::ref('MarcOptions')),
            ])],
            '/staff/marc/imports' => ['get' => O::op($t, 'staffMarcImports', 'The caller\'s unexpired MARC import jobs, newest first (paged: limit, cursor): an import left running elsewhere can be resumed', [
                '200' => O::ok('Jobs.', ['type' => 'array', 'items' => O::ref('MarcImportJob')]),
            ]), 'post' => O::op($t, 'staffMarcImportUpload', 'Upload a MARC file: stages its records and answers the job with a preview', [
                '201' => O::ok('Job created (status ready, processed 0).', O::ref('MarcImportJob')),
                '422' => O::err('`validation`: meta.fields.file (no file, wrong extension, over 20 MB, no readable MARC record) or an unknown flavour / encoding / duplicates / supplier_id.'),
            ], ['requestBody' => O::multipart([
                'file' => ['type' => 'string', 'format' => 'binary', 'description' => '.mrc .marc .iso .dat (ISO 2709) or .xml .marcxml (MARCXML), at most 20 MB / 20000 records.'],
                'flavour' => ['type' => 'string', 'enum' => ['auto', 'marc21', 'unimarc', 'danmarc2'], 'default' => 'auto'],
                'encoding' => ['type' => 'string', 'enum' => ['auto', 'utf8', 'marc8'], 'default' => 'auto'],
                'duplicates' => ['type' => 'string', 'enum' => ['skip', 'fill', 'overwrite'], 'default' => 'skip', 'description' => 'Books already in the catalogue (same ISBN-13, ISBN-10 or EAN).'],
                'download_covers' => ['type' => 'boolean', 'default' => false, 'description' => 'Download the covers of field 856 (chunks of 2).'],
                'supplier_id' => ['type' => 'integer', 'description' => 'Suppliers module on: assigned to new books and, with overwrite, to the updated ones.'],
            ], ['file'])])],
            '/staff/marc/imports/{id}' => [
                'get' => O::op($t, 'staffMarcImport', 'A MARC import job (own jobs; an admin sees every job)', ['200' => $job] + O::notFound(), $jobId),
                'delete' => O::op($t, 'staffMarcImportCancel', 'Cancel a job and drop its staged records (idempotent)', [
                    '200' => O::ok('Cancelled.', O::object(['id' => ['type' => 'string'], 'cancelled' => ['type' => 'boolean']])),
                ] + O::notFound() + O::conflict('`job_busy`: a chunk is running, retry.'), $jobId),
            ],
            '/staff/marc/imports/{id}/chunk' => ['post' => O::op($t, 'staffMarcImportChunk', 'Import the next records of the job', [
                '200' => $job,
                '410' => O::err('`gone`: completed, cancelled or expired (meta.status).'),
            ] + O::notFound() + $invalid + ['409' => O::err('`out_of_order` (meta.processed: resume from there), `job_busy` (another chunk is running), `job_cancelled` (cancelled while the chunk ran; meta.processed).')], $jobId + [
                'requestBody' => O::inlineBody([
                    'start' => ['type' => 'integer', 'description' => 'Must equal the job\'s processed; default processed.'],
                    'size' => ['type' => 'integer', 'description' => '1..chunk_size, default chunk_size.'],
                ]),
                'description' => 'Same contract as POST /staff/imports/{id}/chunk. Repeat until complete=true.',
            ])],
            '/staff/marc/export' => ['get' => O::op($t, 'staffMarcExport', 'Export the catalogue or a selection in MARC (15/min)', [
                '200' => ['description' => 'The file, streamed as an attachment: application/marc (.mrc, ISO 2709) or application/marcxml+xml (.xml).', 'content' => [
                    'application/marc' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                    'application/marcxml+xml' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                ]],
            ] + $invalid, ['parameters' => [
                O::query('format', 'string', 'marc21 (default), marcxml or unimarc.'),
                O::query('ids', 'string', 'Comma-separated book ids (at most 1000); absent = the whole catalogue (desiderata excluded).'),
            ]])],
            '/staff/copy-cataloging/servers' => ['get' => O::op($cc, 'staffCopyCatalogingServers', 'The catalogues that can be searched', [
                '200' => O::ok('Servers and form choices.', O::object([
                    'servers' => O::listOf('CopyCatalogingServer'),
                    'indexes' => ['type' => 'array', 'items' => O::object(['value' => ['type' => 'string'], 'label' => ['type' => 'string']])],
                    'duplicate_modes' => ['type' => 'array', 'items' => O::object(['value' => ['type' => 'string'], 'label' => ['type' => 'string'], 'description' => ['type' => 'string']])],
                    'max_results_per_server' => ['type' => 'integer'], 'max_import_ids' => ['type' => 'integer'],
                ])),
            ] + $unavailable)],
            '/staff/copy-cataloging/search' => ['post' => O::op($cc, 'staffCopyCatalogingSearch', 'Search the chosen catalogues; the results are kept 24 h under search_id', [
                '201' => O::ok('Results (at most 100 in all); servers = per-catalogue report.', O::ref('CopyCatalogingSearch')),
            ] + $invalid + $unavailable, [
                'requestBody' => O::inlineBody([
                    'servers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keys from /staff/copy-cataloging/servers.'],
                    'index' => ['type' => 'string', 'enum' => ['title', 'author', 'isbn', 'issn', 'any'], 'default' => 'any'],
                    'term' => ['type' => 'string', 'maxLength' => 200],
                    'max' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10, 'description' => 'Per catalogue.'],
                ], ['servers', 'term']),
                'description' => 'Each search is stored for its operator; only the latest 5 are kept.',
            ])],
            '/staff/copy-cataloging/import' => ['post' => O::op($cc, 'staffCopyCatalogingImport', 'Import chosen results of a search', [
                '200' => O::ok('Outcome per record; meta.replayed=true when the Idempotency-Key answered a previous import.', O::ref('CopyCatalogingImport')),
                '410' => O::err('`gone`: the search expired, search again.'),
                '422' => O::err('`validation` (meta.fields) or `idempotency_conflict`: the key was used for another selection.'),
                '409' => O::err('`concurrent_retry`: another import of this search is running, retry.'),
                '404' => O::err('`not_found` (unknown search_id) or `copy_cataloging_unavailable`.'),
            ], [
                'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Stable across retries of the same import: the same key with the same ids / duplicates / covers answers the first outcome again without importing twice.', 'schema' => ['type' => 'string', 'maxLength' => 128]]],
                'requestBody' => O::inlineBody([
                    'search_id' => ['type' => 'string', 'format' => 'uuid'],
                    'ids' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string']],
                    'duplicates' => ['type' => 'string', 'enum' => ['skip', 'fill', 'overwrite'], 'default' => 'skip'],
                    'covers' => ['type' => 'boolean', 'default' => false],
                ], ['search_id', 'ids']),
            ])],
        ];
    }
}
