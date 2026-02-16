<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\HtmlHelper;
use App\Support\RouteTranslator;
use App\Support\SitemapGenerator;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
        ];

        $response->getBody()->write(implode("\n", $lines) . "\n");
        return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function resolveBaseUrl(?Request $request = null): string
    {
        $envUrl = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
        if (is_string($envUrl) && $envUrl !== '') {
            return rtrim($envUrl, '/');
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

        // Include basePath for subfolder installations (e.g. /pinakes)
        $base .= \App\Support\HtmlHelper::getBasePath();

        return rtrim($base, '/');
    }
}
