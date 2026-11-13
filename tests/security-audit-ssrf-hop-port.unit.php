<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — SsrfGuard::fetchImage must pin every hop's port.
 *
 * fetchImage() validates each redirect hop's host and pins the connection to
 * the validated IP through CURLOPT_RESOLVE. It used to pin only host:80 and
 * host:443, so a hop to host:8099 made cURL resolve the host itself after
 * validation: DNS rebinding then reached internal HTTP services. Cover fetches
 * never need another port, so a non-default-port hop is refused before any
 * connection, and every connection pins the exact port it uses.
 *
 * No network: gethostbynamel/dns_get_record and the curl_* calls are replaced
 * by namespace-level stubs in App\Support (SsrfGuard calls them unqualified),
 * which record every connection cURL would have made.
 *
 * Run:  php tests/security-audit-ssrf-hop-port.unit.php   (exit 0 iff all pass)
 */

namespace App\Support {
    /** @var array<string, mixed> */
    $GLOBALS['__ssrf_stub'] = [
        'dns' => [],        // host => list<ipv4>
        'responses' => [],  // url => [code, body, redirectUrl]
        'connections' => [], // list<array{url:string, resolve:list<string>}>
    ];

    function gethostbynamel(string $hostname): array|false
    {
        return $GLOBALS['__ssrf_stub']['dns'][strtolower($hostname)] ?? false;
    }

    function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
    {
        return [];
    }

    function curl_init(?string $url = null): \stdClass
    {
        $h = new \stdClass();
        $h->url = (string) $url;
        $h->opts = [];
        return $h;
    }

    function curl_setopt_array(\stdClass $h, array $opts): bool
    {
        $h->opts = $opts + $h->opts;
        return true;
    }

    function curl_setopt(\stdClass $h, int $opt, mixed $value): bool
    {
        $h->opts[$opt] = $value;
        return true;
    }

    function curl_exec(\stdClass $h): string|false
    {
        $GLOBALS['__ssrf_stub']['connections'][] = [
            'url' => $h->url,
            'resolve' => array_values((array) ($h->opts[CURLOPT_RESOLVE] ?? [])),
        ];
        $h->response = $GLOBALS['__ssrf_stub']['responses'][$h->url] ?? [404, '', ''];
        return $h->response[1];
    }

    function curl_getinfo(\stdClass $h, int $opt): mixed
    {
        return match ($opt) {
            CURLINFO_HTTP_CODE => $h->response[0] ?? 0,
            CURLINFO_REDIRECT_URL => $h->response[2] ?? '',
            default => null,
        };
    }
}

namespace {
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';

    use App\Support\SsrfGuard;

    if (!extension_loaded('curl')) {
        echo "SKIP: curl extension not loaded (fetchImage returns null before any hop)\n";
        exit(0);
    }

    $pass = 0;
    $fail = 0;
    $check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
        if ($ok) { $pass++; echo "  OK  {$label}\n"; }
        else     { $fail++; echo "  FAIL {$label}\n"; }
    };

    // A real (tiny) PNG so getimagesizefromstring() accepts the final body.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
    $png .= str_repeat("\0", 200); // above the 100-byte floor

    /** Every connection must pin the port its URL actually uses. */
    $everyConnectionPinned = static function (): bool {
        foreach ($GLOBALS['__ssrf_stub']['connections'] as $c) {
            $p = parse_url($c['url']);
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            $want = strtolower($p['host']) . ':' . $port . ':';
            $found = false;
            foreach ($c['resolve'] as $entry) {
                if (str_starts_with($entry, $want)) { $found = true; }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    };

    $reset = static function (array $dns, array $responses): void {
        $GLOBALS['__ssrf_stub'] = ['dns' => $dns, 'responses' => $responses, 'connections' => []];
    };

    echo "A. Redirect hop to a non-default port (the rebinding vector)\n";
    $reset(
        ['covers.example.com' => ['93.184.216.34'], 'rebind.example.net' => ['93.184.216.35']],
        [
            'https://covers.example.com/cover.jpg' => [302, '', 'http://rebind.example.net:8099/internal-admin?action=probe'],
            'http://rebind.example.net:8099/internal-admin?action=probe' => [200, $png, ''],
        ]
    );
    $result = SsrfGuard::fetchImage('https://covers.example.com/cover.jpg', 5 * 1024 * 1024);
    $urls = array_column($GLOBALS['__ssrf_stub']['connections'], 'url');
    $check($result === null, 'fetchImage refuses the chain (returns null)');
    $check(!in_array('http://rebind.example.net:8099/internal-admin?action=probe', $urls, true),
        'no connection is ever attempted to the :8099 hop');
    $check($everyConnectionPinned(), 'every connection that did happen pinned its own port');

    echo "B. Initial URL on a non-default port\n";
    $reset(['covers.example.com' => ['93.184.216.34']], ['https://covers.example.com:8443/c.jpg' => [200, $png, '']]);
    $check(SsrfGuard::fetchImage('https://covers.example.com:8443/c.jpg', 5 * 1024 * 1024) === null,
        'https on :8443 is refused');
    $check($GLOBALS['__ssrf_stub']['connections'] === [], '... before any connection');

    echo "C. Legitimate chains keep working, pinned on the port they use\n";
    $reset(
        ['covers.openlibrary.org' => ['207.241.224.2'], 'ia800100.us.archive.org' => ['207.241.224.3']],
        [
            'http://covers.openlibrary.org/b/isbn/1.jpg' => [302, '', 'https://ia800100.us.archive.org/view/1.jpg'],
            'https://ia800100.us.archive.org/view/1.jpg' => [200, $png, ''],
        ]
    );
    $result = SsrfGuard::fetchImage('http://covers.openlibrary.org/b/isbn/1.jpg', 5 * 1024 * 1024);
    $check(is_array($result) && $result[1] === 'image/png', 'http -> https redirect chain returns the image');
    $check(count($GLOBALS['__ssrf_stub']['connections']) === 2, 'two hops were fetched');
    $check($everyConnectionPinned(), 'both hops pinned host:port to the validated IP');
    $resolves = array_merge(...array_column($GLOBALS['__ssrf_stub']['connections'], 'resolve'));
    $check(in_array('covers.openlibrary.org:80:207.241.224.2', $resolves, true), 'hop 1 pinned on :80');
    $check(in_array('ia800100.us.archive.org:443:207.241.224.3', $resolves, true), 'hop 2 pinned on :443');

    $reset(['img.example.org' => ['93.184.216.36']], ['https://img.example.org:443/x.png' => [200, $png, '']]);
    $check(is_array(SsrfGuard::fetchImage('https://img.example.org:443/x.png', 5 * 1024 * 1024)),
        'an explicit :443 is still accepted');

    echo "D. Host resolving to shared address space is refused\n";
    $reset(['cgnat.example.net' => ['100.64.0.10']], ['https://cgnat.example.net/x.png' => [200, $png, '']]);
    $check(SsrfGuard::fetchImage('https://cgnat.example.net/x.png', 5 * 1024 * 1024) === null, '100.64.0.10 is not fetched');
    $check($GLOBALS['__ssrf_stub']['connections'] === [], '... and no connection is attempted');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
