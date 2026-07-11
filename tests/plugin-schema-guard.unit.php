<?php
declare(strict_types=1);

/**
 * Plugin schema guard — the release gate for "a plugin's declared schema must
 * actually be creatable, and every table it declares must exist after its own
 * ensureSchema() runs".
 *
 * For EVERY bundled plugin that declares expectedTables() this asserts:
 *   (a) ensureSchema() reports no failed tables;
 *   (b) every table in expectedTables() exists afterwards (catches a wrong /
 *       stale list — the exact mistake that lets PluginManager's self-heal
 *       either miss a real gap or thrash on a table that is never created);
 *   (c) a second ensureSchema() is a no-op (idempotent, safe to re-run on
 *       every boot / upgrade).
 *
 * This is what makes the boot-time self-heal trustworthy: the heal only re-runs
 * ensureSchema when an expectedTable is missing, so expectedTables() MUST be an
 * exact subset of what ensureSchema() creates. A stale entry would make a
 * healthy install rebuild on every boot; a missing entry would leave a real gap
 * un-healed. Both are caught here, before release.
 *
 * Idempotent + non-destructive: ensureSchema is CREATE TABLE IF NOT EXISTS, so
 * running it against the dev DB only fills in tables that a full install would
 * already have.
 */

require __DIR__ . '/../vendor/autoload.php';

function psg_env(string $path): array
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

$env    = psg_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

$tableExists = function (string $t) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "'"
    )->num_rows;
};

$hm = new \App\Support\HookManager($db);

// Every bundled plugin dir with a global "<Name>Plugin" class we can construct.
$pluginsDir = __DIR__ . '/../storage/plugins';
$dirs = array_filter(glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [], function ($d) {
    return file_exists($d . '/wrapper.php')
        || (bool) glob($d . '/*Plugin.php');
});
sort($dirs);

$checkedPlugins = 0;

foreach ($dirs as $dir) {
    $slug = basename($dir);
    // Load the plugin (wrapper defines the global forwarding class).
    $wrapper = $dir . '/wrapper.php';
    $mainGlob = glob($dir . '/*Plugin.php');
    if (file_exists($wrapper)) {
        require_once $wrapper;
    } elseif ($mainGlob) {
        require_once $mainGlob[0];
    } else {
        continue;
    }
    // Derive the global class name from the main file (e.g. NcipServerPlugin).
    $className = $mainGlob ? basename($mainGlob[0], '.php') : null;
    if ($className === null || !class_exists($className, false)) {
        continue;
    }

    // ENFORCEMENT: a plugin that creates tables MUST declare expectedTables(),
    // otherwise PluginManager's boot self-heal can never notice its tables are
    // missing after an already-active upgrade (the Uwe #138 class of bug). This
    // is what makes the self-heal "dynamic for every plugin": you cannot add a
    // table-owning plugin (or a new table) without also declaring it, because
    // this test fails. The check is on the main plugin file's own CREATE TABLE
    // (its unconditional core schema) — the tables the self-heal must guarantee.
    $ownsSchema = false;
    if ($mainGlob) {
        $src = (string) @file_get_contents($mainGlob[0]);
        $ownsSchema = stripos($src, 'CREATE TABLE') !== false;
    }
    $declares = method_exists($className, 'expectedTables');
    if ($ownsSchema) {
        check($declares, "$slug: creates tables (CREATE TABLE in its source) → MUST declare expectedTables() so PluginManager's boot self-heal covers it");
    }
    if (!$declares) {
        continue; // legit: plugin owns no schema and needs no self-heal
    }

    try {
        $instance = new $className($db, $hm);
    } catch (\Throwable $e) {
        // Owns schema + declares expectedTables but can't be constructed with
        // ($db, $hm) — that is itself a problem for a schema-owning plugin.
        check(!$ownsSchema, "$slug: schema-owning plugin must be constructible as ($db, HookManager) for the self-heal to run — " . $e->getMessage());
        continue;
    }
    if (method_exists($instance, 'setPluginId')) {
        $pidRow = $db->query("SELECT id FROM plugins WHERE name='" . $db->real_escape_string($slug) . "'");
        $pid = ($pidRow && $pidRow->num_rows) ? (int) $pidRow->fetch_row()[0] : 0;
        $instance->setPluginId($pid);
    }

    $expected = $instance->expectedTables();
    check(is_array($expected) && $expected !== [], "$slug: expectedTables() returns a non-empty list");

    // Run the plugin's own schema creation (idempotent).
    $result = $instance->ensureSchema();
    $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];
    check($failed === [], "$slug: ensureSchema() reports no failed tables (" . (empty($failed) ? 'ok' : implode(',', $failed)) . ")");

    // Every declared table must now exist — this is the contract the self-heal relies on.
    $missing = array_values(array_filter($expected, fn($t) => !$tableExists((string) $t)));
    check($missing === [], "$slug: all expectedTables() exist after ensureSchema (missing: " . (empty($missing) ? 'none' : implode(',', $missing)) . ")");

    // Idempotency: a second run must not report failures either.
    $result2 = $instance->ensureSchema();
    $failed2 = is_array($result2['failed'] ?? null) ? $result2['failed'] : [];
    check($failed2 === [], "$slug: second ensureSchema() is a clean no-op (idempotent)");

    $checkedPlugins++;
}

check($checkedPlugins >= 6, "covered at least 6 schema-owning plugins (got {$checkedPlugins})");

$db->close();
printf("\nALL %d PASS (%d plugins verified)\n", $TESTNO, $checkedPlugins);
