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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
