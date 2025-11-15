<?php
/**
 * Migration: Add SEO fields to home_content table
 *
 * Adds the following columns:
 * - seo_title (VARCHAR 255) - Custom SEO title override
 * - seo_description (TEXT) - Custom meta description
 * - seo_keywords (VARCHAR 500) - SEO keywords
 * - og_image (VARCHAR 500) - Custom Open Graph image override
 *
 * Usage: php scripts/add-seo-fields-to-home-content.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
$db = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "🔧 Starting migration: Add SEO fields to home_content table\n\n";

// Check if columns already exist
$checkQuery = "SHOW COLUMNS FROM home_content LIKE 'seo_title'";
$result = $db->query($checkQuery);

if ($result && $result->num_rows > 0) {
    echo "⚠️  SEO fields already exist. Skipping migration.\n";
    $db->close();
    exit(0);
}

// Add SEO columns
echo "📝 Adding SEO columns to home_content table...\n";

$alterQueries = [
    "ALTER TABLE home_content ADD COLUMN seo_title VARCHAR(255) DEFAULT NULL COMMENT 'Custom SEO title (overrides default)' AFTER background_image",
    "ALTER TABLE home_content ADD COLUMN seo_description TEXT DEFAULT NULL COMMENT 'Custom meta description' AFTER seo_title",
    "ALTER TABLE home_content ADD COLUMN seo_keywords VARCHAR(500) DEFAULT NULL COMMENT 'SEO keywords (comma-separated)' AFTER seo_description",
    "ALTER TABLE home_content ADD COLUMN og_image VARCHAR(500) DEFAULT NULL COMMENT 'Custom Open Graph image (overrides hero background)' AFTER seo_keywords"
];

foreach ($alterQueries as $query) {
    if (!$db->query($query)) {
        echo "❌ Error: " . $db->error . "\n";
        $db->close();
        exit(1);
    }
}

echo "✅ SEO columns added successfully\n\n";

// Verify columns were added
echo "🔍 Verifying new columns...\n";
$verifyQuery = "SHOW COLUMNS FROM home_content WHERE Field IN ('seo_title', 'seo_description', 'seo_keywords', 'og_image')";
$result = $db->query($verifyQuery);

if ($result && $result->num_rows === 4) {
    echo "✅ All 4 SEO columns verified:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   - {$row['Field']} ({$row['Type']})\n";
    }
} else {
    echo "⚠️  Warning: Could not verify all columns\n";
}

// Add default SEO values for hero section
echo "\n📝 Adding default SEO values for hero section...\n";

$updateQuery = "UPDATE home_content
                SET seo_description = CONCAT('Esplora il catalogo completo della nostra biblioteca digitale. ', subtitle)
                WHERE section_key = 'hero'
                AND seo_description IS NULL
                AND subtitle IS NOT NULL";

if ($db->query($updateQuery)) {
    $affected = $db->affected_rows;
    if ($affected > 0) {
        echo "✅ Default SEO description set for hero section ($affected row)\n";
    } else {
        echo "ℹ️  No hero section found or already has SEO description\n";
    }
} else {
    echo "⚠️  Could not set default SEO values: " . $db->error . "\n";
}

echo "\n✅ Migration completed successfully!\n";
echo "\n📋 Next steps:\n";
echo "   1. Update installer/database/schema.sql with new columns\n";
echo "   2. Test CMS home edit form with new SEO fields\n";
echo "   3. Update FrontendController to use SEO data\n";

$db->close();
