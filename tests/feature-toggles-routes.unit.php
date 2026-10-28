<?php
declare(strict_types=1);

/**
 * Route gate of the optional modules (plan §1.5, §2): source invariant over
 * app/Routes/web.php and the z39-server plugin, plus the middleware's
 * behaviour.
 *  - every route of a module carries FeatureGateMiddleware(<its key>), added
 *    FIRST (so it runs after authentication: a visitor who is not signed in
 *    goes to the login, not to a refusal that reveals the configuration);
 *  - no other route carries it (the loans ICS feed and rotate-link stay);
 *  - Permissions::permissionForPath() is unchanged (defence in depth);
 *  - off: 302 to the dashboard, or 404 feature_disabled for JSON callers;
 *    on: the request reaches the handler.
 *
 * Run: php tests/feature-toggles-routes.unit.php (the exit code is the verdict)
 */

use App\Middleware\FeatureGateMiddleware;
use App\Support\Features;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** The module of an admin path, null when the path is not gated (plan §2). */
$expected = static function (string $path): ?string {
    return match (true) {
        str_starts_with($path, '/admin/inventory') => 'stocktake',
        in_array($path, ['/admin/books/import/marc', '/admin/books/import/marc/upload', '/admin/books/import/marc/chunk', '/admin/books/export/marc'], true) => 'marc',
        str_starts_with($path, '/admin/books/copy-cataloging') => 'marc',
        in_array($path, ['/admin/books/{id:\d+}/catalogue-card', '/admin/books/catalogue-cards'], true) => 'catalogue_cards',
        str_starts_with($path, '/admin/suppliers') => 'suppliers',
        str_starts_with($path, '/admin/attendance') => 'visitor_log',
        in_array($path, ['/admin/settings/cards', '/admin/users/{id:\d+}/card-pdf', '/admin/users/cards-pdf', '/admin/users/{id:\d+}/photo', '/admin/users/{id:\d+}/photo/delete'], true) => 'patron_cards',
        str_starts_with($path, '/admin/users/import') => 'user_import',
        str_starts_with($path, '/admin/settings/circulation'), $path === '/admin/books/copies/{id:\d+}/consultation' => 'circulation_rules',
        ($path === '/admin/calendar' || str_starts_with($path, '/admin/calendar/')) && $path !== '/admin/calendar/rotate-link' => 'closures_calendar',
        $path === '/admin/statistics/fines.csv', str_starts_with($path, '/admin/users/{id:\d+}/ledger/') => 'fines',
        default => null,
    };
};

/**
 * Each route registration: path, the gate key (or null) and whether the gate
 * is the first middleware added.
 *
 * @return list<array{path: string, gate: ?string, first: bool}>
 */
$routes = static function (string $source): array {
    $out = [];
    preg_match_all('/\$app->(?:get|post)\(\'([^\']+)\',/', $source, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => [$path, $offset]) {
        $next = $m[0][$i + 1][1] ?? strlen($source);
        $chunk = substr($source, $offset, $next - $offset);
        $gate = preg_match("/FeatureGateMiddleware\\('([a-z_]+)'\\)/", $chunk, $g) === 1 ? $g[1] : null;
        // First ->add() after the handler closes (`})` or `$inventory('x'))`).
        $first = $gate !== null && preg_match("/(?:\\}\\)|\\('[a-zA-Z]+'\\)\\))->add\\(new \\\\App\\\\Middleware\\\\FeatureGateMiddleware\\('{$gate}'\\)\\)/", $chunk) === 1;
        $out[] = ['path' => $path, 'gate' => $gate, 'first' => $first];
    }
    return $out;
};

$web = $routes((string) file_get_contents($root . '/app/Routes/web.php'));
$z39 = $routes((string) file_get_contents($root . '/storage/plugins/z39-server/Z39ServerPlugin.php'));
$all = array_merge($web, $z39);
$check(count($all) > 300, 'the route files were parsed (' . count($all) . ' routes)');

