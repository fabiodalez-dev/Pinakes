<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ConfigStore;
use App\Support\HtmlHelper;
use App\Support\I18n;
use App\Support\RouteTranslator;
use App\Support\SitemapGenerator;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

class SeoController
{
    public function sitemap(Request $request, Response $response, mysqli $db): Response
    {
        $baseUrl = self::resolveBaseUrl($request);
        $generator = new SitemapGenerator($db, $baseUrl);
        $response->getBody()->write($generator->generate());

        return $response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(Request $request, Response $response): Response
    {
        $baseUrl = self::resolveBaseUrl($request);

        $basePath = HtmlHelper::getBasePath();
        $lines = [
            'User-agent: *',
            'Disallow: ' . $basePath . '/admin/',
            'Disallow: ' . $basePath . RouteTranslator::route('login'),
            'Disallow: ' . $basePath . RouteTranslator::route('register'),
            '',
            'Sitemap: ' . $baseUrl . '/sitemap.xml',
            'Feed: ' . $baseUrl . '/feed.xml',
        ];

        if (ConfigStore::get('seo.llms_txt_enabled', '0') === '1') {
            $lines[] = 'llms.txt: ' . $baseUrl . '/llms.txt';
        }

        $response->getBody()->write(implode("\n", $lines) . "\n");
        return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function llmsTxt(Request $request, Response $response, mysqli $db): Response
    {
        if (ConfigStore::get('seo.llms_txt_enabled', '0') !== '1') {
            throw new HttpNotFoundException($request);
        }

        $baseUrl = self::resolveBaseUrl($request);
        $appName = ConfigStore::get('app.name', 'Pinakes');
        $appDesc = ConfigStore::get('app.footer_description', '');
        $locales = I18n::getAvailableLocales();

        // Gather stats
        $stats = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL) AS books,
                (SELECT COUNT(*) FROM autori) AS authors,
                (SELECT COUNT(*) FROM editori) AS publishers,
                (SELECT COUNT(*) FROM events WHERE is_active = 1) AS events"
        );
        $row = $stats ? $stats->fetch_assoc() : [];
        $bookCount = (int) ($row['books'] ?? 0);
        $authorCount = (int) ($row['authors'] ?? 0);
        $publisherCount = (int) ($row['publishers'] ?? 0);
        $eventCount = (int) ($row['events'] ?? 0);

        // Language list
        $langNames = [];
        foreach ($locales as $name) {
            $langNames[] = $name;
        }
        $languageList = implode(', ', $langNames);

        // Build markdown
        $lines = [];
        $lines[] = '# ' . $appName;
        $lines[] = '';

        $descPart = $appDesc !== '' ? rtrim($appDesc, '.') . '. ' : '';
        $summary = $descPart . sprintf(__('Collezione: %d libri, %d autori, %d editori.'), $bookCount, $authorCount, $publisherCount);
        $lines[] = '> ' . $summary;
        $lines[] = '';
        $lines[] = sprintf(__('Catalogo bibliotecario gestito con [Pinakes](https://github.com/fabiodalez-dev/Pinakes). Disponibile in: %s.'), $languageList);
        $lines[] = '';

        // Main Pages
        $lines[] = '## ' . __('Pagine Principali');
        $lines[] = '- [' . __('Catalogo') . '](' . $baseUrl . RouteTranslator::route('catalog') . '): ' . __('Sfoglia e cerca la collezione completa');
        $lines[] = '- [' . __('Chi Siamo') . '](' . $baseUrl . RouteTranslator::route('about') . '): ' . __('Informazioni sulla biblioteca');
        $lines[] = '- [' . __('Contatti') . '](' . $baseUrl . RouteTranslator::route('contact') . '): ' . __('Informazioni di contatto');
        if ($eventCount > 0) {
            $lines[] = '- [' . __('Eventi') . '](' . $baseUrl . RouteTranslator::route('events') . '): ' . __('Calendario eventi culturali');
        }
        $lines[] = '- [' . __('Privacy') . '](' . $baseUrl . RouteTranslator::route('privacy') . '): ' . __('Informativa sulla privacy');
        $lines[] = '';

        // Feeds & Discovery
        $lines[] = '## ' . __('Feed e Scoperta');
        $lines[] = '- [' . __('Feed RSS') . '](' . $baseUrl . '/feed.xml): ' . __('Ultime aggiunte al catalogo (RSS 2.0)');
        $lines[] = '- [Sitemap](' . $baseUrl . '/sitemap.xml): ' . __('Indice completo degli URL');
        $lines[] = '';

        // API (only if enabled)
        if (ConfigStore::get('api.enabled', '0') === '1') {
            $lines[] = '## API';
            $lines[] = '- [SRU 1.2](' . $baseUrl . '/api/sru?operation=explain): ' . __('Interoperabilità bibliotecaria (MARCXML, Dublin Core)');
            $lines[] = '';
        }

        // Optional
        $lines[] = '## ' . __('Accesso');
        $lines[] = '- [' . __('Accedi') . '](' . $baseUrl . RouteTranslator::route('login') . '): ' . __('Autenticazione utente');
        $lines[] = '- [' . __('Registrati') . '](' . $baseUrl . RouteTranslator::route('register') . '): ' . __('Registrazione nuovo utente');
        $lines[] = '';

        $response->getBody()->write(implode("\n", $lines));
        return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function resolveBaseUrl(?Request $request = null): string
    {
        $envUrl = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
        if (is_string($envUrl) && $envUrl !== '') {
            // NOTE: APP_CANONICAL_URL must include the subfolder if installed in one
            // (e.g. https://example.com/pinakes), otherwise canonical URLs will be incorrect.
            $envUrl = rtrim($envUrl, '/');
            return $envUrl;
        }

        $scheme = 'https';
        $host = 'localhost';
        $port = null;

        if ($request !== null) {
            $uri = $request->getUri();
            $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
            $host = $uri->getHost() !== '' ? $uri->getHost() : $host;
            $port = $uri->getPort();
        } else {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $forwardedProto = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
                $scheme = strtolower($forwardedProto) === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $scheme = 'https';
            } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
                $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
            } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
                $scheme = 'https';
            } else {
                $scheme = 'http';
            }

            if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $host = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0];
            } elseif (!empty($_SERVER['HTTP_HOST'])) {
                $host = (string)$_SERVER['HTTP_HOST'];
            } elseif (!empty($_SERVER['SERVER_NAME'])) {
                $host = (string)$_SERVER['SERVER_NAME'];
            }

            if (str_contains($host, ':')) {
                [$hostOnly, $portPart] = explode(':', $host, 2);
                $host = $hostOnly;
                $port = is_numeric($portPart) ? (int)$portPart : null;
            } elseif (isset($_SERVER['SERVER_PORT']) && is_numeric((string)$_SERVER['SERVER_PORT'])) {
                $port = (int)$_SERVER['SERVER_PORT'];
            }
        }

        $base = $scheme . '://' . $host;

        $defaultPorts = ['http' => 80, 'https' => 443];
        if ($port !== null && ($defaultPorts[$scheme] ?? null) !== $port) {
            $base .= ':' . $port;
        }

        // Include basePath for subfolder installations (e.g. /pinakes).
        $base .= HtmlHelper::getBasePath();

        return rtrim($base, '/');
    }
}
