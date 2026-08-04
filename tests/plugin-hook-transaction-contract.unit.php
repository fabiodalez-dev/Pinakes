<?php
declare(strict_types=1);

/**
 * Reusable transaction-ownership contract for self-healing plugin hooks.
 *
 * Add a plugin to $pluginCases to verify that setPluginId():
 *   - joins both explicit and autocommit(false) caller transactions;
 *   - leaves commit/rollback ownership with the caller;
 *   - commits its complete hook set when invoked standalone.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/digital-library/DigitalLibraryPlugin.php';
require_once $root . '/storage/plugins/goodlib/GoodLibPlugin.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }

    $failed++;
    echo "FAIL  {$label}\n";
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    $env[trim($key)] = $value;
}

// Prefer the E2E_DB_* environment (how CI/the runner injects credentials),
// falling back to .env, so configured creds don't make the test silently SKIP.
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    echo "SKIP: database not reachable ({$e->getMessage()})\n";
    exit(0);
}

/** @var list<array{label:string,class:class-string}> $pluginCases */
$pluginCases = [
    ['label' => 'Digital Library', 'class' => DigitalLibraryPlugin::class],
    ['label' => 'GoodLib', 'class' => GoodLibPlugin::class],
];

$hookCount = static function (mysqli $db, int $pluginId): int {
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM plugin_hooks WHERE plugin_id = ?');
    $stmt->bind_param('i', $pluginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
};

$displayName = static function (mysqli $db, int $pluginId): string {
    $stmt = $db->prepare('SELECT display_name FROM plugins WHERE id = ?');
    $stmt->bind_param('i', $pluginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['display_name'] ?? '');
};

$setDisplayName = static function (mysqli $db, int $pluginId, string $value): void {
    $stmt = $db->prepare('UPDATE plugins SET display_name = ? WHERE id = ?');
    $stmt->bind_param('si', $value, $pluginId);
    $stmt->execute();
    $stmt->close();
};

$deleteHooks = static function (mysqli $db, int $pluginId): void {
    $stmt = $db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
    $stmt->bind_param('i', $pluginId);
    $stmt->execute();
    $stmt->close();
};

/**
 * @param array{label:string,class:class-string} $case
 */
$verifyPluginContract = static function (array $case) use (
    $db,
    $check,
    $hookCount,
    $displayName,
    $setDisplayName,
    $deleteHooks
): void {
    $token = bin2hex(random_bytes(5));
    $name = 'tx-contract-' . strtolower(str_replace(' ', '-', $case['label'])) . '-' . $token;
    $originalLabel = "{$case['label']} transaction fixture {$token}";
    $version = '0.0.0-test';
    $path = $name;
    $mainFile = 'wrapper.php';

    $insert = $db->prepare(
        'INSERT INTO plugins (name, display_name, version, path, main_file) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->bind_param('sssss', $name, $originalLabel, $version, $path, $mainFile);
    $insert->execute();
    $pluginId = (int) $db->insert_id;
    $insert->close();

    echo "{$case['label']}:\n";
    try {
        // Derive the expected hook count from the plugin itself (a standalone
        // registration) rather than hard-coding it, so adding or removing a hook
        // never requires editing this test. The transaction-mode assertions then
        // check they reproduce this same baseline set.
        $baseline = new $case['class']($db);
        $baseline->setPluginId($pluginId);
        $expectedHooks = $hookCount($db, $pluginId);
        $deleteHooks($db, $pluginId);
        $setDisplayName($db, $pluginId, $originalLabel);
        $check(
            $expectedHooks > 0,
            "{$case['label']} registers at least one hook standalone"
        );

        $modes = [
            'begin_transaction' => static function (mysqli $connection): void {
                $connection->begin_transaction();
            },
            'autocommit(false)' => static function (mysqli $connection): void {
                $connection->autocommit(false);
            },
        ];

        foreach ($modes as $mode => $beginCallerTransaction) {
            $beginCallerTransaction($db);
            $changedLabel = "{$originalLabel} changed by {$mode}";
            $setDisplayName($db, $pluginId, $changedLabel);

            $class = $case['class'];
            $plugin = new $class($db);
            $plugin->setPluginId($pluginId);

            $check(
                $hookCount($db, $pluginId) === $expectedHooks,
                "{$case['label']} registers the complete hook set inside {$mode}"
            );

            $db->rollback();
            $db->autocommit(true);

            $check(
                $displayName($db, $pluginId) === $originalLabel,
                "{$case['label']} does not commit caller data opened with {$mode}"
            );
            $check(
                $hookCount($db, $pluginId) === 0,
                "{$case['label']} leaves rollback ownership to {$mode} caller"
            );

            // Keeps later contract cases isolated even when this assertion is
            // run against a deliberately broken implementation.
            $deleteHooks($db, $pluginId);
            $setDisplayName($db, $pluginId, $originalLabel);
        }

        $class = $case['class'];
        $plugin = new $class($db);
        $plugin->setPluginId($pluginId);
        $check(
            $hookCount($db, $pluginId) === $expectedHooks,
            "{$case['label']} commits its complete hook set when standalone"
        );
    } finally {
        $db->rollback();
        $db->autocommit(true);
        $deleteHooks($db, $pluginId);
        $stmt = $db->prepare('DELETE FROM plugins WHERE id = ?');
        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $stmt->close();
    }
};

foreach ($pluginCases as $case) {
    $verifyPluginContract($case);
}

$db->close();

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
