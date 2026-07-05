<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\ApiController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller owned by this module (Repo/BaseController are already required
// by BookClubPlugin.php). StatsRepo is shipped by the Stats module but only
// queries core bookclub_* tables, so the stats endpoint can reuse it safely.
require_once __DIR__ . '/../StatsRepo.php';
require_once __DIR__ . '/../ApiController.php';

/**
 * API module — REST read-only (plan §11).
 *
 * JSON API under /api/book-club/v1/*, authenticated with the CORE api_keys
 * mechanism (\App\Middleware\ApiKeyMiddleware — X-API-Key header or api_key
 * query parameter, gated by the global "API enabled" setting) and
 * rate-limited with \App\Middleware\RateLimitMiddleware (60 req/60s per IP,
 * shared across the module's endpoints via a single action key).
 *
 * Endpoints (all GET, all read-only):
 *  - /api/book-club/v1/clubs                 active public+private clubs
 *  - /api/book-club/v1/clubs/{slug}          club detail (books by state,
 *                                            upcoming meetings, open polls)
 *  - /api/book-club/v1/clubs/{slug}/stats    headline stats
 *  - /api/book-club/v1/openapi.json          OpenAPI 3.1 document
 *
 * Privacy: hidden and invite clubs are NEVER exposed; member data is limited
 * to member_count (no names, no emails); meeting video_url is excluded.
 * Per-club enablement: the clubs list only contains clubs with this module
 * enabled, and the detail endpoints 404 when it is disabled for the club.
 * The openapi.json document has no club context and is always served while
 * the module's routes are registered.
 *
 * No tables of its own.
 */
class ApiModule extends AbstractModule
{
    public function slug(): string
    {
        return 'api';
    }

    public function label(): string
    {
        return __('API REST');
    }

    public function description(): string
    {
        return __('API JSON in sola lettura per app e integrazioni esterne (richiede una chiave API)');
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new ApiController($this->db, $this->repo, $this);
        // Auth first (outermost), then rate limit — same layering as the
        // core admin API routes in app/Routes/web.php. One shared action
        // key: the 60 req/60s budget covers the whole module per client IP.
        $apiKeyMw = new \App\Middleware\ApiKeyMiddleware($this->db);
        $rateMw = new \App\Middleware\RateLimitMiddleware(60, 60, 'bookclub_api');

        $app->get(
            '/api/book-club/v1/openapi.json',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->openapi($rq, $rs)
        )->add($rateMw)->add($apiKeyMw);
        $app->get(
            '/api/book-club/v1/clubs',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->listClubs($rq, $rs)
        )->add($rateMw)->add($apiKeyMw);
        $app->get(
            '/api/book-club/v1/clubs/{slug:[a-z0-9\-]+}',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->showClub($rq, $rs, (string) $a['slug'])
        )->add($rateMw)->add($apiKeyMw);
        $app->get(
            '/api/book-club/v1/clubs/{slug:[a-z0-9\-]+}/stats',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->clubStats($rq, $rs, (string) $a['slug'])
        )->add($rateMw)->add($apiKeyMw);
    }
}
