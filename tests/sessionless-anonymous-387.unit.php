<?php
declare(strict_types=1);

/**
 * Step 6 of the caching overhaul (issue #387): sessionless anonymous request
 * path with lazy CSRF. Security invariants:
 *
 *   1. SessionPolicy: only audited, read-only, cookie-less public routes are
 *      sessionless; unknown and plugin routes keep the session (fail-safe).
 *   2. Csrf stays fail-closed with no session data: missing/invalid tokens
 *      are rejected; a lazily minted token (GET /csrf-token flow) validates.
 *   3. CsrfMiddleware still rejects missing/invalid tokens on POST and still
 *      accepts a valid session-backed token (body and header variants), and
 *      the login double-submit cookie fallback still works.
 *   4. PrivateModeMiddleware still gates anonymous access with no session.
 */

use App\Middleware\CsrfMiddleware;
use App\Middleware\PrivateModeMiddleware;
use App\Support\Csrf;
use App\Support\RouteTranslator;
use App\Support\SessionPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

// ---------------------------------------------------------------------------
// 1. SessionPolicy predicate
// ---------------------------------------------------------------------------
echo "-- SessionPolicy --\n";
SessionPolicy::clearCache();
$sessionCookieName = session_name();

// Sessionless: anonymous read-only, no cookies, public content paths.
$check(!SessionPolicy::requiresSession('GET', [], '/', ''), 'GET / with no cookies is sessionless');
$check(!SessionPolicy::requiresSession('GET', [], '/catalog', ''), 'GET /catalog with no cookies is sessionless');
$check(!SessionPolicy::requiresSession('GET', [], '/catalogo', ''), 'GET /catalogo (it) with no cookies is sessionless');
$check(!SessionPolicy::requiresSession('HEAD', [], '/book/42', ''), 'HEAD /book/42 with no cookies is sessionless');
$check(!SessionPolicy::requiresSession('GET', [], '/language/en_US', ''), 'GET /language/{locale} is sessionless (cookie-based)');
$check(!SessionPolicy::requiresSession('GET', [], '/csrf-token', ''), 'lazy token endpoint reaches its own session_start');
$check(!SessionPolicy::requiresSession('GET', ['pinakes_locale' => 'de_DE'], '/catalog', ''), 'locale cookie alone does not force a session');

// adamsreview F1 (#390): only the PUBLIC /uploads subtrees are sessionless.
$check(!SessionPolicy::requiresSession('GET', [], '/uploads/copertine/12.jpg', ''), 'public book cover is sessionless');
$check(!SessionPolicy::requiresSession('GET', [], '/uploads/autori/3.png', ''), 'public author photo is sessionless');
$check(!SessionPolicy::requiresSession('GET', [], '/uploads/settings/logo.png', ''), 'public branding is sessionless');
// The PRIVATE subtrees must NOT be sessionless (else the future edge cache would
// make them cache-eligible → unauthenticated disclosure). PrivateModeMiddleware
// gates them; SessionPolicy must not stamp them session-free.
$check(SessionPolicy::requiresSession('GET', [], '/uploads/digital/secret.pdf', ''), 'private digital-library file keeps the session (not cache-eligible)');
$check(SessionPolicy::requiresSession('GET', [], '/uploads/archives/documents/x.pdf', ''), 'private archive document keeps the session');
$check(SessionPolicy::requiresSession('GET', [], '/uploads/storage/x.bin', ''), 'private storage file keeps the session');

// Unknown/plugin/canonical catch-all routes stay sessionful until audited.
$check(SessionPolicy::requiresSession('GET', [], '/club/summer', ''), 'unknown plugin route keeps the session');
$check(SessionPolicy::requiresSession('GET', [], '/author-slug/book-slug/42', ''), 'unclassified canonical route keeps the session');

// Session kept: any state-changing / unknown method.
$check(SessionPolicy::requiresSession('POST', [], '/anything', ''), 'POST always keeps the session');
$check(SessionPolicy::requiresSession('PUT', [], '/anything', ''), 'PUT always keeps the session');
$check(SessionPolicy::requiresSession('DELETE', [], '/anything', ''), 'DELETE always keeps the session');
$check(SessionPolicy::requiresSession('PATCH', [], '/anything', ''), 'PATCH always keeps the session');
$check(SessionPolicy::requiresSession('', [], '/', ''), 'missing method (CLI) keeps the session');

