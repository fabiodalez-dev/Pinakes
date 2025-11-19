<?php
/**
 * Theme Migration Runner
 *
 * This script executes the themes table migration.
 * Run this file ONCE to add the themes table and default themes to your database.
 *
 * Usage:
 *   - Via browser: Navigate to /installer/run_migration_themes.php
 *   - Via CLI: php installer/run_migration_themes.php
 */

declare(strict_types=1);

// Load database configuration
$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    die("ERROR: Database configuration file not found at: {$configFile}\n");
}

$config = require $configFile;

// Extract database credentials
$host = $config['host'] ?? 'localhost';
$dbname = $config['database'] ?? '';
$username = $config['username'] ?? 'root';
$password = $config['password'] ?? '';
$charset = $config['charset'] ?? 'utf8mb4';

if (empty($dbname)) {
    die("ERROR: Database name not configured in config/database.php\n");
}

echo "=== Theme Migration Runner ===\n";
echo "Database: {$dbname}\n";
echo "Host: {$host}\n\n";

try {
    // Connect to database
    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}"
    ]);

    echo "✓ Connected to database successfully\n\n";

    // Check if themes table already exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'themes'");
    $tableExists = $stmt->rowCount() > 0;

    if ($tableExists) {
        echo "⚠ WARNING: Table 'themes' already exists!\n";
        echo "Do you want to continue anyway? This will skip table creation but insert default themes.\n";
        echo "Press ENTER to continue or CTRL+C to cancel...\n";

        if (PHP_SAPI === 'cli') {
            fgets(STDIN);
        } else {
            echo "<p style='color: orange;'>⚠ Table already exists. Migration will continue...</p>\n";
        }
    }

    // Read migration file
    $migrationFile = __DIR__ . '/database/migration_themes.sql';
    if (!file_exists($migrationFile)) {
        die("ERROR: Migration file not found at: {$migrationFile}\n");
    }

    $sql = file_get_contents($migrationFile);
    if ($sql === false) {
        die("ERROR: Could not read migration file\n");
    }

    echo "Reading migration file...\n";

    // Remove comments and split into individual statements
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($stmt) => !empty($stmt)
    );

    echo "Executing " . count($statements) . " SQL statements...\n\n";

    // Execute each statement
    $successCount = 0;
    $errorCount = 0;

    foreach ($statements as $index => $statement) {
        if (empty($statement)) {
            continue;
        }

        try {
            $pdo->exec($statement);
            $successCount++;

            // Show progress for specific operations
            if (stripos($statement, 'CREATE TABLE') !== false) {
                echo "  ✓ Created table 'themes'\n";
            } elseif (stripos($statement, 'INSERT INTO') !== false) {
                echo "  ✓ Inserted default themes data\n";
            }
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if ($e->getCode() === '42S01' || stripos($e->getMessage(), 'already exists') !== false) {
                echo "  ⚠ Table already exists (skipped)\n";
                continue;
            }

            // Ignore duplicate entry errors for themes
            if ($e->getCode() === '23000' && stripos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "  ⚠ Some themes already exist (skipped duplicates)\n";
                continue;
            }

            echo "  ✗ Error in statement " . ($index + 1) . ": " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }

    echo "\n";
    echo "=== Migration Complete ===\n";
    echo "✓ Success: {$successCount} statements\n";
    if ($errorCount > 0) {
        echo "✗ Errors: {$errorCount} statements\n";
    }

    // Verify themes were inserted
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM themes");
    $result = $stmt->fetch();
    $themeCount = $result['count'] ?? 0;

    echo "\nThemes in database: {$themeCount}\n";

    if ($themeCount > 0) {
        echo "\n✓ Migration successful! The themes table is ready.\n";
        echo "\nYou can now:\n";
        echo "  1. Visit /admin/themes to manage themes\n";
        echo "  2. Visit /admin/themes/customize to customize the active theme\n";
        echo "  3. Delete this script for security: installer/run_migration_themes.php\n";
    } else {
        echo "\n⚠ WARNING: No themes found in database. Please check the migration file.\n";
    }

} catch (PDOException $e) {
    echo "\n✗ Database Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";
