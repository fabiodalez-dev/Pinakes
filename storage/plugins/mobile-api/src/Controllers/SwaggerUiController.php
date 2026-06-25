<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Support\ConfigStore;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves the Swagger UI page at GET /api/v1/docs.
 *
 * Asset strategy: **self-hosted only, never a CDN**. The Swagger UI dist files
 * are vendored in public/assets/swagger-ui/ and shipped in the release ZIP, so
 * the page works offline / behind an egress firewall. To refresh them, run
 * `npm pack swagger-ui-dist@5.18.2` and copy swagger-ui-bundle.js +
 * swagger-ui.css into public/assets/swagger-ui/ (current vendored version: 5.18.2).
 *
 * Public endpoint — no bearer token required.
 */
final class SwaggerUiController
{
    public function page(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        try {
            $baseUrl       = $this->baseUrl($request);
            $openApiUrl    = rtrim($baseUrl, '/') . '/api/v1/openapi.json';
            $assetsBaseUrl = $this->assetsBaseUrl($baseUrl);
            $html          = $this->buildHtml($openApiUrl, $assetsBaseUrl);

            $response->getBody()->write($html);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'public, max-age=300')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] swagger ui page failed: ' . $e->getMessage());

            $response->getBody()->write('<html><body><h1>API Docs temporarily unavailable</h1></body></html>');

            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildHtml(string $openApiUrl, string $assetsBaseUrl): string
    {
        $title    = htmlspecialchars(
            (string) ConfigStore::get('app.name', 'Pinakes') . ' — Mobile API docs',
            ENT_QUOTES,
            'UTF-8'
        );
        $cssUrl   = $assetsBaseUrl . '/swagger-ui.css';
        $jsUrl    = $assetsBaseUrl . '/swagger-ui-bundle.js';
        // openApiUrl is a URL built from trusted server state, escape for HTML context only.
        $docUrl   = htmlspecialchars($openApiUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <link rel="stylesheet" type="text/css" href="{$cssUrl}">
  <style>
    html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
    *, *:before, *:after { box-sizing: inherit; }
    body { margin: 0; background: #fafafa; }
    .swagger-ui .topbar { background-color: #1e293b; }
    .swagger-ui .topbar .download-url-wrapper .select-label { color: #fff; }
    .pinakes-header {
      background: #1e293b;
      color: #fff;
      padding: 10px 20px;
      font-family: sans-serif;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .pinakes-header a { color: #818cf8; text-decoration: none; }
    .pinakes-header a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="pinakes-header">
    <strong>Pinakes Mobile API</strong>
    <span style="opacity:.5">|</span>
    <a href="../health">GET /api/v1/health</a>
    <span style="opacity:.5">|</span>
    <a href="../openapi.json">openapi.json</a>
  </div>
  <div id="swagger-ui"></div>
  <script src="{$jsUrl}"></script>
  <script>
  (function () {
    'use strict';
    window.onload = function () {
      var ui = SwaggerUIBundle({
        url: "{$docUrl}",
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIBundle.SwaggerUIStandalonePreset
        ],
        layout: 'BaseLayout',
        persistAuthorization: true,
        requestInterceptor: function (request) {
          // Avoid sending cookies/session on "Try it out" calls: the API is
          // bearer-token only, so cookies would just leak the web session.
          request.credentials = 'omit';
          return request;
        }
      });
      window._swaggerUi = ui;
    };
  })();
  </script>
</body>
</html>
HTML;
    }

    /**
     * Base URL for the locally-vendored Swagger UI assets.
     *
     * Always the local `public/assets/swagger-ui/` copy — never a CDN.
     * $siteBaseUrl already includes BASE_PATH (from baseUrl()).
     */
    private function assetsBaseUrl(string $siteBaseUrl): string
    {
        return rtrim($siteBaseUrl, '/') . '/assets/swagger-ui';
    }

    private function baseUrl(ServerRequestInterface $request): string
    {
        $uri  = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && $port !== 80 && $port !== 443) {
            $base .= ':' . $port;
        }

        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : '';

        return rtrim($base . $basePath, '/');
    }
}
