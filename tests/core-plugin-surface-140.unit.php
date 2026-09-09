<?php
declare(strict_types=1);

/**
 * Issue #140 (emeroteca review) — core surfaces plugins can extend.
 *
 * The review found two places where a plugin's public pages are invisible to
 * the core: the sitemap (no extension point at all) and the catalogue search
 * (only reads libri.search_index, so a periodical title looks like "no
 * results"). Two filters now cover both, and this test drives the REAL
 * production paths — SitemapGenerator::generate() against the real database,
 * FrontendController::catalog() rendering the real catalogue page — with a
 * real HookManager:
 *
 *  1. `sitemap.entries` receives the assembled entry list and its additions
 *     reach the XML (loc + lastmod + changefreq + priority);
 *  2. a listener that throws does NOT prevent the sitemap from being generated;
 *  3. malformed entries (no loc, off-site loc, protocol-relative, whitespace,
 *     over-long, non-array) are discarded, the valid ones survive;
 *  4. with no listener the sitemap is byte-identical to the unhooked baseline;
 *  5. `search.external_suggestions` yields an empty array by default and the
 *     catalogue page renders no hint;
 *  6. suggestions from a fake listener appear in the results HTML both when the
 *     catalogue found nothing AND when it found something;
 *  7. unsafe suggestions (javascript:, protocol-relative, non-string) never
 *     reach the HTML.
 *
 * Test data uses zz_* names; cleanup runs in finally.
 *
 * Run:  php tests/core-plugin-surface-140.unit.php
 */

use App\Controllers\FrontendController;
use App\Support\HookManager;
use App\Support\Hooks;
use App\Support\SitemapGenerator;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? 'fabiodal_biblioteca_user');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? 'Zd10)uwziWlK'));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? 'fabiodal_biblioteca');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            $dbUser,
            $dbPass,
            $dbName,
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    printf("[%02d] %s: %s\n", $TESTNO, $cond ? 'PASS' : 'FAIL', $desc);
    if (!$cond) {
        $failed++;
    }
}

$RUN = bin2hex(random_bytes(4));
$BASE = 'https://zz-sitemap-' . $RUN . '.test';
$_SESSION = [];

// Real HookManager, marked "runtime-loaded" so DB-registered plugin hooks are
// NOT pulled in: the test controls exactly which listeners exist.
$hookManager = new HookManager($db);
$hookManager->setPluginsLoadedRuntime();
Hooks::init($hookManager);
$resetHooks = static function () use ($hookManager): void {
    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
};

// The generator stamps "Generated on <ISO timestamp>" into the document, which
// changes between two runs a second apart; strip it before comparing.
$normalize = static fn(string $xml): string => (string) preg_replace('/<!--Generated on [^>]*-->/', '', $xml);

$bookId = 0;

