<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi;

use App\Models\SettingsRepository;
use App\Middleware\RateLimitMiddleware;
use App\Plugins\MobileApi\Controllers\ActionsController;
use App\Plugins\MobileApi\Controllers\AuthController;
use App\Plugins\MobileApi\Controllers\CatalogController;
use App\Plugins\MobileApi\Controllers\HealthController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\HttpsEnforceMiddleware;
use App\Plugins\MobileApi\Support\TokenQuotaMiddleware;
use App\Support\HookManager;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/src/Support/ResponseEnvelope.php';
require_once __DIR__ . '/src/Support/CursorCodec.php';
require_once __DIR__ . '/src/Support/JsonBody.php';
require_once __DIR__ . '/src/Support/ProxyTrust.php';
require_once __DIR__ . '/src/Support/HttpsEnforceMiddleware.php';
require_once __DIR__ . '/src/Support/TokenService.php';
require_once __DIR__ . '/src/Support/AppAuthMiddleware.php';
require_once __DIR__ . '/src/Support/TokenQuotaMiddleware.php';
require_once __DIR__ . '/src/Push/PushPayload.php';
require_once __DIR__ . '/src/Push/PushResult.php';
require_once __DIR__ . '/src/Push/PushProvider.php';
require_once __DIR__ . '/src/Push/VapidSigner.php';
require_once __DIR__ . '/src/Push/NullProvider.php';
require_once __DIR__ . '/src/Push/UnifiedPushProvider.php';
require_once __DIR__ . '/src/Push/FcmProvider.php';
require_once __DIR__ . '/src/Push/PushDispatcher.php';
require_once __DIR__ . '/src/Controllers/HealthController.php';
require_once __DIR__ . '/src/Controllers/AuthController.php';
require_once __DIR__ . '/src/Controllers/CatalogController.php';
require_once __DIR__ . '/src/Controllers/ActionsController.php';
require_once __DIR__ . '/src/Controllers/PushController.php';
require_once __DIR__ . '/src/Controllers/OpenApiController.php';
require_once __DIR__ . '/src/Controllers/SwaggerUiController.php';
require_once __DIR__ . '/src/Controllers/ReviewsController.php';

/**
 * Mobile API plugin.
 *
 * Bundled, default-inactive plugin that exposes a versioned REST/JSON API under
 * /api/v1 for the Pinakes mobile companion app. It wires:
 *   - the 5 data-model tables (mobile_app_tokens, mobile_push_subscriptions,
 *     mobile_push_prefs, mobile_availability_watchers, mobile_push_log) via
 *     ensureSchema();
 *   - the dedicated app-access gate setting `mobile_api.enabled` (default '0');
 *   - the /api/v1 route group with HTTPS-except-loopback enforcement;
 *   - the public GET /api/v1/health discovery endpoint;
 *   - token auth (login/register/logout/devices), catalog search + book detail,
 *     loans/reservations, wishlist, profile, contact messages, in-app
 *     notifications, push (UnifiedPush + VAPID) and prefs;
 *   - the OpenAPI 3.1 document + Swagger UI + the admin settings page.
 *
 * See STATUS.md for what is complete vs. partial (notably Web Push payload
 * encryption and the FCM provider).
 */
class MobileApiPlugin
{
    /** Plugin slug (matches directory + plugins.name). */
    public const SLUG = 'mobile-api';

    /** Settings category/key for the dedicated app-access gate. */
    private const ENABLE_CATEGORY = 'mobile_api';
    private const ENABLE_KEY      = 'enabled';

    /**
     * Push provider settings (spec §Push config). All optional: when none are
     * present the dispatcher selects NullProvider and the app falls back to the
     * in-app feed (NEVER hard-fail).
     *   - push_provider     : 'unifiedpush' | 'fcm' (selects the impl).
     *   - push_vapid_subject: optional mailto:/https: subject advertised to UP distributors.
     *   - push_fcm_credentials: raw FCM service-account JSON (used only by FcmProvider stub).
     */
    private const PUSH_PROVIDER_KEY        = 'push_provider';
    private const PUSH_VAPID_SUBJECT_KEY   = 'push_vapid_subject';
    private const PUSH_FCM_CREDENTIALS_KEY = 'push_fcm_credentials';
    // Trusted reverse-proxy peers (IP / CIDR, comma-separated). When the app-facing
    // request arrives over a proxy that terminates TLS, X-Forwarded-Proto: https is only
    // honoured from these peers (see ProxyTrust). Configurable here so operators behind a
    // NAS/reverse proxy don't have to edit .env to stop the /api/v1 HTTPS 426.
    private const TRUSTED_PROXIES_KEY      = 'trusted_proxies';
    // VAPID keypair (RFC 8292), generated once per instance. Public key is shared
    // with the app (applicationServerKey) and sent as the `k=` value; the private
    // key is stored encrypted at rest (SettingsEncryption).
    private const PUSH_VAPID_PUBLIC_KEY     = 'push_vapid_public_key';
    private const PUSH_VAPID_PRIVATE_KEY    = 'push_vapid_private_key';

