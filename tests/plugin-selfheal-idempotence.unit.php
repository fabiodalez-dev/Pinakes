<?php
declare(strict_types=1);

/**
 * Self-heal idempotence — a hook-less plugin must not re-run onActivate() for
 * ever.
 *
 * THE BUG this pins down: the same-version branch of
 * autoRegisterBundledPlugins() treats "zero rows in plugin_hooks" as a symptom
 * of a missed sync. For a plugin whose hooks depend on configuration that is
 * false: api-book-scraper returns early from registerHooks() while its
 * `enabled` setting is off, so zero hooks is its correct steady state. The
 * branch therefore re-instantiated the plugin and re-ran onActivate() on EVERY
 * maintenance pass, achieving nothing each time — and once the 0.7.86 sitemap
 * work started treating "the self-heal ran" as a state change, that invisible
 * waste became a published file deleted once per maintenance window, for ever.
 *
 * The fix records a per-version marker in plugin_data when onActivate() runs on
 * a healthy schema and still produces no hooks. Two properties matter, and they
 * pull in opposite directions — both are asserted here:
 *
 *   A. the marker SUPPRESSES the pointless repeat (and a version bump lifts it);
 *   B. the marker NEVER suppresses a real repair — an incomplete schema must
 *      still self-heal even with the marker in place. Getting this wrong would
 *      turn an optimisation into the permanent-1146 bug of Uwe #138.
 *
 * Observability note: "was onActivate() called?" is not directly observable, so
 * B is asserted through its effect (the table comes back) and A through the
 * marker's `updated_at`, forced into the past before the run. If the branch
 * executes, setData() rewrites the row and the timestamp moves; if it is
 * skipped, the timestamp stays. That is deterministic, unlike comparing two
 * timestamps taken within the same second.
 *
 * Safe to run repeatedly: every plugin it touches is restored at the end.
 *
 * Run:  php tests/plugin-selfheal-idempotence.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/../vendor/autoload.php';

function si_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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
    return $env;
}

$env    = si_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? @new mysqli(null, $user, $pass, $name, 0, $socket)
        : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}
if ($db->connect_errno) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$pass_n = 0;
$fail_n = 0;
$check = static function (bool $ok, string $label) use (&$pass_n, &$fail_n): void {
    if ($ok) {
        $pass_n++;
        echo "  OK  {$label}\n";
    } else {
        $fail_n++;
        echo "  FAIL {$label}\n";
    }
};

const MARKER_KEY = '_selfheal_noop_version';

$hm      = new \App\Support\HookManager($db);
$runSync = static function () use ($db, $hm): void {
    (new \App\Support\PluginManager($db, $hm))->autoRegisterBundledPlugins();
};
$pluginIdOf = static function (string $plugin) use ($db): int {
    $row = $db->query("SELECT id FROM plugins WHERE name='" . $db->real_escape_string($plugin) . "'")->fetch_row();
    return $row === null ? 0 : (int) $row[0];
};
$hookCountOf = static function (int $id) use ($db): int {
    return (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id={$id}")->fetch_row()[0];
};
$markerRow = static function (int $id) use ($db): ?array {
    $r = $db->query(
        "SELECT data_value, updated_at, created_at FROM plugin_data
         WHERE plugin_id={$id} AND data_key='" . MARKER_KEY . "'"
    );
    return $r === false ? null : ($r->fetch_assoc() ?: null);
};
$clearMarker = static function (int $id) use ($db): void {
    $db->query("DELETE FROM plugin_data WHERE plugin_id={$id} AND data_key='" . MARKER_KEY . "'");
};
$diskVersionOf = static function (string $plugin): string {
    $meta = json_decode((string) file_get_contents(__DIR__ . "/../storage/plugins/{$plugin}/plugin.json"), true);
    return (string) ($meta['version'] ?? '');
};
$tableExists = static function (string $table) use ($db): bool {
    return (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "'"
    )->fetch_row()[0] > 0;
};

/* ==========================================================================
 * A. A hook-less plugin stops re-running onActivate()
 * ========================================================================== */

echo "A. Hook-less steady state is recorded and then respected\n";

