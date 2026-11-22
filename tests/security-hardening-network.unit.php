<?php
declare(strict_types=1);

/**
 * Security hardening 2026-11 (group C, network) — DB-free regressions.
 *
 *  6   HttpClient ssrf_guard pins BOTH spellings of the host ("x" and "x."),
 *      so a same-host redirect to the other spelling cannot re-enter DNS.
 *  7   ScrapeController accepts only canonical OpenLibrary author keys.
 *  8   /proxy/cover validates resolved IPs with SsrfGuard::isPublicIp.
 *  10  The shadowed GET /uploads/storage/{filepath} route is gone.
 *  88  UnimarcLibriParser loads XML with LIBXML_NONET.
 *  107 Discogs throttle wait is clamped to the configured interval.
 *
 * No network: DNS is stubbed at namespace level in App\Support, and Guzzle
 * runs on a MockHandler that records the cURL options of each request.
 *
 * Run:  php tests/security-hardening-network.unit.php   (exit 0 iff all pass)
 */

namespace App\Support {
    /** @var array<string, list<string>> */
    $GLOBALS['__hardening_dns'] = [];

    function gethostbynamel(string $hostname): array|false
    {
        return $GLOBALS['__hardening_dns'][strtolower($hostname)] ?? false;
    }

    function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
    {
        return [];
    }
}

namespace {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    require_once $root . '/storage/plugins/discogs/DiscogsPlugin.php';

    use App\Support\HttpClient;
    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response;

    $pass = 0;
    $fail = 0;
    $check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
        if ($ok) { $pass++; echo "  OK  {$label}\n"; }
        else     { $fail++; echo "  FAIL {$label}\n"; }
    };

    // ── 6. HttpClient pin covers both host spellings ─────────────────────────
    echo "6. HttpClient CURLOPT_RESOLVE pins both host spellings\n";
    if (!extension_loaded('curl')) {
        echo "  SKIP curl extension not loaded\n";
    } else {
        $GLOBALS['__hardening_dns'] = ['meta.example.org' => ['93.184.216.34']];
        /** @var list<array<int, mixed>> $seen */
        $seen = [];
        $mock = new MockHandler([
            static function ($request, array $options) use (&$seen) {
                $seen[] = (array) ($options['curl'][CURLOPT_RESOLVE] ?? []);
                return new Response(200, [], 'ok');
            },
            static function ($request, array $options) use (&$seen) {
                $seen[] = (array) ($options['curl'][CURLOPT_RESOLVE] ?? []);
                return new Response(200, [], 'ok');
            },
        ]);
        $prop = new ReflectionProperty(HttpClient::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue(null, new Client(['handler' => HandlerStack::create($mock)]));

        foreach (['https://meta.example.org/x.json', 'https://META.example.org./x.json'] as $url) {
            $res = HttpClient::get($url, [], ['ssrf_guard' => true]);
            $check($res['ok'] === true, "request to {$url} goes out");
        }
        foreach ($seen as $n => $entries) {
            $check(in_array('meta.example.org:443:93.184.216.34', $entries, true), "request #{$n} pins meta.example.org:443");
            $check(in_array('meta.example.org.:443:93.184.216.34', $entries, true), "request #{$n} pins meta.example.org.:443 (trailing dot)");
        }

        $entries = (new ReflectionMethod(HttpClient::class, 'curlResolveEntries'))->invoke(null, '[2001:db8::1]', [443], '2001:db8::1');
        $check($entries === ['[2001:db8::1]:443:[2001:db8::1]'], 'an IPv6 literal host gets no trailing-dot alias');
        $prop->setValue(null, null);
    }

    // ── 7. OpenLibrary author key ────────────────────────────────────────────
    echo "7. ScrapeController::isOpenLibraryAuthorKey\n";
    $isKey = [\App\Controllers\ScrapeController::class, 'isOpenLibraryAuthorKey'];
    $check($isKey('/authors/OL23919A') === true, 'canonical key accepted');
    foreach (['@evil.example/x', '/authors/OL1A/../../admin', '/authors/OL1A?x=1', '/authors/OL1A' . "\n", '/works/OL1W', '', null, ['k']] as $bad) {
        $check($isKey($bad) === false, 'rejected: ' . json_encode($bad));
    }
    $scrape = (string) file_get_contents($root . '/app/Controllers/ScrapeController.php');
    $check(str_contains($scrape, "'.json', 5, true)"), 'author fetch runs with ssrf_guard');

    // ── 8. /proxy/cover uses SsrfGuard::isPublicIp ───────────────────────────
    echo "8. Proxy routes use SsrfGuard::isPublicIp\n";
    $web = (string) file_get_contents($root . '/app/Routes/web.php');
    $check(!str_contains($web, 'FILTER_FLAG_NO_PRIV_RANGE'), 'no raw filter_var private-range check left in web.php');
    $coverStart = strpos($web, "\$app->get('/proxy/cover'");
    $coverEnd = $coverStart !== false ? strpos($web, '$app->', $coverStart + 10) : false;
    $coverRoute = ($coverStart !== false && $coverEnd !== false) ? substr($web, $coverStart, $coverEnd - $coverStart) : '';
    $check(substr_count($coverRoute, 'SsrfGuard::isPublicIp') >= 2, '/proxy/cover validates A and AAAA records with isPublicIp');
    $check(\App\Support\SsrfGuard::isPublicIp('64:ff9b::7f00:1') === false, 'NAT64 loopback is not public');

    // ── 10. dead /uploads/storage route removed ──────────────────────────────
    echo "10. Shadowed /uploads/storage/{filepath} route\n";
    $check(!str_contains($web, "'/uploads/storage/{filepath"), 'the dead route is no longer registered');
    $check(str_contains($web, "'/uploads/{path:.*}'"), 'the ProtectedUploadController catch-all is still registered');

    // ── 88. LIBXML_NONET ─────────────────────────────────────────────────────
    echo "88. UnimarcLibriParser LIBXML_NONET\n";
    $parser = (string) file_get_contents($root . '/app/Support/Marc/UnimarcLibriParser.php');
    $check(str_contains($parser, 'loadXML($xml, LIBXML_NONET)'), 'loadXML passes LIBXML_NONET');
    $parsed = (new \App\Support\Marc\UnimarcLibriParser())->fromUnimarcXml(
        '<record><datafield tag="200" ind1="1" ind2=" "><subfield code="a">Titolo</subfield></datafield></record>'
    );
    $check(($parsed['titolo'] ?? null) === 'Titolo', 'a plain record still parses');

    // ── 107. Discogs throttle clamp ──────────────────────────────────────────
    echo "107. Discogs throttle wait clamp\n";
    $wait = [\App\Plugins\Discogs\DiscogsPlugin::class, 'throttleWaitMicros'];
    $now = 1_000_000.0;
    $check($wait($now - 10.0, 1.0, $now) === 0, 'an old timestamp needs no wait');
    $check($wait($now - 0.25, 1.0, $now) === 750_000, 'a recent timestamp waits the remainder');
    $check($wait($now + 86_400.0 * 365, 2.5, $now) === 2_500_000, 'a far-future timestamp waits at most the interval');
    $discogs = (string) file_get_contents($root . '/storage/plugins/discogs/DiscogsPlugin.php');
    $check(str_contains($discogs, "dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache'"), 'throttle file lives in storage/cache');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
