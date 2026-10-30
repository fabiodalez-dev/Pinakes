<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttendanceRepository;
use App\Models\SettingsRepository;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Visitor log kiosk (/admin/attendance): card scan for members, a short form
 * for guests, day / hour-slot statistics and the GDPR retention setting.
 */
class AttendanceController
{
    private const PERIODS = [7, 30, 90, 365];

    public function index(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (($missing = $this->schemaMissing($db, $response)) !== null) {
            return $missing;
        }
        $repo = new AttendanceRepository($db);
        $days = $this->period($request);
        $data = [
            'days' => $days,
            'periods' => self::PERIODS,
            'daily' => $repo->dailyCounts($days),
            'hourly' => $repo->hourlyCounts($days),
            'totals' => $repo->totals($days),
            'today' => $repo->totals(1),
            'recent' => $repo->recent(10),
            'branches' => $repo->branches(),
            'retentionDays' => AttendanceRepository::retentionDays(new SettingsRepository($db)),
            'isAdmin' => ($_SESSION['user']['tipo_utente'] ?? '') === 'admin',
        ];
        ob_start();
        require __DIR__ . '/../Views/attendance/index.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response;
    }

    public function scan(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (($missing = $this->schemaMissing($db, $response)) !== null) {
            return $missing;
        }
        $body = (array) $request->getParsedBody();
        $repo = new AttendanceRepository($db);
        $card = is_string($body['codice_tessera'] ?? null) ? $body['codice_tessera'] : '';
        $member = $repo->findMemberByCard($card);
        if ($member === null) {
            $_SESSION['error_message'] = __('Tessera non riconosciuta. Controlla il codice o registra la visita come ospite.');
            return $this->back($response);
        }
        try {
            $repo->recordMember($member['id'], $this->rememberBranch($repo, $body));
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('Attendance scan failed', ['exception' => get_class($e), 'code' => $e->getCode()]);
            $_SESSION['error_message'] = __('Impossibile registrare la presenza. Riprova.');
            return $this->back($response);
        }
        $name = AttendanceRepository::shortName($member['nome'], $member['cognome']);
        $_SESSION['success_message'] = $member['stato'] === 'attivo'
            ? sprintf(__('Benvenuto/a, %s: presenza registrata.'), $name)
            : sprintf(__('Presenza registrata per %s, ma la tessera non è attiva.'), $name);
        return $this->back($response);
    }

    public function guest(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (($missing = $this->schemaMissing($db, $response)) !== null) {
            return $missing;
        }
        $body = (array) $request->getParsedBody();
        $repo = new AttendanceRepository($db);
        $name = is_string($body['nome_ospite'] ?? null) ? $body['nome_ospite'] : null;
        $reason = is_string($body['motivo'] ?? null) ? $body['motivo'] : null;
        try {
            $repo->recordGuest($name, $reason, $this->rememberBranch($repo, $body));
            $_SESSION['success_message'] = __('Presenza dell\'ospite registrata.');
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('Attendance guest failed', ['exception' => get_class($e), 'code' => $e->getCode()]);
            $_SESSION['error_message'] = __('Impossibile registrare la presenza. Riprova.');
        }
        return $this->back($response);
    }

    /** Retention setting. AdminAuthMiddleware admits staff: this one is admin-only. */
    public function saveSettings(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (!$this->isAdmin()) {
            $_SESSION['error_message'] = __('Operazione riservata agli amministratori.');
            return $this->back($response);
        }
        $body = (array) $request->getParsedBody();
        $days = AttendanceRepository::normalizeRetention($body['retention_days'] ?? null);
        (new SettingsRepository($db))->set(AttendanceRepository::SETTINGS_CATEGORY, AttendanceRepository::SETTING_RETENTION, (string) $days);
        $_SESSION['success_message'] = $days === 0
            ? __('Conservazione aggiornata: le presenze non verranno cancellate automaticamente.')
            : sprintf(__('Conservazione aggiornata: le presenze più vecchie di %d giorni verranno cancellate.'), $days);
        return $this->back($response);
    }

    /** Apply the retention now instead of waiting for the next maintenance run. */
    public function purge(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (($missing = $this->schemaMissing($db, $response)) !== null) {
            return $missing;
        }
        if (!$this->isAdmin()) {
            $_SESSION['error_message'] = __('Operazione riservata agli amministratori.');
            return $this->back($response);
        }
        $days = AttendanceRepository::retentionDays(new SettingsRepository($db));
        $deleted = (new AttendanceRepository($db))->purge($days);
        $_SESSION['success_message'] = sprintf(__('Presenze cancellate: %d.'), $deleted);
        return $this->back($response);
    }

    /** Daily counts for the selected period as CSV. */
    public function exportCsv(Request $request, Response $response, mysqli $db): Response
    {
        if (($off = \App\Support\Features::requireOrRedirect('visitor_log', $response)) !== null) {
            return $off;
        }
        if (($missing = $this->schemaMissing($db, $response)) !== null) {
            return $missing;
        }
        $days = $this->period($request);
        $rows = (new AttendanceRepository($db))->dailyCounts($days);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return $response->withStatus(500);
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Data'), __('Soci'), __('Ospiti'), __('Totale')], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, [$row['giorno'], $row['soci'], $row['ospiti'], $row['totale']], ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="presenze_' . $days . 'g.csv"');
    }

    private function period(Request $request): int
    {
        $days = (int) ($request->getQueryParams()['days'] ?? 30);
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    /**
     * The kiosk keeps the branch chosen for the previous visit selected.
     *
     * @param array<string, mixed> $body
     */
    private function rememberBranch(AttendanceRepository $repo, array $body): ?int
    {
        $sedeId = $repo->validBranchId($body['sede_id'] ?? null);
        $_SESSION['attendance_sede_id'] = $sedeId;
        return $sedeId;
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['user']['tipo_utente'] ?? '') === 'admin';
    }

    private function back(Response $response): Response
    {
        return $response->withHeader('Location', url('/admin/attendance'))->withStatus(302);
    }

    /**
     * Files updated before the 0.9.0 migration ran (or a deploy that skipped
     * it): the visitor log table is not there yet, so every action answers
     * with a message instead of a 500.
     */
    private function schemaMissing(mysqli $db, Response $response): ?Response
    {
        if ((new AttendanceRepository($db))->isAvailable()) {
            return null;
        }
        $_SESSION['error_message'] = __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.');
        return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
    }
}