$scraperId = $pluginIdOf('api-book-scraper');
if ($scraperId === 0) {
    fwrite(STDERR, "FAIL: api-book-scraper is not registered — this suite needs it as the hook-less subject.\n");
    exit(1);
}

$scraperWasActive  = (int) $db->query("SELECT is_active FROM plugins WHERE id={$scraperId}")->fetch_row()[0];
$scraperMarkerBack = $markerRow($scraperId);
$scraperVersion    = $diskVersionOf('api-book-scraper');

// Book-club state we may disturb in section B.
$clubId        = $pluginIdOf('book-club');
$clubWasActive = $clubId > 0 ? (int) $db->query("SELECT is_active FROM plugins WHERE id={$clubId}")->fetch_row()[0] : 0;
$clubMarkerBack = $clubId > 0 ? $markerRow($clubId) : null;
$clubOrigVersion = $clubId > 0 ? (string) $db->query("SELECT version FROM plugins WHERE id={$clubId}")->fetch_row()[0] : '';

$restore = static function () use (
    $db, $hm, $scraperId, $scraperWasActive, $scraperMarkerBack,
    $clubId, $clubWasActive, $clubMarkerBack, $clubOrigVersion
): void {
    $db->query("UPDATE plugins SET is_active={$scraperWasActive} WHERE id={$scraperId}");
    $db->query("DELETE FROM plugin_data WHERE plugin_id={$scraperId} AND data_key='" . MARKER_KEY . "'");
    if ($scraperMarkerBack !== null) {
        $v = $db->real_escape_string((string) $scraperMarkerBack['data_value']);
        $db->query("INSERT INTO plugin_data (plugin_id, data_key, data_value, data_type, created_at)
                    VALUES ({$scraperId}, '" . MARKER_KEY . "', '{$v}', 'string', NOW())");
    }
    if ($clubId > 0) {
        $v = $db->real_escape_string($clubOrigVersion);
        $db->query("UPDATE plugins SET is_active={$clubWasActive}, version='{$v}' WHERE id={$clubId}");
        $db->query("DELETE FROM plugin_data WHERE plugin_id={$clubId} AND data_key='" . MARKER_KEY . "'");
        if ($clubMarkerBack !== null) {
            $v = $db->real_escape_string((string) $clubMarkerBack['data_value']);
            $db->query("INSERT INTO plugin_data (plugin_id, data_key, data_value, data_type, created_at)
                        VALUES ({$clubId}, '" . MARKER_KEY . "', '{$v}', 'string', NOW())");
        }
        // Whatever section B did, a real onActivate() puts book-club back.
        require_once __DIR__ . '/../storage/plugins/book-club/BookClubPlugin.php';
        $club = new \App\Plugins\BookClub\BookClubPlugin($db, $hm);
        $club->setPluginId($clubId);
        try {
            $club->onActivate();
        } catch (\Throwable $e) {
            fwrite(STDERR, "WARNING: book-club restore failed: {$e->getMessage()}\n");
        }
    }
};

try {
    $db->query("UPDATE plugins SET is_active=1 WHERE id={$scraperId}");
    $clearMarker($scraperId);

    $check($hookCountOf($scraperId) === 0, 'api-book-scraper registers no hooks (unconfigured: the steady state under test)');
    $check($markerRow($scraperId) === null, 'no marker before the first pass');

    $runSync();

    $afterFirst = $markerRow($scraperId);
    $check($afterFirst !== null, 'the first pass records the no-op marker');
    $check($afterFirst !== null && (string) $afterFirst['data_value'] === $scraperVersion,
        "the marker holds the disk version ({$scraperVersion})");
    $check($hookCountOf($scraperId) === 0, 'the pass still registered no hooks — nothing was actually healed');

    // Force the marker's timestamp into the past. If the branch runs again it
    // calls setData(), whose upsert moves updated_at; if it is skipped the
    // value stays put. Deterministic, unlike comparing same-second timestamps.
    $db->query("UPDATE plugin_data SET updated_at='2000-01-01 00:00:00'
                WHERE plugin_id={$scraperId} AND data_key='" . MARKER_KEY . "'");

    $runSync();

    $afterSecond = $markerRow($scraperId);
    $check($afterSecond !== null && str_starts_with((string) $afterSecond['updated_at'], '2000-01-01'),
        'the second pass does NOT rewrite the marker — onActivate() was skipped (the non-idempotence is gone)');

    // A version bump must lift the suppression: the marker is keyed to the
    // version it was written for, so a stale value re-enables the self-heal.
    $db->query("UPDATE plugin_data SET data_value='0.0.0-stale', updated_at='2000-01-01 00:00:00'
                WHERE plugin_id={$scraperId} AND data_key='" . MARKER_KEY . "'");

    $runSync();

    $afterBump = $markerRow($scraperId);
    $check($afterBump !== null && (string) $afterBump['data_value'] === $scraperVersion,
        'a marker from another version is ignored and rewritten — a version bump re-enables the self-heal');
    $check($afterBump !== null && !str_starts_with((string) $afterBump['updated_at'], '2000-01-01'),
        'that pass really did run the branch again');

    /* ======================================================================
     * B. The marker never suppresses a real repair
     * ====================================================================== */

    echo "\nB. An incomplete schema still self-heals with the marker present\n";

    if ($clubId === 0) {
        $check(false, 'book-club is registered (needed as the schema-owning subject)');
    } else {
        require_once __DIR__ . '/../storage/plugins/book-club/BookClubPlugin.php';
        // The branch under test only runs for an ACTIVE plugin whose DB version
        // equals the disk version. On a developer install those can legitimately
        // diverge (this one carries a DB row ahead of plugin.json), so the test
        // builds its own precondition instead of assuming it — otherwise the
        // whole section would pass vacuously by never entering the branch.
        $clubVersionForBranch = $db->real_escape_string($diskVersionOf('book-club'));
        $db->query("UPDATE plugins SET is_active=1, version='{$clubVersionForBranch}' WHERE id={$clubId}");
        $club = new \App\Plugins\BookClub\BookClubPlugin($db, $hm);
        $club->setPluginId($clubId);
        $club->onActivate();
        $check($tableExists('bookclub_external_books'), 'baseline: book-club schema is complete');

        // Build the worst case for the optimisation: zero hooks AND a marker
        // claiming "already tried at this version" AND a genuinely missing
        // table. The marker must lose.
        $db->query("DELETE FROM plugin_hooks WHERE plugin_id={$clubId}");
        $clubVersion = $diskVersionOf('book-club');
        $db->query("DELETE FROM plugin_data WHERE plugin_id={$clubId} AND data_key='" . MARKER_KEY . "'");
        $db->query("INSERT INTO plugin_data (plugin_id, data_key, data_value, data_type, created_at)
                    VALUES ({$clubId}, '" . MARKER_KEY . "', '"
                    . $db->real_escape_string($clubVersion) . "', 'string', NOW())");
        @$db->query("ALTER TABLE bookclub_books DROP FOREIGN KEY fk_bcbooks_external");
        @$db->query("ALTER TABLE bookclub_books DROP KEY uq_bcbooks_external");
        @$db->query("ALTER TABLE bookclub_books DROP KEY idx_bcbooks_external");
        @$db->query("ALTER TABLE bookclub_books DROP COLUMN external_book_id");
        $db->query("DROP TABLE IF EXISTS bookclub_external_books");

        $check(!$tableExists('bookclub_external_books'), 'forced broken state: the table is gone');
        $check($hookCountOf($clubId) === 0, 'forced broken state: hooks are gone too (marker would apply)');

        $runSync();

        $check($tableExists('bookclub_external_books'),
            'the schema self-healed despite the marker — a real repair is never suppressed');
        $check($hookCountOf($clubId) > 0, 'hooks were re-registered by the same pass');
    }
} finally {
    $restore();
    $db->close();
}

echo "\n" . ($fail_n === 0
    ? "SUCCESS {$pass_n} behavioural checks\n"
    : "FAILURE {$fail_n} of " . ($pass_n + $fail_n) . " checks failed\n");

exit($fail_n === 0 ? 0 : 1);
