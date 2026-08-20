<?php
declare(strict_types=1);

/**
 * Start one fresh PHP process for the deferred ZIP-update lifecycle contract.
 * Kept separate because an already-required plugin class cannot be replaced in
 * the process that handles its upload.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

function pzub_env(string $path): array
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

$env = pzub_env(__DIR__ . '/../../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$password = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$database = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
$db = (is_string($socket) && $socket !== '' && file_exists($socket))
    ? @new mysqli(null, $user, $password, $database, 0, $socket)
    : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $password, $database, (int) ($env['DB_PORT'] ?? 3306));
if ($db->connect_errno !== 0) {
    fwrite(STDERR, "Database not reachable during plugin update bootstrap.\n");
    exit(2);
}

try {
    $manager = new \App\Support\PluginManager($db, new \App\Support\HookManager($db));
    $manager->loadActivePlugins();
} finally {
    $db->close();
}
