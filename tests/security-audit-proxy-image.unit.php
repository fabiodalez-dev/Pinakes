<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — GET /api/plugins/proxy-image.
 *
 * The route used to be anonymous: any caller could make a PHP worker run a
 * blocking DNS lookup and a 10 s HTTPS download from a host it controls, with
 * no byte cap (curl_exec buffered the whole body). Its only caller is the cover
 * preview in the staff book form, so it now sits behind AdminAuthMiddleware,
 * aborts past 5 MB (CURLOPT_MAXFILESIZE + a progress callback) and validates
 * the resolved addresses with SsrfGuard::isPublicIp().
 *
 * The route is checked statically (registering web.php needs the database, as
 * tests/calendar-permission.unit.php does), and AdminAuthMiddleware is run for
 * real on the route path without a session.
 *
 * Run:  php tests/security-audit-proxy-image.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$routes = (string) file_get_contents($root . '/app/Routes/web.php');
$start = strpos($routes, "\$app->get('/api/plugins/proxy-image'");
$check($start !== false, 'the proxy-image route is registered');
if ($start === false) {
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}
// The route body runs until the closing "})" that ends this registration.
$end = strpos($routes, "\n    });", $start);
$endAdd = strpos($routes, "\n    })->add(", $start);
$stop = min(array_filter([$end, $endAdd], static fn ($v) => $v !== false));
$tailEnd = strpos($routes, "\n", $stop + 2);
$block = substr($routes, $start, ($tailEnd === false ? strlen($routes) : $tailEnd) - $start);

echo "A. Authentication\n";
$check(str_contains($block, "})->add(new AdminAuthMiddleware());"),
    'the route is wrapped in AdminAuthMiddleware');

echo "B. Byte cap on the download\n";
$check(str_contains($block, 'CURLOPT_MAXFILESIZE'), 'CURLOPT_MAXFILESIZE refuses an announced oversize body');
$check(str_contains($block, 'CURLOPT_NOPROGRESS => false') && str_contains($block, 'CURLOPT_PROGRESSFUNCTION'),
    'a progress callback aborts a body that grows past the cap');
$check(preg_match('/strlen\(\$img\)\s*>\s*\$maxProxyBytes/', $block) === 1, 'the buffered body is re-checked against the cap');

echo "C. Address validation uses the audited predicate\n";
$check(substr_count($block, 'SsrfGuard::isPublicIp(') >= 2, 'both A and AAAA answers go through SsrfGuard::isPublicIp()');
$check(!str_contains($block, 'FILTER_FLAG_NO_PRIV_RANGE'), 'no bare NO_PRIV_RANGE filter is left (it passes 100.64.0.0/10)');
$check(str_contains($block, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($block, 'CURLOPT_RESOLVE'),
    'redirects stay off and the connection stays pinned');

echo "D. Response is not cacheable by shared caches\n";
$check(str_contains($block, "'Cache-Control', 'private"), 'Cache-Control is private (the body is now per-session)');

echo "E. AdminAuthMiddleware refuses an anonymous caller on this path\n";
$_SESSION = [];
$request = (new Slim\Psr7\Factory\ServerRequestFactory())
    ->createServerRequest('GET', '/api/plugins/proxy-image?url=' . rawurlencode('https://example.com/a.jpg'));
$reached = false;
$handler = new class ($reached) implements Psr\Http\Server\RequestHandlerInterface {
    public function __construct(private bool &$reached) {}
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        $this->reached = true;
        return new Slim\Psr7\Response(200);
    }
};
$res = (new App\Middleware\AdminAuthMiddleware())->process($request, $handler);
$check($res->getStatusCode() === 401, 'anonymous request gets 401 (got ' . $res->getStatusCode() . ')');
$check($reached === false, 'the proxy handler is never reached');

// Review of PR #536 (F077, F079, F029).
echo "E. The anonymous /proxy/cover gets the same guards\n";
$cStart = (int) strpos($routes, '$app->get(\'/proxy/cover\'');
$cover = substr($routes, $cStart, (int) strpos($routes, "\n    });", $cStart) - $cStart);
$check($cStart > 0 && substr_count($cover, 'SsrfGuard::isPublicIp(') >= 2 && !str_contains($cover, 'FILTER_FLAG_NO_PRIV_RANGE'),
    'A and AAAA answers go through SsrfGuard::isPublicIp(), no bare filter_var left');
$check(str_contains($cover, 'if ($coverPort !== 443)'), 'only the default HTTPS port is accepted');
$check(str_contains($cover, 'CURLOPT_MAXFILESIZE') && str_contains($cover, 'CURLOPT_PROGRESSFUNCTION')
    && str_contains($cover, 'strlen($img) > \\App\\Support\\SsrfGuard::PROXY_MAX_BYTES'),
    'a byte cap, announced, streamed and re-checked');
$check(str_contains($block, 'if ($port !== 443)'), 'proxy-image accepts only the default HTTPS port too');

echo "F. Neither proxy relays SVG, and relayed images cannot run script\n";
$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
$check(\App\Support\SsrfGuard::proxiedImageMime($svg) === null, 'an SVG body is refused');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$check(\App\Support\SsrfGuard::proxiedImageMime($png) === 'image/png', 'a PNG is relayed (control)');
$h = \App\Support\SsrfGuard::proxiedImageHeaders(new Slim\Psr7\Response(200), 'image/png');
$check($h->getHeaderLine('X-Content-Type-Options') === 'nosniff' && str_contains($h->getHeaderLine('Content-Security-Policy'), 'sandbox'),
    'relayed images carry nosniff and a sandboxing CSP');
$check(substr_count($routes, 'SsrfGuard::proxiedImageMime($img)') === 2 && substr_count($routes, 'SsrfGuard::proxiedImageHeaders($response') === 2,
    'both proxies use the raster check and the headers');

echo "G. handleCoverUrl() fetches through SsrfGuard (F029)\n";
$libri = (string) file_get_contents($root . '/app/Controllers/LibriController.php');
$hStart = (int) strpos($libri, 'private function handleCoverUrl(');
$handle = substr($libri, $hStart, (int) strpos($libri, 'private function handleCoverUpload(', $hStart) - $hStart);
$check($hStart > 0 && str_contains($handle, 'SsrfGuard::fetchImage($url,') && !str_contains($handle, 'gethostbyname(')
    && !str_contains($handle, 'FILTER_FLAG_NO_PRIV_RANGE') && !str_contains($handle, 'curl_init('),
    'no own resolution, filter_var or cURL left: the audited fetch does it all');
$guard = (string) file_get_contents($root . '/app/Support/SsrfGuard.php');
$fStart = (int) strpos($guard, 'public static function fetchImage(');
$check(str_contains(substr($guard, $fStart), 'CURLOPT_MAXFILESIZE     => $maxBytes') && str_contains(substr($guard, $fStart), 'CURLOPT_PROGRESSFUNCTION'),
    'SsrfGuard::fetchImage() enforces its cap while streaming');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
