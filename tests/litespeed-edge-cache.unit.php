<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
putenv('PINAKES_DISABLE_CLI_PURGE=1');

use App\Middleware\LiteSpeedCacheMiddleware;
use App\Middleware\PrivateModeMiddleware;
use App\Support\ConfigStore;
use App\Support\ContentCache;
use App\Support\ContentSecurityPolicy;
use App\Support\LiteSpeedCache;
use App\Support\SessionPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        echo "  OK  {$message}\n";
        $passed++;
    } else {
        echo "FAIL  {$message}\n";
        $failed++;
    }
};

$configProperty = new ReflectionProperty(ConfigStore::class, 'runtimeCache');
$setConfig = static function (bool $enabled, string $privateMode = '0') use ($configProperty): void {
    $configProperty->setValue(null, [
        'cache' => [
            'litespeed_enabled' => $enabled,
            'litespeed_home_ttl' => 300,
            'litespeed_catalog_ttl' => 120,
            'litespeed_book_ttl' => 600,
        ],
        'advanced' => ['private_mode' => $privateMode],
    ]);
};

$request = static function (array $cookies = []): ServerRequestInterface {
    return (new ServerRequestFactory())
        ->createServerRequest('GET', 'https://library.example/catalog')
        ->withCookieParams($cookies);
};
$handler = static function (string $marker, bool $setCookie = false, bool $upgrade = false): RequestHandlerInterface {
    return new class ($marker, $setCookie, $upgrade) implements RequestHandlerInterface {
        public function __construct(private string $marker, private bool $setCookie, private bool $upgrade)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $response = new Response(200);
            $response->getBody()->write('<!doctype html><html><head><style nonce="random">.x{color:red}</style></head><body><script nonce="random">window.x=1;</script></body></html>');
            $response = $response
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Content-Security-Policy', $this->upgrade ? "default-src 'self'; upgrade-insecure-requests" : "default-src 'self'")
                ->withHeader(LiteSpeedCache::MARKER_HEADER, $this->marker);
            return $this->setCookie ? $response->withAddedHeader('Set-Cookie', 'sid=private') : $response;
        }
    };
};

echo "-- routed session policy --\n";
$check(!SessionPolicy::requiresEarlySession('GET', []), 'anonymous GET defers its final session decision until routing');
$check(
    !SessionPolicy::requiresRoutedSession('GET', [], '/author/title/42', '/{authorSlug}/{bookSlug}/{id:\\d+}', ''),
    'exact canonical book pattern is sessionless after routing'
);
$check(
    SessionPolicy::requiresRoutedSession('GET', [], '/club/summer/42', '/club/{slug}/{id:\\d+}', ''),
    'similar plugin pattern remains sessionful'
);
$check(SessionPolicy::requiresEarlySession('POST', []), 'mutation starts a session before routing');
$check(SessionPolicy::requiresEarlySession('GET', [session_name() => 'abc']), 'session cookie starts a session before routing');

echo "-- CSP stabilization --\n";
$html = '<style nonce="one">a{color:red}</style><script nonce="two">window.ok=true;</script>';
$stable = ContentSecurityPolicy::removeNonceAttributes($html);
$csp = ContentSecurityPolicy::headerForCachedHtml($stable);
$check(!str_contains($stable, 'nonce='), 'shared HTML contains no reusable CSP nonce');
$check(substr_count($csp, "'sha256-") === 2, 'shared CSP hashes every inline style/script block');

