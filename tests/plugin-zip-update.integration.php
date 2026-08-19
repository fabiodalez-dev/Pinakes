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
function pzu_create_zip(
    string $slug,
    string $className,
    string $version,
    string $displayName,
    string $lifecycle,
    string $tableName
): string
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
    $lifecycleMethod = '';
    if ($lifecycle === 'success') {
        $lifecycleMethod = <<<'PHP'
    public function onActivate(): void
    {
        if ($this->pluginId === null) {
            throw new RuntimeException('plugin id missing');
        }
        if (!$this->db->query('CREATE TABLE IF NOT EXISTS `{{TABLE}}` (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB')) {
            throw new RuntimeException('schema update failed');
        }
        $delete = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        $delete->bind_param('i', $this->pluginId);
        if (!$delete->execute()) {
            throw new RuntimeException('hook cleanup failed');
        }
        $delete->close();
        $hookName = 'test.update.v2';
        $callbackClass = self::class;
        $callbackMethod = 'handleUpdated';
        $priority = 7;
        $insert = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, 1)'
        );
        $insert->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $callbackMethod, $priority);
        if (!$insert->execute()) {
            throw new RuntimeException('hook registration failed');
        }
        $insert->close();
    }

    public function handleUpdated(): void
    {
    }
PHP;
    } elseif ($lifecycle === 'failure') {
        $lifecycleMethod = <<<'PHP'
    public function onActivate(): void
    {
        if ($this->pluginId === null) {
            throw new RuntimeException('plugin id missing');
        }
        $hookName = 'test.update.partial';
        $callbackClass = self::class;
        $callbackMethod = 'handlePartial';
        $priority = 3;
        $insert = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) '
            . 'VALUES (?, ?, ?, ?, ?, 1)'
        );
        $insert->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $callbackMethod, $priority);
        $insert->execute();
        $insert->close();
        throw new RuntimeException('intentional lifecycle failure');
    }

    public function handlePartial(): void
    {
    }
PHP;
    } else {
        $lifecycleMethod = <<<'PHP'
    public function handleLegacy(): void
    {
    }
PHP;
    }

    $wrapperTemplate = <<<'PHP'
<?php
declare(strict_types=1);

class {{CLASS}}
{
    private mysqli $db;
    private ?int $pluginId = null;

    public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
    {
        $this->db = $db;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

{{LIFECYCLE}}
}
PHP;
    $wrapper = strtr($wrapperTemplate, [
        '{{CLASS}}' => $className,
        '{{TABLE}}' => $tableName,
        '{{LIFECYCLE}}' => str_replace('{{TABLE}}', $tableName, $lifecycleMethod),
    ]);
    $zip->addFromString($slug . '/plugin.json', $manifest);
    $zip->addFromString($slug . '/wrapper.php', $wrapper);
    $zip->close();
    return $zipPath;
}

function pzu_run_fresh_bootstrap(): void
{
    // Route stderr to a file, not a pipe: reading stdout to EOF then stderr can
    // deadlock if the child fills the ~64 KB stderr pipe buffer first (child
    // blocks writing stderr ↔ parent blocks reading stdout) — exactly the
    // large-stack-trace case where the diagnostic matters most.
    $stderrFile = tempnam(sys_get_temp_dir(), 'pzu_stderr_');
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/helpers/plugin-zip-update-bootstrap.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        @unlink($stderrFile);
        throw new RuntimeException('Unable to start fresh plugin bootstrap process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $stderr = (string) @file_get_contents($stderrFile);
    @unlink($stderrFile);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Fresh plugin bootstrap failed: ' . trim((string) $stdout . "\n" . $stderr)
        );
    }

    // The child process (finalizePendingPluginUpdates) deletes the marker and
    // backup directory. PHP's stat cache is only invalidated by filesystem
    // calls made in THIS process, so without an explicit clear the parent still
    // sees the pre-bootstrap state for is_file()/is_dir() checks — a stale hit
    // that surfaces on Linux/PHP 8.2 (CI) even though macOS/PHP 8.4 masks it.
    clearstatcache(true);
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
$pendingMarker = $pluginsDir . '/' . \App\Support\PluginManager::PENDING_UPDATE_PREFIX;
$tableName = 'plugin_zip_update_' . $suffix;
$zipV1 = null;
$zipV2 = null;
$zipV3 = null;
$pluginId = 0;

