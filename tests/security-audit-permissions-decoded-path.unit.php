<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, finding app/Support/Permissions.php:authorization-on-undecoded-path.
 *
 * Slim rawurldecode()s the request path before matching a route, but the
 * permission gate judged the raw, still percent-encoded path. Encoding one
 * ordinary character therefore selected the protected route while missing the
 * gate: a reader opened /%61dmin/books (the /admin/books route) and a
 * catalogue-only role reached the borrower-revealing /api/v1/staff/books/1/%71ueue
 * and /api/v1/staff/copies/by%2Dcode views as catalog.manage.
 *
 * DB-free: the session role re-validation verdict is primed through reflection.
 *
 * Run:  php tests/security-audit-permissions-decoded-path.unit.php   (exit 0 iff all pass)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Middleware\BackendPermissionsMiddleware;
use App\Support\Permissions as P;
use App\Support\SessionRoleRevalidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

// Prime the per-request verdict so authorizePath() needs no database.
$prime = static function (int $id, string $role): void {
    SessionRoleRevalidator::reset();
    $cache = new ReflectionProperty(SessionRoleRevalidator::class, 'cache');
    $cache->setValue(null, [$id => ['status' => SessionRoleRevalidator::OK, 'role' => $role, 'stato' => 'attivo']]);
};

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204);
    }
};
$gate = new BackendPermissionsMiddleware();
// The path is set with withPath() and kept exactly as given (no normalisation),
// as Slim's request factory does with REQUEST_URI on a real request.
$status = static function (string $path) use ($gate, $handler): int {
    $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://library.test/');
    $request = $request->withUri($request->getUri()->withPath($path));
    return $gate->process($request, $handler)->getStatusCode();
};

echo "A. BackendPermissionsMiddleware, reader session (no backend capability)\n";
$_SESSION = ['user' => ['id' => 9101, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => []]];
$prime(9101, 'standard');
$check($status('/admin/books') === 403, '/admin/books is refused (control)');
$check($status('/%61dmin/books') === 403, '/%61dmin/books (routed to /admin/books) is refused');
$check($status('/%61dmin/books/3') === 403, '/%61dmin/books/3 is refused');
$check($status('/admin/%62ooks/3') === 403, '/admin/%62ooks/3 is refused');
$check($status('/%61%64%6D%69%6E/publishers/1') === 403, 'a fully encoded /admin segment is refused');
$check($status('/admin%2Fbooks') === 403, 'an encoded slash inside /admin is refused');
$check($status('//admin/books') === 403, 'repeated leading slashes are gated, not waved through');
$check($status('/%2Fadmin/books') === 403, 'an encoded leading slash is gated');
$check($status('/api/%61dmin/pending-registrations') === 403, '/api/%61dmin/... is refused');
$check($status('/admin') === 204, 'the /admin root keeps its own route guard (unchanged)');
$check($status('/catalogo') === 204, 'public pages pass');
$check($status('/autore/100%25%20Rock') === 204, 'a public path with an encoded % still passes');

echo "B. BackendPermissionsMiddleware, custom role without catalog.manage\n";
$_SESSION = ['user' => ['id' => 9102, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'users.manage']]];
$prime(9102, 'standard');
$check($status('/%61dmin/books') === 403, 'a users-only role does not reach /%61dmin/books');
$check($status('/admin/users') === 204, 'its own module stays reachable');
$check($status('/%61dmin/users') === 204, '...also when the path is encoded (same decision as the router)');

echo "C. BackendPermissionsMiddleware, Staff\n";
$_SESSION = ['user' => ['id' => 9103, 'tipo_utente' => 'staff', 'stato' => 'attivo', 'permissions' => []]];
$prime(9103, 'staff');
$check($status('/%61dmin/books') === 204 && $status('/admin%2Fbooks') === 204, 'Staff keeps every backend route, encoded or not');
$_SESSION = [];
SessionRoleRevalidator::reset();

echo "D. Permissions::permissionForPath / allowsPath (mobile staff API)\n";
$catalog = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'catalog.manage']];
$desk = ['tipo_utente' => 'premium', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$admin = ['tipo_utente' => 'admin', 'stato' => 'attivo'];
$check(P::permissionForPath('/api/v1/staff/books/1/%71ueue') === 'loans.manage', '/books/1/%71ueue maps to loans.manage');
$check(P::permissionForPath('/api/v1/staff/books/1/%61vailability') === 'loans.manage', '/books/1/%61vailability maps to loans.manage');
$check(P::permissionForPath('/api/v1/staff/copies/by%2Dcode') === 'loans.manage', '/copies/by%2Dcode maps to loans.manage');
$check(P::permissionForPath('/%61dmin/books') === 'catalog.manage', '/%61dmin/books maps like /admin/books');
$check(!P::allowsPath('/api/v1/staff/books/1/queue', $catalog), 'catalogue-only role: queue refused (control)');
$check(!P::allowsPath('/api/v1/staff/books/1/%71ueue', $catalog), 'catalogue-only role: encoded queue refused');
$check(!P::allowsPath('/api/v1/staff/copies/by%2Dcode', $catalog), 'catalogue-only role: encoded by-code refused');
$check(!P::allowsPath('/api/v1/staff/copies/by%2dcode', $catalog), '...lower-case escape too');
$check(P::allowsPath('/api/v1/staff/books/1', $catalog), 'catalogue-only role keeps the catalogue (control)');
$check(P::allowsPath('/api/v1/staff/books/1/%71ueue', $desk), 'the loan desk reaches the queue, encoded or not');
$check(P::allowsPath('/api/v1/staff/%75sers/5', $desk), 'the loan-desk borrower lookup exception follows the decoded path');

echo "E. Fail closed on what the router would not decode the same way\n";
$check(P::permissionForPath('/admin%2Fbooks') === null, 'an encoded slash maps to no capability');
$check(P::permissionForPath('/admin/books%5C1') === null, 'an encoded backslash maps to no capability');
$check(P::permissionForPath('/admin/books%001') === null, 'an encoded NUL maps to no capability');
$check(P::permissionForPath('/admin/books/%2531') === null, 'a double-encoded path maps to no capability');
$check(P::permissionForPath("/admin/books/1\t") === null, 'a control character maps to no capability');
$check(!P::allowsPath('/admin%2Fbooks', $catalog), 'so a custom role is refused');
$check(P::allowsPath('/admin%2Fbooks', $admin), 'an administrator is not affected');
$check(P::permissionForPath('/admin/books') === 'catalog.manage' && P::permissionForPath('/admin/loans/returned/4') === 'loans.manage', 'plain paths map as before');

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