    private \mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    public function __construct(\mysqli $db, HookManager $hookManager)
    {
        $this->db          = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    // ─── Lifecycle ──────────────────────────────────────────────────────────

    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[MobileApi] Schema activation failed for: ' . implode(', ', $result['failed'])
            );
        }

        $this->db->begin_transaction();
        try {
            // ONLY registerHookInDb here — never doAction()/applyFilters() in
            // onActivate (it would trigger loadHooks() before the guard and
            // double-register routes → FastRoute "Cannot register two routes").
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 10);
            // Push dispatch piggy-backs on the existing loan/notification cron:
            // MaintenanceService fires this action right after sending the email
            // reminders, on the SAME pass (see app/Support/MaintenanceService.php).
            // ONLY registerHookInDb here (no doAction in onActivate).
            $this->registerHookInDb('mobile_api.dispatch_push', 'dispatchPush', 20);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Ensure the VAPID keypair exists (idempotent, outside the tx). Best-effort:
        // never let a keygen failure undo a successful activation.
        try {
            $this->ensureVapidKeys();
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] VAPID keygen at activate failed: ' . $e->getMessage());
        }
    }

    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[MobileApi] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }

        // App access is OFF until the manager explicitly enables it.
        $this->setDefaultEnabledFlag();

        // Generate the VAPID keypair now so /health can advertise the public key
        // (applicationServerKey) without lazily doing crypto on a public request.
        // Best-effort: a keygen failure must never abort install.
        try {
            $this->ensureVapidKeys();
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] VAPID keygen at install failed: ' . $e->getMessage());
        }
    }

    public function onUninstall(): void
    {
        // Keep the mobile_* tables across uninstall so device tokens / push
        // prefs survive a reinstall (consistent with OAI keeping its tracking
        // tables). The hooks are removed via deleteHooksFromDb() on deactivate.
    }

    // ─── Schema ─────────────────────────────────────────────────────────────

    /**
     * Idempotent schema creation. Called from BOTH onActivate() and onInstall()
     * (CLAUDE.md "Plugin Schema Rule — ABSOLUTE"): upgrades never re-call
     * onActivate() for already-active plugins, so tables defined only in
     * onInstall() would silently go missing after an upgrade.
     *
     * @return array{created:list<string>, failed:list<string>}
     */
    /**
     * Tables this plugin's ensureSchema() always creates. Declared so
     * PluginManager's boot-time self-heal re-runs ensureSchema when any is
     * missing on an already-active plugin (a partial/aborted upgrade). One
     * cheap read-only probe; DDL only runs when a table is actually absent.
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return ['mobile_app_tokens', 'mobile_availability_watchers', 'mobile_push_log', 'mobile_push_prefs', 'mobile_push_subscriptions'];
    }

    public function ensureSchema(): array
    {
        $created = [];
        $failed  = [];

        $tables = [
            // One revocable bearer token per device. Only the sha256 hash of the
            // plaintext token is ever stored (spec §Auth model).
            'mobile_app_tokens' => "CREATE TABLE IF NOT EXISTS mobile_app_tokens (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT          NOT NULL,
                token_hash   CHAR(64)     NOT NULL,
                device_name  VARCHAR(190) NULL,
                device_id    VARCHAR(190) NULL,
                platform     VARCHAR(32)  NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME     NULL,
                revoked_at   DATETIME     NULL,
                expires_at   DATETIME     NULL,
                UNIQUE KEY uq_token_hash (token_hash),
                KEY idx_user       (user_id),
                KEY idx_user_active (user_id, revoked_at),
                CONSTRAINT fk_mobile_token_user
                    FOREIGN KEY (user_id) REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Push endpoint registered for a device (UnifiedPush WebPush or FCM).
            'mobile_push_subscriptions' => "CREATE TABLE IF NOT EXISTS mobile_push_subscriptions (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                user_id         INT          NOT NULL,
                token_id        INT          NULL,
                provider        ENUM('unifiedpush','fcm') NOT NULL DEFAULT 'unifiedpush',
                endpoint        VARCHAR(500) NULL,
                registration_id VARCHAR(500) NULL,
                public_key      VARCHAR(255) NULL,
                auth            VARCHAR(255) NULL,
                created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_ok_at      DATETIME     NULL,
                failure_count   INT          NOT NULL DEFAULT 0,
                KEY idx_user     (user_id),
                KEY idx_token    (token_id),
                CONSTRAINT fk_mobile_push_user
                    FOREIGN KEY (user_id) REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_push_token
                    FOREIGN KEY (token_id) REFERENCES mobile_app_tokens (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Per-user push preferences (per-type toggles + quiet hours).
            'mobile_push_prefs' => "CREATE TABLE IF NOT EXISTS mobile_push_prefs (
                user_id           INT       NOT NULL PRIMARY KEY,
                loan_due          TINYINT(1) NOT NULL DEFAULT 1,
                loan_overdue      TINYINT(1) NOT NULL DEFAULT 1,
                reservation_ready TINYINT(1) NOT NULL DEFAULT 1,
                new_message       TINYINT(1) NOT NULL DEFAULT 1,
                book_available    TINYINT(1) NOT NULL DEFAULT 1,
                quiet_start       TIME      NULL,
                quiet_end         TIME      NULL,
                CONSTRAINT fk_mobile_prefs_user
                    FOREIGN KEY (user_id) REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Who to notify when a title becomes loanable again.
            'mobile_availability_watchers' => "CREATE TABLE IF NOT EXISTS mobile_availability_watchers (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT       NOT NULL,
                libro_id   INT       NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_book (user_id, libro_id),
                KEY idx_libro (libro_id),
                CONSTRAINT fk_mobile_watch_user
                    FOREIGN KEY (user_id) REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_watch_book
                    FOREIGN KEY (libro_id) REFERENCES libri (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Push dedup ledger: one row per (user, event-key) ensures the cron
            // dispatcher pushes each event at most once (mark-then-send parity with
            // the core email reminders). No FK on user_id by design — keeping a
            // dedup record after a user is deleted is harmless and avoids coupling
            // the ledger lifecycle to the utenti table; rows are pruned by age.
            'mobile_push_log' => "CREATE TABLE IF NOT EXISTS mobile_push_log (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT          NOT NULL,
                event_key  VARCHAR(191) NOT NULL,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_event (user_id, event_key),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $name => $ddl) {
            if ($this->db->query($ddl) === true) {
                $created[] = $name;
            } else {
                SecureLogger::error("[MobileApi] CREATE TABLE {$name} failed: " . $this->db->error);
                $failed[] = $name;
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    // ─── Hook registration ──────────────────────────────────────────────────

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[MobileApi] pluginId not set; cannot register hook ' . $hookName);
            return;
        }

        $del = $this->db->prepare(
            'DELETE FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?'
        );
        if ($del !== false) {
            $del->bind_param('iss', $this->pluginId, $hookName, $method);
            $del->execute();
            $del->close();
        }

        $stmt = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[MobileApi] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }

        // Must be the GLOBAL proxy class name — PluginManager resolves hook
        // callbacks through getPluginClassName('mobile-api') === 'MobileApiPlugin'.
        $callbackClass = 'MobileApiPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[MobileApi] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    // ─── Routes ─────────────────────────────────────────────────────────────

    /**
     * Registers the /api/v1 route group. Invoked by the core dispatcher via the
     * 'app.routes.register' hook (app/Routes/web.php) with the Slim $app.
     */
    public function registerRoutes(\Slim\App $app): void
    {
        $plugin = $this;
        $db     = $this->db;
        $hookManager = $this->hookManager;
        $appAccessEnabled = $this->isAppAccessEnabled();

        // Shared per-token quota + bearer auth for the authenticated surface.
        // (Slim runs route middleware LIFO; AppAuthMiddleware is added LAST so it
        // runs FIRST and the quota — added before it — wraps the inner handler and
        // sees the resolved token id attribute.)
        $authMw  = static fn (): AppAuthMiddleware => new AppAuthMiddleware($db, $appAccessEnabled);
        $quotaMw = static fn (): TokenQuotaMiddleware => new TokenQuotaMiddleware();

        $group = $app->group('/api/v1', function (\Slim\Routing\RouteCollectorProxy $group) use ($plugin, $db, $authMw, $quotaMw, $hookManager): void {
            // ── OpenAPI document + Swagger UI (public, no token) ──────────────
            // Capture $hookManager via use(): inside a Slim route closure $this is
            // bound to the container, not the plugin, so $this->hookManager would be
            // null and the mobile_api.openapi filter (book-club bridge paths) would
            // never run.
            $group->get('/openapi.json', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($hookManager): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\OpenApiController($hookManager))->document($request, $response);
            });

            $group->get('/docs', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\SwaggerUiController())->page($request, $response);
            });

            // Public discovery — no token. Reachable over http on loopback so the
            // app can finish onboarding in dev; it advertises https status itself.
            $group->get('/health', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($plugin): ResponseInterface {
                return $plugin->healthAction($request, $response);
            });

            // ── Public auth endpoints ──────────────────────────────────────
            // Strong throttle on login (anti brute-force, spec §Rate limiting):
            // 10 attempts / 5 min per IP, action-keyed so localized paths can't
            // bypass it (there are none here, but keeps parity with the web rule).
            $group->post('/auth/login', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->login($request, $response);
            })->add(new RateLimitMiddleware(10, 300, 'mobile_login'));

            $group->post('/auth/register', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->register($request, $response);
            })->add(new RateLimitMiddleware(5, 3600, 'mobile_register'));

            $group->post('/auth/forgot-password', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->forgotPassword($request, $response);
            })->add(new RateLimitMiddleware(5, 3600, 'mobile_forgot'));

            // ── Authenticated auth endpoints (Bearer token) ────────────────
            $group->post('/auth/logout', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->logout($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/devices', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->listDevices($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->delete('/me/devices/{id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new AuthController($db))->revokeDevice($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            // ── Catalog (read) ─────────────────────────────────────────────
            // All catalog reads are behind the bearer token, so an
            // unauthenticated request is rejected (401) before reaching the
            // controller — which means private mode is honored implicitly: only
            // a resolved, active user ever sees catalog data. The controller
            // still applies `AND deleted_at IS NULL` on every libri query and
            // scopes personal history to the token-resolved user only.
            $group->get('/catalog/search', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new CatalogController($db))->search($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/catalog/books/{id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new CatalogController($db))->bookDetail($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            $group->get('/catalog/books/{id:[0-9]+}/availability', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new CatalogController($db))->bookAvailability($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            // ── Book reviews (stars + text) — moderated via the core recensioni
            //    table; PUT is an idempotent upsert; DELETE is user-scoped. ──
            $group->get('/catalog/books/{id:[0-9]+}/reviews', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\ReviewsController($db))->bookReviews($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            $group->put('/catalog/books/{id:[0-9]+}/reviews', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\ReviewsController($db))->submitReview($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            $group->delete('/catalog/books/{id:[0-9]+}/reviews', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\ReviewsController($db))->deleteReview($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/reviews', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\ReviewsController($db))->myReviews($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/catalog/genres', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new CatalogController($db))->genres($request, $response);
            })->add($quotaMw())->add($authMw());

            // ── User actions (loans / reservations / wishlist / profile / msg) ──
            // Every handler is bearer-authenticated and strictly scoped to the
            // token-resolved user; loan/reservation overlap + availability reuse
            // the canonical core logic (ActionsController delegates, never
            // reimplements). All `libri` reads carry `AND deleted_at IS NULL`.
            $group->get('/me/loans', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->myLoans($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/reservations', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->myReservations($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->post('/reservations', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->requestReservation($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->delete('/reservations/{id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->cancelReservation($request, $response, (int) $args['id']);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/wishlist', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->getWishlist($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->post('/me/wishlist', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->addWishlist($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->delete('/me/wishlist/{book_id:[0-9]+}', function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->removeWishlist($request, $response, (int) $args['book_id']);
            })->add($quotaMw())->add($authMw());

            $group->get('/me', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->getProfile($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->patch('/me', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->updateProfile($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->post('/me/password', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->changePassword($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->post('/messages', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->sendMessage($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/notifications', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new ActionsController($db))->notifications($request, $response);
            })->add($quotaMw())->add($authMw());

            // ── Push (subscribe + per-type prefs / quiet hours) ────────────────
            // Best-effort: subscribing is always accepted; if the instance has no
            // provider credentials the dispatcher uses NullProvider and the user
            // falls back to the in-app feed (GET /me/notifications).
            $group->post('/me/push/subscribe', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\PushController($db))->subscribe($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->delete('/me/push/subscribe', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\PushController($db))->unsubscribe($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->get('/me/push/prefs', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\PushController($db))->getPrefs($request, $response);
            })->add($quotaMw())->add($authMw());

            $group->put('/me/push/prefs', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($db): ResponseInterface {
                return (new \App\Plugins\MobileApi\Controllers\PushController($db))->putPrefs($request, $response);
            })->add($quotaMw())->add($authMw());
        });

        // Enforce HTTPS-except-loopback for the entire group (spec §Transport).
        $group->add(new HttpsEnforceMiddleware());
    }

    // ─── Endpoint handlers ──────────────────────────────────────────────────

    public function healthAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Pass the app-access gate read via SettingsRepository (ConfigStore does
        // not surface plugin-defined categories like `mobile_api`, so a direct
        // ConfigStore::get there would always report the default '0'). Also pass
        // the VAPID public key so the app can use it as applicationServerKey.
        // ensureVapidKeys() is idempotent: it generates the keypair on first need
        // (covers already-active installs whose onActivate didn't re-run) and is a
        // cached read thereafter. Best-effort: never let it break discovery.
        $vapidPublic = '';
        try {
            $keys = $this->ensureVapidKeys();
            $vapidPublic = (string) ($keys['public'] ?? '');
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] VAPID key access in health failed: ' . $e->getMessage());
        }

        return (new HealthController($this->db))->index(
            $request,
            $response,
            $this->isAppAccessEnabled(),
            $vapidPublic
        );
    }

    // ─── Settings (app-access gate) ─────────────────────────────────────────

    public function isAppAccessEnabled(): bool
    {
        return $this->repo()->get(self::ENABLE_CATEGORY, self::ENABLE_KEY, '0') === '1';
    }

    /**
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        $repo = $this->repo();

        return [
            'enabled'              => $repo->get(self::ENABLE_CATEGORY, self::ENABLE_KEY, '0') ?? '0',
            'push_provider'        => $repo->get(self::ENABLE_CATEGORY, self::PUSH_PROVIDER_KEY, 'unifiedpush') ?? 'unifiedpush',
            'push_vapid_subject'   => $repo->get(self::ENABLE_CATEGORY, self::PUSH_VAPID_SUBJECT_KEY, '') ?? '',
            'trusted_proxies'      => $repo->get(self::ENABLE_CATEGORY, self::TRUSTED_PROXIES_KEY, '') ?? '',
            'push_fcm_credentials' => $this->fcmCredentials(),
            'push_vapid_public_key' => $repo->get(self::ENABLE_CATEGORY, self::PUSH_VAPID_PUBLIC_KEY, '') ?? '',
        ];
    }

    /**
     * The FCM service-account JSON, decrypted from its at-rest encrypted form.
     * Empty string when unset. Tolerates a legacy plaintext value (decrypt returns
     * null on a non-ciphertext input → fall back to the raw stored string).
     */
    private function fcmCredentials(): string
    {
        $stored = (string) ($this->repo()->get(self::ENABLE_CATEGORY, self::PUSH_FCM_CREDENTIALS_KEY, '') ?? '');
        if ($stored === '') {
            return '';
        }
        $plain = \App\Support\SettingsEncryption::decrypt($stored);

        return ($plain !== null && $plain !== '') ? $plain : $stored;
    }

    /**
     * Return the instance VAPID keypair, generating + persisting it on first use.
     * Public key is stored/returned plain (it is meant to be shared); the private
     * key is stored encrypted at rest and returned decrypted (PEM).
     *
     * @return array{public:string, private:string}|null Null if EC keygen is
     *         unavailable — callers then send without VAPID (advisory).
     */
    public function ensureVapidKeys(): ?array
    {
        $repo    = $this->repo();
        $public  = (string) ($repo->get(self::ENABLE_CATEGORY, self::PUSH_VAPID_PUBLIC_KEY, '') ?? '');
        $privEnc = (string) ($repo->get(self::ENABLE_CATEGORY, self::PUSH_VAPID_PRIVATE_KEY, '') ?? '');

        if ($public !== '' && $privEnc !== '') {
            $privPem = \App\Support\SettingsEncryption::decrypt($privEnc);
            if (is_string($privPem) && $privPem !== '') {
                return ['public' => $public, 'private' => $privPem];
            }
        }

        $pair = \App\Plugins\MobileApi\Push\VapidSigner::generateKeyPair();
        if ($pair === null) {
            return null;
        }

        $repo->set(self::ENABLE_CATEGORY, self::PUSH_VAPID_PUBLIC_KEY, $pair['public']);
        $repo->set(self::ENABLE_CATEGORY, self::PUSH_VAPID_PRIVATE_KEY, \App\Support\SettingsEncryption::encrypt($pair['private']));

        return $pair;
    }

    /**
     * Persist settings from the admin UI: the app-access gate plus the optional
     * push provider credentials. Absent push credentials are fine — the dispatcher
     * degrades to NullProvider + in-app feed (NEVER hard-fail).
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(array $settings): bool
    {
        try {
            $repo = $this->repo();

            if (array_key_exists('enabled', $settings)) {
                $value = (string) $settings['enabled'] === '1' ? '1' : '0';
                $repo->set(self::ENABLE_CATEGORY, self::ENABLE_KEY, $value);
                // Keep ConfigStore's cache coherent for in-request reads.
                \App\Support\ConfigStore::set(self::ENABLE_CATEGORY . '.' . self::ENABLE_KEY, $value);
            }

            if (array_key_exists('trusted_proxies', $settings)) {
                // Keep only valid IPs / CIDRs so an invalid entry can't poison ProxyTrust's
                // parser; store as a normalised comma list. ConfigStore::set keeps the value
                // readable in the same request (ProxyTrust reads it via ConfigStore::get).
                $clean = [];
                foreach (explode(',', (string) $settings['trusted_proxies']) as $entry) {
                    $entry = trim($entry);
                    if ($entry === '') {
                        continue;
                    }
                    if (strpos($entry, '/') !== false) {
                        [$net, $len] = explode('/', $entry, 2);
                        if (filter_var($net, FILTER_VALIDATE_IP) !== false && ctype_digit($len)) {
                            $clean[] = $entry;
                        }
                    } elseif (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                        $clean[] = $entry;
                    }
                }
                $value = implode(', ', $clean);
                $repo->set(self::ENABLE_CATEGORY, self::TRUSTED_PROXIES_KEY, $value);
                \App\Support\ConfigStore::set(self::ENABLE_CATEGORY . '.' . self::TRUSTED_PROXIES_KEY, $value);
            }

            if (array_key_exists('push_provider', $settings)) {
                $provider = (string) $settings['push_provider'];
                if (!in_array($provider, ['unifiedpush', 'fcm'], true)) {
                    $provider = 'unifiedpush';
                }
                $repo->set(self::ENABLE_CATEGORY, self::PUSH_PROVIDER_KEY, $provider);
            }

            if (array_key_exists('push_vapid_subject', $settings)) {
                $repo->set(self::ENABLE_CATEGORY, self::PUSH_VAPID_SUBJECT_KEY, trim((string) $settings['push_vapid_subject']));
            }

            if (array_key_exists('push_fcm_credentials', $settings)) {
                // FCM service-account JSON contains a private key → encrypt at rest
                // (same treatment as the VAPID private key), so a DB dump / stray
                // read can't expose the Google credential.
                $fcm = trim((string) $settings['push_fcm_credentials']);
                $repo->set(
                    self::ENABLE_CATEGORY,
                    self::PUSH_FCM_CREDENTIALS_KEY,
                    $fcm === '' ? '' : \App\Support\SettingsEncryption::encrypt($fcm)
                );
            }

            return true;
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] saveSettings failed: ' . $e->getMessage());
            return false;
        }
    }

    // ─── Push dispatch (cron hook) ──────────────────────────────────────────────

    /**
     * Invoked by the core loan/notification cron via the 'mobile_api.dispatch_push'
     * action (MaintenanceService fires it right after the email reminders, on the
     * same pass). Pushes the same event taxonomy (loan due / overdue / reservation
     * ready / book back available) to subscribed devices, gated by each user's
     * prefs and quiet hours. Best-effort: NEVER throws, NEVER hard-fails.
     */
    public function dispatchPush(): void
    {
        try {
            // Push is only meaningful when app access is enabled; otherwise there
            // are no active tokens/subscriptions to deliver to anyway.
            if (!$this->isAppAccessEnabled()) {
                return;
            }

            $provider   = $this->makeProvider();
            $dispatcher = new \App\Plugins\MobileApi\Push\PushDispatcher($this->db, $provider);
            $result     = $dispatcher->dispatch();

            SecureLogger::debug('[MobileApi] push sweep: ' . json_encode($result));
        } catch (\Throwable $e) {
            // The cron must continue regardless — push is best-effort.
            SecureLogger::error('[MobileApi] dispatchPush failed: ' . $e->getMessage());
        }
    }

    /**
     * Select the push provider from settings. With no credentials configured,
     * returns NullProvider (graceful fallback to in-app feed — spec §Push config).
     */
    public function makeProvider(): \App\Plugins\MobileApi\Push\PushProvider
    {
        $settings = $this->getSettings();
        $choice   = (string) ($settings['push_provider'] ?? 'unifiedpush');

        if ($choice === 'fcm') {
            $creds = (string) ($settings['push_fcm_credentials'] ?? '');
            // FCM is a stub; with no credentials there is nothing to deliver with →
            // fall back to NullProvider rather than pretending to be configured.
            if (trim($creds) === '') {
                return new \App\Plugins\MobileApi\Push\NullProvider();
            }
            return new \App\Plugins\MobileApi\Push\FcmProvider($creds);
        }

        // UnifiedPush needs no central credential: the per-device endpoint IS the
        // credential. It is therefore the always-available default whenever app
        // access is on. A VAPID keypair (auto-generated, RFC 8292) lets the POST
        // be signed so standard Web Push endpoints / VAPID-requiring distributors
        // accept it; without it the provider still sends (advisory).
        $subject = (string) ($settings['push_vapid_subject'] ?? '');
        $keys    = $this->ensureVapidKeys();

        return new \App\Plugins\MobileApi\Push\UnifiedPushProvider(
            $subject !== '' ? $subject : null,
            $keys['public'] ?? null,
            $keys['private'] ?? null
        );
    }

    // ─── Admin device management ─────────────────────────────────────────────

    /**
     * Returns all non-revoked device tokens across all users for the admin
     * settings page "Devices" tab.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllDevices(): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.user_id, t.device_name, t.device_id, t.platform,
                    t.created_at, t.last_used_at, t.expires_at,
                    u.nome, u.cognome, u.email
             FROM mobile_app_tokens t
             INNER JOIN utenti u ON u.id = t.user_id
             WHERE t.revoked_at IS NULL
             ORDER BY t.last_used_at DESC, t.created_at DESC
             LIMIT 200'
        );

        if ($stmt === false) {
            SecureLogger::error('[MobileApi] listAllDevices prepare failed: ' . $this->db->error);
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        return $rows;
    }

    /**
     * Admin-initiated revoke of a specific device token.
     * Returns true on success, false if the token was not found or not owned
     * by an existing user.
     */
    public function adminRevokeDevice(int $tokenId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_app_tokens SET revoked_at = NOW()
             WHERE id = ? AND revoked_at IS NULL'
        );

        if ($stmt === false) {
            SecureLogger::error('[MobileApi] adminRevokeDevice prepare failed: ' . $this->db->error);
            return false;
        }

        $stmt->bind_param('i', $tokenId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $ok && $affected > 0;
    }

    public function hasSettingsPage(): bool
    {
        return true;
    }

    public function getSettingsViewPath(): string
    {
        return __DIR__ . '/views/settings.php';
    }

    private function setDefaultEnabledFlag(): void
    {
        $existing = $this->repo()->get(self::ENABLE_CATEGORY, self::ENABLE_KEY, null);
        if ($existing === null) {
            $this->repo()->set(self::ENABLE_CATEGORY, self::ENABLE_KEY, '0');
        }
    }

    private function repo(): SettingsRepository
    {
        return new SettingsRepository($this->db);
    }
}