try {
    $manager = new \App\Support\PluginManager($db, new \App\Support\HookManager($db));
    $zipV1 = pzu_create_zip($slug, $className, '1.0.0', 'Disposable plugin v1', 'none', $tableName);
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
    $db->query("INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active) VALUES ({$pluginId}, 'test.update.legacy', '{$className}', 'handleLegacy', 10, 1)");

    $zipV2 = pzu_create_zip($slug, $className, '1.1.0', 'Disposable plugin v2', 'success', $tableName);
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
    $legacyHooksBeforeBootstrap = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.legacy'")->fetch_row()[0];

    if (!is_array($row) || $row['version'] !== '1.1.0' || $row['display_name'] !== 'Disposable plugin v2') {
        throw new RuntimeException('ZIP update did not persist the replacement manifest.');
    }
    if ((int) $row['is_active'] !== 1 || $settings !== 1 || $data !== 1 || $legacyHooksBeforeBootstrap !== 1) {
        throw new RuntimeException('ZIP update did not preserve plugin state and related data.');
    }
    if (!is_file($pluginDir . '/wrapper.php')) {
        throw new RuntimeException('ZIP update did not promote the replacement package.');
    }

    $pendingMarker .= $pluginId . '.json';
    if (!is_file($pendingMarker)) {
        throw new RuntimeException('Active ZIP update did not persist a deferred lifecycle marker.');
    }
    $schemaBeforeBootstrap = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableName}'"
    )->fetch_row()[0];
    if ($schemaBeforeBootstrap !== 0) {
        throw new RuntimeException('Replacement lifecycle ran in the process that still owns the old class.');
    }

    pzu_run_fresh_bootstrap();

    $schemaAfterBootstrap = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableName}'"
    )->fetch_row()[0];
    $legacyHooks = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.legacy'")->fetch_row()[0];
    $updatedHooks = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.v2' AND callback_method = 'handleUpdated'")->fetch_row()[0];
    if ($schemaAfterBootstrap !== 1 || $legacyHooks !== 0 || $updatedHooks !== 1) {
        throw new RuntimeException('Fresh bootstrap did not apply replacement schema and hook lifecycle.');
    }
    if (is_file($pendingMarker) || (glob($pluginsDir . '/.' . $slug . '.backup-*') ?: []) !== []) {
        throw new RuntimeException('Successful replacement lifecycle left update recovery files behind.');
    }

    // A later update whose lifecycle fails must restore the v2 files, metadata
    // and hook rows instead of leaving a half-upgraded active plugin behind.
    $zipV3 = pzu_create_zip($slug, $className, '1.2.0', 'Disposable plugin v3', 'failure', $tableName);
    $failingUpdate = $manager->installFromZip($zipV3);
    if (($failingUpdate['success'] ?? false) !== true || !is_file($pendingMarker)) {
        throw new RuntimeException('Unable to stage the failing lifecycle rollback case.');
    }
    pzu_run_fresh_bootstrap();

    $rolledBack = $db->query("SELECT version, display_name, is_active FROM plugins WHERE id = {$pluginId}")->fetch_assoc();
    $updatedHooksAfterRollback = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.v2' AND callback_method = 'handleUpdated' AND is_active = 1")->fetch_row()[0];
    $partialHooks = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.partial'")->fetch_row()[0];
    $restoredSource = (string) file_get_contents($pluginDir . '/wrapper.php');
    if (!is_array($rolledBack)
        || $rolledBack['version'] !== '1.1.0'
        || $rolledBack['display_name'] !== 'Disposable plugin v2'
        || (int) $rolledBack['is_active'] !== 1
        || $updatedHooksAfterRollback !== 1
        || $partialHooks !== 0
        || !str_contains($restoredSource, 'handleUpdated')
        || str_contains($restoredSource, 'intentional lifecycle failure')
    ) {
        throw new RuntimeException('Failed replacement lifecycle did not restore package, metadata and hooks.');
    }
    // A later, unrelated request must not disturb the rolled-back plugin. With
    // the marker already gone (the rollback unlinked it), this fresh process runs
    // finalizePendingPluginUpdates() as a no-op: v2 stays active, its version is
    // not bumped again, and its hook keeps serving. (Skipping the broken v3 class
    // is an intra-process concern of the rollback request above — a separate
    // process cannot and need not observe it.)
    $stateBeforeNextRequest = $db->query("SELECT version, is_active FROM plugins WHERE id = {$pluginId}")->fetch_assoc();
    pzu_run_fresh_bootstrap();
    $stateAfterNextRequest = $db->query("SELECT version, is_active FROM plugins WHERE id = {$pluginId}")->fetch_assoc();
    $activeHooksOnNextRequest = (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = {$pluginId} AND hook_name = 'test.update.v2' AND callback_method = 'handleUpdated' AND is_active = 1")->fetch_row()[0];
    if (!is_array($stateAfterNextRequest)
        || $stateAfterNextRequest != $stateBeforeNextRequest
        || $stateAfterNextRequest['version'] !== '1.1.0'
        || (int) $stateAfterNextRequest['is_active'] !== 1
        || $activeHooksOnNextRequest !== 1
    ) {
        throw new RuntimeException('A later request re-triggered or disturbed the rolled-back plugin.');
    }
    if (is_file($pendingMarker) || (glob($pluginsDir . '/.' . $slug . '.backup-*') ?: []) !== []) {
        throw new RuntimeException('Lifecycle rollback left update recovery files behind.');
    }

    echo "PASS: ZIP update defers lifecycle to a fresh process and rolls back package, metadata and hooks on failure\n";
} finally {
    if ($pluginId > 0) {
        $db->query("DELETE FROM plugins WHERE id = {$pluginId}");
    }
    $db->query("DROP TABLE IF EXISTS `{$tableName}`");
    pzu_delete_directory($pluginDir);
    if ($pluginId > 0) {
        @unlink($pluginsDir . '/' . \App\Support\PluginManager::PENDING_UPDATE_PREFIX . $pluginId . '.json');
    }
    foreach (glob($pluginsDir . '/.' . $slug . '.backup-*') ?: [] as $backup) {
        pzu_delete_directory($backup);
    }
    if (is_string($zipV1)) {
        @unlink($zipV1);
    }
    if (is_string($zipV2)) {
        @unlink($zipV2);
    }
    if (is_string($zipV3)) {
        @unlink($zipV3);
    }
    $db->close();
}
