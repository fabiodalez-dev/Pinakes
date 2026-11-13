<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, UpdateController:staff-clear-maintenance-and-read-logs).
 *
 * AdminAuthMiddleware admits staff on every /admin path, so each admin-only
 * updater handler re-checks the role itself. clearMaintenance() lost that check
 * (a staff POST deleted storage/.maintenance, including the sticky lock left by
 * an incomplete restore, and reopened the site), and getLogs() / getBackups()
 * never had it (staff read app.log and the list of recovery points).
 *
 * Invariant: a staff session gets 403 from all three and the maintenance flag
 * survives; an administrator still clears it and reads the log.
 *
 * The test briefly creates storage/.maintenance in THIS checkout when none is
 * present, and always removes what it created. It refuses to touch a flag that
 * already exists (an update or restore may own it) and skips those checks.
 *
 * Run:  php tests/security-audit-updater-admin-only.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Controllers\UpdateController;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$staff = ['id' => 2, 'tipo_utente' => 'staff', 'stato' => 'attivo'];
$admin = ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo'];
$token = bin2hex(random_bytes(16));
$as = static function (array $user) use ($token): void {
    $_SESSION = ['user' => $user, 'csrf_token' => $token];
};
$respond = static fn() => (new ResponseFactory())->createResponse();
$clear = static function () use ($token, $respond) {
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/updates/maintenance/clear')
        ->withParsedBody(['csrf_token' => $token]);
    return (new UpdateController())->clearMaintenance($request, $respond());
};
$logs = static function () use ($respond) {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/updates/logs')
        ->withQueryParams(['filter' => '', 'lines' => '5']);
    return (new UpdateController())->getLogs($request, $respond());
};
$backups = static function () use ($respond) {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/updates/backups');
    return (new UpdateController())->getBackups($request, $respond(), new SecurityAuditFakeDb());
};
$body = static fn($response): array => (array) json_decode((string) $response->getBody(), true);

echo "Staff is refused\n";
$as($staff);
$r = $logs();
$check($r->getStatusCode() === 403 && !isset($body($r)['logs']), 'staff GET /admin/updates/logs?filter= : 403, no log lines');
$r = $backups();
$check($r->getStatusCode() === 403 && !isset($body($r)['backups']), 'staff GET /admin/updates/backups : 403, no backup list');

$flag = $root . '/storage/.maintenance';
if (file_exists($flag)) {
    echo "  SKIP storage/.maintenance already exists in this checkout — not touching it; maintenance checks not run\n";
    $r = $clear();
    $check($r->getStatusCode() === 403 && file_exists($flag), 'staff POST /admin/updates/maintenance/clear : 403, existing flag kept');
} else {
    $created = false;
    try {
        $created = file_put_contents($flag, json_encode(['sticky' => true, 'reason' => 'security-audit test'])) !== false;
        $check($created, 'a sticky maintenance flag is in place');
        $r = $clear();
        $check($r->getStatusCode() === 403, 'staff POST /admin/updates/maintenance/clear : 403');
        $check(file_exists($flag), 'the maintenance flag survives the staff request');

        echo "Administrators keep the emergency switch\n";
        $as($admin);
        $r = $clear();
        $check($r->getStatusCode() === 200 && ($body($r)['success'] ?? false) === true, 'admin clears maintenance: 200 success');
        $check(!file_exists($flag), 'and the flag is gone');
    } finally {
        if ($created && file_exists($flag)) {
            @unlink($flag);
        }
    }
}

$as($admin);
$r = $logs();
$check(in_array($r->getStatusCode(), [200, 404], true), 'admin reads the updater log (status ' . $r->getStatusCode() . ')');
$r = $backups();
$check($r->getStatusCode() === 200 && array_key_exists('backups', $body($r)), 'admin lists backups');
$_SESSION = [];

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
