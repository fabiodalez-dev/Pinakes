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
        $title = "Report Integrità Dati - Pinakes";
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
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
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
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

    /**
     * Crea gli indici di ottimizzazione mancanti
     */
    public function createMissingIndexes(Request $request, Response $response, mysqli $db): Response {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $integrity = new DataIntegrity($db);

        try {
            $result = $integrity->createMissingIndexes();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => sprintf(__("%d indici creati con successo"), $result['created']),
                'details' => $result
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Errore durante la creazione degli indici:") . ' ' . $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Genera lo script SQL per gli indici mancanti
     */
    public function generateIndexesSQL(Request $request, Response $response, mysqli $db): Response {
        $integrity = new DataIntegrity($db);
        $sql = $integrity->generateMissingIndexesSQL();

        $response->getBody()->write($sql);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="missing_indexes_' . date('Y-m-d') . '.sql"');
    }

    /**
     * Applica un fix specifico alla configurazione .env
     */
    public function applyConfigFix(Request $request, Response $response): Response {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Parse JSON body
        $rawBody = (string) $request->getBody();
        $body = json_decode($rawBody, true);

        if (!is_array($body)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Formato richiesta non valido")
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $issueType = $body['issue_type'] ?? '';
        $fixValue = $body['fix_value'] ?? '';

        // Valida tipo di issue
        $allowedTypes = ['missing_canonical_url', 'empty_canonical_url', 'invalid_canonical_url'];
        if (!in_array($issueType, $allowedTypes, true)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Tipo di issue non valido")
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Valida URL
        if (!filter_var($fixValue, FILTER_VALIDATE_URL)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("L'URL fornito non è valido")
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            // Aggiorna il file .env
            $envPath = __DIR__ . '/../../.env';
            if (!file_exists($envPath)) {
                throw new \Exception(__("File .env non trovato"));
            }

            $envContent = file_get_contents($envPath);
            if ($envContent === false) {
                throw new \Exception(__("Impossibile leggere il file .env"));
            }

            // Cerca e sostituisci APP_CANONICAL_URL
            $pattern = '/^APP_CANONICAL_URL=.*$/m';
            $replacement = 'APP_CANONICAL_URL=' . $fixValue;

            if (preg_match($pattern, $envContent)) {
                // Esiste già, sostituisci
                $newContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                // Non esiste, aggiungi alla fine
                $newContent = rtrim($envContent) . "\n" . $replacement . "\n";
            }

            if (file_put_contents($envPath, $newContent) === false) {
                throw new \Exception(__("Impossibile scrivere nel file .env"));
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __("Configurazione aggiornata con successo!")
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __("Errore durante l'applicazione del fix:") . ' ' . $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}