<?php
declare(strict_types=1);

/**
 * Behavioural coverage for PluginManager::disableOrphanHook().
 *
 * A hook row registered by an OLD plugin version and dropped in a NEW one
 * lingers in plugin_hooks because upgrades don't re-run onActivate() (which
 * resyncs hooks). The loader used to log "[PluginManager] Method not found" for
 * that orphan on EVERY request. Now it DISABLES the dead row (is_active = 0) on
 * first sight — reversibly, so a partially-deployed upgrade can't destroy a
 * legitimate registration — and the loader (which filters is_active = 1) stops
 * re-loading it. This test asserts the self-heal is correct, idempotent, and —
 * most importantly — precise: it must NEVER touch a legitimate sibling hook, and
 * the disabled row must survive for audit rather than being deleted.
 *
 * Run: php tests/plugin-orphan-hook-selfheal.unit.php
 */

use App\Support\HookManager;
use App\Support\PluginManager;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

// A real plugin_id is needed (FK plugin_hooks.plugin_id -> plugins.id).
$pluginId = (int) ($db->query('SELECT MIN(id) AS id FROM plugins')->fetch_assoc()['id'] ?? 0);
if ($pluginId === 0) {
    fwrite(STDERR, "FAIL: no plugins seeded — cannot exercise pruneOrphanHook\n");
    exit(1);
}

$orphanHook = 'zz.test.orphan.' . getmypid();
$keeperHook = 'zz.test.keeper.' . getmypid();
$class = 'ZzPruneTestPlugin';

$cleanup = static function () use ($db, $pluginId, $orphanHook, $keeperHook): void {
    $stmt = $db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ? AND hook_name IN (?, ?)');
    $stmt->bind_param('iss', $pluginId, $orphanHook, $keeperHook);
    $stmt->execute();
    $stmt->close();
};
$cleanup();
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$insertHook = static function (string $hook, string $method) use ($db, $pluginId, $class): void {
    $stmt = $db->prepare("INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at) VALUES (?, ?, ?, ?, 10, 1, NOW())");
    $stmt->bind_param('isss', $pluginId, $hook, $class, $method);
    $stmt->execute();
    $stmt->close();
};
$hookExists = static function (string $hook, string $method) use ($db, $pluginId): bool {
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?');
    $stmt->bind_param('iss', $pluginId, $hook, $method);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
};
// Returns -1 if the row is absent, else its is_active flag (0/1).
$hookActive = static function (string $hook, string $method) use ($db, $pluginId): int {
    $stmt = $db->prepare('SELECT is_active FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?');
    $stmt->bind_param('iss', $pluginId, $hook, $method);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row === null ? -1 : (int) $row['is_active'];
};
// Mirrors the loader's WHERE clause: an active hook the loader would register.
$loaderWouldLoad = static function (string $hook, string $method) use ($db, $pluginId): bool {
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ? AND is_active = 1');
    $stmt->bind_param('iss', $pluginId, $hook, $method);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n > 0;
};

$insertHook($orphanHook, 'zzMissingMethod');
$insertHook($keeperHook, 'zzKeeperMethod');

$pm = new PluginManager($db, new HookManager($db));
$disable = new ReflectionMethod($pm, 'disableOrphanHook');
$disable->setAccessible(true);

echo "disableOrphanHook — correctness, reversibility, idempotency, precision\n";
$check($loaderWouldLoad($orphanHook, 'zzMissingMethod'), '01 orphan seeded active (loader would load it)');
$check($loaderWouldLoad($keeperHook, 'zzKeeperMethod'), '02 keeper seeded active');

$r1 = $disable->invoke($pm, $pluginId, $orphanHook, 'zzMissingMethod');
$check($r1 === true, '03 disable flips the orphan 1->0 and reports true (so the caller logs once)');
$check($hookActive($orphanHook, 'zzMissingMethod') === 0, '04 orphan row is DISABLED, not deleted (kept for audit, restorable)');
$check(!$loaderWouldLoad($orphanHook, 'zzMissingMethod'), '05 loader would no longer load the disabled orphan (is_active=1 filter)');

$r2 = $disable->invoke($pm, $pluginId, $orphanHook, 'zzMissingMethod');
$check($r2 === false, '06 disable on the already-disabled orphan reports false (no repeat log within cache TTL / under concurrency)');

$check($loaderWouldLoad($keeperHook, 'zzKeeperMethod'), '07 the legitimate sibling hook is UNTOUCHED (precise match, no over-reach)');

// Wrong method / wrong plugin must never disable the keeper.
$disable->invoke($pm, $pluginId, $keeperHook, 'zzWrongMethod');
$check($loaderWouldLoad($keeperHook, 'zzKeeperMethod'), '08 disable with a non-matching method does not touch the keeper');
$disable->invoke($pm, $pluginId + 999999, $keeperHook, 'zzKeeperMethod');
$check($loaderWouldLoad($keeperHook, 'zzKeeperMethod'), '09 disable scoped to a different plugin_id does not touch the keeper');

$cleanup();
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);