try {
    // ===============================================================
    // A. sitemap.entries
    // ===============================================================
    $resetHooks();
    $baseline = $normalize((new SitemapGenerator($db, $BASE))->generate());
    check(str_contains($baseline, '<urlset'), 'baseline sitemap is a urlset document');
    check(str_contains($baseline, '<loc>' . $BASE . '/</loc>'), 'baseline sitemap contains the homepage');
    $baselineCount = substr_count($baseline, '<loc>');
    check($baselineCount > 0, "baseline sitemap has URLs ({$baselineCount})");

    // --- 4. no listener → identical output ------------------------------
    $resetHooks();
    $again = $normalize((new SitemapGenerator($db, $BASE))->generate());
    check($again === $baseline, 'with no sitemap.entries listener the sitemap is identical to the baseline');

    // --- 1. a listener can add a URL ------------------------------------
    $resetHooks();
    $seen = null;
    Hooks::add('sitemap.entries', function (array $entries, string $baseUrl, string $locale) use (&$seen, $BASE): array {
        $seen = ['count' => count($entries), 'baseUrl' => $baseUrl, 'locale' => $locale];
        $entries[] = [
            'loc' => $BASE . '/emeroteca/zz-testata',
            'lastmod' => '2026-01-02 03:04:05',
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ];
        return $entries;
    });

    $generator = new SitemapGenerator($db, $BASE);
    $xml = $generator->generate();
    $stats = $generator->getStats();

    check(is_array($seen), 'sitemap.entries listener was invoked');
    check(($seen['count'] ?? 0) === $baselineCount, 'listener received every core entry already collected');
    check(($seen['baseUrl'] ?? '') === $BASE, 'listener received the base URL as second argument');
    check(is_string($seen['locale'] ?? null) && $seen['locale'] !== '', 'listener received the default locale as third argument');
    check(str_contains($xml, '<loc>' . $BASE . '/emeroteca/zz-testata</loc>'), 'the plugin URL is present in the generated XML');
    check(str_contains($xml, '<changefreq>weekly</changefreq>'), 'the plugin changefreq reached the XML');
    check(str_contains($xml, '<priority>0.7</priority>'), 'the plugin priority reached the XML');
    check(str_contains($xml, '<lastmod>2026-01-02'), 'the plugin lastmod reached the XML');
    check(($stats['plugins'] ?? -1) === 1, "stats['plugins'] counts the added URL");
    check(($stats['total'] ?? 0) === $baselineCount + 1, 'total stat grew by exactly one URL');
    check(substr_count($xml, '<loc>') === $baselineCount + 1, 'XML holds baseline URLs plus the plugin one');

    // --- 2. a throwing listener must not break the generation ------------
    $resetHooks();
    Hooks::add('sitemap.entries', function (): array {
        throw new \RuntimeException('broken emeroteca sitemap listener');
    });
    $xmlThrow = $normalize((new SitemapGenerator($db, $BASE))->generate());
    check(str_contains($xmlThrow, '<urlset'), 'a throwing sitemap.entries listener still yields a sitemap');
    check($xmlThrow === $baseline, 'core entries survive intact when the listener throws');

    // A listener returning garbage instead of an array is equally harmless.
    $resetHooks();
    Hooks::add('sitemap.entries', static fn(): string => 'not-an-array');
    $xmlGarbage = $normalize((new SitemapGenerator($db, $BASE))->generate());
    check($xmlGarbage === $baseline, 'a non-array return value is discarded and core entries are kept');

    // --- 3. malformed entries are discarded, valid ones survive ----------
    $resetHooks();
    Hooks::add('sitemap.entries', function (array $entries) use ($BASE): array {
        $entries[] = 'not-an-array';                                     // wrong type
        $entries[] = ['changefreq' => 'daily'];                          // no loc
        $entries[] = ['loc' => ''];                                      // empty loc
        $entries[] = ['loc' => 'https://evil.example/steal'];            // off-site
        $entries[] = ['loc' => $BASE . '.evil.example/lookalike'];       // sibling host
        $entries[] = ['loc' => '//evil.example/protocol-relative'];      // protocol-relative
        $entries[] = ['loc' => $BASE . '/with space'];                   // whitespace
        $entries[] = ['loc' => $BASE . '/' . str_repeat('x', 4000)];     // over-long
        $entries[] = ['loc' => $BASE . '/emeroteca/zz-ok'];              // the only good one
        return $entries;
    });
    $generator = new SitemapGenerator($db, $BASE);
    $xmlMixed = $generator->generate();
    $statsMixed = $generator->getStats();

    check(str_contains($xmlMixed, '<loc>' . $BASE . '/emeroteca/zz-ok</loc>'), 'the valid entry of a mixed batch is kept');
    check(!str_contains($xmlMixed, 'evil.example'), 'off-site / protocol-relative / lookalike locs never reach the XML');
    check(!str_contains($xmlMixed, 'with space'), 'a loc containing whitespace is discarded');
    check(!str_contains($xmlMixed, str_repeat('x', 4000)), 'an over-long loc is discarded');
    check(($statsMixed['total'] ?? 0) === $baselineCount + 1, 'exactly one entry of the malformed batch was accepted');

    // Invalid optional fields are dropped without losing the URL.
    $resetHooks();
    Hooks::add('sitemap.entries', function (array $entries) use ($BASE): array {
        $entries[] = [
            'loc' => $BASE . '/emeroteca/zz-fields',
            'changefreq' => 'sometimes',   // not a sitemap-protocol value
            'priority' => '9.9',           // outside 0.0–1.0
            'lastmod' => 'not-a-date',
        ];
        return $entries;
    });
    $xmlFields = (new SitemapGenerator($db, $BASE))->generate();
    check(str_contains($xmlFields, '<loc>' . $BASE . '/emeroteca/zz-fields</loc>'), 'entry with invalid optional fields keeps its URL');
    check(!str_contains($xmlFields, 'sometimes'), 'an invalid changefreq is dropped');
    check(!str_contains($xmlFields, '<priority>9.9</priority>'), 'an out-of-range priority is dropped');
    check(!str_contains($xmlFields, 'not-a-date'), 'an unparseable lastmod is dropped');

    // The manual regeneration path (saveTo → generate) applies the filter too.
    $resetHooks();
    Hooks::add('sitemap.entries', function (array $entries) use ($BASE): array {
        $entries[] = ['loc' => $BASE . '/emeroteca/zz-saved', 'changefreq' => 'daily'];
        return $entries;
    });
    $tmpFile = sys_get_temp_dir() . "/zz-sitemap-{$RUN}.xml";
    (new SitemapGenerator($db, $BASE))->saveTo($tmpFile);
    $savedXml = (string) file_get_contents($tmpFile);
    check(
        str_contains($savedXml, '<loc>' . $BASE . '/emeroteca/zz-saved</loc>'),
        'saveTo() (admin regenerate button / CLI script) applies the filter as well'
    );

    // ===============================================================
    // B. search.external_suggestions
    // ===============================================================
    $controller = new FrontendController();
    $renderCatalog = static function (string $term) use ($controller, $db): string {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/catalogo')
            ->withQueryParams(['q' => $term]);
        return (string) $controller
            ->catalog($request, (new ResponseFactory())->createResponse(), $db)
            ->getBody();
    };
    $totalCount = static function (string $html): int {
        return preg_match('/<strong id="total-count">([\d.,]+)<\/strong>/', $html, $m) === 1
            ? (int) str_replace([',', '.'], '', $m[1])
            : -1;
    };

    // --- 5. no listener → no hint ---------------------------------------
    $resetHooks();
    $missTerm = 'zzmiss' . $RUN;
    $htmlNoHook = $renderCatalog($missTerm);
    check($totalCount($htmlNoHook) === 0, 'the control term matches no book in the catalogue');
    check(!str_contains($htmlNoHook, 'id="external-search-suggestions"'), 'without a listener the federated-search hint is not rendered');

    // --- 6a. zero results + listener → hint rendered ---------------------
    $resetHooks();
    $received = null;
    Hooks::add('search.external_suggestions', function (array $suggestions, string $term) use (&$received): array {
        $received = ['initial' => $suggestions, 'term' => $term];
        $suggestions[] = ['label' => 'zz Emeroteca (2 testate)', 'url' => '/emeroteca?q=' . rawurlencode($term)];
        return $suggestions;
    });
    $htmlHint = $renderCatalog($missTerm);
    check(($received['initial'] ?? null) === [], 'the filter starts from an empty suggestion array');
    check(($received['term'] ?? '') === $missTerm, 'the listener receives the raw search term');
    check(str_contains($htmlHint, 'id="external-search-suggestions"'), 'the hint block is rendered when a listener answers');
    check(str_contains($htmlHint, 'zz Emeroteca (2 testate)'), 'the suggestion label appears in the results HTML');
    check(str_contains($htmlHint, '/emeroteca?q=' . $missTerm), 'the suggestion URL appears in the results HTML');
    check($totalCount($htmlHint) === 0, 'the hint is shown on a zero-result search');

    // --- 6b. results present + listener → hint still rendered ------------
    $hitTerm = 'zzsuggest' . $RUN;
    $stmt = $db->prepare('INSERT INTO libri (titolo, search_index) VALUES (?, ?)');
    $seedTitle = $hitTerm . ' Rivista';
    $seedIndex = $hitTerm . ' rivista';
    $stmt->bind_param('ss', $seedTitle, $seedIndex);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;

    $htmlWithBooks = $renderCatalog($hitTerm);
    check($totalCount($htmlWithBooks) >= 1, 'the seeded book is found by the catalogue search');
    check(str_contains($htmlWithBooks, 'zz Emeroteca (2 testate)'), 'the hint is shown even when the catalogue has results');

    // --- 7. unsafe / malformed suggestions never reach the HTML ----------
    $resetHooks();
    Hooks::add('search.external_suggestions', function (array $suggestions): array {
        $suggestions[] = ['label' => 'zz Javascript', 'url' => 'javascript:alert(1)'];
        $suggestions[] = ['label' => 'zz Offsite', 'url' => 'https://evil.example/x'];
        $suggestions[] = ['label' => 'zz Protocol', 'url' => '//evil.example/x'];
        $suggestions[] = ['label' => 'zz NoUrl'];
        $suggestions[] = ['url' => '/emeroteca?q=x'];                      // no label
        $suggestions[] = ['label' => ['array'], 'url' => '/emeroteca'];    // wrong type
        $suggestions[] = 'not-an-array';
        $suggestions[] = ['label' => 'zz <script>alert(1)</script>', 'url' => '/emeroteca?q=xss'];
        return $suggestions;
    });
    $htmlUnsafe = $renderCatalog($missTerm);
    check(!str_contains($htmlUnsafe, 'javascript:alert(1)'), 'a javascript: suggestion URL is rejected');
    check(!str_contains($htmlUnsafe, 'evil.example'), 'off-site and protocol-relative suggestion URLs are rejected');
    check(!str_contains($htmlUnsafe, 'zz Javascript') && !str_contains($htmlUnsafe, 'zz Offsite'), 'rejected suggestions are not rendered at all');
    check(!str_contains($htmlUnsafe, 'zz NoUrl'), 'a suggestion without url is skipped');
    check(str_contains($htmlUnsafe, '/emeroteca?q=xss'), 'the one valid suggestion of the batch survives');
    check(
        !str_contains($htmlUnsafe, 'zz <script>alert(1)</script>')
            && str_contains($htmlUnsafe, 'zz &lt;script&gt;alert(1)&lt;/script&gt;'),
        'suggestion labels are HTML-escaped, not interpreted'
    );

    // A throwing listener must not break the catalogue page.
    $resetHooks();
    Hooks::add('search.external_suggestions', function (): array {
        throw new \RuntimeException('broken emeroteca suggestion listener');
    });
    $htmlThrow = $renderCatalog($missTerm);
    check(str_contains($htmlThrow, 'id="books-container"'), 'a throwing suggestion listener still renders the catalogue page');
    check(!str_contains($htmlThrow, 'id="external-search-suggestions"'), 'no hint is rendered when the listener throws');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    try {
        $resetHooks();
        if ($bookId > 0) {
            $db->query("DELETE FROM libri WHERE id = {$bookId}");
        }
        if (isset($tmpFile) && is_string($tmpFile) && is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    } catch (\Throwable $cleanupError) {
        fwrite(STDERR, 'cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }
    $db->close();
}

echo "\n";
if ($failed > 0) {
    echo "RESULT: {$failed} of {$TESTNO} checks FAILED\n";
    exit(1);
}
echo "RESULT: all {$TESTNO} checks passed\n";
exit(0);