$gatedByKey = [];
$wrong = [];
$notFirst = [];
foreach ($all as $route) {
    $want = $expected($route['path']);
    if ($want !== $route['gate']) {
        $wrong[] = $route['path'] . ' (gate ' . ($route['gate'] ?? 'none') . ', expected ' . ($want ?? 'none') . ')';
    }
    if ($route['gate'] !== null) {
        $gatedByKey[$route['gate']] = ($gatedByKey[$route['gate']] ?? 0) + 1;
        if (!$route['first']) {
            $notFirst[] = $route['path'];
        }
    }
}
$check($wrong === [], 'every module route carries its own gate and no other route carries one' . ($wrong !== [] ? ': ' . implode('; ', array_slice($wrong, 0, 5)) : ''));
$check($notFirst === [], 'the gate is the first middleware added (it runs after authentication)' . ($notFirst !== [] ? ': ' . implode('; ', $notFirst) : ''));
$routeModules = ['stocktake', 'marc', 'catalogue_cards', 'suppliers', 'visitor_log', 'patron_cards', 'user_import', 'circulation_rules', 'closures_calendar', 'fines'];
foreach ($routeModules as $key) {
    $check(($gatedByKey[$key] ?? 0) > 0, "{$key}: " . ($gatedByKey[$key] ?? 0) . ' gated routes');
}
// rda_types has no route of its own: its fields live in the book form.
$check(!isset($gatedByKey['rda_types']), 'rda_types gates no route (form fields only)');
$check(count(array_filter($all, static fn (array $r): bool => $r['path'] === '/admin/calendar/rotate-link' && $r['gate'] === null)) === 1, 'the loans ICS rotate-link is not part of the closures calendar module');

// Defence in depth: the permission map is unchanged.
$check(\App\Support\Permissions::permissionForPath('/admin/inventory') === 'inventory.manage', 'permissionForPath(/admin/inventory) is still inventory.manage');
$check(\App\Support\Permissions::permissionForPath('/admin/suppliers') !== null, 'permissionForPath(/admin/suppliers) is still mapped');

// Behaviour.
$handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public int $calls = 0;
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $this->calls++;
        return new \Slim\Psr7\Response(200);
    }
};
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$_SESSION = [];
Features::override('suppliers', false);
$res = (new FeatureGateMiddleware('suppliers'))->process($factory->createServerRequest('GET', '/admin/suppliers'), $handler);
$check($res->getStatusCode() === 302 && str_ends_with($res->getHeaderLine('Location'), '/admin/dashboard'), 'off, browser: 302 to the dashboard');
$check(($_SESSION['error_message'] ?? '') !== '', 'off, browser: the reason is flashed');
$check($handler->calls === 0, 'off: the handler never runs');
$res = (new FeatureGateMiddleware('suppliers'))->process($factory->createServerRequest('POST', '/admin/suppliers/create')->withHeader('Accept', 'application/json'), $handler);
$payload = json_decode((string) $res->getBody(), true);
$check($res->getStatusCode() === 404 && ($payload['code'] ?? '') === 'feature_disabled' && ($payload['error'] ?? false) === true, 'off, JSON caller: 404 feature_disabled');
$res = (new FeatureGateMiddleware('suppliers'))->process($factory->createServerRequest('POST', '/admin/inventory/1/scan')->withHeader('X-Requested-With', 'XMLHttpRequest'), $handler);
$check($res->getStatusCode() === 404, 'off, XHR: 404 feature_disabled');
Features::override('suppliers', true);
$res = (new FeatureGateMiddleware('suppliers'))->process($factory->createServerRequest('GET', '/admin/suppliers'), $handler);
$check($res->getStatusCode() === 200 && $handler->calls === 1, 'on: the request reaches the handler');
Features::resetOverrides();

// requireOrRedirect(): the controller-level re-check.
Features::override('marc', false);
$check(Features::requireOrRedirect('marc', new \Slim\Psr7\Response())?->getStatusCode() === 302, 'requireOrRedirect() refuses a module that is off');
Features::override('marc', true);
$check(Features::requireOrRedirect('marc', new \Slim\Psr7\Response()) === null, 'requireOrRedirect() lets a module that is on through');
Features::resetOverrides();

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
