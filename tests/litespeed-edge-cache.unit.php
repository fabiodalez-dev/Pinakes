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
use App\Support\QueryCache;
use App\Support\RouteTranslator;
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
$check(
    !SessionPolicy::requiresSession('GET', [], RouteTranslator::route('api_edge_availability'), ''),
    'live availability endpoint uses its centralized route key and remains sessionless'
);

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
$setConfig(false);
$disabled = $middleware->process($request(), $handler('catalog'));
$check($disabled->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'disabled feature explicitly bypasses upstream LiteSpeed rules');
$setConfig(true);

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
$httpUrlMethod = new ReflectionMethod(LiteSpeedCache::class, 'isHttpUrl');
$check($httpUrlMethod->invoke(null, 'http://127.0.0.1:8080/purge') === true, 'loopback purge URL may use plain HTTP');
$check($httpUrlMethod->invoke(null, 'http://localhost/purge') === true, 'localhost purge URL may use plain HTTP');
$check($httpUrlMethod->invoke(null, 'http://cache.example/purge') === false, 'remote purge URL rejects plaintext HTTP');
$check($httpUrlMethod->invoke(null, 'https://cache.example/purge') === true, 'remote purge URL accepts HTTPS');

$settingsView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/settings/index.php');
$advancedSettingsView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/settings/advanced-tab.php');
$settingsController = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SettingsController.php');
$configStore = (string) file_get_contents(dirname(__DIR__) . '/app/Support/ConfigStore.php');
$frontendController = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/FrontendController.php');
$frontendLayout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/layout.php');
$bookDetail = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/book-detail.php');
$catalogGrid = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/catalog-grid.php');
$homeGrid = (string) file_get_contents(dirname(__DIR__) . '/app/Views/frontend/home-books-grid.php');
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
$check(str_contains($advancedMethod ?: '', "route_path('admin.settings') . '?tab=advanced'"), 'Advanced cache redirects use the centralized, base-aware settings route');
$check(str_contains($configStore, "!empty(\$raw['cache'])"), 'database cache settings are mapped back into ConfigStore');
$check(str_contains($configStore, "'litespeed_enabled'"), 'persisted LiteSpeed enablement is normalized as a boolean');
$check(str_contains($htaccess, 'E=Cache-Control:no-cache'), 'Apache/LiteSpeed config bypasses private lookups before PHP');
$check(str_contains($htaccess, 'pinakes_locale='), 'lookup bypass permits only the locale cookie exception');
$check(str_contains($htaccess, 'E=Cache-Vary:pinakes_locale'), 'locale participates in the LiteSpeed lookup key before PHP');
$check(str_contains($htaccessDist, 'E=Cache-Control:no-cache'), 'fresh-install htaccess ships the lookup-time bypass');
$check(str_contains($liveJs, "cache: 'no-store'"), 'availability hydration always bypasses HTTP cache');
$check(str_contains($liveJs, 'hydrationGeneration'), 'obsolete hydration responses cannot overwrite a newer catalog render');
$check(str_contains($liveJs, 'neutral, actionable fallback'), 'availability failures retain a usable neutral fallback');
$check(str_contains($liveJs, 'offset += 100'), 'availability hydration batches pages larger than the endpoint cap');
$check(!str_contains($frontendLayout, '[data-live-pending="1"]{visibility:hidden'), 'pending live fragments remain visible as neutral fallbacks');
$check(str_contains($bookDetail, "__('Verifica disponibilità')"), 'book detail exposes a neutral pending label');
$check(!str_contains($bookDetail, 'data-live-role="action" data-live-pending="1" disabled'), 'loan action remains usable if hydration fails');
$check(str_contains($catalogGrid, 'book-status-badge availability-pending'), 'catalog cards use a neutral pending badge');
$check(str_contains($homeGrid, 'book-status-badge availability-pending'), 'home cards use a neutral pending badge');
$check(str_contains($homeHero, '$heroStatsServerRendered && !$edgeCacheEnabled'), 'edge availability stat is not marked server-rendered');
$check(substr_count($homeHero, 'animate-spin') >= 3, 'edge availability stat renders the loading indicator');
$check(str_contains($frontendController, "QueryCache::remember(\n                    'home_edge_availability_stats'"), 'edge home aggregate is cached briefly');
$check(str_contains($homeView, "grid.dispatchEvent(new Event('pinakes:catalog-grid-updated'"), 'dynamically loaded home cards are hydrated live');
$check(str_contains($homeView, 'if (availableBooksEl.dataset.liveStat) return;'), 'home avoids racing the dedicated edge stats hydrator');
$check(str_contains($routesSource, "RateLimitMiddleware(300, 60, 'edge-availability')"), 'public live-availability endpoint is rate limited');

foreach (glob(dirname(__DIR__) . '/locale/??_??.json') ?: [] as $localeFile) {
    $localeJson = (string) file_get_contents($localeFile);
    foreach (['30 minuti', '1 ora', '2 ore'] as $durationKey) {
        $check(substr_count($localeJson, '"' . $durationKey . '":') === 1, basename($localeFile) . " has one {$durationKey} translation key");
    }
    $check(substr_count($localeJson, '"Verifica disponibilità":') === 1, basename($localeFile) . ' translates the neutral availability label');
    $check(substr_count($localeJson, '"Impossibile elaborare l\'immagine.":') === 1, basename($localeFile) . ' has one image-processing error key');
}

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

echo "-- database-backed cache settings --\n";
$dbSettingsProperty = new ReflectionProperty(ConfigStore::class, 'dbSettingsCache');
$configProperty->setValue(null, null);
$dbSettingsProperty->setValue(null, null);
QueryCache::set('config_settings_raw', [
    'cache' => [
        'litespeed_enabled' => '1',
        'litespeed_home_ttl' => '600',
        'litespeed_catalog_ttl' => '900',
        'litespeed_book_ttl' => '1800',
    ],
], 60);
$check(ConfigStore::get('cache.litespeed_enabled') === true, 'persisted LiteSpeed toggle reloads as true');
$check(ConfigStore::get('cache.litespeed_home_ttl') === 600, 'persisted home TTL reloads as an integer');
$check(ConfigStore::get('cache.litespeed_catalog_ttl') === 900, 'persisted catalog TTL reloads as an integer');
$check(ConfigStore::get('cache.litespeed_book_ttl') === 1800, 'persisted book TTL reloads as an integer');
QueryCache::delete('config_settings_raw');
ConfigStore::clearCache();
echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
