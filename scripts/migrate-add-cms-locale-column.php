<?php
/**
 * Migration Script: Add locale column to cms_pages table
 * Run this to enable multi-language CMS pages with locale-specific slugs
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
echo "║   Migration: Add CMS Locale Column        ║\n";
echo "╚════════════════════════════════════════════╝\n\n";

echo "ℹ️  Connecting to database: $dbname@$host\n";

// Connect to database
$db = new mysqli($host, $user, $pass, $dbname);
if ($db->connect_error) {
    die("❌ Connection failed: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "✅ Connected successfully\n\n";

// Check if table exists
$result = $db->query("SHOW TABLES LIKE 'cms_pages'");
if ($result->num_rows === 0) {
    echo "❌ Error: Table 'cms_pages' does not exist.\n";
    echo "   This migration requires the CMS pages table to exist.\n\n";
    $db->close();
    exit(1);
}

// Check if column already exists
$result = $db->query("SHOW COLUMNS FROM cms_pages LIKE 'locale'");
if ($result->num_rows > 0) {
    echo "⚠️  Column 'locale' already exists in cms_pages. Skipping migration.\n";
    echo "   Migration already applied.\n\n";
    $db->close();
    exit(0);
}

echo "ℹ️  Adding 'locale' column to cms_pages table...\n";

// Get default locale from languages table
$defaultLocale = 'it_IT'; // Fallback
$result = $db->query("SELECT code FROM languages WHERE is_default = 1 LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $defaultLocale = $row['code'];
    echo "   ✅ Found default locale: $defaultLocale\n";
}

// Add locale column
$sql = "ALTER TABLE cms_pages
        ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT '$defaultLocale' AFTER slug,
        ADD INDEX idx_cms_locale (locale),
        ADD INDEX idx_cms_slug_locale (slug, locale)";

if ($db->query($sql)) {
    echo "   ✅ Column 'locale' added successfully\n";
    echo "   ✅ Index idx_cms_locale created\n";
    echo "   ✅ Composite index idx_cms_slug_locale created\n";
} else {
    echo "   ❌ Error: " . $db->error . "\n";
    $db->close();
    exit(1);
}

// Verify column was added
$result = $db->query("SHOW COLUMNS FROM cms_pages LIKE 'locale'");
if ($result->num_rows > 0) {
    $column = $result->fetch_assoc();
    echo "   ✅ Column verified:\n";
    echo "      - Type: {$column['Type']}\n";
    echo "      - Default: {$column['Default']}\n";
    echo "      - Null: {$column['Null']}\n";
} else {
    echo "   ❌ Error: Column 'locale' was not created\n";
    $db->close();
    exit(1);
}

// Count existing CMS pages
$result = $db->query("SELECT COUNT(*) as count FROM cms_pages");
$row = $result->fetch_assoc();
$pagesCount = $row['count'];

echo "\n";
echo "╔════════════════════════════════════════════╗\n";
echo "║   Migration Completed Successfully!       ║\n";
echo "╚════════════════════════════════════════════╝\n";
echo "\n";
echo "✅ Summary:\n";
echo "   - Column 'locale' added to cms_pages\n";
echo "   - Default locale: $defaultLocale\n";
echo "   - Existing CMS pages: $pagesCount (all set to $defaultLocale)\n";
echo "   - Indexes created: idx_cms_locale, idx_cms_slug_locale\n";
echo "\n";
echo "ℹ️  Impact:\n";
echo "   - CMS pages now support multiple locales\n";
echo "   - Each page can have different slugs per language\n";
echo "   - Existing pages will continue to work with default locale\n";
echo "\n";
echo "ℹ️  Next steps:\n";
echo "   1. Visit /admin/cms to manage CMS pages\n";
echo "   2. Create alternate language versions of pages if needed\n";
echo "   3. Use translated slugs from route translation system\n";
echo "\n";

$db->close();
