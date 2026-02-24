<?php
/**
 * Manual Update Script for Pinakes
 *
 * Usage: Upload this file to the root of your Pinakes installation
 * and access it via browser: https://yourdomain.com/manual-update.php
 *
 * This script will:
 * 1. Check write permissions
 * 2. Download the latest release from GitHub
 * 3. Extract and install the update
 * 4. Run database migrations
 *
 * DELETE THIS FILE AFTER USE!
 */

// Security: Only allow access from logged-in admin (via session)
// or with a secret key for emergency access (must be set in .env)
$secretKey = getenv('MANUAL_UPDATE_KEY') ?: (
    file_exists(__DIR__ . '/../.env') ?
        (function() {
            $env = file_get_contents(__DIR__ . '/../.env');
            preg_match('/^MANUAL_UPDATE_KEY=(.*)$/m', $env, $m);
            return trim($m[1] ?? '');
        })() : ''
);

session_start();
$isAdmin = isset($_SESSION['user']['tipo_utente']) && $_SESSION['user']['tipo_utente'] === 'admin';
$hasKey = !empty($secretKey) && isset($_GET['key']) && hash_equals($secretKey, $_GET['key']);

if (!$isAdmin && !$hasKey) {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Please login as admin or provide a valid MANUAL_UPDATE_KEY.";
    exit;
}

// Configuration
$repoOwner = 'fabiodalez-dev';
$repoName = 'Pinakes';
$targetVersion = $_GET['version'] ?? null;

// Error reporting and resource limits
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M'); // Ensure enough memory for extraction

echo "<!DOCTYPE html><html><head><title>Pinakes Manual Update</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#fff;}";
echo ".ok{color:#4caf50;}.err{color:#f44336;}.warn{color:#ff9800;}";
echo "pre{background:#333;padding:10px;overflow-x:auto;}</style></head><body>";
echo "<h1>Pinakes Manual Update</h1>";

function output($msg, $type = 'info') {
    $class = match($type) {
        'ok' => 'ok',
        'error' => 'err',
        'warning' => 'warn',
        default => ''
    };
    echo "<p class=\"$class\">$msg</p>";
    flush();
    ob_flush();
}

function deleteRecursiveDir($path) {
    if (!is_dir($path)) {
        return @unlink($path);
    }
    $files = array_diff(@scandir($path) ?: [], ['.', '..']);
    foreach ($files as $file) {
        deleteRecursiveDir($path . '/' . $file);
    }
    return @rmdir($path);
}

// Step 1: Check permissions
output("=== Step 1: Checking Permissions ===");

$rootPath = __DIR__;
$dirs = [
    'storage',
    'storage/tmp',
    'storage/backups',
    'storage/logs',
    'app',
    'public',
    'config',
];

$allOk = true;
foreach ($dirs as $dir) {
    $path = $rootPath . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (!is_writable($path)) {
        output("$dir: NOT WRITABLE", 'error');
        $allOk = false;
    } else {
        output("$dir: OK", 'ok');
    }
}

if (!$allOk) {
    output("Fix permissions before continuing!", 'error');
    output("Run: chmod -R 775 storage app public config", 'warning');
    exit;
}

// Check ZipArchive extension
if (!class_exists('ZipArchive')) {
    output("ZipArchive extension not available! Contact your hosting provider.", 'error');
    exit;
}
output("ZipArchive: OK", 'ok');

// Check disk space (need at least 200MB)
$freeSpace = @disk_free_space($rootPath);
if ($freeSpace !== false) {
    $freeSpaceMB = round($freeSpace / 1024 / 1024);
    if ($freeSpace < 200 * 1024 * 1024) {
        output("Disk space insufficient: {$freeSpaceMB}MB available, need at least 200MB", 'error');
        exit;
    }
    output("Disk space: {$freeSpaceMB}MB available", 'ok');
} else {
    output("Cannot check disk space (continuing anyway)", 'warning');
}

// Clean up old temp directories
$tmpDir = $rootPath . '/storage/tmp';
$oldDirs = @glob($tmpDir . '/extracted*', GLOB_ONLYDIR);
if ($oldDirs) {
    foreach ($oldDirs as $oldDir) {
        deleteRecursiveDir($oldDir);
    }
    output("Cleaned up " . count($oldDirs) . " old temp directories", 'ok');
}
// Also clean old update.zip if exists
if (file_exists($tmpDir . '/update.zip')) {
    @unlink($tmpDir . '/update.zip');
    output("Cleaned up old update.zip", 'ok');
}

// Step 2: Get current version
output("=== Step 2: Current Version ===");

