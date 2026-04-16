<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\BulkEnrichmentService;
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
        $service = new BulkEnrichmentService($db);

        try {
            $results = $service->enrichBatch(20);

            $response->getBody()->write(json_encode([
                'success' => true,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST: Toggle automatic enrichment (cron) on/off
     * CSRF validated by CsrfMiddleware
     */
    public function toggle(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        $enabled = !empty($data['enabled']);

        $service = new BulkEnrichmentService($db);
        $service->setEnabled($enabled);

        $response->getBody()->write(json_encode([
            'success' => true,
            'enabled' => $enabled,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
