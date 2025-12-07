<?php
/**
 * Test script for migration 0.4.0
 * Run: php scripts/test_migration.php
 *
 * This script simulates what the Updater does when applying migrations.
 * It's safe to run multiple times (idempotent).
 */
declare(strict_types=1);

echo "=== MIGRATION 0.4.0 TEST ===\n\n";

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

// Database configuration
$settings = require __DIR__ . '/../config/settings.php';
$cfg = $settings['db'];

// Connect
$socket = null;
if ($cfg['hostname'] === 'localhost') {
    $socketPaths = ['/tmp/mysql.sock', '/var/run/mysqld/mysqld.sock', '/usr/local/var/mysql/mysql.sock', '/opt/homebrew/var/mysql/mysql.sock'];
    foreach ($socketPaths as $socketPath) {
        if (file_exists($socketPath)) {
            $socket = $socketPath;
            break;
        }
    }
}

$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $cfg['port'], $socket);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}
$db->set_charset($cfg['charset']);

// Disable exceptions - return false on error instead (same as Updater behavior)
mysqli_report(MYSQLI_REPORT_OFF);

echo "✓ Connected to database: {$cfg['database']}\n\n";

// Check current state
echo "=== PRE-MIGRATION STATE ===\n";

// Check utenti columns
$result = $db->query("SHOW COLUMNS FROM utenti WHERE Field IN ('privacy_accettata', 'data_accettazione_privacy', 'privacy_policy_version')");
$existingColumns = [];
while ($row = $result->fetch_assoc()) {
    $existingColumns[] = $row['Field'];
}
echo "GDPR columns in utenti: " . (empty($existingColumns) ? "NONE" : implode(", ", $existingColumns)) . "\n";

// Check tables
$gdprTables = ['user_sessions', 'gdpr_requests', 'consent_log'];
foreach ($gdprTables as $table) {
    $result = $db->query("SHOW TABLES LIKE '{$table}'");
    $exists = $result->num_rows > 0;
    echo "Table {$table}: " . ($exists ? "EXISTS" : "NOT EXISTS") . "\n";
}

echo "\n=== RUNNING MIGRATION ===\n";

// Read migration file
$migrationFile = __DIR__ . '/../installer/database/migrations/migrate_0.4.0.sql';
if (!file_exists($migrationFile)) {
    die("Migration file not found: {$migrationFile}\n");
}

$sql = file_get_contents($migrationFile);

// Remove SQL comments (lines starting with --)
$sqlLines = explode("\n", $sql);
$sqlLines = array_filter($sqlLines, fn($line) => !preg_match('/^\s*--/', $line));
$sql = implode("\n", $sqlLines);

$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => !empty($s)
);

echo "Found " . count($statements) . " SQL statements\n\n";

// Ignorable MySQL error codes
$ignorableErrors = [1060, 1061, 1050, 1068];

$executed = 0;
$skipped = 0;
$errors = 0;

foreach ($statements as $i => $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;

    // Show first 80 chars of statement
    $preview = substr(preg_replace('/\s+/', ' ', $statement), 0, 80);
    echo "[" . ($i + 1) . "] {$preview}...\n";

    $result = $db->query($statement);

    if ($result === false) {
        if (in_array($db->errno, $ignorableErrors, true)) {
            echo "    ⚠ SKIPPED (idempotent): {$db->error}\n";
            $skipped++;
        } else {
            echo "    ✗ ERROR [{$db->errno}]: {$db->error}\n";
            $errors++;
        }
    } else {
        $affected = $db->affected_rows;
        echo "    ✓ OK" . ($affected > 0 ? " ({$affected} rows affected)" : "") . "\n";
        $executed++;
    }
}

echo "\n=== POST-MIGRATION STATE ===\n";

// Verify utenti columns
$result = $db->query("SHOW COLUMNS FROM utenti WHERE Field IN ('privacy_accettata', 'data_accettazione_privacy', 'privacy_policy_version')");
$newColumns = [];
while ($row = $result->fetch_assoc()) {
    $newColumns[] = $row['Field'];
}
echo "GDPR columns in utenti: " . implode(", ", $newColumns) . "\n";

// Verify tables
foreach ($gdprTables as $table) {
    $result = $db->query("SHOW TABLES LIKE '{$table}'");
    $exists = $result->num_rows > 0;
    echo "Table {$table}: " . ($exists ? "✓ EXISTS" : "✗ MISSING") . "\n";
}

// Count backfilled users
$result = $db->query("SELECT COUNT(*) as cnt FROM utenti WHERE privacy_accettata = 1");
$row = $result->fetch_assoc();
echo "Users with privacy_accettata=1: {$row['cnt']}\n";

echo "\n=== SUMMARY ===\n";
echo "Executed: {$executed}\n";
echo "Skipped (idempotent): {$skipped}\n";
echo "Errors: {$errors}\n";

if ($errors === 0) {
    echo "\n✓ MIGRATION TEST PASSED\n";
    exit(0);
} else {
    echo "\n✗ MIGRATION TEST FAILED\n";
    exit(1);
}
