<?php
declare(strict_types=1);

/**
 * Language default-switch regression: create/update must leave exactly one
 * default row and restore the original catalogue state after the test.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            $user,
            $pass,
            $name,
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    echo "SKIP: database not reachable ({$e->getMessage()})\n";
    exit(0);
}

$table = $db->query("SHOW TABLES LIKE 'languages'");
if (!$table instanceof mysqli_result || $table->num_rows === 0) {
    echo "SKIP: languages table not installed\n";
    exit(0);
}

$originalDefault = $db->query('SELECT code FROM languages WHERE is_default = 1 LIMIT 1')->fetch_column();
if (!is_string($originalDefault) || $originalDefault === '') {
    echo "SKIP: no original default language to restore\n";
    exit(0);
}

$alphabet = range('A', 'Z');
$suffix = $alphabet[random_int(0, 25)] . $alphabet[random_int(0, 25)];
$firstCode = 'zx_' . $suffix;
$secondCode = 'zy_' . $suffix;
$model = new \App\Models\Language($db);
$passed = 0;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException("assertion failed: {$label}");
    }
    $passed++;
    echo "PASS: {$label}\n";
};

$deleteFixtures = static function () use ($db, $firstCode, $secondCode): void {
    $stmt = $db->prepare('DELETE FROM languages WHERE code IN (?, ?)');
    $stmt->bind_param('ss', $firstCode, $secondCode);
    $stmt->execute();
    $stmt->close();
};

try {
    $deleteFixtures();

    $model->create([
        'code' => $firstCode,
        'name' => 'Atomic language one',
        'native_name' => 'Atomic language one',
        'is_default' => 1,
    ]);
    $row = $db->query('SELECT COUNT(*) AS total, MAX(code) AS code FROM languages WHERE is_default = 1')->fetch_assoc();
    $check((int) ($row['total'] ?? 0) === 1 && ($row['code'] ?? null) === $firstCode, 'create promotes exactly one default language');

    $model->create([
        'code' => $secondCode,
        'name' => 'Atomic language two',
        'native_name' => 'Atomic language two',
    ]);
    $model->update($secondCode, ['is_default' => 1]);
    $row = $db->query('SELECT COUNT(*) AS total, MAX(code) AS code FROM languages WHERE is_default = 1')->fetch_assoc();
    $check((int) ($row['total'] ?? 0) === 1 && ($row['code'] ?? null) === $secondCode, 'update promotes exactly one default language');
} finally {
    try {
        $model->setDefault($originalDefault);
    } finally {
        $deleteFixtures();
        $db->close();
    }
}

echo "ALL {$passed} PASS\n";
