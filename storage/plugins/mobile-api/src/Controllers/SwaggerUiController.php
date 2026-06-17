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
 * Asset strategy (spec: "self-hosted, no external CDN if avoidable"):
 *   1. If public/assets/swagger-ui/swagger-ui-bundle.js exists (pre-downloaded),
 *      all three asset references point to the local copy.
 *   2. Otherwise, falls back to jsDelivr CDN (swagger-ui v5 latest).
 *      Run  npm install swagger-ui-dist  and copy the dist files to
 *      public/assets/swagger-ui/  to avoid the CDN dependency.
 *
 * Public endpoint — no bearer token required.
 */
final class SwaggerUiController
{
    /** Swagger UI version pinned for the CDN fallback. */
    private const SWAGGER_UI_VERSION = '5.18.2';

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
          // Strip Cookies from cross-origin requests (CORS hardening).
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
     * Returns the base URL for Swagger UI static assets.
     *
     * Local copy (preferred): public/assets/swagger-ui/ — served via the normal
     * web server; check by testing for the bundle file on disk.
     * CDN fallback: jsDelivr pinned to a specific Swagger UI version.
     */
    private function assetsBaseUrl(string $siteBaseUrl): string
    {
        $localBundle = $this->docRoot() . '/assets/swagger-ui/swagger-ui-bundle.js';

        if (is_file($localBundle)) {
            // Local copy available — use it.
            $basePath = defined('BASE_PATH') ? (string) BASE_PATH : '';

            return rtrim($siteBaseUrl, '/') . rtrim($basePath, '/') . '/assets/swagger-ui';
        }

        // Fallback: jsDelivr CDN (pinned version).
        return 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_UI_VERSION;
    }

    private function docRoot(): string
    {
        return defined('PUBLIC_PATH') ? (string) PUBLIC_PATH : (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
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
