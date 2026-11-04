<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffStatisticsController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * The statistics page (/admin/statistics) for the app. No module of its own:
 * each section follows its module switch, as on the page. Like the page,
 * no custom-role mapping in Permissions: administrators and Staff only.
 */
final class StatisticsArea implements StaffArea
{
    public static function area(): string
    {
        return 'statistics';
    }

    public static function feature(): ?string
    {
        return null;
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        return [
            ['GET', '/staff/statistics', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->index($q, $r)],
            ['GET', '/staff/statistics/fines.csv', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->finesCsv($q, $r), 'feature' => 'fines'],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-statistics';
        $doc['tags'][] = ['name' => $t, 'description' => 'Loan statistics (Mobile API 1.10.0, the /admin/statistics page). Administrators and Staff only. Each 0.9.0 section is present only while its module is on.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_statistics'] = [
            'type' => 'boolean', 'description' => 'GET /staff/statistics is served (always true on 1.10.0); the fines CSV follows the fines flag.',
        ];

        $i = ['type' => 'integer'];
        $s = ['type' => 'string'];
        $loans = ['total_loans' => $i, 'completed_loans' => $i, 'active_loans' => ['type' => 'integer', 'description' => 'attivo = 1 and in_corso / in_ritardo.'], 'overdue_loans' => $i];
        $money = ['charged_cents' => $i, 'collected_cents' => $i, 'waived_cents' => $i, 'reversed_cents' => $i];
        $doc['components']['schemas']['StaffStatistics'] = O::object([
            'overview' => O::object([
                'books_available' => $i, 'books_on_loan' => $i, 'active_loans' => $i, 'overdue_loans' => $i, 'completed_loans' => $i,
                'borrowers' => ['type' => 'integer', 'description' => 'Distinct readers with at least one loan ever ("Utenti Attivi" on the page).'],
            ]),
            'loans_by_month' => ['type' => 'array', 'description' => 'Last 12 months, oldest first; months without loans are absent.', 'items' => O::object(['month' => ['type' => 'string', 'description' => 'YYYY-MM.'], 'total' => $i])],
            'loans_by_status' => ['type' => 'array', 'description' => 'Active loans by state.', 'items' => O::object(['status' => ['type' => 'string', 'enum' => ['in_corso', 'in_ritardo']], 'label' => $s, 'total' => $i])],
            'loans_by_media_type' => ['type' => 'array', 'description' => 'Loans that left the library in the last 12 months by material; every media type listed.', 'items' => O::object(['media_type' => ['type' => 'string', 'enum' => ['libro', 'disco', 'audiolibro', 'dvd', 'altro']], 'label' => $s, 'total' => $i])],
            'top_books' => ['type' => 'array', 'description' => 'Top 10 most borrowed (deleted records excluded).', 'items' => O::object(['id' => $i, 'title' => $s, 'cover_url' => $s] + $loans)],
            'top_readers' => ['type' => 'array', 'description' => 'Top 10 most active readers.', 'items' => O::object(['id' => $i, 'name' => $s, 'email' => $s, 'user_type' => $s] + $loans)],
            'visitors' => ['type' => 'object', 'description' => 'ONLY while visitor_log is on: last 30 days of the visitor log.', 'properties' => [
                'days' => $i, 'members' => $i, 'guests' => $i, 'total' => $i,
                'daily' => ['type' => 'array', 'items' => O::object(['date' => ['type' => 'string', 'format' => 'date'], 'members' => $i, 'guests' => $i, 'total' => $i])],
            ]],
            'sections' => ['type' => 'array', 'description' => 'The 0.9.0 table sections in page order, each only while its module is on and it has data: patron-types (circulation_rules), fines (fines; hidden with no fine configured and an empty ledger), stocktake (stocktake; last closed session).', 'items' => O::object([
                'id' => ['type' => 'string', 'enum' => ['patron-types', 'fines', 'stocktake']],
                'title' => $s,
                'rows' => ['type' => 'array', 'items' => O::object(['label' => $s, 'value' => ['type' => ['integer', 'number', 'string'], 'description' => 'Already formatted for display (amounts carry the currency).']])],
                'note' => O::nullable('string'),
            ])],
            'fines_report' => ['type' => 'object', 'description' => 'ONLY while fines is on: charged / collected / waived / reversed per month of the period.', 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'], 'currency' => ['type' => 'string', 'description' => 'ISO 4217.'],
                'months' => ['type' => 'array', 'items' => O::object(['month' => ['type' => 'string', 'description' => 'YYYY-MM.']] + $money)],
                'totals' => O::object($money),
            ]],
        ]);
        $period = [
            O::query('fines_from', 'string', 'YYYY-MM-DD; default: first day of the month 11 months before fines_to.'),
            O::query('fines_to', 'string', 'YYYY-MM-DD; default: today. A reversed range is swapped.'),
        ];
        $doc['paths']['/staff/statistics'] = ['get' => O::op($t, 'staffStatistics', 'Loan statistics (the web statistics page)', [
            '200' => O::ok('meta.modules = {visitor_log, circulation_rules, fines, stocktake}: which modules are on.', O::ref('StaffStatistics')),
            '422' => O::err('`validation` (meta.fields.fines_from / fines_to: not a YYYY-MM-DD date).'),
        ], ['parameters' => $period])];
        $doc['paths']['/staff/statistics/fines.csv'] = ['get' => O::op($t, 'staffStatisticsFinesCsv', 'Fines report CSV (fines)', [
            '200' => O::file('text/csv', 'UTF-8 with BOM, "," separated: month, charged, collected, waived, reversed (decimal amounts in the currency named in the header), then a total row. Content-Disposition: attachment; filename="multe_{from}_{to}.csv".'),
            '422' => O::err('`validation` (meta.fields.fines_from / fines_to).'),
        ] + O::notFound(), ['parameters' => $period, 'description' => '404 feature_disabled while the fines module is off.'])];

        return $doc;
    }
}
