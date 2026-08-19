<?php
declare(strict_types=1);

/**
 * End-to-end contract for the ZIP-update path (PluginManager::installFromZip in
 * its in-place-update mode) run against EVERY installed bundled plugin.
 *
 * For each plugin under storage/plugins/ it:
 *   1. backs the plugin directory up on disk and records its plugins-row version;
 *   2. builds a ZIP from the plugin's REAL files with only the manifest version
 *      bumped;
 *   3. calls installFromZip() — an in-place update because the name already
 *      exists — and asserts it succeeds, keeps the same plugin ID, promotes the
 *      replacement files and persists the new version;
 *   4. restores the directory byte-for-byte and the plugins-row version, so a
 *      developer/CI database and working tree are left exactly as found.
 *
 * Active-plugin lifecycle is intentionally deferred to a fresh bootstrap. This
 * file only checks package compatibility for every bundled plugin; the
 * disposable plugin integration test exercises lifecycle success and rollback.
 *
 * Run: php tests/plugin-zip-update-all-bundled.integration.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

function pzua_env(string $path): array
{
    $values = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }
    return $values;
}

function pzua_rmdir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? pzua_rmdir($path) : @unlink($path);
    }
    @rmdir($directory);
}

function pzua_copydir(string $src, string $dst): void
{
    @mkdir($dst, 0775, true);
    foreach (array_diff(scandir($src) ?: [], ['.', '..']) as $entry) {
        $s = $src . DIRECTORY_SEPARATOR . $entry;
        $d = $dst . DIRECTORY_SEPARATOR . $entry;
        is_dir($s) ? pzua_copydir($s, $d) : @copy($s, $d);
    }
}

/** Build a ZIP of every file under $pluginDir, placed under "$slug/", bumping only the manifest version. */
function pzua_zip_plugin(string $pluginDir, string $slug, string $bumpedVersion): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'pinakes-plugin-all-');
    if ($zipPath === false) {
        throw new RuntimeException('Unable to create temporary ZIP path.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP for ' . $slug);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($pluginDir) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $entry = $slug . '/' . $relative;
        if ($item->isDir()) {
            $zip->addEmptyDir($entry);
            continue;
        }
        if ($relative === 'plugin.json') {
            $meta = json_decode((string) file_get_contents($item->getPathname()), true);
            if (is_array($meta)) {
                $meta['version'] = $bumpedVersion;
                $zip->addFromString($entry, (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                continue;
            }
        }
        $zip->addFile($item->getPathname(), $entry);
    }
    $zip->close();
    return $zipPath;
}

$env = pzua_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$password = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$database = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
$db = (is_string($socket) && $socket !== '' && file_exists($socket))
    ? @new mysqli(null, $user, $password, $database, 0, $socket)
    : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $password, $database, (int) ($env['DB_PORT'] ?? 3306));
if ($db->connect_errno !== 0) {
    echo "SKIP: database not reachable\n";
    exit(0);
}
if (!class_exists(ZipArchive::class)) {
    echo "SKIP: ZipArchive extension unavailable\n";
    exit(0);
}

$root = dirname(__DIR__);
$pluginsDir = $root . '/storage/plugins';
$pluginDirs = glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [];
sort($pluginDirs);

$manager = new \App\Support\PluginManager($db, new \App\Support\HookManager($db));
// Ensure every bundled plugin owns a plugins row so installFromZip takes the
// in-place UPDATE branch (a missing row would make it a fresh install instead).
$manager->autoRegisterBundledPlugins();

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  OK  {$label}\n"; }
    else     { $failed++; echo "  FAIL {$label}\n"; }
};

echo "ZIP update contract for every installed bundled plugin (" . count($pluginDirs) . " found):\n";

foreach ($pluginDirs as $pluginDir) {
    $slug = basename($pluginDir);
    $manifest = json_decode((string) @file_get_contents($pluginDir . '/plugin.json'), true);
    if (!is_array($manifest) || !isset($manifest['name'])) {
        $check(false, "{$slug}: has a readable plugin.json");
        continue;
    }
    $name = (string) $manifest['name'];
    $row = $db->query("SELECT id, version FROM plugins WHERE name = '" . $db->real_escape_string($name) . "' LIMIT 1");
    $before = $row instanceof mysqli_result ? $row->fetch_assoc() : null;
    if (!is_array($before)) {
        $check(false, "{$slug}: is registered in the plugins table");
        continue;
    }
    $pluginId = (int) $before['id'];
    $originalVersion = (string) $before['version'];
    $bumped = ((string) ($manifest['version'] ?? '0.0.0')) . '-ziptest';

    $backupDir = $pluginsDir . '/.zipupdate-backup-' . $slug;
    pzua_rmdir($backupDir);
    pzua_copydir($pluginDir, $backupDir);
    $zipPath = null;

    try {
        $zipPath = pzua_zip_plugin($pluginDir, $slug, $bumped);
        $result = $manager->installFromZip($zipPath);

        $ok = ($result['success'] ?? false) === true
            && ($result['updated'] ?? false) === true
            && (int) ($result['plugin_id'] ?? 0) === $pluginId;

        $after = $db->query("SELECT version FROM plugins WHERE id = {$pluginId} LIMIT 1");
        $afterVersion = $after instanceof mysqli_result ? (string) ($after->fetch_row()[0] ?? '') : '';

        $mainFile = (string) ($manifest['main_file'] ?? '');
        $filesPromoted = is_file($pluginDir . '/plugin.json')
            && ($mainFile === '' || is_file($pluginDir . '/' . $mainFile));

        $check(
            $ok && $afterVersion === $bumped && $filesPromoted,
            "{$slug}: ZIP update keeps id {$pluginId}, promotes files, persists version"
                . ($ok ? '' : ' [msg: ' . ($result['message'] ?? 'unknown') . ']')
        );
    } catch (\Throwable $e) {
        $check(false, "{$slug}: ZIP update threw — " . $e->getMessage());
    } finally {
        // Restore the plugin directory byte-for-byte and its plugins-row version.
        pzua_rmdir($pluginDir);
        pzua_copydir($backupDir, $pluginDir);
        pzua_rmdir($backupDir);
        $restore = $db->prepare('UPDATE plugins SET version = ? WHERE id = ?');
        if ($restore instanceof mysqli_stmt) {
            $restore->bind_param('si', $originalVersion, $pluginId);
            $restore->execute();
            $restore->close();
        }
        if (is_string($zipPath)) {
            @unlink($zipPath);
        }
        // Clean any stray staging/backup directories the update may have left.
        foreach (glob($pluginsDir . '/.' . $slug . '.backup-*') ?: [] as $stray) {
            pzua_rmdir($stray);
        }
        foreach (glob($pluginsDir . '/.' . $slug . '.staging-*') ?: [] as $stray) {
            pzua_rmdir($stray);
        }
        @unlink($pluginsDir . '/' . \App\Support\PluginManager::PENDING_UPDATE_PREFIX . $pluginId . '.json');
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
$db->close();
exit($failed === 0 ? 0 : 1);
