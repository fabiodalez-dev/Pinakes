<?php
declare(strict_types=1);

/**
 * Fifty release-gate checks for the integrated performance stack.
 *
 * The cases are intentionally database-independent: they cover session
 * routing, CSP stabilization, LiteSpeed edge policy, QueryCache generations
 * and the catalog materialization fallbacks on every CI worker. Database
 * behavior remains covered by catalog-materialization-db.unit.php and the
 * migration gate.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
putenv('PINAKES_DISABLE_CLI_PURGE=1');

use App\Controllers\FrontendController;
use App\Middleware\LiteSpeedCacheMiddleware;
use App\Support\CatalogAuthorProjection;
use App\Support\CatalogSnapshot;
use App\Support\ConfigStore;
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
$number = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed, &$number): void {
    $number++;
    echo sprintf('[%02d] %s %s', $number, $condition ? 'PASS' : 'FAIL', $label) . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

// Preserve a pending real purge, if any. Middleware tests consume the durable
// queue by design; the finally block restores pre-existing tags.
$queuedBefore = LiteSpeedCache::consumeQueuedPurge();
$cacheKeys = [];
$fixtures = [];

try {
    echo "A. Session routing\n";
    $check(!SessionPolicy::requiresSession('GET', [], '/', ''), 'anonymous home GET is sessionless');
    $check(!SessionPolicy::requiresSession('HEAD', [], RouteTranslator::route('catalog'), ''), 'anonymous catalog HEAD is sessionless');
    $check(SessionPolicy::requiresSession('POST', [], RouteTranslator::route('catalog'), ''), 'catalog mutation requires a session');
    $check(SessionPolicy::requiresSession('GET', [session_name() => 'abc'], '/', ''), 'PHP session cookie requires an early session');
    $check(SessionPolicy::requiresSession('GET', ['remember_token' => 'abc'], '/', ''), 'remember-me cookie requires an early session');
    $check(SessionPolicy::requiresSession('GET', ['csrf_login' => 'abc'], '/', ''), 'login CSRF cookie requires an early session');
    $check(!SessionPolicy::requiresSession('GET', [], '/pinakes/catalog', '/pinakes'), 'subfolder catalog route remains sessionless');
    $check(SessionPolicy::requiresSession('GET', [], '/pinakes-other/catalog', '/pinakes'), 'base-path boundary mismatch fails closed');
    $check(
        !SessionPolicy::requiresRoutedSession('GET', [], '/writer/title/42', '/{authorSlug}/{bookSlug}/{id:\\d+}', ''),
        'canonical book route pattern is sessionless'
    );
    $check(
        SessionPolicy::requiresRoutedSession('GET', [], '/plugin/title/42', '/plugin/{slug}/{id:\\d+}', ''),
        'lookalike plugin route stays sessionful'
    );

    echo "B. Stable CSP for shared HTML\n";
    $doubleQuoted = '<script nonce="random">window.a=1;</script>';
    $singleQuoted = "<style nonce='random'>a{color:red}</style>";
    $check(ContentSecurityPolicy::removeNonceAttributes($doubleQuoted) === '<script>window.a=1;</script>', 'double-quoted nonce is removed');
    $check(ContentSecurityPolicy::removeNonceAttributes($singleQuoted) === '<style>a{color:red}</style>', 'single-quoted nonce is removed');
    $check(
        ContentSecurityPolicy::removeNonceAttributes('<div data-nonce="keep">x</div>') === '<div data-nonce="keep">x</div>',
        'unrelated data-nonce attributes are preserved'
    );
    $stableOne = ContentSecurityPolicy::removeNonceAttributes('<script nonce="one">window.same=1;</script>');
    $stableTwo = ContentSecurityPolicy::removeNonceAttributes('<script nonce="two">window.same=1;</script>');
    $check($stableOne === $stableTwo, 'different request nonces produce byte-identical cached HTML');
    $scriptHash = "'sha256-" . base64_encode(hash('sha256', 'window.same=1;', true)) . "'";
    $check(str_contains(ContentSecurityPolicy::headerForCachedHtml($stableOne), $scriptHash), 'cached CSP contains the exact inline-script hash');
    $styleBody = 'a{color:red}';
    $styleHash = "'sha256-" . base64_encode(hash('sha256', $styleBody, true)) . "'";
    $check(str_contains(ContentSecurityPolicy::headerForCachedHtml('<style>' . $styleBody . '</style>'), $styleHash), 'cached CSP contains the exact inline-style hash');
    $check(substr_count(ContentSecurityPolicy::headerForCachedHtml('<script></script>'), "'sha256-") === 0, 'empty inline blocks do not add meaningless hashes');
    $check(!str_contains(ContentSecurityPolicy::headerForCachedHtml($stableOne), 'upgrade-insecure-requests'), 'cached CSP omits HTTPS upgrade when not requested');
    $check(str_contains(ContentSecurityPolicy::headerForCachedHtml($stableOne, true), 'upgrade-insecure-requests'), 'cached CSP preserves the HTTPS upgrade directive');

    echo "C. LiteSpeed helpers and response policy\n";
    $configProperty = new ReflectionProperty(ConfigStore::class, 'runtimeCache');
    $setConfig = static function (int $homeTtl, int $catalogTtl = 120, int $bookTtl = 300) use ($configProperty): void {
        $configProperty->setValue(null, [
            'cache' => [
                'litespeed_enabled' => true,
                'litespeed_home_ttl' => $homeTtl,
                'litespeed_catalog_ttl' => $catalogTtl,
                'litespeed_book_ttl' => $bookTtl,
            ],
            'advanced' => ['private_mode' => '0'],
        ]);
    };
    $setConfig(300);
    $check(LiteSpeedCache::ttlFor('home') === 300, 'home TTL uses the configured value');
    $setConfig(1);
    $check(LiteSpeedCache::ttlFor('home') === 30, 'TTL is clamped to the 30-second safety floor');
    $setConfig(999999);
    $check(LiteSpeedCache::ttlFor('home') === 86400, 'TTL is clamped to the one-day ceiling');

    $httpUrl = new ReflectionMethod(LiteSpeedCache::class, 'isHttpUrl');
    $check($httpUrl->invoke(null, 'https://cache.example.test/purge') === true, 'remote HTTPS purge URL is accepted');
    $check($httpUrl->invoke(null, 'http://cache.example.test/purge') === false, 'remote plaintext purge URL is rejected');
    $check($httpUrl->invoke(null, 'http://127.0.0.1:8080/purge') === true, 'loopback plaintext purge URL is accepted');
    $check(LiteSpeedCache::purgeHeader(['GOOD_tag', 'bad tag', 'good_tag']) === 'public,tag=good_tag', 'purge tags are normalized, filtered and deduplicated');
    $check(LiteSpeedCache::purgeHeader([]) === 'public', 'empty purge list retains valid public purge syntax');

    $partial = tempnam(sys_get_temp_dir(), 'pinakes-ls-partial-');
    if (!is_string($partial)) {
        throw new RuntimeException('cannot create partial .htaccess fixture');
    }
    $fixtures[] = $partial;
    file_put_contents($partial, "# === Pinakes LiteSpeed privacy bypass ===\n");
    $check(!LiteSpeedCache::lookupBypassInstalled($partial), 'partial privacy marker is not considered installed');

    $noAnchor = tempnam(sys_get_temp_dir(), 'pinakes-ls-noanchor-');
    if (!is_string($noAnchor)) {
        throw new RuntimeException('cannot create no-anchor .htaccess fixture');
    }
    $fixtures[] = $noAnchor;
    file_put_contents($noAnchor, "RewriteEngine On\nRewriteRule ^ index.php [L]\n");
    $check(!LiteSpeedCache::ensureLookupBypass($noAnchor), 'self-heal refuses an ambiguous file without its insertion anchor');

    $legacy = tempnam(sys_get_temp_dir(), 'pinakes-ls-legacy-');
    if (!is_string($legacy)) {
        throw new RuntimeException('cannot create legacy .htaccess fixture');
    }
    $fixtures[] = $legacy;
    file_put_contents(
        $legacy,
        "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\n"
    );
    $check(LiteSpeedCache::ensureLookupBypass($legacy), 'self-heal installs the privacy bypass in a legacy file');
    $healed = (string) file_get_contents($legacy);
    $check(LiteSpeedCache::ensureLookupBypass($legacy) && (string) file_get_contents($legacy) === $healed, 'self-heal is byte-idempotent');

    $setConfig(300, 120, 600);
    $_SESSION = [];
    $middleware = new LiteSpeedCacheMiddleware();
    $request = static function (string $method = 'GET', array $headers = []): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://library.example.test/catalog');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    };
    $handler = static function (
        string $marker,
        int $status = 200,
        string $cacheControl = ''
    ): RequestHandlerInterface {
        return new class ($marker, $status, $cacheControl) implements RequestHandlerInterface {
            public function __construct(
                private string $marker,
                private int $status,
                private string $cacheControl
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response($this->status);
                $response->getBody()->write('<!doctype html><html><body><script nonce="request">window.edge=1;</script></body></html>');
                $response = $response
                    ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                    ->withHeader('Content-Security-Policy', "default-src 'self'")
                    ->withHeader(LiteSpeedCache::MARKER_HEADER, $this->marker);
                return $this->cacheControl !== ''
                    ? $response->withHeader('Cache-Control', $this->cacheControl)
                    : $response;
            }
        };
    };
    $home = $middleware->process($request(), $handler('home'));
    $check($home->getHeaderLine('X-LiteSpeed-Cache-Control') === 'public,max-age=300', 'marked anonymous home response is publicly cacheable');
    $head = $middleware->process($request('HEAD'), $handler('catalog'));
    $check($head->getHeaderLine('X-LiteSpeed-Cache-Control') === 'public,max-age=120', 'marked anonymous HEAD response is cacheable');
    $post = $middleware->process($request('POST'), $handler('catalog'));
    $check($post->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'mutating response is never publicly cached');
    $authorized = $middleware->process($request('GET', ['Authorization' => 'Bearer secret']), $handler('catalog'));
    $check($authorized->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'Authorization-bearing response is never publicly cached');
    $privateResponse = $middleware->process($request(), $handler('home', 200, 'private, no-store'));
    $check($privateResponse->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'private/no-store controller response remains uncacheable');
    $book = $middleware->process($request(), $handler('book:42'));
    $check(str_contains($book->getHeaderLine('X-LiteSpeed-Tag'), 'pinakes_book_42'), 'book response carries its precise purge tag');

    echo "D. QueryCache generation behavior\n";
    $run = bin2hex(random_bytes(8));
    $roundTripKey = 'release50_roundtrip_' . $run;
    $falseKey = 'release50_false_' . $run;
    $generationKey = 'schema_table_release50_' . $run;
    $cacheKeys = [$roundTripKey, $falseKey, $generationKey];
    $payload = ['nested' => ['value' => 42], 'unicode' => 'biblioteca'];
    $check(QueryCache::set($roundTripKey, $payload, 120) && QueryCache::get($roundTripKey) === $payload, 'array payload round-trips through the selected backend');
    $check(QueryCache::set($falseKey, false, 120) && QueryCache::get($falseKey) === false, 'boolean false is cached as a hit, not confused with a miss');
    $check(QueryCache::delete($roundTripKey) && QueryCache::get($roundTripKey) === null, 'delete removes a current-generation entry');
    $generationBefore = QueryCache::namespaceGeneration('schema_table_');
    $check($generationBefore > 0, 'known namespace exposes a persistent positive generation');
    QueryCache::set($generationKey, 'old', 120);
    QueryCache::bumpGeneration('schema_table_');
    $generationAfter = QueryCache::namespaceGeneration('schema_table_');
    $check($generationAfter > $generationBefore, 'generation bump is monotonic in the current process');
    $check(QueryCache::get($generationKey) === null, 'old namespace value is unreachable after the generation bump');
    try {
        QueryCache::namespaceGeneration('release50_unknown_');
        $unknownRejected = false;
    } catch (InvalidArgumentException) {
        $unknownRejected = true;
    }
    $check($unknownRejected, 'unknown namespace cannot forge a shared materialization generation');

    echo "E. Catalog materialization fallbacks\n";
    $disconnected = new mysqli();
    $negativeLoads = 0;
    $negative = CatalogSnapshot::remember($disconnected, 'release50-negative', -1, static function () use (&$negativeLoads): string {
        $negativeLoads++;
        return 'live-negative';
    });
    $check($negative === 'live-negative' && $negativeLoads === 1, 'negative/degraded generation bypasses snapshot persistence');
    $zeroLoads = 0;
    CatalogSnapshot::remember($disconnected, 'release50-zero', 0, static function () use (&$zeroLoads): string {
        $zeroLoads++;
        return 'live-zero';
    });
    CatalogSnapshot::remember($disconnected, 'release50-zero', 0, static function () use (&$zeroLoads): string {
        $zeroLoads++;
        return 'live-zero';
    });
    $check($zeroLoads === 2, 'zero generation never creates a reusable shared snapshot');
    $check(!CatalogAuthorProjection::isReadable($disconnected), 'unavailable schema fails closed to the live author query');

    $controller = new FrontendController();
    $controllerReflection = new ReflectionClass($controller);
    $authorSelect = $controllerReflection->getMethod('catalogAuthorSelect')->invoke($controller, $disconnected);
    $check(
        is_string($authorSelect)
            && substr_count($authorSelect, 'SELECT') === 3
            && str_contains($authorSelect, 'AS autore_cognome'),
        'pre-migration catalog fallback retains all three live author subqueries'
    );
    $bounded = $controllerReflection->getMethod('hasBoundedCatalogCacheKey');
    $baseFilters = [
        'search' => '',
        'genere_id' => 0,
        'disponibilita' => '',
        'editore' => '',
        'anno_min' => '',
        'anno_max' => '',
        'tipo_media' => '',
        'autore_id' => 0,
        'sort' => 'newest',
    ];
    $check($bounded->invoke($controller, $baseFilters) === true, 'finite default catalog state is eligible for shared materialization');
    $check($bounded->invoke($controller, array_replace($baseFilters, ['search' => 'unbounded'])) === false, 'free-text catalog state bypasses persistent materialization');
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, 'UNCAUGHT: ' . $e->getMessage() . PHP_EOL);
} finally {
    foreach ($cacheKeys as $key) {
        QueryCache::delete($key);
    }
    foreach ($fixtures as $fixture) {
        if (is_string($fixture) && is_file($fixture)) {
            @unlink($fixture);
        }
    }
    // Restore any queue entries that predated this isolated test process.
    LiteSpeedCache::consumeQueuedPurge();
    if ($queuedBefore !== []) {
        LiteSpeedCache::queuePurge($queuedBefore, true);
    }
    ConfigStore::clearCache();
}

echo PHP_EOL . "{$passed} passed, {$failed} failed; {$number} numbered checks" . PHP_EOL;
if ($number !== 50) {
    fwrite(STDERR, "FAIL: expected exactly 50 numbered checks, got {$number}\n");
    exit(1);
}
exit($failed === 0 ? 0 : 1);