// Session kept: any auth-related cookie.
$check(SessionPolicy::requiresSession('GET', [$sessionCookieName => 'abc'], '/catalog', ''), 'session cookie keeps the session');
$check(SessionPolicy::requiresSession('GET', ['remember_token' => 'abc'], '/catalog', ''), 'remember_token cookie keeps the session');
$check(SessionPolicy::requiresSession('GET', ['csrf_login' => 'abc'], '/catalog', ''), 'csrf_login cookie keeps the session');

// Session kept: auth/contact routes in every registered locale + legacy aliases.
$authChecks = [];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    foreach (['login', 'register', 'forgot_password', 'reset_password', 'verify_email', 'contact'] as $key) {
        $route = RouteTranslator::getRouteForLocale($key, $locale);
        if ($route !== '' && $route !== '/') {
            $authChecks[$route] = $key . ' (' . $locale . ')';
        }
    }
}
$allAuthKeepSession = true;
foreach ($authChecks as $route => $label) {
    if (!SessionPolicy::requiresSession('GET', [], $route, '')) {
        $allAuthKeepSession = false;
        echo "       route without session: {$route} [{$label}]\n";
    }
}
$check($allAuthKeepSession, 'every localized auth/contact route keeps the session (' . count($authChecks) . ' routes)');
$check(SessionPolicy::requiresSession('GET', [], '/login', ''), 'legacy /login keeps the session');
$check(SessionPolicy::requiresSession('GET', [], '/login.php', ''), 'legacy /login.php keeps the session');
$check(SessionPolicy::requiresSession('GET', [], '/reset-password/sometoken', ''), 'auth route sub-path keeps the session');

// Base path handling (sub-folder installs).
$check(SessionPolicy::requiresSession('GET', [], '/pinakes/login', '/pinakes'), 'base-path auth route keeps the session');
$check(!SessionPolicy::requiresSession('GET', [], '/pinakes/catalog', '/pinakes'), 'base-path public route is sessionless');
$check(SessionPolicy::requiresSession('GET', [], '/pinakes-other/catalog', '/pinakes'), 'base-path stripping requires a path boundary');

// ---------------------------------------------------------------------------
// 2. Csrf fail-closed without a session + lazy mint flow
// ---------------------------------------------------------------------------
echo "-- Csrf fail-closed / lazy mint --\n";
$_SESSION = [];
$check(!Csrf::validate(null), 'no token + no session token is rejected');
$check(!Csrf::validate('forged-token'), 'forged token with no session token is rejected');
$check(Csrf::validateWithReason(null)['reason'] === 'missing_token', 'missing token reported as missing_token');
$check(Csrf::validateWithReason('forged')['reason'] === 'session_expired', 'token without session store reported as session_expired');

// Lazy mint: GET /csrf-token calls ensureToken() on a fresh session; the
// returned token must validate exactly like a page-rendered one.
$_SESSION = [];
$minted = Csrf::ensureToken();
$check($minted !== '' && Csrf::validate($minted), 'lazily minted token validates');
$check(!Csrf::validate($minted . 'x'), 'tampered minted token is rejected');

// Browser helper invariants. The behavioral counterpart is exercised by the
// browser suite; these guards keep the security-critical implementation from
// silently regressing during a refactor.
$csrfHelper = file_get_contents(dirname(__DIR__) . '/public/assets/js/csrf-helper.js');
$check(is_string($csrfHelper) && str_contains($csrfHelper, 'let lazyTokenPromise = null'), 'lazy CSRF mint is single-flight');
$check(is_string($csrfHelper) && str_contains($csrfHelper, '.finally(function()'), 'single-flight promise is cleared after completion');
$check(is_string($csrfHelper) && str_contains($csrfHelper, 'window.location.origin'), 'CSRF header is limited to same-origin requests');
$check(is_string($csrfHelper) && str_contains($csrfHelper, 'new Headers(options.headers || {})'), 'Headers instances are preserved');

$frontController = file_get_contents(dirname(__DIR__) . '/public/index.php');
$check(
    is_string($frontController)
        && str_contains($frontController, '$localeRestoredFromSession')
        && str_contains($frontController, 'if (!$localeRestoredFromSession)'),
    'locale cookie falls back when an active session has no valid locale'
);

// ---------------------------------------------------------------------------
// 3. CsrfMiddleware behavior (direct invocation)
// ---------------------------------------------------------------------------
echo "-- CsrfMiddleware --\n";

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = new SlimResponse(200);
        $response->getBody()->write('handled');
        return $response;
    }
};

