<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\MobileApiController;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/../MobileApiController.php';

/**
 * Mobile bridge: exposes the Book Club to the Pinakes Android/iOS app
 * through the mobile-api plugin's bearer-token surface.
 *
 * Routes live under /api/v1/bookclub/* and are guarded by the SAME
 * AppAuthMiddleware + TokenQuotaMiddleware the mobile-api plugin uses, so
 * the app authenticates once and reuses its token. AppAuthMiddleware
 * mirrors the token identity into $_SESSION['user'] for the request
 * duration, which means every BaseController permission helper
 * (membership, canManage, can, Permissions matrix) works unchanged here.
 *
 * The bridge registers its routes ONLY when the mobile-api plugin is
 * active (its classes are loaded at bootstrap by PluginManager): without
 * mobile-api the module is inert and /api/v1/bookclub/* simply 404s.
 *
 * Discovery for the app: GET /api/v1/bookclub/health (no token) returns
 * {success, enabled, version} — the app probes it after login to decide
 * whether to show the Book Club section.
 */
final class MobileModule extends AbstractModule
{
    public function slug(): string
    {
        return 'mobile';
    }

    public function label(): string
    {
        return __('App mobile');
    }

    public function description(): string
    {
        return __('Espone il club nell\'app Pinakes (API /api/v1/bookclub con token dell\'app)');
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    public function registerRoutes($app): void
    {
        if (!$this->mobileApiAvailable()) {
            return;
        }

        $db = $this->db;
        $controller = new MobileApiController($db, $this->repo, $this);

        // Same wiring as MobileApiPlugin::registerRoutes: LIFO middleware —
        // auth added last so it runs first and the quota sees the token id.
        $appAccessEnabled = $this->appAccessEnabled();
        $authMw = static fn(): \App\Plugins\MobileApi\Support\AppAuthMiddleware =>
            new \App\Plugins\MobileApi\Support\AppAuthMiddleware($db, $appAccessEnabled);
        $quotaMw = static fn(): \App\Plugins\MobileApi\Support\TokenQuotaMiddleware =>
            new \App\Plugins\MobileApi\Support\TokenQuotaMiddleware();

        // Public discovery — no token, mirrors /api/v1/health.
        $app->get('/api/v1/bookclub/health', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->health($rq, $rs));

        // Authenticated surface (Bearer token of the mobile-api plugin).
        $app->get('/api/v1/bookclub/clubs', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->clubs($rq, $rs))->add($quotaMw())->add($authMw());
        $app->get('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->clubDetail($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
        $app->get('/api/v1/bookclub/me/dashboard', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->dashboard($rq, $rs))->add($quotaMw())->add($authMw());
        $app->post('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}/join', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->join($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
        $app->post('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}/proposals', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->propose($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
        $app->post('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}/vote', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->vote($rq, $rs, (string) $a['slug'], (int) $a['pollId']))->add($quotaMw())->add($authMw());
        $app->post('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}/meetings/{meetingId:[0-9]+}/rsvp', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->rsvp($rq, $rs, (string) $a['slug'], (int) $a['meetingId']))->add($quotaMw())->add($authMw());
        $app->post('/api/v1/bookclub/clubs/{slug:[a-z0-9\-]+}/books/{clubBookId:[0-9]+}/progress', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->progress($rq, $rs, (string) $a['slug'], (int) $a['clubBookId']))->add($quotaMw())->add($authMw());
    }

    /**
     * The bridge needs the mobile-api plugin: active in the plugins table
     * AND its middleware classes loaded (PluginManager requires the main
     * file of every active plugin at bootstrap, before routes register).
     */
    public function mobileApiAvailable(): bool
    {
        if (!class_exists(\App\Plugins\MobileApi\Support\AppAuthMiddleware::class)
            || !class_exists(\App\Plugins\MobileApi\Support\TokenQuotaMiddleware::class)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("SELECT is_active FROM plugins WHERE name = 'mobile-api' LIMIT 1");
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $row = $stmt->get_result()?->fetch_assoc();
            $stmt->close();
            return (int) ($row['is_active'] ?? 0) === 1;
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:mobile] mobileApiAvailable check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Same gate the mobile-api plugin applies to its own surface
     * (system_settings mobile_api.enabled). AppAuthMiddleware re-checks it
     * per request; reading it here only mirrors the wiring exactly.
     */
    private function appAccessEnabled(): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM system_settings WHERE category = 'mobile_api' AND setting_key = 'enabled' LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $row = $stmt->get_result()?->fetch_assoc();
            $stmt->close();
            return (string) ($row['setting_value'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