$versionFile = $rootPath . '/version.json';
$currentVersion = '0.0.0';
if (file_exists($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    $currentVersion = $versionData['version'] ?? '0.0.0';
}
output("Current version: $currentVersion", 'ok');

// Step 3: Fetch latest release
output("=== Step 3: Fetching Latest Release ===");

$apiUrl = "https://api.github.com/repos/$repoOwner/$repoName/releases/latest";
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'User-Agent: Pinakes-Updater/1.0',
            'Accept: application/vnd.github.v3+json',
        ],
        'timeout' => 30,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ]
]);

$response = @file_get_contents($apiUrl, false, $context);
if ($response === false) {
    // Try with curl
    if (function_exists('curl_init')) {
        output("file_get_contents failed, trying cURL...", 'warning');
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$response) {
            output("cURL error: $error", 'error');
            exit;
        }
    } else {
        output("Cannot fetch release info: file_get_contents failed and cURL not available", 'error');
        exit;
    }
}

$release = json_decode($response, true);
if (!$release || !isset($release['tag_name'])) {
    output("Invalid release data", 'error');
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

$latestVersion = ltrim($release['tag_name'], 'v');
output("Latest version: $latestVersion", 'ok');

if (version_compare($currentVersion, $latestVersion, '>=')) {
    output("Already up to date!", 'ok');
    exit;
}

// Step 4: Find download URL
output("=== Step 4: Finding Download URL ===");

$downloadUrl = null;

// Look for pinakes-vX.X.X.zip in assets
foreach ($release['assets'] ?? [] as $asset) {
    if (preg_match('/pinakes-v.*\.zip$/i', $asset['name'])) {
        $downloadUrl = $asset['browser_download_url'];
        output("Found release package: " . $asset['name'], 'ok');
        break;
    }
}

// Fallback to source zipball
if (!$downloadUrl) {
    $downloadUrl = $release['zipball_url'] ?? null;
    output("Using source zipball (no release package found)", 'warning');
}

if (!$downloadUrl) {
    output("No download URL found!", 'error');
    exit;
}

output("Download URL: $downloadUrl", 'ok');

// Step 5: Download update
output("=== Step 5: Downloading Update ===");

$tmpDir = $rootPath . '/storage/tmp';
$zipPath = $tmpDir . '/update.zip';

// Download with cURL for better handling of large files
if (function_exists('curl_init')) {
    $ch = curl_init($downloadUrl);
    $fp = fopen($zipPath, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $success = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$success || $httpCode !== 200) {
        output("Download failed: $error (HTTP $httpCode)", 'error');
        @unlink($zipPath);
        exit;
    }
} else {
    $content = @file_get_contents($downloadUrl, false, $context);
    if ($content === false) {
        output("Download failed", 'error');
        exit;
    }
    file_put_contents($zipPath, $content);
}

$size = filesize($zipPath);
output("Downloaded: " . round($size / 1024 / 1024, 2) . " MB", 'ok');

// Step 6: Extract update (with retry)
output("=== Step 6: Extracting Update ===");

$extractPath = $tmpDir . '/extracted_' . time();
$extractionSuccess = false;
$maxRetries = 3;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    output("Extraction attempt $attempt of $maxRetries...");

    // Clean up any previous failed extraction
    if (is_dir($extractPath)) {
        deleteRecursiveDir($extractPath);
    }
    @mkdir($extractPath, 0775, true);

    $zip = new ZipArchive();
    $result = $zip->open($zipPath);

    if ($result !== true) {
        $errorMessages = [
            ZipArchive::ER_NOZIP => 'Not a ZIP file',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error',
        ];
        $errorMsg = $errorMessages[$result] ?? "Unknown error code: $result";
        output("Cannot open ZIP: $errorMsg", 'warning');

        if ($attempt < $maxRetries) {
            output("Waiting 2 seconds before retry...", 'warning');
            sleep(2);
            continue;
        }
        output("All attempts failed to open ZIP", 'error');
        @unlink($zipPath);
        exit;
    }

    // Try extraction
    if ($zip->extractTo($extractPath)) {
        $zip->close();
        $extractionSuccess = true;
        output("Extracted successfully", 'ok');
        break;
    }

    $zip->close();
    output("Extraction failed on attempt $attempt", 'warning');

    if ($attempt < $maxRetries) {
        output("Waiting 2 seconds before retry...", 'warning');
        sleep(2);
        // Try increasing memory for next attempt
        $currentLimit = ini_get('memory_limit');
        $currentBytes = (int)$currentLimit * 1024 * 1024;
        @ini_set('memory_limit', ($currentBytes * 2) . 'M');
    }
}

if (!$extractionSuccess) {
    output("Extraction failed after $maxRetries attempts!", 'error');
    @unlink($zipPath);
    deleteRecursiveDir($extractPath);
    exit;
}