$makePost = static function (string $path, array $body = [], array $headers = [], array $cookies = []): ServerRequestInterface {
    $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    if ($body !== []) {
        $request = $request->withParsedBody($body);
    }
    if ($cookies !== []) {
        $request = $request->withCookieParams($cookies);
    }
    return $request;
};

$middleware = new CsrfMiddleware();

// Authenticated-style session with a valid token.
$_SESSION = [];
$valid = Csrf::ensureToken();

$res = $middleware->process($makePost('/admin/libri', ['csrf_token' => $valid], ['Accept' => 'application/json']), $handler);
$check($res->getStatusCode() === 200 && (string) $res->getBody() === 'handled', 'valid body token on POST passes');

$res = $middleware->process($makePost('/admin/libri', [], ['Accept' => 'application/json', 'X-CSRF-Token' => $valid]), $handler);
$check($res->getStatusCode() === 200, 'valid X-CSRF-Token header on POST passes');

$res = $middleware->process($makePost('/admin/libri', ['csrf_token' => $valid . 'x'], ['Accept' => 'application/json']), $handler);
$check($res->getStatusCode() === 403, 'invalid token on POST is rejected (403)');

$res = $middleware->process($makePost('/admin/libri', [], ['Accept' => 'application/json']), $handler);
$check($res->getStatusCode() === 403, 'missing token on POST is rejected (403)');
$decoded = json_decode((string) $res->getBody(), true);
$check(is_array($decoded) && ($decoded['code'] ?? '') !== '', 'rejection is a structured JSON error');

// Sessionless page → POST that opened a FRESH session (no csrf_token yet):
// still rejected for non-login routes (fail closed).
$_SESSION = [];
$res = $middleware->process($makePost('/contact/submit', ['csrf_token' => 'anything'], ['Accept' => 'application/json']), $handler);
$check($res->getStatusCode() === 403, 'fresh empty session + token on non-login POST stays rejected');

// Login double-submit cookie fallback (unchanged behavior, now the only path
// for a login POSTed after a sessionless login-page render would be a session
// minted on the login GET — but the cookie fallback must still work).
$_SESSION = [];
$loginRoute = RouteTranslator::route('login');
$cookieToken = implode('-', str_split(bin2hex(random_bytes(32)), 8));
$res = $middleware->process(
    $makePost($loginRoute, ['csrf_token' => $cookieToken], ['Accept' => 'application/json'], ['csrf_login' => $cookieToken]),
    $handler
);
$check($res->getStatusCode() === 200, 'login POST with matching csrf_login double-submit cookie passes');

$_SESSION = [];
$res = $middleware->process(
    $makePost($loginRoute, ['csrf_token' => $cookieToken], ['Accept' => 'application/json'], ['csrf_login' => 'different-value']),
    $handler
);
$check($res->getStatusCode() === 403, 'login POST with mismatched csrf_login cookie is rejected');

// ---------------------------------------------------------------------------
// 4. Private mode still gates anonymous access (no session data at all)
// ---------------------------------------------------------------------------
echo "-- PrivateModeMiddleware --\n";

// Force advanced.private_mode=1 through ConfigStore's runtime cache (no DB).
$configRef = new ReflectionClass(\App\Support\ConfigStore::class);
$runtimeCacheProp = $configRef->getProperty('runtimeCache');
$runtimeCacheProp->setAccessible(true);
$previousRuntimeCache = $runtimeCacheProp->getValue();
$runtimeCacheProp->setValue(null, ['advanced' => ['private_mode' => '1']]);

try {
    $_SESSION = [];
    $pm = new PrivateModeMiddleware();

    $req = (new ServerRequestFactory())->createServerRequest('GET', '/catalog');
    $res = $pm->process($req, $handler);
    $check($res->getStatusCode() === 302, 'private mode: anonymous catalog GET redirected');
    $check(str_contains($res->getHeaderLine('Location'), 'private_mode'), 'private mode: redirect targets login with private_mode flag');

    $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/internal/whatever');
    $res = $pm->process($req, $handler);
    $check($res->getStatusCode() === 401, 'private mode: anonymous API GET gets 401');

    $req = (new ServerRequestFactory())->createServerRequest('GET', '/login');
    $res = $pm->process($req, $handler);
    $check($res->getStatusCode() === 200, 'private mode: login page stays reachable');

    // Authenticated user passes.
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'standard']];
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/catalog');
    $res = $pm->process($req, $handler);
    $check($res->getStatusCode() === 200, 'private mode: authenticated user passes');
} finally {
    $runtimeCacheProp->setValue(null, $previousRuntimeCache);
    $_SESSION = [];
}

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
