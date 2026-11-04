<?php

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatsController
{
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        // The loan figures live in LoanStatistics, shared with the app's
        // GET /api/v1/staff/statistics.
        $topBooks = \App\Support\LoanStatistics::topBooks($db);
        $topReaders = \App\Support\LoanStatistics::topReaders($db);
        $loansByMonth = \App\Support\LoanStatistics::loansByMonth($db);
        $loansByStatus = \App\Support\LoanStatistics::loansByStatus($db);
        $stats = \App\Support\LoanStatistics::overview($db);

        // 0.9.0 sections: visitors (attendance log), loans by material and the
        // extension points other branches fill (see StatisticsSections).
        $visitors = \App\Support\StatisticsSections::visitors($db, 30);
        $loansByMedia = \App\Support\StatisticsSections::loansByMediaType($db);
        $extraSections = \App\Support\StatisticsSections::extensions($db);
        // Fines report (plan §7): charged / collected / waived per month.
        [$finesFrom, $finesTo] = self::finesPeriod($request);
        $finesByMonth = \App\Services\PatronLedger::forDb($db)->periodTotals($finesFrom, $finesTo);

        $title = 'Statistiche Prestiti';

        ob_start();
        include __DIR__ . '/../../Views/admin/stats.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /** CSV of the fines report for the selected period. */
    public function finesCsv(Request $request, Response $response, \mysqli $db): Response
    {
        [$from, $to] = self::finesPeriod($request);
        $rows = \App\Services\PatronLedger::forDb($db)->periodTotals($from, $to);
        $currency = \App\Services\FineParameters::currency();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [__('Mese'), __('Addebitato') . ' (' . $currency . ')', __('Incassato') . ' (' . $currency . ')', __('Condonato') . ' (' . $currency . ')', __('Stornato') . ' (' . $currency . ')'], ',', '"', '');
        $sum = ['addebitato' => 0, 'incassato' => 0, 'condonato' => 0, 'stornato' => 0];
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['mese'],
                \App\Services\FineParameters::centsToDecimal($row['addebitato']),
                \App\Services\FineParameters::centsToDecimal($row['incassato']),
                \App\Services\FineParameters::centsToDecimal($row['condonato']),
                \App\Services\FineParameters::centsToDecimal($row['stornato']),
            ], ',', '"', '');
            foreach ($sum as $k => $v) {
                $sum[$k] = $v + $row[$k];
            }
        }
        fputcsv($out, [
            __('Totale'),
            \App\Services\FineParameters::centsToDecimal($sum['addebitato']),
            \App\Services\FineParameters::centsToDecimal($sum['incassato']),
            \App\Services\FineParameters::centsToDecimal($sum['condonato']),
            \App\Services\FineParameters::centsToDecimal($sum['stornato']),
        ], ',', '"', '');
        rewind($out);
        $csv = "\xEF\xBB\xBF" . (string) stream_get_contents($out);
        fclose($out);
        $filename = 'multe_' . $from . '_' . $to . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Report period from ?fines_from / ?fines_to (see LoanStatistics::finesPeriod()).
     *
     * @return array{0: string, 1: string}
     */
    private static function finesPeriod(Request $request): array
    {
        return \App\Support\LoanStatistics::finesPeriod($request->getQueryParams());
    }
}
