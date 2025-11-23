<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ScrapingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ScrapeController
{
    public function byIsbn(Request $request, Response $response): Response
    {
        $isbn = trim((string)($request->getQueryParams()['isbn'] ?? ''));
        $scraper = new ScrapingService();
        $result = $scraper->byIsbn($isbn);

        if (isset($result['error'])) {
            $status = 400;
            if ($result['error'] === __('Nessuna fonte di scraping disponibile. Installa almeno un plugin di scraping (es. Open Library o Scraping Pro).')) {
                $status = 503;
            } elseif (strpos($result['error'], 'ISBN non trovato') !== false) {
                $status = 404;
            }
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $response->getBody()->write(json_encode([
                'error' => __('Impossibile generare la risposta JSON.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
