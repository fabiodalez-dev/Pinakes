<?php
declare(strict_types=1);

/**
 * Behavioural coverage for PluginManager::pruneOrphanHook().
 *
 * A hook row registered by an OLD plugin version and dropped in a NEW one
 * lingers in plugin_hooks because upgrades don't re-run onActivate() (which
 * resyncs hooks). The loader used to log "[PluginManager] Method not found" for
 * that orphan on EVERY request. Now it deletes the dead row on first sight
 * (self-heal). This test asserts the prune is correct, idempotent, and — most
 * importantly — precise: it must NEVER delete a legitimate sibling hook.
 *
 * Run: php tests/plugin-orphan-hook-prune.unit.php
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

$insertHook($orphanHook, 'zzMissingMethod');
$insertHook($keeperHook, 'zzKeeperMethod');

$pm = new PluginManager($db, new HookManager($db));
$prune = new ReflectionMethod($pm, 'pruneOrphanHook');
$prune->setAccessible(true);

echo "pruneOrphanHook — correctness, idempotency, precision\n";
$check($hookExists($orphanHook, 'zzMissingMethod'), '01 orphan seeded');
$check($hookExists($keeperHook, 'zzKeeperMethod'), '02 keeper seeded');

$r1 = $prune->invoke($pm, $pluginId, $orphanHook, 'zzMissingMethod');
$check($r1 === true, '03 prune deletes the orphan and reports true (so the caller logs once)');
$check(!$hookExists($orphanHook, 'zzMissingMethod'), '04 orphan row is gone');

$r2 = $prune->invoke($pm, $pluginId, $orphanHook, 'zzMissingMethod');
$check($r2 === false, '05 prune on the already-removed orphan reports false (no repeat log within cache TTL)');

$check($hookExists($keeperHook, 'zzKeeperMethod'), '06 the legitimate sibling hook is UNTOUCHED (precise match, no over-delete)');

// Wrong method / wrong plugin must never delete the keeper.
$prune->invoke($pm, $pluginId, $keeperHook, 'zzWrongMethod');
$check($hookExists($keeperHook, 'zzKeeperMethod'), '07 prune with a non-matching method does not touch the keeper');
$prune->invoke($pm, $pluginId + 999999, $keeperHook, 'zzKeeperMethod');
$check($hookExists($keeperHook, 'zzKeeperMethod'), '08 prune scoped to a different plugin_id does not touch the keeper');

$cleanup();
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);