echo "-- LiteSpeed response policy --\n";
$setConfig(true);
LiteSpeedCache::consumeQueuedPurge();
$middleware = new LiteSpeedCacheMiddleware();
$public = $middleware->process($request(), $handler('catalog'));
$check($public->getHeaderLine('X-LiteSpeed-Cache-Control') === 'public,max-age=120', 'catalog marker gets configured public TTL');
$check($public->getHeaderLine('X-LiteSpeed-Vary') === 'cookie=pinakes_locale', 'locale is isolated with LiteSpeed cookie vary');
$check(str_contains($public->getHeaderLine('X-LiteSpeed-Tag'), LiteSpeedCache::TAG_CATALOG), 'catalog response carries a surgical purge tag');
$check(str_contains($public->getHeaderLine('Vary'), 'Cookie'), 'non-LiteSpeed shared caches also vary on Cookie');
$check(!$public->hasHeader(LiteSpeedCache::MARKER_HEADER), 'internal controller marker never leaks to clients');
$check(!str_contains((string) $public->getBody(), 'nonce='), 'cached response body is nonce-independent');
$check(str_contains($public->getHeaderLine('Content-Security-Policy'), "'sha256-"), 'cached response uses hash CSP');
$upgraded = $middleware->process($request(), $handler('home', false, true));
$check(str_contains($upgraded->getHeaderLine('Content-Security-Policy'), 'upgrade-insecure-requests'), 'cached CSP preserves proxy-aware HTTPS upgrades');

$locale = $middleware->process($request(['pinakes_locale' => 'de_DE']), $handler('home'));
$check($locale->getHeaderLine('X-LiteSpeed-Cache-Control') === 'public,max-age=300', 'sole locale cookie remains cacheable');
$badLocale = $middleware->process($request(['pinakes_locale' => 'not-a-locale']), $handler('home'));
$check($badLocale->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'malformed locale cookie fails closed');
$visitorCookie = $middleware->process($request(['cookie_consent' => '1']), $handler('home'));
$check($visitorCookie->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'any non-locale cookie bypasses shared cache');
$setCookie = $middleware->process($request(), $handler('home', true));
$check($setCookie->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'Set-Cookie response bypasses shared cache');
$unmarked = $middleware->process($request(), $handler(''));
$check($unmarked->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'unmarked HTML fails closed');
$setConfig(true, '1');
$private = $middleware->process($request(), $handler('home'));
$check($private->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'private mode bypasses shared cache');
$privateMode = new PrivateModeMiddleware();
$purgeRequest = (new ServerRequestFactory())->createServerRequest('POST', 'https://library.example/_pinakes/litespeed-purge');
$purgePass = $privateMode->process($purgeRequest, $handler(''));
$check($purgePass->getStatusCode() === 200, 'private mode permits the independently authenticated exact purge endpoint');
$nearPurgeRequest = (new ServerRequestFactory())->createServerRequest('POST', 'https://library.example/_pinakes/litespeed-purge/other');
$nearPurge = $privateMode->process($nearPurgeRequest, $handler(''));
$check($nearPurge->getStatusCode() === 302, 'private mode does not allow lookalike purge paths');

$setConfig(true);
LiteSpeedCache::consumeQueuedPurge();
$_SESSION['user'] = ['tipo_utente' => 'admin'];
$adminMutation = (new ServerRequestFactory())->createServerRequest('POST', 'https://library.example/admin/themes/1');
$adminMutationResponse = $middleware->process($adminMutation, $handler(''));
$check(
    str_contains($adminMutationResponse->getHeaderLine('X-LiteSpeed-Purge'), 'tag=' . LiteSpeedCache::TAG_ALL),
    'successful admin mutations defensively purge shared page HTML'
);
unset($_SESSION['user']);

echo "-- purge integration and static contracts --\n";
$setConfig(true);
ContentCache::booksChanged();
$purges = LiteSpeedCache::consumeQueuedPurge();
$check(in_array(LiteSpeedCache::TAG_HOME, $purges, true), 'book writes purge home tag');
$check(in_array(LiteSpeedCache::TAG_CATALOG, $purges, true), 'book writes purge catalog tag');
$check(in_array(LiteSpeedCache::TAG_BOOKS, $purges, true), 'book writes purge book-detail tag');
$check(
    LiteSpeedCache::purgeHeader([LiteSpeedCache::TAG_HOME]) === 'public,tag=' . LiteSpeedCache::TAG_HOME,
    'purge header uses LiteSpeed public tag syntax'
);

