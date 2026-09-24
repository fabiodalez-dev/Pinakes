<?php
declare(strict_types=1);
/**
 * The published sitemap is a cache, and a plugin lifecycle change invalidates it.
 *
 * Without this the file written before a plugin was switched off keeps
 * advertising that plugin's public URLs — which now answer 404 — to every
 * crawler, for as long as nobody presses "Rigenera adesso". Nothing in the
 * application tells the operator, so the only thing that can notice is a test.
 *
 * The wiring is exercised on the REAL published path, because the bug this
 * guards is precisely "the real file is not the one that gets removed": a test
 * against a temporary path would pass while the shipped path went untouched.
 * The file is backed up first and restored by both a finally block and a
 * shutdown handler, so an interrupted run cannot cost the dev machine its
 * sitemap.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\PluginManager;
use App\Support\SitemapCache;

$n = 0;
function check_sm(bool $ok, string $label): void
{
    global $n;
    if (!$ok) { throw new RuntimeException($label); }
    echo 'PASS '.(++$n).' '.$label."\n";
}

$published = SitemapCache::publishedPath();
$backup = $published . '.unittest-backup-' . bin2hex(random_bytes(4));
$restore = static function () use ($published, $backup): void {
    if (is_file($backup)) {
        @rename($backup, $published);
    }
};
if (is_file($published) && !@rename($published, $backup)) {
    echo "SKIP: impossibile mettere da parte $published\n";
    exit(0);
}
register_shutdown_function($restore);

try {
    check_sm(
        $published === dirname(__DIR__) . '/public/sitemap.xml',
        'publishedPath() points at the file Apache serves'
    );

    // 1. Nothing to remove is the normal state, not a failure.
    check_sm(SitemapCache::invalidate('unit: absent') === false, 'invalidating an absent sitemap reports no removal and does not throw');

    // 2. A lifecycle mutation removes the published file.
    file_put_contents($published, "<urlset><url><loc>/emeroteca</loc></url></urlset>");
    check_sm(is_file($published), 'a published sitemap is in place before the lifecycle change');
    PluginManager::clearPluginCache();
    check_sm(!is_file($published), 'clearPluginCache() — every plugin install/activate/deactivate/uninstall — drops the published sitemap');

    // 3. Idempotent: a second mutation over an already-clean state is silent.
    PluginManager::clearPluginCache();
    check_sm(!is_file($published), 'a second lifecycle change over an already-invalidated sitemap is harmless');

    // 4. A failure to delete must never propagate: an unwritable directory
    //    costs a stale sitemap, never a failed plugin activation.
    $dir = sys_get_temp_dir() . '/pinakes-sitemap-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $locked = $dir . '/sitemap.xml';
    file_put_contents($locked, 'x');
    chmod($dir, 0500);
    $threw = false;
    try {
        $removed = SitemapCache::invalidate('unit: unwritable', $locked);
    } catch (\Throwable $e) {
        $threw = true;
    }
    chmod($dir, 0700);
    $stillThere = is_file($locked);
    @unlink($locked);
    @rmdir($dir);
    check_sm(!$threw, 'an undeletable sitemap never throws into the lifecycle operation');
    check_sm($stillThere === true && ($removed ?? true) === false, 'a failed deletion is reported as "not removed", not as a success');

    // 5. The generator still answers, so removing the file is not an outage:
    //    this is the property that makes deletion the right invalidation.
    check_sm(
        str_contains((string) file_get_contents(dirname(__DIR__).'/app/Routes/web.php'), "\$app->get('/sitemap.xml'"),
        'the dynamic /sitemap.xml route exists, so an invalidated file degrades to a slower answer, never to a 404'
    );

    echo "SUCCESS $n behavioural checks\n";
} finally {
    if (is_file($published)) { @unlink($published); }
    $restore();
}
