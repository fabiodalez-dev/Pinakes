<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\Admin\StatsController;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Support\Features;
use App\Support\LoanStatistics;
use App\Support\SecureLogger;
use App\Support\StatisticsSections;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * The /admin/statistics page for the app (Mobile API 1.10.0).
 *
 * Every figure comes from the classes the web page renders: LoanStatistics
 * (overview, top books / readers, loans per month and per state),
 * StatisticsSections (visitors, loans by material, the patron type / fines /
 * stocktake sections) and PatronLedger::periodTotals (fines report). Each
 * section follows its own module switch exactly as on the page: a section
 * whose module is off is absent from the answer. The fines CSV is delegated
 * to StatsController::finesCsv.
 */
final class StaffStatisticsController
{
    public function __construct(private mysqli $db)
    {
    }

    /** GET /staff/statistics?fines_from=&fines_to= */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $period = $this->finesPeriod($request);
        if (isset($period['errors'])) {
            return $this->invalid($response, $period['errors']);
        }
        try {
            $db = $this->db;
            $overview = LoanStatistics::overview($db) ?? [];
            $statusLabels = loan_status_label_map();
            $data = [
                'overview' => [
                    'books_available' => (int) ($overview['libri_disponibili'] ?? 0),
                    'books_on_loan'   => (int) ($overview['libri_prestati'] ?? 0),
                    'active_loans'    => (int) ($overview['prestiti_attivi'] ?? 0),
                    'overdue_loans'   => (int) ($overview['prestiti_in_ritardo'] ?? 0),
                    'completed_loans' => (int) ($overview['prestiti_completati'] ?? 0),
                    'borrowers'       => (int) ($overview['utenti_con_prestiti'] ?? 0),
                ],
                'loans_by_month' => array_map(static fn (array $r): array => [
                    'month' => (string) $r['mese'],
                    'total' => (int) $r['totale_prestiti'],
                ], LoanStatistics::loansByMonth($db)),
                'loans_by_status' => array_map(static fn (array $r): array => [
                    'status' => (string) $r['stato'],
                    'label'  => $statusLabels[(string) $r['stato']] ?? (string) $r['stato'],
                    'total'  => (int) $r['totale'],
                ], LoanStatistics::loansByStatus($db)),
                'loans_by_media_type' => array_map(static fn (array $r): array => [
                    'media_type' => $r['tipo_media'],
                    'label'      => $r['label'],
                    'total'      => $r['totale'],
                ], StatisticsSections::loansByMediaType($db)),
                'top_books' => array_map(static fn (array $r): array => [
                    'id'              => (int) $r['id'],
                    'title'           => \App\Support\HtmlHelper::decode((string) $r['titolo']),
                    'cover_url'       => absoluteUrl(StaffLoanReader::coverPath($r['copertina_url'] ?? null)),
                    'total_loans'     => (int) $r['prestiti_totali'],
                    'completed_loans' => (int) $r['prestiti_completati'],
                    'active_loans'    => (int) $r['prestiti_attivi'],
                    'overdue_loans'   => (int) $r['prestiti_in_ritardo'],
                ], LoanStatistics::topBooks($db)),
                'top_readers' => array_map(static fn (array $r): array => [
                    'id'              => (int) $r['id'],
                    'name'            => StaffLoanReader::name($r['nome'] ?? '', $r['cognome'] ?? ''),
                    'email'           => (string) ($r['email'] ?? ''),
                    'user_type'       => (string) ($r['tipo_utente'] ?? ''),
                    'total_loans'     => (int) $r['prestiti_totali'],
                    'completed_loans' => (int) $r['prestiti_completati'],
                    'active_loans'    => (int) $r['prestiti_attivi'],
                    'overdue_loans'   => (int) $r['prestiti_in_ritardo'],
                ], LoanStatistics::topReaders($db)),
            ];
            $visitors = StatisticsSections::visitors($db, 30);
            if ($visitors !== null) {
                $data['visitors'] = [
                    'days'    => $visitors['days'],
                    'members' => $visitors['soci'],
                    'guests'  => $visitors['ospiti'],
                    'total'   => $visitors['totale'],
                    'daily'   => array_map([\App\Plugins\MobileApi\Staff090\Support\AttendancePayload::class, 'day'], $visitors['daily']),
                ];
            }
            $sections = [];
            foreach (StatisticsSections::extensions($db) as $id => $section) {
                $sections[] = [
                    'id'    => (string) $id,
                    'title' => $section['title'],
                    'rows'  => $section['data']['rows'],
                    'note'  => $section['data']['note'] ?? null,
                ];
            }
            $data['sections'] = $sections;
            if (Features::enabled('fines')) {
                [$from, $to] = $period;
                $months = [];
                $totals = ['charged_cents' => 0, 'collected_cents' => 0, 'waived_cents' => 0, 'reversed_cents' => 0];
                foreach (PatronLedger::forDb($db)->periodTotals($from, $to) as $row) {
                    $month = [
                        'month'           => $row['mese'],
                        'charged_cents'   => $row['addebitato'],
                        'collected_cents' => $row['incassato'],
                        'waived_cents'    => $row['condonato'],
                        'reversed_cents'  => $row['stornato'],
                    ];
                    foreach ($totals as $k => $v) {
                        $totals[$k] = $v + $month[$k];
                    }
                    $months[] = $month;
                }
                $data['fines_report'] = [
                    'from'     => $from,
                    'to'       => $to,
                    'currency' => FineParameters::currency(),
                    'months'   => $months,
                    'totals'   => $totals,
                ];
            }

            return ResponseEnvelope::success($response, $data, [
                'modules' => [
                    'visitor_log'       => Features::enabled('visitor_log'),
                    'circulation_rules' => Features::enabled('circulation_rules'),
                    'fines'             => Features::enabled('fines'),
                    'stocktake'         => Features::enabled('stocktake'),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'statistics', $e);
        }
    }

    /** GET /staff/statistics/fines.csv?fines_from=&fines_to= (features.fines) */
    public function finesCsv(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $period = $this->finesPeriod($request);
        if (isset($period['errors'])) {
            return $this->invalid($response, $period['errors']);
        }
        [$from, $to] = $period;
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new StatsController())->finesCsv(
                Delegation::request('GET', '/admin/statistics/fines.csv', [], ['fines_from' => $from, 'fines_to' => $to]),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200 || $outcome->body === '') {
                throw new \RuntimeException('fines csv HTTP ' . $outcome->status);
            }
            $response->getBody()->write($outcome->body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', $outcome->response->getHeaderLine('Content-Disposition') ?: 'attachment; filename="multe_' . $from . '_' . $to . '.csv"')
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return $this->fail($response, 'fines csv', $e);
        }
    }

    /**
     * The fines report period, as the web page reads it (default: the last
     * twelve months; a reversed range is swapped), except that a value which
     * is not a YYYY-MM-DD date is refused instead of silently ignored.
     *
     * @return array{0:string, 1:string}|array{errors: array<string, string>}
     */
    private function finesPeriod(ServerRequestInterface $request): array
    {
        $query = [];
        $errors = [];
        foreach (['fines_from', 'fines_to'] as $key) {
            $raw = StaffPaging::query($request, $key);
            if ($raw === '') {
                continue;
            }
            if (StaffPaging::isoDate($raw) === null) {
                $errors[$key] = __('Data non valida (formato AAAA-MM-GG).');
                continue;
            }
            $query[$key] = $raw;
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        return LoanStatistics::finesPeriod($query);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        $first = (string) array_key_first($fields);

        return ResponseEnvelope::error($response, 'validation', $fields[$first], 422, ['field' => $first, 'fields' => $fields]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
