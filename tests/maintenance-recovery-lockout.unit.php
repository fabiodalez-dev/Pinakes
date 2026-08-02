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
 *   Layer 1 (public/index.php) — the maintenance gate allow-lists only the
 *     login/logout routes, update UI/API and static assets needed for recovery.
 *   Layer 2 (app/Support/Updater.php) — BOTH register_shutdown_function
 *     handlers clear .maintenance for the owning request, while the shared
 *     lock inode remains persistent and flock is the sole ownership signal.
 *   Layer 3 — the existing 30-minute stale sweep stays as the last resort.
 *
 * A. Behavioural (needs the dev server on :8081): with .maintenance present,
 *    the login page is reachable, an unauthenticated visitor gets 503, and the
 *    update / asset allow-list still works. Skipped (not failed) if the server
 *    is unreachable. Always removes the flag via a finally-style guard.
 * B. Source guard (always runs): the Layer 1 allow-list and the Layer 2
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

            // Reachable = actually served (2xx/3xx), not merely "not 503": a 404
            // or 500 must fail these, otherwise a route that isn't served would
            // pass as "reachable".
            $reachable = static fn (?int $c): bool => $c !== null && $c >= 200 && $c < 400;

            $login = $httpCode($baseUrl . $loginPath);
            $check(
                $login !== null && $login >= 200 && $login < 300,
                "01 login page ({$loginPath}) served during maintenance (got " . var_export($login, true) . ", expected 2xx)"
            );

            $home = $httpCode($baseUrl . '/');
            $check($home === 503, "02 unauthenticated home returns 503 during maintenance (got " . var_export($home, true) . ")");

            $updates = $httpCode($baseUrl . '/admin/updates');
            $check($reachable($updates), "03 update endpoint (/admin/updates) served during maintenance, not 503 (got " . var_export($updates, true) . ")");

            $asset = $httpCode($baseUrl . '/assets/main.css');
            $check($reachable($asset), "04 static assets served during maintenance, not 503 (got " . var_export($asset, true) . ")");
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
echo "B. Source guard (scoped Layer 1 allow-list + safe Layer 2 cleanup)\n";

$index = (string) file_get_contents($root . '/public/index.php');
$routes = (string) file_get_contents($root . '/app/Routes/web.php');

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

// Layer 1b: maintenance access stays route-scoped. A privileged session must
// not bypass the gate for arbitrary application routes while code/schema may be
// only partially updated.
$check(
    !str_contains($index, "read_and_close")
        && !str_contains($index, '$maintenanceRole'),
    "12 index.php has no global admin/staff maintenance bypass"
);

// The recovery endpoint is contained beneath the only privileged route prefix
// that remains reachable during maintenance.
$check(
    str_contains($index, "'/admin/updates'")
        && str_contains($routes, "'/admin/updates/maintenance/clear'"),
    "15 emergency clear endpoint remains under the scoped /admin/updates allow-list"
);

// Layer 2: BOTH shutdown handlers must (a) return early unless this request owns
// the update lock, and (b) otherwise clear maintenance unconditionally. The
// shared lock file itself remains persistent to avoid an inode race.
$updater = (string) file_get_contents($root . '/app/Support/Updater.php');
$backupManager = (string) file_get_contents($root . '/app/Support/BackupManager.php');

// Isolate each register_shutdown_function body (signature carries the ownership
// flag by reference).
preg_match_all(
    '/register_shutdown_function\(function \(\) use \(\$maintenanceFile, &\$ownsUpdate\) \{(.*?)\}\);/s',
    $updater,
    $handlers
);
$handlerBodies = $handlers[1] ?? [];
$check(count($handlerBodies) >= 2, "13 found both updater shutdown handlers (got " . count($handlerBodies) . ")");

$unconditional = 0;
$ownershipGuarded = 0;
foreach ($handlerBodies as $body) {
    // Ownership guard: a request that didn't acquire the lock must bail before
    // touching any state.
    if (preg_match('/if \(!\$ownsUpdate\) \{\s*return;\s*\}/s', $body)) {
        $ownershipGuarded++;
    }
    // Strip the ownership guard AND the fatal-error diagnostic block, then check
    // an unlink of both files still survives at the handler's top level (i.e.
    // it's unconditional for the owner, not gated by the fatal `if`).
    $stripped = preg_replace('/if \(!\$ownsUpdate\) \{\s*return;\s*\}\s*/s', '', $body);
    $stripped = is_string($stripped) ? preg_replace('/if \(\$error !== null.*?\}\s*/s', '', $stripped) : null;
    if (is_string($stripped)
        && preg_match('/@unlink\(\$maintenanceFile\)/', $stripped)) {
        $unconditional++;
    }
}
$check($ownershipGuarded >= 2, "14 both shutdown handlers bail unless this request owns the update lock (got {$ownershipGuarded}/2)");
$check($unconditional >= 2, "16 the owner clears .maintenance UNCONDITIONALLY (got {$unconditional}/2)");
// The ownership flag is only raised after the lock is held and maintenance is on.
$check(
    substr_count($updater, '$ownsUpdate = true;') >= 2,
    "17 both update paths raise the ownership flag after acquiring the lock"
);

// Normal completion must disarm the shutdown fallback while the lock is still
// held. Otherwise request A can release its lock, request B can acquire it, and
// A's later shutdown handler can erase B's maintenance flag.
$check(
    preg_match_all(
        '/\$this->cleanup\(\);(?:(?!flock).)*\$ownsUpdate = false;(?:(?!flock).)*flock\(\$lockHandle/s',
        $updater
    ) >= 2,
    "18 both normal cleanup paths disarm shutdown ownership before releasing the lock"
);

$check(
    !str_contains($updater, '@unlink($lockFile)')
        && !str_contains($backupManager, '@unlink($lockFile)'),
    "19 updater and restore keep the shared lock inode persistent"
);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
