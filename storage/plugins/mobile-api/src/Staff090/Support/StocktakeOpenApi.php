<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragment of the stocktake area (StocktakeArea).
 */
final class StocktakeOpenApi
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
        $t = 'staff-stocktake';
        $doc['tags'][] = ['name' => $t, 'description' => 'Inventory stocktake sessions (Pinakes 0.9.0, module `stocktake`, permission inventory.manage). Every route answers 404 `feature_disabled` while the module is off.'];

        $int = ['type' => 'integer'];
        $str = ['type' => 'string'];
        $bool = ['type' => 'boolean'];
        $outcomes = array_values(StocktakeVocabulary::OUTCOMES);
        $scanOutcomes = array_values(array_diff($outcomes, ['missing']));
        $color = O::object(['name' => ['type' => 'string', 'enum' => ['green', 'amber', 'blue', 'red', 'purple', 'gray']], 'foreground' => $str, 'background' => $str]);
        $tone = ['oneOf' => [['type' => 'null'], O::object(['frequency_hz' => $int, 'duration_ms' => $int])], 'description' => 'The beep the web scan page plays for this outcome.'];
        $person = ['oneOf' => [['type' => 'null'], O::object(['id' => $int, 'name' => $str])]];
        $summaryProps = [];
        foreach (StocktakeVocabulary::SUMMARY_KEYS as $key) {
            $summaryProps[$key] = $int;
        }
        $filterProps = [
            'shelf_id' => O::nullable('integer'), 'level_id' => O::nullable('integer'), 'genre_id' => O::nullable('integer'),
            'media_type' => ['type' => ['string', 'null'], 'enum' => [...\App\Services\StocktakeService::MEDIA_TYPES, null]],
            'collocation_from' => O::nullable('string'), 'collocation_to' => O::nullable('string'),
        ];

        $doc['components']['schemas']['StocktakeSummary'] = O::object($summaryProps) + ['description' => 'Counters. expected = found + misplaced + on_loan + missing. Live while open, frozen at close; lost = the part of missing a mark-lost close marked; unknown/out_of_scope/duplicates are scans on the side; scans = every scan; mine = the caller\'s scans.'];
        $doc['components']['schemas']['StocktakeScan'] = O::object([
            'id' => $int, 'code' => ['type' => 'string', 'description' => 'The code as read (normalised).'],
            'inventory_number' => $str,
            'outcome' => ['type' => 'string', 'enum' => $scanOutcomes], 'outcome_label' => $str, 'color' => $color,
            'copy_id' => O::nullable('integer'), 'book_id' => O::nullable('integer'), 'title' => O::nullable('string'),
            'collocation' => O::nullable('string'), 'media_type' => O::nullable('string'),
            'copy_status' => O::nullable('string'), 'copy_status_label' => O::nullable('string'),
            'off_shelf' => ['type' => 'boolean', 'description' => 'The copy is recorded lost, damaged, at the restorer, in transit or in maintenance: tell the operator it turned up.'],
            'expected_shelf' => O::nullable('string'), 'operator' => $person, 'scanned_at' => O::nullable('string', 'date-time'),
        ]);
        $doc['components']['schemas']['StocktakeSession'] = O::object([
            'id' => $int, 'name' => $str,
            'status' => ['type' => 'string', 'enum' => array_values(StocktakeVocabulary::STATUSES)], 'status_label' => $str, 'status_color' => $color,
            'branch' => $person, 'filter' => O::object($filterProps),
            'filter_labels' => ['type' => 'array', 'items' => $str, 'description' => 'Localized filter description; empty = the whole catalogue.'],
            'note' => O::nullable('string'), 'expected_total' => $int,
            'opened_at' => O::nullable('string', 'date-time'), 'opened_by' => $person,
            'closed_at' => O::nullable('string', 'date-time'), 'closed_by' => $person,
            'missing_action' => ['type' => ['string', 'null'], 'enum' => ['none', 'lost', null]],
            'summary' => O::ref('StocktakeSummary'),
            'pending_lost' => ['type' => 'integer', 'description' => 'Missing copies a mark-lost close still has to mark (0 unless closed with lost).'],
            'can_scan' => $bool, 'can_upload' => $bool, 'can_close' => $bool, 'can_cancel' => $bool,
            'can_mark_lost' => ['type' => 'boolean', 'description' => 'Administrator, and the session is open or a mark-lost close left copies to mark.'],
            'recent_scans' => ['type' => 'array', 'items' => O::ref('StocktakeScan'), 'description' => 'Detail only: the 50 latest scans (only the caller\'s with mine=1).'],
            'history' => ['type' => 'array', 'description' => 'Detail only.', 'items' => O::object(['event' => $str, 'label' => $str, 'operator_name' => O::nullable('string'), 'at' => O::nullable('string', 'date-time')])],
        ]);

        $sid = O::path('id', 'Session id');
        $mine = O::query('mine', 'string', '1 = only the caller\'s scans in the scan list.');
        $notFound = ['404' => O::err('`not_found` (no such session) or `feature_disabled` (module off).')];
        $closed = O::conflict('`session_closed` or `session_cancelled`: the session is no longer open.');
        $session = O::ok('Session.', O::ref('StocktakeSession'));
        $idem = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => '1..128 of A-Z a-z 0-9 . _ : - ; a retry with the same key and body replays the first answer (meta.replayed=true), with another body is 422 `idempotency_conflict`.', 'schema' => ['type' => 'string']];
        $validation = ['422' => O::err('`validation`: meta.fields = {field: message}.')];

        $paths = [
            '/staff/stocktake/options' => ['get' => O::op($t, 'staffStocktakeOptions', 'Choices of the create form and the scan screen: branches, shelves with levels, genres, media types, outcomes (label, colour, tone), statuses, missing actions, limits', [
                '200' => O::ok('Options.', O::object([
                    'branches' => ['type' => 'array', 'items' => O::object(['id' => $int, 'name' => $str])],
                    'shelves' => ['type' => 'array', 'items' => O::object(['id' => $int, 'code' => $str, 'name' => $str, 'label' => $str, 'levels' => ['type' => 'array', 'items' => O::object(['id' => $int, 'level' => $int])]])],
                    'genres' => ['type' => 'array', 'items' => O::object(['id' => $int, 'label' => ['type' => 'string', 'description' => 'Indented with "— " per level, as the web select.']])],
                    'media_types' => ['type' => 'array', 'items' => O::object(['code' => $str, 'label' => $str])],
                    'outcomes' => ['type' => 'array', 'items' => O::object(['code' => ['type' => 'string', 'enum' => $outcomes], 'label' => $str, 'color' => $color, 'tone' => $tone, 'scan_result' => ['type' => 'boolean', 'description' => 'false for `missing`, a report bucket only.']])],
                    'statuses' => ['type' => 'array', 'items' => O::object(['code' => $str, 'label' => $str, 'color' => $color])],
                    'missing_actions' => ['type' => 'array', 'items' => O::object(['code' => ['type' => 'string', 'enum' => ['none', 'lost']], 'label' => $str, 'admin_only' => $bool])],
                    'can_mark_lost' => $bool,
                    'limits' => O::object(['max_code_length' => $int, 'max_upload_codes' => $int, 'max_upload_bytes' => $int, 'upload_extensions' => ['type' => 'array', 'items' => $str], 'max_name_length' => $int, 'max_collocation_length' => $int, 'scans_per_minute' => $int]),
                ])),
            ] + $notFound)],
            '/staff/stocktake/sessions' => [
                'get' => O::op($t, 'staffStocktakeSessions', 'Sessions: every open one first, then closed and cancelled (newest first), each with its counters (no recent_scans/history). 30/min.', [
                    '200' => O::ok('Sessions; meta has count, limit, next_cursor, has_more, total_count.', O::listOf('StocktakeSession')),
                ] + $notFound + $validation, ['parameters' => array_merge([O::query('status', 'string', 'open | closed | cancelled | all (default).')], O::paging())]),
                'post' => O::op($t, 'staffStocktakeCreate', 'Open a session: the copies matching the filter are frozen as the expected ones', [
                    '201' => $session, '200' => O::ok('Replay of an Idempotency-Key (meta.replayed=true).', O::ref('StocktakeSession')),
                ] + $notFound + $validation, ['parameters' => [$idem], 'requestBody' => O::inlineBody([
                    'name' => ['type' => 'string', 'maxLength' => 255], 'note' => O::nullable('string'), 'branch_id' => O::nullable('integer'),
                ] + $filterProps, ['name'])]),
            ],
            '/staff/stocktake/sessions/{id}' => ['get' => O::op($t, 'staffStocktakeSession', 'Session detail with counters, the 50 latest scans and the history', ['200' => $session] + $notFound, ['parameters' => [$sid, $mine]])],
            '/staff/stocktake/sessions/{id}/state' => ['get' => O::op($t, 'staffStocktakeState', 'Light polling: status, counters, the 50 latest scans', [
                '200' => O::ok('State.', O::object(['id' => $int, 'status' => ['type' => 'string', 'enum' => array_values(StocktakeVocabulary::STATUSES)], 'summary' => O::ref('StocktakeSummary'), 'recent_scans' => O::listOf('StocktakeScan')])),
            ] + $notFound, ['parameters' => [$sid, $mine]])],
            '/staff/stocktake/sessions/{id}/scans' => [
                'get' => O::op($t, 'staffStocktakeScans', 'Every scan of the session, newest first', [
                    '200' => O::ok('Scans; meta has total_count.', O::listOf('StocktakeScan')),
                ] + $notFound + $validation, ['parameters' => array_merge([$sid, $mine, O::query('outcome', 'string', implode(' | ', $scanOutcomes)), O::query('cursor', 'string', 'Opaque cursor from meta.next_cursor.'), O::query('limit', 'integer', '1..200, default 50.')])]),
                'post' => O::op($t, 'staffStocktakeScan', 'Record one scanned code. The same code scanned again (without the same Idempotency-Key) is a new scan recorded as `duplicate`, as on the web. 600/min.', [
                    '201' => O::ok('The scan, its tone, the updated counters.', O::object(['scan' => O::ref('StocktakeScan'), 'tone' => $tone, 'summary' => O::ref('StocktakeSummary'), 'session_status' => $str])),
                    '200' => O::ok('Replay of an Idempotency-Key (meta.replayed=true): the first answer, with today\'s counters.'),
                ] + $notFound + $closed + $validation, ['parameters' => [$sid, $idem], 'requestBody' => O::inlineBody([
                    'code' => ['type' => 'string', 'maxLength' => \App\Services\StocktakeService::MAX_CODE_LENGTH],
                    'shelf_id' => ['type' => ['integer', 'null'], 'description' => 'Shelf the operator is working on: an expected copy of another shelf is `misplaced`.'],
                ], ['code'])]),
            ],
            '/staff/stocktake/sessions/{id}/report' => ['get' => O::op($t, 'staffStocktakeReport', 'Report rows as the web final report and the CSV: every expected copy not scanned (on_loan or missing), then every scan', [
                '200' => O::ok('Rows; meta.total_count without a category filter.', ['type' => 'array', 'items' => O::object([
                    'category' => ['type' => 'string', 'enum' => $outcomes], 'category_label' => $str, 'color' => $color, 'code' => $str, 'title' => $str,
                    'media_type' => O::nullable('string'), 'collocation' => O::nullable('string'), 'expected_shelf' => O::nullable('string'),
                    'copy_status' => O::nullable('string'), 'copy_status_label' => O::nullable('string'), 'operator_name' => O::nullable('string'), 'scanned_at' => O::nullable('string', 'date-time'),
                ])]),
            ] + $notFound + $validation, ['parameters' => [$sid, O::query('category', 'string', implode(' | ', $outcomes)), O::query('cursor', 'string', 'Opaque cursor from meta.next_cursor.'), O::query('limit', 'integer', '1..200, default 50.')]])],
            '/staff/stocktake/sessions/{id}/upload' => ['post' => O::op($t, 'staffStocktakeUpload', 'Upload a list of codes (.txt one per line, or .csv first column; ≤ 2 MB, ≤ 5000 codes), one scan each, all or nothing. 30/min. Not idempotent: uploading the same file again records its codes again as duplicates, as on the web.', [
                '200' => O::ok('Counts per outcome and the updated counters.', O::object([
                    'total' => $int, 'outcomes' => ['type' => 'array', 'items' => O::object(['code' => $str, 'label' => $str, 'count' => $int])],
                    'summary' => O::ref('StocktakeSummary'), 'message' => $str,
                ])),
            ] + $notFound + $closed + $validation, ['parameters' => [$sid], 'requestBody' => O::multipart([
                'file' => ['type' => 'string', 'format' => 'binary'], 'shelf_id' => ['type' => 'integer'],
            ], ['file'])])],
            '/staff/stocktake/sessions/{id}/close' => ['post' => O::op($t, 'staffStocktakeClose', 'Close the session. missing_action=lost (administrators only, 403 `forbidden` otherwise) marks the missing copies lost; posting lost again on a session closed with lost finishes a marking cut short', [
                '200' => O::ok('The closed session plus close_result.', ['allOf' => [O::ref('StocktakeSession')], 'properties' => ['close_result' => O::object([
                    'resumed' => $bool, 'marked_lost' => $int, 'pending_lost' => $int,
                    'partial' => ['type' => 'boolean', 'description' => 'Closed, but some copies could not be updated (see message).'], 'message' => $str,
                ])]]),
            ] + $notFound + O::conflict('`session_closed`, `session_cancelled`, or `invalid_state` (lost resumed on a session not closed with lost).') + $validation, [
                'parameters' => [$sid], 'requestBody' => O::inlineBody(['missing_action' => ['type' => 'string', 'enum' => ['none', 'lost'], 'default' => 'none']]),
            ])],
            '/staff/stocktake/sessions/{id}/cancel' => ['post' => O::op($t, 'staffStocktakeCancel', 'Cancel the session: scans stay recorded, no copy is changed', [
                '200' => O::ok('The cancelled session plus message.', O::ref('StocktakeSession')),
            ] + $notFound + $closed, ['parameters' => [$sid]])],
            '/staff/stocktake/sessions/{id}/report.csv' => ['get' => O::op($t, 'staffStocktakeReportCsv', 'Report as CSV (any size). 30/min.', [
                '200' => O::file('text/csv', 'Content-Disposition: attachment; filename="revisione-{id}-{name}.csv".'),
            ] + $notFound, ['parameters' => [$sid]])],
            '/staff/stocktake/sessions/{id}/report.pdf' => ['get' => O::op($t, 'staffStocktakeReportPdf', 'Report as PDF. 30/min.', [
                '200' => O::file('application/pdf', 'Content-Disposition: attachment; filename="revisione-{id}-{name}.pdf".'),
                '422' => O::err('`report_too_large`: too many rows for the PDF, take the CSV.'),
            ] + $notFound, ['parameters' => [$sid]])],
        ];
        foreach ($paths as $path => $operations) {
            $doc['paths'][$path] = $operations;
        }

        return $doc;
    }
}
