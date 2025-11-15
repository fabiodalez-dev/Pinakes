<?php
/**
 * Export Email Templates to Installer data.sql
 *
 * This script exports email templates from the current database
 * (with proper UTF-8 encoding) and replaces them in installer/database/data.sql
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
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
    die("❌ Connection failed: " . $db->connect_error);
}

// Set UTF-8 encoding
$db->set_charset('utf8mb4');
$db->query("SET NAMES utf8mb4");

echo "📧 Exporting Email Templates to Installer...\n\n";

// Fetch all email templates
$result = $db->query("SELECT * FROM email_templates ORDER BY id");

if (!$result) {
    die("❌ Failed to fetch templates: " . $db->error);
}

$templates = [];
while ($row = $result->fetch_assoc()) {
    $templates[] = $row;
}

$db->close();

echo "✓ Found " . count($templates) . " templates in database\n";

// Generate SQL INSERT statements with sequential IDs starting from 1
$inserts = [];
$newId = 1;
foreach ($templates as $t) {
    // Escape values for SQL
    $name = addslashes($t['name']);
    $subject = addslashes($t['subject']);
    $body = addslashes($t['body']);
    $description = addslashes($t['description'] ?? '');
    $active = (int)$t['active'];
    $created = $t['created_at'];
    $updated = $t['updated_at'];

    $inserts[] = "INSERT INTO `email_templates` VALUES ({$newId},'{$name}','{$subject}','{$body}','{$description}',{$active},'{$created}','{$updated}');";
    $newId++;
}

// Read current data.sql
$dataFile = __DIR__ . '/../installer/database/data.sql';
$content = file_get_contents($dataFile);

if ($content === false) {
    die("❌ Failed to read data.sql\n");
}

echo "✓ Read installer/database/data.sql\n";

// Find email_templates section
// Pattern: INSERT INTO `email_templates` VALUES ... ;
$pattern = '/INSERT INTO `email_templates` VALUES \(.*?\);/s';

// Count existing templates
preg_match_all($pattern, $content, $matches);
$oldCount = count($matches[0]);

echo "✓ Found {$oldCount} old templates in data.sql\n";

// Replace all email_templates INSERT statements
$newInserts = implode("\n", $inserts);
$content = preg_replace($pattern, '', $content);

// Find the position after last generi INSERT and before home_content
$insertPosition = strpos($content, "INSERT INTO `generi`");
if ($insertPosition === false) {
    die("❌ Could not find generi section in data.sql\n");
}

// Find end of generi section (before home_content or SET FOREIGN_KEY_CHECKS)
$endPosition = strpos($content, "-- Home content", $insertPosition);
if ($endPosition === false) {
    $endPosition = strpos($content, "SET FOREIGN_KEY_CHECKS=1", $insertPosition);
}

if ($endPosition === false) {
    die("❌ Could not find insertion point in data.sql\n");
}

// Insert new templates before the marker
$before = substr($content, 0, $endPosition);
$after = substr($content, $endPosition);

// Remove any duplicate empty lines
$before = rtrim($before) . "\n";

$newContent = $before . $newInserts . "\n\n\n" . $after;

// Write back to file
file_put_contents($dataFile, $newContent);

echo "✓ Replaced templates in data.sql\n";
echo "\n";
echo "═══════════════════════════════════════\n";
echo "✅ Export completato!\n";
echo "═══════════════════════════════════════\n";
echo "Templates esportati: " . count($templates) . "\n";
echo "Templates vecchi rimossi: {$oldCount}\n";
echo "File: installer/database/data.sql\n";
echo "\n";
echo "🎉 I template email nell'installer sono ora\n";
echo "   sincronizzati con il database corrente!\n";
echo "\n";
echo "⚠️  IMPORTANTE: Commit questo cambiamento in Git\n";
echo "   per mantenere sincronizzato l'installer.\n";
