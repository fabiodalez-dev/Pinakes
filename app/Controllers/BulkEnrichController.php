<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\BulkEnrichmentService;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BulkEnrichController
{
    /**
     * Display the bulk enrichment admin page with stats
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $service = new BulkEnrichmentService($db);
        $stats = $service->getStats();
        $enabled = $service->isEnabled();

        $pageTitle = __('Arricchimento Massivo');

        ob_start();
        require __DIR__ . '/../Views/admin/bulk-enrich.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * POST: Start a manual batch enrichment (20 books per batch)
     * CSRF validated by CsrfMiddleware
     */
    public function start(Request $request, Response $response, mysqli $db): Response
    {
        // Batch scraping can exceed the default 30s max_execution_time
        // (each book takes ~2-5s for API calls + 1s inter-book rate limit).
        set_time_limit(300);

        $service = new BulkEnrichmentService($db);

        try {
            $results = $service->enrichBatch(20);

            $response->getBody()->write(json_encode([
                'success' => true,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));
        } catch (\Throwable $e) {
            // Log full details server-side; never leak raw exception messages
            // (may contain DB schema hints, paths, credentials, stack traces).
            SecureLogger::error('BulkEnrichController::start failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => __('Errore durante l\'arricchimento'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST: Toggle automatic enrichment (cron) on/off
     * CSRF validated by CsrfMiddleware
     *
     * Accepts `enabled` as a string ("1"/"0"/"true"/"false") OR boolean. Using
     * `!empty()` alone would have treated "0" (string) as truthy in a subtle
     * way in older PHP quirks around form-urlencoded booleans — use filter_var
     * with FILTER_VALIDATE_BOOL so "false", "0", "off", "no" disable correctly.
     */
    public function toggle(Request $request, Response $response, mysqli $db): Response
    {
        $data    = (array) $request->getParsedBody();
        $raw     = $data['enabled'] ?? false;
        $enabled = (bool) filter_var($raw, FILTER_VALIDATE_BOOL);

        $service = new BulkEnrichmentService($db);
        if (!$service->setEnabled($enabled)) {
            SecureLogger::error('BulkEnrichController::toggle persist failed', [
                'requested' => $enabled,
            ]);
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => __('Errore nel salvataggio delle impostazioni.'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'enabled' => $enabled,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
