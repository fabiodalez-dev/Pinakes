<?php
declare(strict_types=1);

/**
 * Regression test for the maintenance-mode recovery lockout.
 *
 * Background: an in-app update writes storage/.maintenance and the front
 * controller (public/index.php) blocks EVERY request while it exists. The
 * emergency "clear maintenance" endpoint is admin-only, but the block also
 * covered the login route — so an admin who was logged out could never
 * authenticate to reach it. A non-fatal early-return in the updater (or a
 * caught exception that skipped disableMaintenanceMode()) would leave the site
 * stuck until the 30-minute stale sweep. Real incident:
 * biblioteca.casadelledonnealessandria.it, Aug 2026.
 *
 * The fix has three layers; this test guards all of them:
 *   Layer 1 (public/index.php) — the maintenance gate allow-lists the login /
 *     logout routes (localised + literal defaults) AND lets an authenticated
 *     admin/staff through, so recovery is always reachable.
 *   Layer 2 (app/Support/Updater.php) — BOTH register_shutdown_function
 *     handlers clear .maintenance + the lock file UNCONDITIONALLY, not only on
 *     a fatal PHP error.
 *   Layer 3 — the existing 30-minute stale sweep stays as the last resort.
 *
 * A. Behavioural (needs the dev server on :8081): with .maintenance present,
 *    the login page is reachable, an unauthenticated visitor gets 503, and the
 *    update / asset allow-list still works. Skipped (not failed) if the server
 *    is unreachable. Always removes the flag via a finally-style guard.
 * B. Source guard (always runs): the Layer 1 bypass and the Layer 2
 *    unconditional cleanup are present so the fix cannot silently regress.
 *
 * Run:  php tests/maintenance-recovery-lockout.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$baseUrl = getenv('E2E_BASE_URL') ?: 'http://localhost:8081';
$maintenanceFile = $root . '/storage/.maintenance';

$httpCode = static function (string $url, string $cookie = ''): ?int {
    $cmd = 'curl -s -o /dev/null -w "%{http_code}" --max-time 10 ' . escapeshellarg($url);
    if ($cookie !== '') {
        $cmd .= ' -H ' . escapeshellarg('Cookie: ' . $cookie);
    }
    $out = @shell_exec($cmd);
    $code = $out === null ? '' : trim((string) $out);
    // curl prints "000" when the connection never produced an HTTP response
    // (refused / DNS / pre-response timeout) — that is "unreachable", not a
    // status. Treating it as a real code made the behavioural block run against
    // a dead server on CI (no :8081) and fail check 02. Return null so the
    // caller skips the behavioural checks there instead.
    if ($code === '' || !preg_match('/^\d{3}$/', $code) || (int) $code === 0) {
        return null;
    }
    return (int) $code;
};

// Resolve the install's login slug the same way the front controller does, so
// the behavioural check follows whatever locale the install runs under.
$loginPath = '/login';
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
    try {
        if (class_exists(\App\Support\RouteTranslator::class)) {
            $resolved = \App\Support\RouteTranslator::route('login');
            if (is_string($resolved) && $resolved !== '') {
                $loginPath = $resolved;
            }
        }
    } catch (\Throwable) {
        // Keep the /login default.
    }
}

// ── A. Behavioural — the gate, exercised through real HTTP ──────────────────
echo "A. Maintenance gate behaviour (dev server on {$baseUrl})\n";

$serverUp = $httpCode($baseUrl . '/') !== null;
if (!$serverUp) {
    echo "  SKIP behavioural checks — {$baseUrl} unreachable (start Apache+PHP-FPM to run them)\n";
} else {
    // Guarantee the flag is gone no matter how the block below exits.
    $cleanupMaintenance = static function () use ($maintenanceFile): void {
        if (is_file($maintenanceFile)) {
            @unlink($maintenanceFile);
        }
    };
    // Never clobber a flag a real update might have written this instant.
    $preExisting = is_file($maintenanceFile);
    if ($preExisting) {
        echo "  SKIP behavioural checks — storage/.maintenance already present (a real update may be running)\n";
    } else {
        try {
            file_put_contents(
                $maintenanceFile,
                json_encode(['time' => time(), 'message' => 'maintenance-recovery.unit self-test'])
            );

            $login = $httpCode($baseUrl . $loginPath);
            $check($login !== null && $login !== 503, "01 login page ({$loginPath}) reachable during maintenance (got " . var_export($login, true) . ", must not be 503)");

            $home = $httpCode($baseUrl . '/');
            $check($home === 503, "02 unauthenticated home returns 503 during maintenance (got " . var_export($home, true) . ")");

            $updates = $httpCode($baseUrl . '/admin/updates');
            $check($updates !== null && $updates !== 503, "03 update endpoint (/admin/updates) reachable during maintenance (got " . var_export($updates, true) . ")");

            $asset = $httpCode($baseUrl . '/assets/main.css');
            $check($asset !== null && $asset !== 503, "04 static assets reachable during maintenance (got " . var_export($asset, true) . ")");
        } finally {
            $cleanupMaintenance();
        }
        // Prove the finally actually lifted the block.
        $check(!is_file($maintenanceFile), "05 test removed storage/.maintenance after the run");
        $home = $httpCode($baseUrl . '/');
        $check($home !== null && $home !== 503, "06 home serves normally again once the flag is gone (got " . var_export($home, true) . ")");
    }
}

// ── B. Source guard — the fix cannot silently regress ───────────────────────
echo "B. Source guard (Layer 1 bypass + Layer 2 unconditional cleanup)\n";

$index = (string) file_get_contents($root . '/public/index.php');

// Layer 1a: login/logout routes are allow-listed so an admin can authenticate.
$check(
    str_contains($index, "RouteTranslator::route('login')")
        && str_contains($index, "RouteTranslator::route('logout')"),
    "10 index.php allow-lists the localised login/logout routes"
);
$check(
    (bool) preg_match("/\\\$allowedPaths\\s*=\\s*\\[[^\\]]*'\\/login'[^\\]]*'\\/logout'[^\\]]*\\]/s", $index),
    "11 index.php allow-lists the literal /login and /logout defaults"
);

// Layer 1b: an authenticated admin/staff bypasses the block via a read-only
// session read (read_and_close) that doesn't disturb the later writable start.
$check(
    str_contains($index, "read_and_close")
        && (bool) preg_match("/tipo_utente.*\\[[^\\]]*'admin'[^\\]]*'staff'[^\\]]*\\]/s", $index),
    "12 index.php lets an authenticated admin/staff through (read_and_close session probe)"
);

// Layer 2: BOTH shutdown handlers must unlink the maintenance + lock files
// UNCONDITIONALLY — not nested inside the fatal-error `if`. Verify by removing
// each fatal-error block and confirming an unlink of $maintenanceFile still
// remains in the handler afterwards.
$updater = (string) file_get_contents($root . '/app/Support/Updater.php');

// Isolate each register_shutdown_function body.
preg_match_all(
    '/register_shutdown_function\(function \(\) use \(\$maintenanceFile, \$lockFile\) \{(.*?)\}\);/s',
    $updater,
    $handlers
);
$handlerBodies = $handlers[1] ?? [];
$check(count($handlerBodies) >= 2, "13 found both updater shutdown handlers (got " . count($handlerBodies) . ")");

$unconditional = 0;
foreach ($handlerBodies as $body) {
    // Strip the fatal-error diagnostic `if (...) { ... }` block, then check an
    // unlink of the maintenance file still survives at the handler's top level.
    $withoutFatalBlock = preg_replace('/if \(\$error !== null.*?\}\s*/s', '', $body);
    if (is_string($withoutFatalBlock)
        && preg_match('/@unlink\(\$maintenanceFile\)/', $withoutFatalBlock)
        && preg_match('/@unlink\(\$lockFile\)/', $withoutFatalBlock)) {
        $unconditional++;
    }
}
$check($unconditional >= 2, "14 both shutdown handlers clear .maintenance + lock UNCONDITIONALLY (got {$unconditional}/2)");

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
