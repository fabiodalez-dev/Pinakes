<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ScrapeController
{
    public function byIsbn(Request $request, Response $response): Response
    {
        $isbn = trim((string)($request->getQueryParams()['isbn'] ?? ''));
        if ($isbn === '') {
            $response->getBody()->write(json_encode([
                'error' => __('Parametro ISBN mancante.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // SSRF Protection: Validate ISBN format before constructing URL
        $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Validate ISBN format (ISBN-10 or ISBN-13)
        $isValid = $this->isValidIsbn($cleanIsbn);

        // Hook: scrape.isbn.validate - Allow custom ISBN validation
        $isValid = \App\Support\Hooks::apply('scrape.isbn.validate', $isValid, [$cleanIsbn, 'user_input']);

        if (!$isValid) {
            $response->getBody()->write(json_encode([
                'error' => __('Formato ISBN non valido.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Get available scraping sources
        $sources = $this->getDefaultSources();

        // Hook: scrape.sources - Allow plugins to add custom scraping sources
        $sources = \App\Support\Hooks::apply('scrape.sources', $sources, [$cleanIsbn]);

        // Check if any sources are available
        if (empty($sources)) {
            error_log("[ScrapeController] No scraping sources available");
            $response->getBody()->write(json_encode([
                'error' => __('Nessuna fonte di scraping disponibile. Installa almeno un plugin di scraping (es. Open Library o Scraping Pro).'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        error_log("[ScrapeController] Available sources: " . implode(', ', array_keys($sources)));

        // Hook: scrape.fetch.custom - Allow plugins to completely replace scraping logic
        $customResult = \App\Support\Hooks::apply('scrape.fetch.custom', null, [$sources, $cleanIsbn]);

        if ($customResult !== null) {
            error_log("[ScrapeController] ISBN $cleanIsbn found via plugins");

            // Plugin handled scraping completely, use its result
            $payload = $customResult;

            // Hook: scrape.response - Modify final JSON response
            $payload = \App\Support\Hooks::apply('scrape.response', $payload, [$cleanIsbn, $sources, ['timestamp' => time()]]);

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $response->getBody()->write(json_encode([
                    'error' => __('Impossibile generare la risposta JSON.'),
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Plugins are active but no data found for this ISBN
        error_log("[ScrapeController] ISBN $cleanIsbn not found in any source");

        $sourceNames = array_map(fn($s) => $s['name'] ?? 'Unknown', $sources);
        $response->getBody()->write(json_encode([
            'error' => sprintf(
                __('ISBN non trovato. Fonti consultate: %s'),
                implode(', ', $sourceNames)
            ),
            'isbn' => $cleanIsbn,
            'sources_checked' => array_keys($sources),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    /**
     * Get default scraping sources
     *
     * @return array Array of scraping sources
     */
    private function getDefaultSources(): array
    {
        // Le fonti vengono dichiarate dinamicamente dai plugin attivi.
        return [];
    }

    /**
     * Validate ISBN format (ISBN-10 or ISBN-13)
     */
    private function isValidIsbn(string $isbn): bool
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Check ISBN-13 format
        if (strlen($isbn) === 13) {
            if (!ctype_digit($isbn)) {
                return false;
            }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$isbn[$i] * (($i % 2) === 0 ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            return ((int)$isbn[12]) === $checkDigit;
        }

        // Check ISBN-10 format
        if (strlen($isbn) === 10) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                if (!ctype_digit($isbn[$i])) {
                    return false;
                }
                $sum += (int)$isbn[$i] * (10 - $i);
            }
            $checkChar = $isbn[9];
            $checkDigit = (11 - ($sum % 11)) % 11;
            $expectedCheck = ($checkDigit === 10) ? 'X' : (string)$checkDigit;
            return $checkChar === $expectedCheck;
        }

        return false;
    }
}