// Find the content directory (GitHub adds a prefix folder)
$dirs = glob($extractPath . '/*', GLOB_ONLYDIR);
$contentPath = count($dirs) === 1 ? $dirs[0] : $extractPath;
output("Content path: $contentPath", 'ok');

// Step 7: Backup critical files
output("=== Step 7: Backup Critical Files ===");

$backupPath = $rootPath . '/storage/backups/pre-update-' . date('Y-m-d-His');
@mkdir($backupPath, 0775, true);

$criticalFiles = ['.env', 'config.local.php', 'version.json'];
foreach ($criticalFiles as $file) {
    if (file_exists($rootPath . '/' . $file)) {
        copy($rootPath . '/' . $file, $backupPath . '/' . $file);
        output("Backed up: $file", 'ok');
    }
}

// Step 8: Install update
output("=== Step 8: Installing Update ===");

// Files/dirs to skip during update
$skipPaths = ['.env', '.installed', 'config.local.php', 'storage/uploads', 'storage/backups', 'storage/logs', 'public/uploads'];

function copyRecursive($src, $dst, $skipPaths, $rootSrc, $rootDst) {
    $count = 0;
    if (is_dir($src)) {
        @mkdir($dst, 0775, true);
        $files = array_diff(scandir($src), ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            $relativePath = str_replace($rootSrc . '/', '', $srcPath);

            $skip = false;
            foreach ($skipPaths as $skipPath) {
                if (strpos($relativePath, $skipPath) === 0) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip) {
                $count += copyRecursive($srcPath, $dstPath, $skipPaths, $rootSrc, $rootDst);
            }
        }
    } else {
        if (copy($src, $dst)) {
            $count = 1;
        }
    }
    return $count;
}

$copied = copyRecursive($contentPath, $rootPath, $skipPaths, $contentPath, $rootPath);
output("Copied $copied files", 'ok');

// Step 9: Run migrations
output("=== Step 9: Running Migrations ===");

$migrationsPath = $rootPath . '/installer/database/migrations';
if (is_dir($migrationsPath)) {
    // Load database connection
    if (file_exists($rootPath . '/.env')) {
        $envContent = file_get_contents($rootPath . '/.env');
        preg_match('/^DB_HOST=(.*)$/m', $envContent, $m);
        $dbHost = trim($m[1] ?? 'localhost');
        preg_match('/^DB_USER=(.*)$/m', $envContent, $m);
        $dbUser = trim($m[1] ?? '');
        preg_match('/^DB_PASS=(.*)$/m', $envContent, $m);
        $dbPass = trim($m[1] ?? '');
        preg_match('/^DB_NAME=(.*)$/m', $envContent, $m);
        $dbName = trim($m[1] ?? '');

        $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($db->connect_error) {
            output("DB connection failed: " . $db->connect_error, 'error');
        } else {
            $files = glob($migrationsPath . '/migrate_*.sql');
            usort($files, 'version_compare');

            foreach ($files as $file) {
                if (preg_match('/migrate_(\d+\.\d+\.\d+)\.sql$/', $file, $m)) {
                    $migrationVersion = $m[1];
                    if (version_compare($migrationVersion, $currentVersion, '>')) {
                        output("Running migration: " . basename($file));
                        $sql = file_get_contents($file);
                        $statements = array_filter(array_map('trim', explode(';', $sql)));

                        foreach ($statements as $stmt) {
                            if (strpos($stmt, '--') === 0) continue;
                            if (!$db->query($stmt)) {
                                $errno = $db->errno;
                                // Ignore expected idempotent errors:
                                // 1060=duplicate column, 1061=duplicate key, 1050=table exists
                                // 1091=can't DROP, 1068=multiple primary key, 1022=duplicate key entry
                                // 1826=duplicate FK constraint, 1146=table doesn't exist (for DROP IF NOT EXISTS)
                                $ignorableErrors = [1060, 1061, 1050, 1091, 1068, 1022, 1826, 1146];
                                if (!in_array($errno, $ignorableErrors)) {
                                    output("SQL error ($errno): " . $db->error, 'warning');
                                }
                            }
                        }
                        output("Migration $migrationVersion completed", 'ok');
                    }
                }
            }
            $db->close();
        }
    }
} else {
    output("No migrations directory found", 'warning');
}

// Step 10: Cleanup
output("=== Step 10: Cleanup ===");

@unlink($zipPath);
deleteRecursiveDir($extractPath);
output("Cleanup completed", 'ok');

// Done
output("=== UPDATE COMPLETED ===", 'ok');
output("New version: $latestVersion", 'ok');
output("<strong>IMPORTANT: Delete this file (manual-update.php) now!</strong>", 'warning');

echo "</body></html>";
