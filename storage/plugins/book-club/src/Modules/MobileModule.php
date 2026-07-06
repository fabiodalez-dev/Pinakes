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

        // Group the whole bridge so HttpsEnforceMiddleware wraps it exactly like
        // MobileApiPlugin wraps /api/v1 (spec §Transport): Bearer tokens must
        // never travel over plain HTTP on non-loopback hosts.
        $group = $app->group('/api/v1/bookclub', function ($g) use ($controller, $authMw, $quotaMw): void {
            // Public discovery — no token, mirrors /api/v1/health.
            $g->get('/health', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->health($rq, $rs));

        // Authenticated surface (Bearer token of the mobile-api plugin).
            $g->get('/clubs', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->clubs($rq, $rs))->add($quotaMw())->add($authMw());
            $g->get('/clubs/{slug:[a-z0-9\-]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->clubDetail($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
            $g->get('/me/dashboard', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->dashboard($rq, $rs))->add($quotaMw())->add($authMw());
            $g->post('/clubs/{slug:[a-z0-9\-]+}/join', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->join($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
            $g->post('/clubs/{slug:[a-z0-9\-]+}/proposals', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->propose($rq, $rs, (string) $a['slug']))->add($quotaMw())->add($authMw());
            $g->post('/clubs/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}/vote', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->vote($rq, $rs, (string) $a['slug'], (int) $a['pollId']))->add($quotaMw())->add($authMw());
            $g->post('/clubs/{slug:[a-z0-9\-]+}/meetings/{meetingId:[0-9]+}/rsvp', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->rsvp($rq, $rs, (string) $a['slug'], (int) $a['meetingId']))->add($quotaMw())->add($authMw());
            $g->post('/clubs/{slug:[a-z0-9\-]+}/books/{clubBookId:[0-9]+}/progress', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->progress($rq, $rs, (string) $a['slug'], (int) $a['clubBookId']))->add($quotaMw())->add($authMw());
        });

        // Enforce HTTPS-except-loopback for the whole bridge (same as the core group).
        $group->add(new \App\Plugins\MobileApi\Support\HttpsEnforceMiddleware());
    }

    /**
     * The bridge needs the mobile-api plugin: active in the plugins table
     * AND its middleware classes loaded (PluginManager requires the main
     * file of every active plugin at bootstrap, before routes register).
     */
    /**
     * Appends the bridge paths to mobile-api's OpenAPI document (filter target
     * of the 'mobile_api.openapi' hook, dispatched via BookClubPlugin). Static:
     * the hook fires on the plugin instance, which passes its mysqli handle.
     * Mirrors registerRoutes' availability condition so the document only
     * advertises routes that are actually mounted.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extendOpenApi(array $doc, \mysqli $db): array
    {
        if (!class_exists(\App\Plugins\MobileApi\Support\AppAuthMiddleware::class)) {
            return $doc;
        }
        try {
            $stmt = $db->prepare("SELECT is_active FROM plugins WHERE name = 'mobile-api' LIMIT 1");
            if ($stmt === false) {
                return $doc;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row === null || (int) $row['is_active'] !== 1) {
                return $doc;
            }
        } catch (\Throwable) {
            return $doc;
        }

        $slug = ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9\-]+$']];
        $ok   = static fn(string $desc): array => ['200' => ['description' => $desc . ' — envelope {success, data} (bridge-specific, unlike the core {data, meta, error})']];
        $sec  = [['bearerAuth' => []]];
        $tag  = ['bookclub'];

        $paths = [
            '/bookclub/health' => ['get' => ['tags' => $tag, 'summary' => 'Bridge discovery: plugin version, app_access_enabled gate, endpoint list. Public.', 'responses' => $ok('Discovery payload')]],
            '/bookclub/clubs' => ['get' => ['tags' => $tag, 'summary' => 'Clubs directory + the caller\'s memberships.', 'security' => $sec, 'responses' => $ok('my_clubs + directory')]],
            '/bookclub/clubs/{slug}' => ['get' => ['tags' => $tag, 'summary' => 'Club detail: books, polls, meetings, my membership.', 'security' => $sec, 'parameters' => [$slug], 'responses' => $ok('Club detail')]],
            '/bookclub/me/dashboard' => ['get' => ['tags' => $tag, 'summary' => 'Personal dashboard cards across the caller\'s clubs.', 'security' => $sec, 'responses' => $ok('Dashboard cards')]],
            '/bookclub/clubs/{slug}/join' => ['post' => ['tags' => $tag, 'summary' => 'Join (or re-join) a public club; pending state for private clubs.', 'security' => $sec, 'parameters' => [$slug], 'responses' => $ok('Membership state')]],
            '/bookclub/clubs/{slug}/proposals' => ['post' => ['tags' => $tag, 'summary' => 'Propose a catalog book for the club.', 'security' => $sec, 'parameters' => [$slug], 'responses' => $ok('Created proposal')]],
            '/bookclub/clubs/{slug}/polls/{pollId}/vote' => ['post' => ['tags' => $tag, 'summary' => 'Cast votes on an open poll (simple/multiple/weighted modes).', 'security' => $sec, 'parameters' => [$slug, ['name' => 'pollId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => $ok('Updated poll')]],
            '/bookclub/clubs/{slug}/meetings/{meetingId}/rsvp' => ['post' => ['tags' => $tag, 'summary' => 'RSVP to a meeting (seat-gated on new yes).', 'security' => $sec, 'parameters' => [$slug, ['name' => 'meetingId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => $ok('Updated meeting')]],
            '/bookclub/clubs/{slug}/books/{clubBookId}/progress' => ['post' => ['tags' => $tag, 'summary' => 'Update the caller\'s reading progress for a club book.', 'security' => $sec, 'parameters' => [$slug, ['name' => 'clubBookId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => $ok('Updated progress')]],
        ];

        $doc['paths'] = array_merge(is_array($doc['paths'] ?? null) ? $doc['paths'] : [], $paths);

        return $doc;
    }

    public function mobileApiAvailable(): bool
    {
        if (!class_exists(\App\Plugins\MobileApi\Support\AppAuthMiddleware::class)
            || !class_exists(\App\Plugins\MobileApi\Support\TokenQuotaMiddleware::class)
            || !class_exists(\App\Plugins\MobileApi\Support\HttpsEnforceMiddleware::class)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare("SELECT is_active FROM plugins WHERE name = 'mobile-api' LIMIT 1");
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
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
     * per request; reading it here mirrors the wiring exactly. Public so
     * the health endpoint can advertise the gate in its payload.
     */
    public function appAccessEnabled(): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM system_settings WHERE category = 'mobile_api' AND setting_key = 'enabled' LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return (string) ($row['setting_value'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
