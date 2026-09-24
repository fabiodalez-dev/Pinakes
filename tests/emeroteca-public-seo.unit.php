<?php
declare(strict_types=1);

/**
 * The robots/canonical policy of the public Emeroteca listings.
 *
 * THE BUG this pins down: PublicController::articles() declared a listing
 * noindex only when one of the three ContributionService::FILTER_FIELDS was
 * present. `testata` and `q` are read separately and are not in that set, so a
 * listing narrowed by masthead or by search term kept `index,follow` while
 * pointing at the bare canonical — duplicate content under an address that
 * claims to be the original. Pages after the first canonicalised onto page 1
 * as well, so a record reachable only later in the sequence had nothing
 * pointing at it. The core catalogue had already decided both questions the
 * other way (app/Views/frontend/catalog.php), and this listing disagreed with
 * it.
 *
 * Why this file exists at all: the policy was verified by hand against a
 * running Apache, and nothing in CI constrained it — so the next edit to the
 * condition would have been free to undo it silently. Driving the controller
 * through a real PSR-7 request is not the obstacle it was assumed to be; the
 * suite already does exactly this for the core catalogue
 * (tests/core-plugin-surface-140.unit.php).
 *
 * Run:  php tests/emeroteca-public-seo.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

// index() reaches for \EmerotecaPlugin::testataSearchWhere() — the single
// owner of the masthead predicate, shared with the catalogue hint. Plugin
// classes have no PSR-4 autoloading, so the test has to require what
// production gets from the plugin bootstrap.
require_once $root . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require_once $root . '/storage/plugins/emeroteca/src/Controllers/PublicController.php';

$controllerClass = 'App\\Plugins\\Emeroteca\\Controllers\\PublicController';
if (!class_exists($controllerClass)) {
    fwrite(STDERR, "FAIL: {$controllerClass} was not found after require.\n");
    exit(1);
}

$controller = new $controllerClass($db, new \App\Support\HookManager($db));

/**
 * Drive the real controller and pull the two SEO values out of the rendered
 * page. Reading the markup rather than the array keeps this honest: what the
 * crawler sees is what the template emitted, not what the controller intended.
 *
 * @return array{robots:string,canonical:string}
 */
$seoOf = static function (array $query) use ($controller): array {
    $request = (new ServerRequestFactory())
        ->createServerRequest('GET', '/emeroteca/articoli')
        ->withQueryParams($query);
    $html = (string) $controller
        ->articles($request, (new ResponseFactory())->createResponse())
        ->getBody();

    $robots = preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $m) === 1 ? $m[1] : '(none)';
    $canonical = preg_match('/<link\s+rel="canonical"\s+href="([^"]*)"/i', $html, $m) === 1 ? $m[1] : '(none)';

    return ['robots' => $robots, 'canonical' => $canonical];
};

try {
    echo "A. An unnarrowed listing is the indexable one\n";

    $bare = $seoOf([]);
    $check($bare['robots'] === 'index,follow', "the bare listing is index,follow (got '{$bare['robots']}')");
    $check(str_ends_with($bare['canonical'], '/emeroteca/articoli'),
        "the bare listing canonicalises to itself (got '{$bare['canonical']}')");

    echo "\nB. Every way of narrowing the listing keeps it out of the index\n";

    foreach ([
        'a whitelisted filter (autore)' => ['autore' => 'Rossi'],
        'a whitelisted filter (keyword)' => ['keyword' => 'storia'],
        'a search term'                 => ['q' => 'storia'],
        'a masthead'                    => ['testata' => '1'],
        'a term AND a masthead'         => ['q' => 'storia', 'testata' => '1'],
        'a filter whose value is "0"'   => ['keyword' => '0'],
    ] as $label => $query) {
        $seo = $seoOf($query);
        $check($seo['robots'] === 'noindex,follow',
            "{$label} => noindex,follow (got '{$seo['robots']}')");
    }

    // `testata=0` is the "no masthead" sentinel, not a narrowing: it must not
    // drag the bare listing out of the index.
    $sentinel = $seoOf(['testata' => '0']);
    $check($sentinel['robots'] === 'index,follow',
        "the testata=0 sentinel is not a narrowing (got '{$sentinel['robots']}')");

    echo "\nC. Pagination canonicalises to the page it served\n";

    $page1 = $seoOf(['page' => '1']);
    $check(str_ends_with($page1['canonical'], '/emeroteca/articoli'),
        "page 1 carries no page component (got '{$page1['canonical']}')");

    // A request past the last page is clamped by the service, and the canonical
    // must name the page actually served — never a page that does not exist.
    $far = $seoOf(['page' => '9999']);
    $check(!str_contains($far['canonical'], 'page=9999'),
        "a request past the last page does not advertise page 9999 (got '{$far['canonical']}')");
    $check($far['canonical'] !== '(none)', 'a clamped request still emits a canonical');

    echo "\nD. The masthead listing follows the same rule\n";

    $indexSeo = static function (array $query) use ($controller): string {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/emeroteca')
            ->withQueryParams($query);
        $html = (string) $controller
            ->index($request, (new ResponseFactory())->createResponse())
            ->getBody();
        return preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $m) === 1 ? $m[1] : '(none)';
    };

    $check($indexSeo([]) === 'index,follow', 'the bare masthead listing is indexable');
    $check($indexSeo(['q' => 'storia']) === 'noindex,follow', 'a searched masthead listing is not');
} finally {
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
