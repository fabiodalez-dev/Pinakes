<?php
/**
 * Migration Script: Add languages table
 * Run this to add multilingual support to existing installations
 */

declare(strict_types=1);

// Load .env file
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    die("❌ Error: .env file not found. Make sure the application is installed.\n");
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'biblioteca';

echo "╔════════════════════════════════════════════╗\n";
echo "║   Migration: Add Languages Table          ║\n";
echo "╚════════════════════════════════════════════╝\n\n";

echo "ℹ️  Connecting to database: $dbname@$host\n";

// Connect to database
$db = new mysqli($host, $user, $pass, $dbname);
if ($db->connect_error) {
    die("❌ Connection failed: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "✅ Connected successfully\n\n";

// Check if table already exists
$result = $db->query("SHOW TABLES LIKE 'languages'");
if ($result->num_rows > 0) {
    echo "⚠️  Table 'languages' already exists. Skipping migration.\n";
    echo "   If you want to re-run, drop the table first:\n";
    echo "   DROP TABLE languages;\n\n";
    $db->close();
    exit(0);
}

// Read migration SQL file
$migrationFile = __DIR__ . '/migrations/add-languages-table.sql';
if (!file_exists($migrationFile)) {
    $db->close();
    die("❌ Error: Migration file not found: $migrationFile\n");
}

echo "ℹ️  Reading migration file...\n";
$sql = file_get_contents($migrationFile);

// Remove comments
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = trim($sql);

echo "ℹ️  Executing migration...\n\n";

// Execute with multi_query
if ($db->multi_query($sql)) {
    echo "   ✅ SQL executed successfully\n";

    // Clear all results
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    $success = 1;
    $errors = 0;
} else {
    echo "   ❌ Error: " . $db->error . "\n";
    $success = 0;
    $errors = 1;
}

echo "\n";

// Verify table was created
$result = $db->query("SHOW TABLES LIKE 'languages'");
if ($result->num_rows > 0) {
    echo "✅ Table 'languages' created successfully\n";
} else {
    echo "❌ Error: Table 'languages' was not created\n";
    $db->close();
    exit(1);
}

// Verify seed data
$result = $db->query("SELECT COUNT(*) as count FROM languages");
$row = $result->fetch_assoc();
$count = $row['count'];

if ($count > 0) {
    echo "✅ Seed data inserted: $count languages\n";

    // Show inserted languages
    $result = $db->query("SELECT code, native_name, flag_emoji, is_default FROM languages ORDER BY is_default DESC, code");
    echo "\n   Languages in database:\n";
    while ($lang = $result->fetch_assoc()) {
        $default = $lang['is_default'] ? ' (default)' : '';
        echo "   - {$lang['flag_emoji']} {$lang['native_name']} ({$lang['code']}){$default}\n";
    }
} else {
    echo "⚠️  Warning: No seed data found in languages table\n";
}

echo "\n";
echo "╔════════════════════════════════════════════╗\n";
echo "║   Migration Completed Successfully!       ║\n";
echo "╚════════════════════════════════════════════╝\n";
echo "\n";
echo "✅ Summary:\n";
echo "   - Statements executed: $success\n";
echo "   - Errors: $errors\n";
echo "   - Languages table: EXISTS\n";
echo "   - Seed data: $count rows\n";
echo "\n";
echo "ℹ️  Next steps:\n";
echo "   1. Refresh admin panel to see new Languages menu\n";
echo "   2. Visit /admin/languages to manage languages\n";
echo "   3. Add language switcher to your layout\n";
echo "\n";

$db->close();
