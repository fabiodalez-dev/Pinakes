<?php
declare(strict_types=1);

/**
 * Plugin hooks payload cache — generation-based invalidation contract.
 *
 * The active-plugins + hooks payload ('plugins_payload_active_with_hooks')
 * used to be invalidated with a point delete, which left a race window: a
 * request that computed the PRE-mutation payload could re-write it AFTER the
 * delete with a 300s TTL, hiding a freshly activated plugin's hooks for up
 * to 5 minutes (observed deterministically on the post-upgrade bench, where
 * slower requests widened the window). With the 'plugins_payload_' prefix
 * generation-namespaced, invalidation bumps a generation counter embedded in
 * the storage key: stale writers persist under the OLD generation, which no
 * subsequent reader can ever resolve.
 */

use App\Support\PluginManager;
use App\Support\QueryCache;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $passed++;
    echo "  OK  {$label}\n";
};

// 1. The payload key lives in a generation-namespaced prefix. If someone
//    renames the key outside the namespace, invalidation silently degrades
//    back to the racy point-delete — fail loudly instead.
$pm = new ReflectionClass(PluginManager::class);
$key = $pm->getConstant('HOOKS_PAYLOAD_KEY');
$check(is_string($key) && str_starts_with($key, 'plugins_payload_'),
    'HOOKS_PAYLOAD_KEY is inside the generation-namespaced plugins_payload_ prefix');

$qc = new ReflectionClass(QueryCache::class);
$prefixes = $qc->getConstant('NAMESPACE_PREFIXES');
$check(is_array($prefixes) && in_array('plugins_payload_', $prefixes, true),
    "QueryCache registers 'plugins_payload_' for O(1) generation invalidation");

// 2. Behavioural: set → visible; generation bump → unreachable.
$probe = 'plugins_payload_unit_probe_' . bin2hex(random_bytes(4));
QueryCache::set($probe, ['marker' => 'fresh'], 60);
$check(QueryCache::get($probe)['marker'] === 'fresh', 'payload readable before invalidation');

QueryCache::clearByPrefix('plugins_payload_');
$check(QueryCache::get($probe) === null, 'generation bump makes the previous payload unreachable');

// 3. A new write after the bump lands under the new generation and is readable.
QueryCache::set($probe, ['marker' => 'regenerated'], 60);
$check(QueryCache::get($probe)['marker'] === 'regenerated', 'post-bump writes resolve under the new generation');
QueryCache::clearByPrefix('plugins_payload_');

// 4. clearPluginCache() must bump the payload namespace (the lifecycle
//    entry point every activate/deactivate/install goes through).
$probe2 = 'plugins_payload_unit_probe2_' . bin2hex(random_bytes(4));
QueryCache::set($probe2, ['marker' => 'pre-lifecycle'], 60);
PluginManager::clearPluginCache();
$check(QueryCache::get($probe2) === null, 'clearPluginCache() invalidates the payload namespace');

echo "\nPassed: {$passed}, Failed: 0\n";