$settingsView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/settings/index.php');
$advancedSettingsView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/settings/advanced-tab.php');
$settingsController = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SettingsController.php');
$htaccess = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess');
$htaccessDist = (string) file_get_contents(dirname(__DIR__) . '/public/.htaccess.dist');
$liveJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/live-availability.js');
$homeHero = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/home-sections/hero.php');
$homeView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/home.php');
$routesSource = (string) file_get_contents(dirname(__DIR__) . '/app/Routes/web.php');
$check(!str_contains($settingsView, 'name="litespeed_enabled"'), 'General settings no longer mixes identity with infrastructure controls');
$check(str_contains($advancedSettingsView, 'name="litespeed_enabled"'), 'Advanced settings exposes the LiteSpeed opt-in toggle');
$check(str_contains($advancedSettingsView, "'litespeed_catalog_ttl' =>"), 'Advanced settings exposes per-surface TTL controls');
$advancedMethod = strstr($settingsController, 'public function updateAdvancedSettings');
$generalMethod = strstr($settingsController, 'public function updateGeneral');
$check(is_string($advancedMethod) && str_contains(substr($advancedMethod, 0, 8000), "repository->set('cache'"), 'Advanced settings endpoint owns cache persistence');
$check(is_string($generalMethod) && !str_contains(substr($generalMethod, 0, 12000), "repository->set('cache'"), 'General settings endpoint cannot mutate cache policy');
$check(str_contains($htaccess, 'E=Cache-Control:no-cache'), 'Apache/LiteSpeed config bypasses private lookups before PHP');
$check(str_contains($htaccess, 'pinakes_locale='), 'lookup bypass permits only the locale cookie exception');
$check(str_contains($htaccess, 'E=Cache-Vary:pinakes_locale'), 'locale participates in the LiteSpeed lookup key before PHP');
$check(str_contains($htaccessDist, 'E=Cache-Control:no-cache'), 'fresh-install htaccess ships the lookup-time bypass');
$check(str_contains($liveJs, "cache: 'no-store'"), 'availability hydration always bypasses HTTP cache');
$check(str_contains($liveJs, 'stale cached counts are never shown'), 'availability failures keep stale fragments hidden');
$check(str_contains($liveJs, 'offset += 100'), 'availability hydration batches pages larger than the endpoint cap');
$check(str_contains($homeHero, 'if ($edgeCacheEnabled):'), 'home availability count is omitted from shared HTML');
$check(str_contains($homeView, "grid.dispatchEvent(new Event('pinakes:catalog-grid-updated'"), 'dynamically loaded home cards are hydrated live');
$check(str_contains($routesSource, "RateLimitMiddleware(300, 60, 'edge-availability')"), 'public live-availability endpoint is rate limited');

echo "-- upgraded .htaccess self-heal --\n";
$fixture = tempnam(sys_get_temp_dir(), 'pinakes-htaccess-');
$legacyHtaccess = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n    RewriteRule ^ index.php [QSA,L]\n</IfModule>\n";
$fixtureReady = is_string($fixture) && file_put_contents($fixture, $legacyHtaccess) !== false;
$check($fixtureReady, 'legacy .htaccess fixture is writable');
if ($fixtureReady) {
    $check(LiteSpeedCache::ensureLookupBypass($fixture), 'existing installations receive the privacy bypass automatically');
    $healed = (string) file_get_contents($fixture);
    $check(substr_count($healed, '# === Pinakes LiteSpeed privacy bypass ===') === 1, 'self-heal inserts one marked bypass block');
    $check(str_contains($healed, 'E=Cache-Vary:pinakes_locale'), 'self-healed bypass includes lookup-time locale vary');
    $check(LiteSpeedCache::lookupBypassInstalled($fixture), 'self-healed .htaccess passes the installation diagnostic');
    $check(LiteSpeedCache::ensureLookupBypass($fixture), 'self-heal is idempotent');
    $check((string) file_get_contents($fixture) === $healed, 'idempotent self-heal leaves the file byte-identical');
    unlink($fixture);
}

$configProperty->setValue(null, null);
echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
