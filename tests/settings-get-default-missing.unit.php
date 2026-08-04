<?php
declare(strict_types=1);

/**
 * Regression: SettingsRepository::get() must return the caller's $default when
 * the key is ABSENT, not ''. mysqli_result::fetch_column() yields `false` (not
 * null) for a no-row result, so the old `if ($value === null)` guard missed it
 * and coerced false → '' — silently discarding every default. The visible
 * victim was the label PDF: label.show_* fell back to '' instead of '1', so
 * `get(...) === '1'` was false and the label rendered only the barcode.
 *
 * Pattern mirrors tests/migration-0.7.26.unit.php: real DB, SKIP (exit 0) when
 * unreachable so CI without a DB doesn't red-fail.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Models\SettingsRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sgLoadEnv(string $path): array
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

$env = sgLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$repo = new SettingsRepository($db);
$repo->ensureTables();

// Unique throwaway category so we never touch a real setting.
$cat = 'zz_get_default_probe';
$db->query("DELETE FROM system_settings WHERE category = '" . $db->real_escape_string($cat) . "'");
$repo->set($cat, 'present_key', 'stored_value');

$checks = [];

// A key that exists returns its stored value.
$checks['existing key returns stored value'] =
    $repo->get($cat, 'present_key', 'DEFAULT') === 'stored_value';

// The regression: a MISSING key must return the default, not ''.
$checks['missing key returns provided default'] =
    $repo->get($cat, 'never_written', 'DEFAULT') === 'DEFAULT';

// Missing key with no default returns null (matches the ?string signature).
$checks['missing key with no default returns null'] =
    $repo->get($cat, 'never_written') === null;

// The concrete label case: a truthy '1' default survives when unset, so
// `=== '1'` stays true and the label keeps rendering its text blocks.
$checks['label show_* style default applies when unset'] =
    ($repo->get($cat, 'show_title_probe', '1') === '1') === true;

// An explicitly-stored empty string is a real value, still returned as ''.
$repo->set($cat, 'blank_key', '');
$checks['explicit empty stored value stays empty (not default)'] =
    $repo->get($cat, 'blank_key', 'DEFAULT') === '';

// Cleanup.
$db->query("DELETE FROM system_settings WHERE category = '" . $db->real_escape_string($cat) . "'");
$db->close();

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
