<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DataIntegrity;

class MaintenanceController {

    public function integrityReport(Request $request, Response $response, mysqli $db): Response {
        $integrity = new DataIntegrity($db);
        $report = $integrity->generateIntegrityReport();

        ob_start();
        $title = "Report Integrità Dati - Sistema Biblioteca";
        require __DIR__ . '/../Views/admin/integrity_report.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function fixIntegrityIssues(Request $request, Response $response, mysqli $db): Response {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $integrity = new DataIntegrity($db);

        try {
            // Correggi le inconsistenze
            $fixResult = $integrity->fixDataInconsistencies();

            // Genera report aggiornato
            $report = $integrity->generateIntegrityReport();

            $result = [
                'success' => true,
                'message' => sprintf(__("Correzioni applicate: %d record aggiornati"), $fixResult['fixed']),
                'details' => $fixResult,
                'report' => $report
            ];

        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => __("Errore durante la correzione:") . ' ' . $e->getMessage(),
                'details' => []
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function recalculateAvailability(Request $request, Response $response, mysqli $db): Response {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $integrity = new DataIntegrity($db);

        try {
            $result = $integrity->recalculateAllBookAvailability();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => sprintf(__("Aggiornate %d righe"), $result['updated']),
                'details' => $result
            ]));

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Errore durante il ricalcolo:") . ' ' . $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function performMaintenance(Request $request, Response $response, mysqli $db): Response {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $integrity = new DataIntegrity($db);
        $results = [];

        try {
            // 1. Ricalcola tutte le disponibilità
            $availabilityResult = $integrity->recalculateAllBookAvailability();
            $results['availability'] = $availabilityResult;

            // 2. Correggi inconsistenze
            $fixResult = $integrity->fixDataInconsistencies();
            $results['fixes'] = $fixResult;

            // 3. Genera report finale
            $report = $integrity->generateIntegrityReport();
            $results['final_report'] = $report;

            $totalFixed = $availabilityResult['updated'] + $fixResult['fixed'];
            $message = sprintf(__("Manutenzione completata: %d record corretti"), $totalFixed);

            if (!empty($report['consistency_issues'])) {
                $issueCount = count($report['consistency_issues']);
                $message .= ", " . sprintf(__("%d problemi rilevati"), $issueCount);
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $message,
                'results' => $results
            ]));

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Errore durante la manutenzione:") . ' ' . $e->getMessage(),
                'results' => $results
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}