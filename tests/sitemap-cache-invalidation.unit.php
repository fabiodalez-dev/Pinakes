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

/**
 * The dev/CI database, or null when it is not reachable. The maintenance-pass
 * checks need a real `plugins` table; everything above them does not, so an
 * unreachable database costs those two checks and not the whole suite.
 */
function sitemap_unit_db(): ?mysqli
{
    $env = [];
    foreach (@file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }

    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
    $user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
    $pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
    $name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $db = ($socket !== '' && file_exists($socket))
            ? @new mysqli(null, $user, $pass, $name, 0, $socket)
            : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    } catch (\Throwable $e) {
        return null;
    }
    if ($db->connect_errno !== 0) {
        return null;
    }
    $db->set_charset('utf8mb4');

    // A database without a `plugins` table is a sandbox, not this install.
    $probe = $db->query("SHOW TABLES LIKE 'plugins'");
    if (!$probe || $probe->num_rows === 0) {
        $db->close();
        return null;
    }
    $probe->free();

    return $db;
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

    // ------------------------------------------------------------------
    // 6-7. The two maintenance methods — autoRegisterBundledPlugins() and
    //      cleanupOrphanPlugins() — install, activate and deactivate plugins
    //      during an upgrade and on every /admin/plugins view, so they owe the
    //      same invalidation as the lifecycle methods. But they also run when
    //      NOTHING changed: at bootstrap, once per 15-minute maintenance
    //      window, and on every admin page load.
    //
    //      Hence both directions are asserted, and the NO-OP one matters most.
    //      An unconditional clearPluginCache() at the end of those methods
    //      passes check 7 and destroys the feature: public/sitemap.xml would be
    //      deleted on every admin page load and every maintenance window, so
    //      the published file would never survive long enough to serve anyone.
    //      Checks 1-5 above cannot see that regression — they only ever assert
    //      that the file DISAPPEARS.
    // ------------------------------------------------------------------
    $db = sitemap_unit_db();
    if ($db === null) {
        echo "NOTE: database not reachable — the maintenance-pass checks (6-7) are skipped\n";
    } else {
        $hooks = new \App\Support\HookManager($db);
        $manager = new PluginManager($db, $hooks);

        // These checks call the maintenance pass DIRECTLY, without the
        // once-per-window marker the application boot goes through — and
        // cleanupOrphanPlugins() deletes the `plugins` row of any non-bundled
        // plugin whose directory is missing, cascading to its hooks, settings
        // and data. On a developer database pointed at by .env that would
        // destroy real rows, earlier than anything else would have. So look
        // first: if this database HAS such a plugin, do not run the pass on it.
        // Refusing loudly is the point — a silent skip here would leave the
        // suite green while proving nothing.
        $orphans = [];
        $orphanRows = $db->query('SELECT name FROM plugins');
        if ($orphanRows) {
            while ($orphanRow = $orphanRows->fetch_assoc()) {
                $orphanName = (string) $orphanRow['name'];
                if (in_array($orphanName, \App\Support\BundledPlugins::LIST, true)) {
                    continue;
                }
                if (!is_dir(dirname(__DIR__) . '/storage/plugins/' . $orphanName)) {
                    $orphans[] = $orphanName;
                }
            }
            $orphanRows->free();
        }

        if ($orphans !== []) {
            echo "NOTE: the maintenance-pass checks (6, 7) are NOT running on this database.\n";
            echo "      It registers non-bundled plugin(s) with no directory on disk: "
                . implode(', ', $orphans) . ".\n";
            echo "      Forcing the pass here would DELETE those rows (and their hooks,\n";
            echo "      settings and data). Point E2E_DB_NAME at a scratch database, or\n";
            echo "      clean up the orphan registration, to get these two checks back.\n";
            $db->close();
            return;
        }

        // Settle first: on a DB that has never synced (or one left mid-upgrade)
        // the FIRST pass legitimately mutates. The no-op property is about the
        // second and every later pass, which is what a live install runs.
        $manager->autoRegisterBundledPlugins();
        $manager->cleanupOrphanPlugins();

        // 6. THE GUARD. A settled install, nothing on disk or in the DB
        //    changed: the published sitemap must still be there afterwards.
        file_put_contents($published, "<urlset><url><loc>/emeroteca</loc></url></urlset>");
        $manager->autoRegisterBundledPlugins();
        $manager->cleanupOrphanPlugins();
        check_sm(
            is_file($published),
            'a maintenance pass that changes nothing LEAVES the published sitemap in place (an unconditional invalidation would delete it on every admin page load)'
        );

        // 7. And a pass that really does mutate drops it. The mutation is
        //    staged by putting a bundled plugin's requires_php out of step with
        //    its manifest: the sync branch rewrites it from disk, which both
        //    proves the invalidation fires and restores the row by itself.
        $target = null;
        $rows = $db->query("SELECT name, requires_php, requires_app FROM plugins");
        if ($rows) {
            while ($row = $rows->fetch_assoc()) {
                if (!in_array($row['name'], \App\Support\BundledPlugins::LIST, true)) {
                    continue;
                }
                if (!is_dir(dirname(__DIR__) . '/storage/plugins/' . $row['name'])) {
                    continue;
                }
                $target = $row;
                break;
            }
            $rows->free();
        }

        if ($target === null) {
            echo "NOTE: no bundled plugin registered on disk — the mutating-pass check (7) is skipped\n";
        } else {
            $stmt = $db->prepare('UPDATE plugins SET requires_php = ? WHERE name = ?');
            $sentinel = '0.0.0-sitemap-unit';
            $stmt->bind_param('ss', $sentinel, $target['name']);
            $stmt->execute();
            $stmt->close();

            // From here the row carries a value that is not true. The sync is
            // expected to rewrite it from the manifest — that is what check 7
            // asserts — but check_sm() throws, so an assertion failure between
            // here and there would walk out past the restore and leave the
            // sentinel in the database. Put it back unconditionally instead of
            // relying on the code under test to do it.
            $restoreRequiresPhp = static function () use ($db, $target): void {
                $stmt = $db->prepare('UPDATE plugins SET requires_php = ? WHERE name = ?');
                if ($stmt === false) {
                    return;
                }
                $original = (string) $target['requires_php'];
                $name = (string) $target['name'];
                $stmt->bind_param('ss', $original, $name);
                $stmt->execute();
                $stmt->close();
            };

            try {
            file_put_contents($published, "<urlset><url><loc>/emeroteca</loc></url></urlset>");
            $manager->autoRegisterBundledPlugins();

            $after = $db->query(
                "SELECT requires_php FROM plugins WHERE name = '" . $db->real_escape_string($target['name']) . "'"
            )->fetch_row()[0];
            // The sync restores the manifest value; assert it before judging
            // the sitemap, so a sync that silently did nothing is reported as
            // a broken fixture rather than as a missing invalidation.
            check_sm(
                (string) $after === (string) $target['requires_php'],
                'the maintenance pass detected and repaired the out-of-step manifest metadata'
            );
            check_sm(
                !is_file($published),
                'a maintenance pass that DID mutate plugin state drops the published sitemap (autoRegisterBundledPlugins honours the clearPluginCache contract)'
            );
            } finally {
                $restoreRequiresPhp();
            }
        }

        $db->close();
    }

    echo "SUCCESS $n behavioural checks\n";
} finally {
    if (is_file($published)) { @unlink($published); }
    $restore();
}
