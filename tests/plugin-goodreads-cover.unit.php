<?php
declare(strict_types=1);

/**
 * Unit test for the Goodreads cover scraper in OpenLibraryPlugin
 * (replaces the retired bookcover.longitood.com service).
 *
 * The scraper fetches the Goodreads book page by shelling out to the system
 * `curl` binary — Cloudflare's anti-bot serves a 202 to PHP's HTTP client but
 * 200 to curl's TLS fingerprint — and parses the og:image with a trusted-CDN
 * host guard. The parsing is tested deterministically against HTML fixtures
 * (no network). A final best-effort live check hits Goodreads only when a curl
 * binary is available and the page is reachable, and never fails the suite when
 * exec is disabled or the network is unavailable.
 *
 * Run:  php tests/plugin-goodreads-cover.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/storage/plugins/open-library/OpenLibraryPlugin.php';

use App\Plugins\OpenLibrary\OpenLibraryPlugin;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$plugin  = new OpenLibraryPlugin();
$extractM = new ReflectionMethod($plugin, 'extractGoodreadsCoverFromHtml');
$extractM->setAccessible(true);
$coverM  = new ReflectionMethod($plugin, 'getGoodreadsCover');
$coverM->setAccessible(true);
$extract = static fn (string $html) => $extractM->invoke($plugin, $html);
$cover   = static fn (string $isbn) => $coverM->invoke($plugin, $isbn);

echo "A. extractGoodreadsCoverFromHtml — og:image parsing (deterministic)\n";
$amazon = 'https://m.media-amazon.com/images/S/compressed.photo.goodreads.com/books/1327269904i/9661681.jpg';
$check($extract('<meta property="og:image" content="' . $amazon . '">') === $amazon, 'Amazon/Goodreads CDN og:image extracted');
$check($extract('<meta property="og:image" content="https://i.gr-assets.com/books/1.jpg">') === 'https://i.gr-assets.com/books/1.jpg', 'gr-assets.com CDN accepted');
$check($extract('<meta content="https://i.gr-assets.com/books/2.jpg" property="og:image">') === 'https://i.gr-assets.com/books/2.jpg', 'reversed content/property attribute order handled');
$check($extract("<meta property='og:image' content='https://i.gr-assets.com/books/3.jpg'>") === 'https://i.gr-assets.com/books/3.jpg', 'single-quoted attributes handled');
$check($extract('<meta property="og:image" content="https://m.media-amazon.com/a.jpg?a=1&amp;b=2">') === 'https://m.media-amazon.com/a.jpg?a=1&b=2', 'HTML entities in URL decoded');

echo "B. extractGoodreadsCoverFromHtml — rejects untrusted / invalid (security)\n";
$check($extract('<meta property="og:image" content="https://evil.example.com/x.jpg">') === null, 'untrusted host rejected (anti-spoof/SSRF)');
$check($extract('<meta property="og:image" content="https://media-amazon.com.evil.com/x.jpg">') === null, 'look-alike host (suffix trick) rejected');
$check($extract('<meta property="og:image" content="https://evilgr-assets.com/x.jpg">') === null, 'look-alike host without label boundary rejected');
$check($extract('<meta property="og:image" content="http://i.gr-assets.com/x.jpg">') === null, 'non-HTTPS cover rejected');
$check($extract('<meta property="og:image" content="ftp://i.gr-assets.com/x.jpg">') === null, 'non-HTTP cover scheme rejected');
$check($extract('<meta property="og:image" content="not-a-valid-url">') === null, 'malformed URL rejected');
$check($extract('<html><head><title>no cover here</title></head></html>') === null, 'no og:image tag → null');
$check($extract('') === null, 'empty HTML → null');

echo "C. getGoodreadsCover — ISBN validation (no network request)\n";
$check($cover('abc') === null, 'non-numeric ISBN → null');
$check($cover('123') === null, 'too-short code → null');
$check($cover('') === null, 'empty ISBN → null');
$check($cover('97888452926131234') === null, 'over-long code → null');

echo "D. Live Goodreads scrape (best-effort — skipped if unreachable)\n";
$reachable = @get_headers('https://www.goodreads.com/', true);
if ($reachable === false) {
    echo "  SKIP Goodreads unreachable — live check not run (not a failure)\n";
} else {
    $live = $cover('9788845292613'); // Il Signore degli Anelli — known to be on Goodreads
    if ($live === null) {
        echo "  SKIP live scrape returned null (rate-limit/transient) — not counted as failure\n";
    } else {
        $host = parse_url($live, PHP_URL_HOST) ?: '';
        $trustedHost = $host === 'gr-assets.com'
            || str_ends_with($host, '.gr-assets.com')
            || $host === 'media-amazon.com'
            || str_ends_with($host, '.media-amazon.com');
        $check(is_string($live) && str_starts_with($live, 'https://') && $trustedHost, "live HTTPS cover from trusted CDN: {$host}");
    }
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
