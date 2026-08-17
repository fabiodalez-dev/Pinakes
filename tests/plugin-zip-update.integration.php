<?php
declare(strict_types=1);

/**
 * End-to-end contract for updating an already-installed plugin from a ZIP.
 * It uses a randomly named disposable plugin and removes its row and files in
 * a finally block, so it can safely run against a developer/CI database.
 *
 * Run: php tests/plugin-zip-update.integration.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

function pzu_env(string $path): array
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

function pzu_delete_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            pzu_delete_directory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

/** @return string ZIP path */
function pzu_create_zip(string $slug, string $className, string $version, string $displayName): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'pinakes-plugin-update-');
    if ($zipPath === false) {
        throw new RuntimeException('Unable to create temporary ZIP path.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test ZIP.');
    }
    $manifest = json_encode([
        'name' => $slug,
        'display_name' => $displayName,
        'version' => $version,
        'main_file' => 'wrapper.php',
        'requires_php' => '8.2',
        'metadata' => ['test_package' => true],
    ], JSON_THROW_ON_ERROR);
    $wrapper = "<?php\ndeclare(strict_types=1);\nclass {$className} {\n"
        . "    public function __construct(mysqli \$db, \\App\\Support\\HookManager \$hookManager) {}\n"
        . "}\n";
    $zip->addFromString($slug . '/plugin.json', $manifest);
    $zip->addFromString($slug . '/wrapper.php', $wrapper);
    $zip->close();
    return $zipPath;
}

$env = pzu_env(__DIR__ . '/../.env');
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

$suffix = bin2hex(random_bytes(6));
$slug = 'plugin-zip-update-' . $suffix;
$className = 'PluginZipUpdate' . ucfirst($suffix) . 'Plugin';
$pluginsDir = dirname(__DIR__) . '/storage/plugins';
$pluginDir = $pluginsDir . '/' . $slug;
$zipV1 = null;
$zipV2 = null;
$pluginId = 0;

try {
    $manager = new \App\Support\PluginManager($db, new \App\Support\HookManager($db));
    $zipV1 = pzu_create_zip($slug, $className, '1.0.0', 'Disposable plugin v1');
    $firstInstall = $manager->installFromZip($zipV1);
    if (($firstInstall['success'] ?? false) !== true) {
        throw new RuntimeException('Initial install failed: ' . ($firstInstall['message'] ?? 'unknown error'));
    }
    $pluginId = (int) ($firstInstall['plugin_id'] ?? 0);
    if ($pluginId <= 0) {
        throw new RuntimeException('Initial install did not return a plugin ID.');
    }

    $db->query("UPDATE plugins SET is_active = 1 WHERE id = {$pluginId}");
    $db->query("INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload) VALUES ({$pluginId}, 'kept_setting', 'kept value', 1)");
    $db->query("INSERT INTO plugin_data (plugin_id, data_key, data_value, data_type) VALUES ({$pluginId}, 'kept_data', 'kept value', 'string')");
    $db->query("INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) VALUES ({$pluginId}, 'test.update', '{$className}', 'handleUpdate', 10, 1)");

    $zipV2 = pzu_create_zip($slug, $className, '1.1.0', 'Disposable plugin v2');
    $update = $manager->installFromZip($zipV2);
    if (($update['success'] ?? false) !== true || ($update['updated'] ?? false) !== true) {
        throw new RuntimeException('ZIP update failed: ' . ($update['message'] ?? 'unknown error'));
    }
    if ((int) ($update['plugin_id'] ?? 0) !== $pluginId) {
        throw new RuntimeException('ZIP update changed the plugin ID.');
    }

    $row = $db->query("SELECT version, display_name, is_active FROM plugins WHERE id = {$pluginId}")->fetch_assoc();
    $settings = (int) $db->query("SELECT COUNT(*) FROM plugin_settings WHERE plugin_id = {$pluginId} AND setting_key = 'kept_setting'")->fetch_row()[0];
    $data = (int) $db->query("SELECT COUNT(*) FROM plugin_data WHERE plugin_id = {$pluginId} AND data_key = 'kept_data'")->fetch_row()[0];
    $hooks = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update'")->fetch_row()[0];

    if (!is_array($row) || $row['version'] !== '1.1.0' || $row['display_name'] !== 'Disposable plugin v2') {
        throw new RuntimeException('ZIP update did not persist the replacement manifest.');
    }
    if ((int) $row['is_active'] !== 1 || $settings !== 1 || $data !== 1 || $hooks !== 1) {
        throw new RuntimeException('ZIP update did not preserve plugin state and related data.');
    }
    if (!is_file($pluginDir . '/wrapper.php')) {
        throw new RuntimeException('ZIP update did not promote the replacement package.');
    }

    echo "PASS: existing plugin ZIP update keeps ID, active state, settings, data and hooks\n";
} finally {
    if ($pluginId > 0) {
        $db->query("DELETE FROM plugins WHERE id = {$pluginId}");
    }
    pzu_delete_directory($pluginDir);
    if (is_string($zipV1)) {
        @unlink($zipV1);
    }
    if (is_string($zipV2)) {
        @unlink($zipV2);
    }
    $db->close();
}
