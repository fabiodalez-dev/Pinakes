<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragment for the Mobile API 1.7.0 staff content tools (tag
 * `staff-content`): copies, series, merges, labels, imports, covers and
 * enrichment. Only the paths of areas mounted on this build (StaffAreas) are
 * documented; every documented path has a row in
 * tests/mobile-api-idempotency.spec.js.
 */
final class StaffContentOpenApi
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extend(array $doc): array
    {
        $doc['tags'][] = ['name' => 'staff-content', 'description' => 'Catalogue tools for admin and staff accounts: copies, series, merges, labels, imports, covers.'];
        foreach (self::schemas() as $name => $schema) {
            $doc['components']['schemas'][$name] = $schema;
        }
        foreach (self::paths() as $area => $paths) {
            if (!StaffAreas::implemented($area)) {
                continue;
            }
            foreach ($paths as $path => $operations) {
                $doc['paths'][$path] = ($doc['paths'][$path] ?? []) + $operations;
            }
        }
        // Phase 1 route, extended in 1.7.0.
        if (isset($doc['components']['schemas']['AddCopiesRequest']['properties'])) {
            $doc['components']['schemas']['AddCopiesRequest']['properties']['inventory_number'] = [
                'type' => 'string', 'maxLength' => 100,
                'description' => 'Since 1.7.0: explicit inventory code (the barcode) for ONE copy; quantity must be 1. 409 `duplicate` with meta.existing = {id, inventory_number, book_id} when the code is taken.',
            ];
        }

        return $doc;
    }

    /** @return array<string, array<string, mixed>> area => paths */
    private static function paths(): array
    {
        $t = 'staff-content';
        $copyId = ['parameters' => [O::path('id', 'Copy id')]];
        $seriesId = ['parameters' => [O::path('id', 'Series id (collane.id)')]];
        $seriesBook = ['parameters' => [O::path('id', 'Series id'), O::path('bookId', 'Book id')]];
        $detail = O::ok('Series detail.', O::ref('StaffSeriesDetail'));
        $mergeBody = O::inlineBody([
            'ids' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'integer']],
            'primary_id' => ['type' => 'integer', 'description' => 'Survivor (default: lowest id).'],
            'new_name' => ['type' => 'string', 'description' => 'Optional new name of the survivor.'],
        ], ['ids']);
        $mergeOk = O::ok('Merged.', O::object(['primary_id' => ['type' => 'integer']]));

        return [
            'copies' => [
                '/staff/copies/{id}' => [
                    'get' => O::op($t, 'staffCopy', 'One physical copy', ['200' => O::ok('Copy.', O::ref('StaffCopy'))] + O::notFound(), $copyId),
                    'put' => O::op($t, 'staffUpdateCopy', 'Change the status, notes and/or consultation flag of a copy', [
                        '200' => O::ok('Updated copy.', O::ref('StaffCopy')),
                    ] + O::notFound() + O::invalid() + O::conflict('`copy_on_loan` (out on loan: use the return), `copy_held` (reserved: use circulation), `conflict` (refused by the server, message = its text; also a copy held by a loan, a pickup or a request cannot become consultation only).'), $copyId + [
                        'requestBody' => O::inlineBody([
                            'status' => ['type' => 'string', 'enum' => \App\Plugins\MobileApi\Controllers\StaffBooksController::CREATABLE_COPY_STATES],
                            'notes' => ['type' => ['string', 'null'], 'maxLength' => 500],
                            'consultation_only' => ['type' => 'boolean', 'description' => 'Pinakes 0.9.0: keep the copy for in-house consultation (true) or make it lendable again (false), as the book page does. 404 feature_disabled with features.consultation_only off.'],
                        ]),
                        'description' => 'Setting `disponibile` on a copy that is out on loan records the return of that loan (same as the web copy page). `consultation_only` is applied first, with the book page\'s refusals; making a copy lendable again promotes the waitlist.',
                    ]),
                    'delete' => O::op($t, 'staffDeleteCopy', 'Delete a copy that is out of circulation and has no loan history', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']])),
                    ] + O::notFound() + O::conflict('`copy_held`, `copy_in_circulation`, `copy_has_history` (meta.reason).'), $copyId),
                ],
            ],
            'series' => [
                '/staff/series' => [
                    'get' => O::op($t, 'staffSeries', 'Series tree (ETag)', [
                        '200' => O::ok('Roots with nested children, or a flat list with flat=1.', O::listOf('StaffSeries')),
                        '304' => ['description' => 'Not modified (If-None-Match).'],
                        '404' => O::err('`feature_disabled`: this installation has no collane table.'),
                    ], ['parameters' => [O::query('q', 'string', 'Name contains.'), O::query('flat', 'integer', '1 = flat list.')]]),
                    'post' => O::op($t, 'staffCreateSeries', 'Create a series (parent auto-created by name, as on the web)', [
                        '201' => O::ok('Created.', O::ref('StaffSeriesDetail')),
                    ] + O::invalid() + O::conflict('`duplicate`: meta.existing = {id, name}.'), ['requestBody' => O::body('SeriesWrite')]),
                ],
                '/staff/series/suggest' => ['get' => O::op($t, 'staffSeriesSuggest', 'Autocomplete names for the series fields', [
                    '200' => O::ok('Up to 10 names.', ['type' => 'array', 'items' => ['type' => 'string']]),
                ] + O::invalid(), ['parameters' => [O::query('q', 'string', 'At least 2 characters.', true), O::query('field', 'string', 'name|parent|group|cycle (default name).')]])],
                '/staff/series/{id}' => [
                    'get' => O::op($t, 'staffSeriesDetail', 'Series with its books and related series', ['200' => $detail] + O::notFound(), $seriesId),
                    'put' => O::op($t, 'staffUpdateSeries', 'Update metadata (merged over the row; name must stay, use rename)', [
                        '200' => $detail,
                    ] + O::notFound() + O::invalid(), $seriesId + ['requestBody' => O::body('SeriesWrite')]),
                    'delete' => O::op($t, 'staffDeleteSeries', 'Delete a series: its books leave it, children move one level up', [
                        '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean'], 'books_updated' => ['type' => 'integer'], 'children_reattached' => ['type' => 'integer']])),
                    ] + O::notFound(), $seriesId),
                ],
                '/staff/series/{id}/rename' => ['post' => O::op($t, 'staffRenameSeries', 'Rename; merge into an existing name only with merge_if_exists', [
                    '200' => O::ok('Renamed (meta.merged=true when merged into the existing series, whose detail is returned).', O::ref('StaffSeriesDetail')),
                ] + O::notFound() + O::invalid() + O::conflict('`duplicate`: meta.existing = {id, name}.'), $seriesId + [
                    'requestBody' => O::inlineBody(['name' => ['type' => 'string', 'maxLength' => 100], 'merge_if_exists' => ['type' => 'boolean', 'default' => false]], ['name']),
                ])],
                '/staff/series/{id}/merge' => ['post' => O::op($t, 'staffMergeSeries', 'Move every book of this series into the target and delete it', [
                    '200' => O::ok('Merged.', O::object(['target' => O::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]), 'books_moved' => ['type' => 'integer']])),
                ] + O::notFound() + O::invalid(), $seriesId + ['requestBody' => O::inlineBody(['target_id' => ['type' => 'integer']], ['target_id'])])],
                '/staff/series/{id}/books' => ['post' => O::op($t, 'staffSeriesAssign', 'Make this series the primary series of several books', [
                    '200' => O::ok('Assigned.', O::object(['assigned' => ['type' => 'integer']])),
                ] + O::notFound() + O::invalid(), $seriesId + ['requestBody' => O::inlineBody(['book_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => ['type' => 'integer']]], ['book_ids'])])],
                '/staff/series/{id}/books/{bookId}' => [
                    'put' => O::op($t, 'staffSeriesBookNumber', 'Set the number of a book in this series', ['200' => $detail] + O::notFound() + O::invalid(), $seriesBook + [
                        'requestBody' => O::inlineBody(['series_number' => ['type' => ['string', 'null'], 'maxLength' => 50]]),
                    ]),
                    'delete' => O::op($t, 'staffSeriesRemoveBook', 'Remove a book from this series (idempotent)', ['200' => $detail] + O::notFound(), $seriesBook),
                ],
                '/staff/series/{id}/parent-work' => ['post' => O::op($t, 'staffSeriesParentWork', 'Create the multi-volume parent work linking every book of the series', [
                    '201' => O::ok('Created.', O::object(['book_id' => ['type' => 'integer'], 'title' => ['type' => 'string']])),
                ] + O::notFound() + O::invalid() + O::conflict('`conflict`, meta.reason = empty_series.'), $seriesId + [
                    'requestBody' => O::inlineBody(['title' => ['type' => 'string', 'maxLength' => 255]], ['title']),
                ])],
            ],
            'merges' => [
                '/staff/authors/duplicates' => ['get' => O::op($t, 'staffAuthorDuplicates', 'Groups of authors whose normalised names coincide', [
                    '200' => O::ok('Groups.', ['type' => 'array', 'items' => ['type' => 'array', 'items' => O::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'books_count' => ['type' => 'integer']])]]),
                ])],
                '/staff/authors/merge' => ['post' => O::op($t, 'staffMergeAuthors', 'Merge authors into one', ['200' => $mergeOk] + O::invalid() + O::conflict('`merge_error`: refused by the server (message = its text, e.g. different GND ids or an id that no longer exists).'), ['requestBody' => $mergeBody])],
                '/staff/publishers/merge' => ['post' => O::op($t, 'staffMergePublishers', 'Merge publishers into one', ['200' => $mergeOk] + O::invalid() + O::conflict('`merge_error`.'), ['requestBody' => $mergeBody])],
                '/staff/genres/{id}/merge' => ['post' => O::op($t, 'staffMergeGenre', 'Merge this genre into the target (books, children and shelves move)', [
                    '200' => O::ok('Merged.', O::object(['target_id' => ['type' => 'integer'], 'books_updated' => ['type' => 'integer'], 'children_moved' => ['type' => 'integer']])),
                ] + O::notFound() + O::invalid() + O::conflict('`conflict`, meta.reason = descendant.'), [
                    'parameters' => [O::path('id', 'Source genre id')],
                    'requestBody' => O::inlineBody(['target_id' => ['type' => 'integer']], ['target_id']),
                ])],
            ],
            'labels' => [
                '/staff/books/{id}/labels/book.pdf' => ['get' => O::op($t, 'staffBookLabel', 'Book label PDF (30/min)', [
                    '200' => O::file('application/pdf', 'Inline PDF.'),
                ] + O::notFound(), ['parameters' => [O::path('id', 'Book id')]])],
                '/staff/books/{id}/labels/copies.pdf' => ['get' => O::op($t, 'staffCopyLabels', 'One label per copy with its Code128 inventory barcode (30/min)', [
                    '200' => O::file('application/pdf', 'Inline PDF.'),
                ] + O::notFound() + O::conflict('`no_copies`: the title has no copies.'), ['parameters' => [O::path('id', 'Book id'), O::query('copy_id', 'integer', 'Only this copy.')]])],
                '/staff/settings/labels' => [
                    'get' => O::op($t, 'staffLabelSettings', 'Label format and content toggles (ETag)', [
                        '200' => O::ok('Settings.', O::ref('LabelSettings')),
                        '304' => ['description' => 'Not modified (If-None-Match).'],
                    ]),
                    'put' => O::op($t, 'staffUpdateLabelSettings', 'Update label settings (absent keys keep their value)', [
                        '200' => O::ok('Settings.', O::ref('LabelSettings')),
                    ] + O::invalid(), ['requestBody' => O::body('LabelSettings')]),
                ],
            ],
            'imports' => [
                '/staff/imports' => [
                    'get' => O::op($t, 'staffImports', 'My unexpired import jobs and the import history', [
                        '200' => O::ok('Jobs (paged by cursor) and the last 50 history rows (own imports; every import for an admin).', O::object([
                            'jobs' => O::listOf('ImportJob'),
                            'history' => ['type' => 'array', 'items' => O::object([
                                'import_id' => $str = ['type' => 'string'], 'kind' => $str, 'file_name' => $str,
                                'user' => ['oneOf' => [['type' => 'null'], O::object(['id' => ['type' => 'integer'], 'name' => $str])]],
                                'total_rows' => ['type' => 'integer'], 'imported' => ['type' => 'integer'], 'updated' => ['type' => 'integer'],
                                'failed' => ['type' => 'integer'], 'status' => $str, 'started_at' => O::nullable('string'), 'completed_at' => O::nullable('string'),
                            ])],
                        ])),
                    ], ['parameters' => O::paging()]),
                    'post' => O::op($t, 'staffCreateImport', 'Upload a CSV (or LibraryThing TSV) and create an import job (20/min)', [
                        '201' => O::ok('Job ready: call /chunk with start = processed until complete.', O::ref('ImportJob')),
                        '404' => O::err('`feature_disabled`: kind=librarything not available on this server.'),
                    ] + O::invalid(), ['requestBody' => O::multipart([
                        'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'csv: .csv; librarything: .tsv/.csv/.txt. Max 20 MB.'],
                        'kind' => ['type' => 'string', 'enum' => ['csv', 'librarything'], 'default' => 'csv'],
                        'enable_scraping' => ['type' => 'string', 'enum' => ['0', '1'], 'description' => 'Enrich every row from the scraping sources (chunks become 1 row each, up to minutes per call).'],
                        'update_fields' => ['type' => 'string', 'description' => 'csv only: comma list of the field families an import may update on books that already exist (default: all).'],
                    ], ['file'])]),
                ],
                '/staff/imports/example.csv' => ['get' => O::op($t, 'staffImportExample', 'Example CSV with every supported column', [
                    '200' => O::file('text/csv', 'Example file.'),
                ])],
                '/staff/imports/history/{importId}/errors.csv' => ['get' => O::op($t, 'staffImportErrors', 'Error report of a finished import (owner or admin)', [
                    '200' => O::file('text/csv', 'Errors.'),
                ] + O::notFound(), ['parameters' => [['name' => 'importId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]]])],
                '/staff/imports/{id}' => [
                    'get' => O::op($t, 'staffImport', 'One import job', ['200' => O::ok('Job.', O::ref('ImportJob'))] + O::notFound(), ['parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]]]),
                    'delete' => O::op($t, 'staffCancelImport', 'Cancel a job (idempotent); rows already imported stay', [
                        '200' => O::ok('Cancelled.', O::object(['id' => ['type' => 'string'], 'cancelled' => ['type' => 'boolean']])),
                    ] + O::notFound() + O::conflict('`job_busy`: a chunk of this job is running (waited a few seconds); retry once it answers.'), ['parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]]]),
                ],
                '/staff/imports/{id}/chunk' => ['post' => O::op($t, 'staffImportChunk', 'Process the next chunk of rows', [
                    '200' => O::ok('Job after the chunk (counters cumulative).', O::ref('ImportJob')),
                    '410' => O::err('`gone`: the job is completed, cancelled, failed or expired.'),
                ] + O::notFound() + O::invalid() + O::conflict('`out_of_order` (meta.processed: resume from there), `job_busy` (another chunk of this job is running) or `job_cancelled` (the job was cancelled while this chunk ran: the rows it wrote stay, the job stays cancelled, meta.processed).'), [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
                    'requestBody' => O::inlineBody(['start' => ['type' => 'integer', 'description' => 'Must equal the job\'s processed (default).'], 'size' => ['type' => 'integer', 'description' => '≤ chunk_size (default).']]),
                    'description' => 'Never run two chunk loops for one job. With enable_scraping a chunk can take minutes: use a long read timeout.',
                ])],
            ],
            'librarything' => [
                '/staff/exports/librarything.tsv' => ['get' => O::op($t, 'staffLibraryThingExport', 'The catalogue as a LibraryThing TSV (15/min)', [
                    '200' => O::file('text/tab-separated-values', 'TSV export.'),
                ])],
            ],
            'covers' => [
                '/staff/covers/missing' => ['get' => O::op($t, 'staffMissingCovers', 'Titles with an ISBN and no local cover', [
                    '200' => O::ok('Page; meta.total_count = all of them.', ['type' => 'array', 'items' => O::object(['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'isbn13' => O::nullable('string')])]),
                ], ['parameters' => O::paging()])],
                '/staff/books/{id}/cover/fetch' => ['post' => O::op($t, 'staffFetchCover', 'Download the cover of one title from the scraping sources (30/min)', [
                    '200' => O::ok('Outcome.', O::object([
                        'fetched' => ['type' => 'boolean'], 'cover_url' => O::nullable('string'),
                        'reason' => ['type' => ['string', 'null'], 'enum' => ['already_has_cover', 'no_isbn', 'invalid_isbn', 'no_cover_found', null]],
                    ])),
                    '502' => O::err('`cover_download_failed`: a source answered but the image could not be fetched.'),
                ] + O::notFound(), ['parameters' => [O::path('id', 'Book id')]])],
                '/staff/covers/sync' => ['post' => O::op($t, 'staffSyncCovers', 'Fetch every missing cover (synchronous, up to 10 minutes; 1 per 2 minutes)', [
                    '200' => O::ok('Totals.', O::object(['synced' => ['type' => 'integer'], 'skipped' => ['type' => 'integer'], 'errors' => ['type' => 'integer'], 'total' => ['type' => 'integer']])),
                ])],
                '/staff/enrich' => ['get' => O::op($t, 'staffEnrich', 'Bulk enrichment statistics and the cron switch', [
                    '200' => O::ok('Overview.', O::object([
                        'stats' => O::object(['total_with_isbn' => ['type' => 'integer'], 'missing_cover' => ['type' => 'integer'], 'missing_description' => ['type' => 'integer'], 'pending' => ['type' => 'integer']]),
                        'cron_enabled' => ['type' => 'boolean'],
                    ])),
                ])],
                '/staff/enrich/batch' => ['post' => O::op($t, 'staffEnrichBatch', 'Enrich the next 20 titles now (synchronous; 1 per 2 minutes)', [
                    '200' => O::ok('Batch result.', O::object([
                        'processed' => ['type' => 'integer'], 'enriched' => ['type' => 'integer'], 'not_found' => ['type' => 'integer'],
                        'skipped' => ['type' => 'integer'], 'errors' => ['type' => 'integer'],
                        'details' => ['type' => 'array', 'items' => O::object(['book_id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'fields_updated' => ['type' => 'array', 'items' => ['type' => 'string']]])],
                    ])),
                ])],
                '/staff/enrich/cron' => ['put' => O::op($t, 'staffEnrichCron', 'Switch the automatic enrichment cron on or off', [
                    '200' => O::ok('New state.', O::object(['enabled' => ['type' => 'boolean']])),
                ] + O::invalid(), ['requestBody' => O::inlineBody(['enabled' => ['type' => 'boolean']], ['enabled'])])],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = ['type' => 'string'];
        $ns = O::nullable('string');
        $i = ['type' => 'integer'];
        $ni = O::nullable('integer');
        $b = ['type' => 'boolean'];
        $types = array_keys(\App\Support\SeriesLabels::types());

        return [
            'StaffSeries' => O::object([
                'id' => $i, 'name' => $s, 'type' => ['type' => ['string', 'null'], 'enum' => array_merge($types, [null])],
                'parent_id' => $ni, 'parent_name' => $ns, 'group' => $ns, 'cycle' => $ns, 'cycle_order' => $ni,
                'complete' => $b, 'book_count' => $i, 'min_number' => $ni, 'max_number' => $ni,
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StaffSeries']],
            ]),
            'StaffSeriesDetail' => ['allOf' => [O::ref('StaffSeries'), O::object([
                'description' => $ns,
                'books' => ['type' => 'array', 'items' => O::object([
                    'id' => $i, 'title' => $s, 'cover_url' => $ns, 'series_number' => $ns, 'is_primary' => $b, 'year' => $ni,
                ])],
                'related' => ['type' => 'array', 'items' => O::object([
                    'id' => $i, 'name' => $s, 'type' => $ns, 'relation' => ['type' => 'string', 'enum' => ['parent', 'child', 'sibling']],
                ])],
            ])]],
            'SeriesWrite' => O::object([
                'name' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Required on POST; on PUT it must equal the current name.'],
                'type' => ['type' => ['string', 'null'], 'enum' => array_merge($types, [null])],
                'parent_id' => $ni, 'parent_name' => ['type' => ['string', 'null'], 'description' => 'Created as a universe when missing (web behaviour).'],
                'group' => $ns, 'cycle' => $ns, 'cycle_order' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 65535],
                'description' => $ns, 'complete' => $b,
            ]),
            'ImportJob' => O::object([
                'id' => ['type' => 'string', 'format' => 'uuid'], 'kind' => ['type' => 'string', 'enum' => ['csv', 'librarything']],
                'file_name' => $s, 'status' => ['type' => 'string', 'enum' => ['ready', 'running', 'completed', 'failed', 'cancelled']],
                'total_rows' => $i, 'processed' => $i, 'chunk_size' => $i, 'enable_scraping' => $b,
                'imported' => $i, 'updated' => $i, 'scraped' => $i, 'authors_created' => $i, 'publishers_created' => $i,
                'errors' => $i, 'error_details' => ['type' => 'array', 'items' => $s, 'description' => 'At most 50, plain text.'],
                'complete' => $b, 'created_at' => $s, 'updated_at' => $s, 'expires_at' => ['type' => 'string', 'description' => '24 h after the last activity.'],
            ]),
            'LabelSettings' => O::object([
                'width' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 100, 'description' => 'mm'],
                'height' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 100, 'description' => 'mm'],
                'padding' => ['type' => 'number', 'minimum' => 0, 'maximum' => 30, 'description' => 'mm on every side'],
                'show_app_name' => $b, 'show_title' => $b, 'show_subtitle' => $b, 'show_author_publisher' => $b, 'show_dewey' => $b,
            ]),
        ];
    }
}
